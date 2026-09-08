<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Theme\Palette;
use Cyrnetix\X11\UI\Event\CanvasPaintEvent;
use Cyrnetix\X11\UI\Event\CanvasPhase;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * A framebuffer in a themed well: the widget for everything the toolkit has no
 * widget for. A drawing surface, a plot, a preview, a rasteriser's output — the
 * application writes pixels and the canvas gets them onto the screen.
 *
 * The pixels are *not* chrome. Everything else in `src/UI` asks the theme what a
 * colour is; a canvas holds document content, so its colours come from whoever
 * owns the document. Its **frame** is still the theme's (a sunken well, like a
 * text box), and its paper follows {@see Palette::$content} unless the
 * application names a colour — so a canvas used as a display surface changes era
 * with everything else, while a canvas that has been drawn on keeps its picture
 * across a theme switch.
 *
 * ## How this maps to the era it looks like
 *
 * This is the X11 half of what Win32 did with `CreateDIBSection` + `BitBlt`: one
 * buffer that is both an array of pixels the application writes into and
 * something the display can blit. The parts line up:
 *
 * | Win32                        | here                                          |
 * |------------------------------|-----------------------------------------------|
 * | `CreateDIBSection` → `ppvBits`| this widget's row storage                    |
 * | drawing into the memory DC   | {@see setPixel()} / {@see drawLine()} / …     |
 * | `InvalidateRect`             | {@see damage()}, which the handler repaints   |
 * | `BitBlt(ps.hdc, ps.rcPaint)` | {@see \Cyrnetix\X11\UI\Painter\CanvasPainter} |
 * | `WM_PAINT`                   | Expose, or any region repaint                 |
 *
 * ## Two different rectangles, and both are needed
 *
 * - **{@see damage()}** is what the *application's* drawing touched, recorded by
 *   the primitives themselves so a tool doesn't have to report what it did. The
 *   handler turns it into a `redrawRegion()`, which is the whole reason a pencil
 *   stroke costs a few hundred bytes instead of a full repaint.
 * - **The renderer's clip** is what may actually show, and the painter blits only
 *   that. They are different: a repaint can be asked for by something other than
 *   drawing (an Expose, a theme switch), and then there is no damage but the
 *   whole visible image has to go out again.
 *
 * ## Storage
 *
 * One string per row, 4 bytes per pixel, little-endian `0xFFRRGGBB` — the wire
 * format {@see \Cyrnetix\X11\Drawing\Renderer::putImage()} wants, so a blit is a
 * `substr` and not a conversion pass. Alpha is always 0xFF: on the toolkit's ARGB
 * window a zero there would punch a hole straight through to the desktop.
 *
 * Row-per-string rather than one flat buffer because both hot paths want it —
 * filling a run is one `substr_replace` on a short string instead of a copy of
 * the whole image, and {@see snapshot()} is an array copy that PHP shares until
 * a row is written, which is what makes an undo stack affordable.
 */
final class Canvas extends Widget implements Bounded
{
    /** Bytes per pixel — see the class doc; ZPixmap at depth 24/32. */
    private const BPP = 4;

    /** @var list<string> One row per image row, each imageWidth * BPP bytes. */
    private array $rows = [];

    /**
     * The paper's size, which is *not* the widget's: an image may be smaller
     * than the well holding it, or larger and clipped by it.
     */
    private int $imageWidth;
    private int $imageHeight;

    /** True once the image size follows the widget's, so setSize() grows the paper. */
    private readonly bool $imageFollowsWidget;

    /** What the application's drawing has touched, in image coordinates. */
    private Rect $damage;

    /**
     * True once the damage covers the whole image.
     *
     * A short-circuit with a measured reason: a software renderer clears the
     * image and then writes thousands of short spans over it, and every one of
     * those was allocating a rectangle to union into a rectangle that already
     * covered it. Nothing can widen a full-image damage, so once it is full the
     * bookkeeping stops until it is taken.
     */
    private bool $fullyDamaged = false;

    /**
     * Has anything been drawn into it? Until something has, the paper follows a
     * theme switch; afterwards a switch must not wipe the picture.
     */
    private bool $drawnOn = false;

    private ?\Closure $onPaint = null;

    /**
     * @param array{int,int,int}|null $paper Paper colour, or null to follow the
     *        theme's content surface. An application drawing a document names
     *        one; a canvas used to display something usually should not.
     * @param int|null $imageWidth  Paper size, or null to track the widget's own.
     */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        ?int $imageWidth = null,
        ?int $imageHeight = null,
        private readonly ?array $paper = null,
    ) {
        parent::__construct($x, $y);

        $this->imageFollowsWidget = $imageWidth === null && $imageHeight === null;
        $this->imageWidth   = max(1, $imageWidth  ?? $this->contentWidth());
        $this->imageHeight  = max(1, $imageHeight ?? $this->contentHeight());
        $this->damage       = Rect::of(0, 0, 0, 0);

        $this->rows = array_fill(0, $this->imageHeight, $this->blankRow());
    }

    // -------------------------------------------------------------------------
    // Geometry
    // -------------------------------------------------------------------------

    /** The whole widget, frame included. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    /** A frame plus a filled interior: nothing behind it shows through. */
    public function paintsOwnBackground(): bool { return true; }

    /**
     * Inside the frame — where the image is blitted and where a click counts.
     *
     * Read from {@see \Cyrnetix\X11\Theme\Metrics::$canvasBorder} on both sides,
     * so a theme with a thinner edge really does expose another pixel of image
     * *and* accept a click on it.
     */
    public function contentRect(): Rect
    {
        return $this->bounds()->inset($this->metrics()->canvasBorder);
    }

    /** The part of the image that is on screen, in window coordinates. */
    public function imageRect(): Rect
    {
        $content = $this->contentRect();

        return Rect::of(
            $content->x,
            $content->y,
            min($this->imageWidth,  max(0, $content->width)),
            min($this->imageHeight, max(0, $content->height)),
        );
    }

    /** Width of the paper in pixels, which may differ from the widget's. */
    public function imageWidth(): int  { return $this->imageWidth; }

    /** Height of the paper in pixels, which may differ from the widget's. */
    public function imageHeight(): int { return $this->imageHeight; }

    /** Whether the point is over a pixel of the image. */
    public function hitTest(int $mx, int $my): bool
    {
        return $this->imageRect()->contains($mx, $my);
    }

    /**
     * Window coordinates → image coordinates. May fall outside the image: a drag
     * that leaves the canvas still reports where it went, and the caller decides
     * whether to clamp (a stroke should) or discard it.
     *
     * @return array{int, int}
     */
    public function toImage(int $mx, int $my): array
    {
        $content = $this->contentRect();

        return [$mx - $content->x, $my - $content->y];
    }

    /** An image rectangle in window coordinates, for a repaint. */
    public function toWindow(Rect $image): Rect
    {
        $content = $this->contentRect();

        return $image->shift($content->x, $content->y);
    }

    /**
     * Resize the widget, and the paper with it when the paper was never given a
     * size of its own. Existing pixels are kept; new ones are paper.
     */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(0, $width);
        $this->height = max(0, $height);

        if ($this->imageFollowsWidget) {
            $this->resizeImage($this->contentWidth(), $this->contentHeight());
        }
    }

    /**
     * Change the paper size, keeping what has been drawn. Growing fills the new
     * area with paper; shrinking discards what falls outside, which is what a
     * bitmap editor does when you crop.
     */
    public function resizeImage(int $width, int $height): void
    {
        $width  = max(1, $width);
        $height = max(1, $height);

        if ($width === $this->imageWidth && $height === $this->imageHeight) return;

        $oldWidth = $this->imageWidth;
        $oldRows  = $this->rows;

        $this->imageWidth  = $width;
        $this->imageHeight = $height;

        $blank      = $this->blankRow();
        $keptBytes  = min($oldWidth, $width) * self::BPP;
        $this->rows = [];

        for ($y = 0; $y < $height; $y++) {
            $old = $oldRows[$y] ?? null;
            $this->rows[] = $old === null
                ? $blank
                : substr_replace($blank, substr($old, 0, $keptBytes), 0, $keptBytes);
        }

        // Everything moved, so nothing partial can be trusted.
        $this->damage = Rect::of(0, 0, $width, $height);
    }

    // -------------------------------------------------------------------------
    // The framebuffer
    // -------------------------------------------------------------------------

    /**
     * The pixels of one image rectangle, cropped and packed ready for
     * {@see \Cyrnetix\X11\Drawing\Renderer::putImage()}. Empty when the
     * rectangle misses the image entirely.
     */
    public function region(Rect $region): string
    {
        $clipped = $region->intersect(Rect::of(0, 0, $this->imageWidth, $this->imageHeight));
        if ($clipped->isEmpty()) return '';

        $offset = $clipped->x * self::BPP;
        $length = $clipped->width * self::BPP;
        $data   = '';

        for ($y = $clipped->y; $y <= $clipped->bottom(); $y++) {
            $data .= substr($this->rows[$y], $offset, $length);
        }

        return $data;
    }

    /** The colour at a pixel, or null outside the image. @return array{int,int,int}|null */
    public function pixelAt(int $x, int $y): ?array
    {
        if ($x < 0 || $y < 0 || $x >= $this->imageWidth || $y >= $this->imageHeight) return null;

        $offset = $x * self::BPP;
        $row    = $this->rows[$y];

        // Little-endian 0xFFRRGGBB: blue first, then green, then red.
        return [ord($row[$offset + 2]), ord($row[$offset + 1]), ord($row[$offset])];
    }

    /**
     * Paint the whole image one colour. Null means the paper colour.
     *
     * @param array{int,int,int}|null $rgb
     */
    public function clear(?array $rgb = null): void
    {
        $this->rows    = array_fill(0, $this->imageHeight, $this->blankRow($rgb));
        $this->drawnOn = true;
        $this->damaged(Rect::of(0, 0, $this->imageWidth, $this->imageHeight));
    }

    /**
     * @param array{int,int,int} $rgb
     */
    public function setPixel(int $x, int $y, array $rgb): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->imageWidth || $y >= $this->imageHeight) return;

        $offset = $x * self::BPP;
        $pixel  = self::pack($rgb);

        // Byte-at-a-time rather than substr_replace: assigning a string offset
        // writes in place, where substr_replace would copy the row.
        $this->rows[$y][$offset]     = $pixel[0];
        $this->rows[$y][$offset + 1] = $pixel[1];
        $this->rows[$y][$offset + 2] = $pixel[2];
        $this->rows[$y][$offset + 3] = $pixel[3];

        $this->drawnOn = true;
        $this->damaged(Rect::of($x, $y, 1, 1));
    }

    /**
     * @param array{int,int,int} $rgb
     */
    public function fillRect(int $x, int $y, int $width, int $height, array $rgb): void
    {
        $rect = Rect::of($x, $y, $width, $height)
            ->intersect(Rect::of(0, 0, $this->imageWidth, $this->imageHeight));
        if ($rect->isEmpty()) return;

        $run = str_repeat(self::pack($rgb), $rect->width);

        for ($row = $rect->y; $row <= $rect->bottom(); $row++) {
            $this->rows[$row] = substr_replace(
                $this->rows[$row], $run, $rect->x * self::BPP, $rect->width * self::BPP,
            );
        }

        $this->drawnOn = true;
        $this->damaged($rect);
    }

    /**
     * Fill a vertical run in one column.
     *
     * The primitive a software renderer actually wants, and the reason it is
     * here rather than being expressed with the others: a wall in a
     * Doom-style renderer is drawn as one vertical span per screen column, and
     * neither existing primitive can carry that.
     *
     * - {@see setPixel()} per pixel costs a bounds check, a `pack()` and a
     *   damage union *each* — measured at 64 ms for a 160x100 screen against
     *   1.6 ms for the same writes done straight, a factor of forty.
     * - {@see fillRect()} of a one-pixel-wide rectangle does one
     *   `substr_replace` per row, and each of those copies the whole row: a
     *   1280-byte row copied 200 times per column, 320 columns deep, is 82 MB a
     *   frame.
     *
     * So this writes the four bytes in place, row by row, and unions the damage
     * once for the whole span. Clipped to the image, so a caller may hand it a
     * span that runs off the top or bottom — which every wall renderer does at
     * the point where a wall is taller than the screen.
     *
     * @param array{int,int,int} $rgb
     */
    public function fillSpan(int $x, int $top, int $height, array $rgb): void
    {
        if ($x < 0 || $x >= $this->imageWidth || $height <= 0) return;

        $bottom = min($this->imageHeight - 1, $top + $height - 1);
        $top    = max(0, $top);
        if ($bottom < $top) return;

        $offset = $x * self::BPP;
        $pixel  = self::pack($rgb);

        for ($y = $top; $y <= $bottom; $y++) {
            $this->rows[$y][$offset]     = $pixel[0];
            $this->rows[$y][$offset + 1] = $pixel[1];
            $this->rows[$y][$offset + 2] = $pixel[2];
            $this->rows[$y][$offset + 3] = $pixel[3];
        }

        $this->drawnOn = true;
        $this->damaged(Rect::of($x, $top, 1, $bottom - $top + 1));
    }

    /**
     * Write a prepared run of pixels down one column.
     *
     * The textured counterpart to {@see fillSpan()}: a renderer that samples a
     * different colour per pixel — a wall texture, a gradient, a scanline of
     * computed output — builds the run itself and hands it over whole. $pixels
     * is 4 bytes each in this widget's own order, which is what
     * {@see \Cyrnetix\X11\Drawing\Renderer::putImage()} takes and what
     * {@see \Cyrnetix\X11\Theme\Palette::pixel()} builds, so a caller with a
     * pre-packed lookup table writes straight from it with no conversion at all.
     *
     * Clipped at both ends like a span, because a wall taller than the screen is
     * the normal case rather than an error: pixels that fall above the image are
     * skipped over in the source, so what lands stays aligned with what was
     * asked for.
     *
     * @param string $pixels 4 bytes per pixel, top to bottom.
     */
    public function putSpan(int $x, int $top, string $pixels): void
    {
        if ($x < 0 || $x >= $this->imageWidth) return;

        $count = intdiv(strlen($pixels), self::BPP);
        if ($count === 0) return;

        // Skip the part above the image rather than shifting the rest up.
        $skip   = $top < 0 ? min($count, -$top) : 0;
        $first  = $top + $skip;
        $last   = min($this->imageHeight - 1, $top + $count - 1);
        if ($first > $last) return;

        $offset = $x * self::BPP;
        $source = $skip * self::BPP;

        for ($y = $first; $y <= $last; $y++) {
            $this->rows[$y][$offset]     = $pixels[$source];
            $this->rows[$y][$offset + 1] = $pixels[$source + 1];
            $this->rows[$y][$offset + 2] = $pixels[$source + 2];
            $this->rows[$y][$offset + 3] = $pixels[$source + 3];
            $source += self::BPP;
        }

        $this->drawnOn = true;
        $this->damaged(Rect::of($x, $first, 1, $last - $first + 1));
    }

    /**
     * Write a prepared run of pixels **across** one row.
     *
     * The cheap direction, and by a margin that decides algorithms. A row-major
     * framebuffer stores a horizontal run contiguously, so this is one
     * `substr_replace` however long the run is, where the vertical
     * {@see putSpan()} must touch every row it crosses. Measured on the same
     * 64,000 pixels: 0.05 ms across rows against 10.4 ms down columns, a factor
     * of two hundred.
     *
     * That asymmetry is why a Doom-style renderer draws walls as vertical strips
     * and floors as horizontal ones — a floor is a plane at a fixed height, so
     * every pixel on one screen row is the same distance away and the row can be
     * mapped with a single perspective divide. The awkward direction for one is
     * the natural direction for the other.
     *
     * Clipped at both ends without shifting the run, so what lands stays aligned
     * with what was asked for.
     *
     * @param string $pixels 4 bytes per pixel, left to right.
     */
    public function putRow(int $y, int $x, string $pixels): void
    {
        if ($y < 0 || $y >= $this->imageHeight) return;

        $count = intdiv(strlen($pixels), self::BPP);
        if ($count === 0) return;

        // Trim what falls off each end rather than sliding the run into view.
        $skip  = $x < 0 ? min($count, -$x) : 0;
        $first = $x + $skip;
        $last  = min($this->imageWidth - 1, $x + $count - 1);
        if ($first > $last) return;

        $length = ($last - $first + 1) * self::BPP;

        $this->rows[$y] = substr_replace(
            $this->rows[$y],
            substr($pixels, $skip * self::BPP, $length),
            $first * self::BPP,
            $length,
        );

        $this->drawnOn = true;
        $this->damaged(Rect::of($first, $y, $last - $first + 1, 1));
    }

    /**
     * A straight line, Bresenham, $size pixels thick — the pencil, and the only
     * primitive a freehand drag needs: pointer motion arrives in jumps, so a
     * stroke is a chain of segments rather than a chain of points.
     *
     * @param array{int,int,int} $rgb
     */
    public function drawLine(int $x0, int $y0, int $x1, int $y1, array $rgb, int $size = 1): void
    {
        $size = max(1, $size);
        $dx   = abs($x1 - $x0);
        $dy   = abs($y1 - $y0);
        $sx   = $x0 < $x1 ? 1 : -1;
        $sy   = $y0 < $y1 ? 1 : -1;
        $err  = $dx - $dy;

        while (true) {
            if ($size === 1) {
                $this->setPixel($x0, $y0, $rgb);
            } else {
                // Centred, so a thick line tracks the cursor rather than
                // hanging below and to the right of it.
                $half = intdiv($size, 2);
                $this->fillRect($x0 - $half, $y0 - $half, $size, $size, $rgb);
            }

            if ($x0 === $x1 && $y0 === $y1) break;

            $e2 = 2 * $err;
            if ($e2 > -$dy) { $err -= $dy; $x0 += $sx; }
            if ($e2 <  $dx) { $err += $dx; $y0 += $sy; }
        }
    }

    /**
     * A rectangle outline $size pixels thick, drawn *inside* the rectangle.
     *
     * @param array{int,int,int} $rgb
     */
    public function strokeRect(int $x, int $y, int $width, int $height, array $rgb, int $size = 1): void
    {
        if ($width <= 0 || $height <= 0) return;

        $size = max(1, min($size, intdiv(min($width, $height) + 1, 2)));

        $this->fillRect($x, $y, $width, $size, $rgb);
        $this->fillRect($x, $y + $height - $size, $width, $size, $rgb);
        $this->fillRect($x, $y, $size, $height, $rgb);
        $this->fillRect($x + $width - $size, $y, $size, $height, $rgb);
    }

    /**
     * An ellipse outline inscribed in the rectangle — midpoint algorithm, one
     * quadrant computed and mirrored into the other three.
     *
     * @param array{int,int,int} $rgb
     */
    public function strokeEllipse(int $x, int $y, int $width, int $height, array $rgb): void
    {
        if ($width <= 0 || $height <= 0) return;

        $a  = intdiv($width  - 1, 2);
        $b  = intdiv($height - 1, 2);
        $cx = $x + $a;
        $cy = $y + $b;

        // A degenerate ellipse is a line, and the mirroring below would draw
        // nothing at all for it.
        if ($a === 0 || $b === 0) {
            $this->drawLine($x, $y, $x + $width - 1, $y + $height - 1, $rgb);
            return;
        }

        $plot = function (int $dx, int $dy) use ($cx, $cy, $rgb, $width, $height): void {
            // The far side is measured from the far edge, so an even width or
            // height stays symmetric instead of losing its last row.
            $right  = $cx + $dx + ($width  % 2 === 0 ? 1 : 0);
            $bottom = $cy + $dy + ($height % 2 === 0 ? 1 : 0);

            $this->setPixel($cx - $dx, $cy - $dy, $rgb);
            $this->setPixel($right,    $cy - $dy, $rgb);
            $this->setPixel($cx - $dx, $bottom,   $rgb);
            $this->setPixel($right,    $bottom,   $rgb);
        };

        $a2 = $a * $a;
        $b2 = $b * $b;

        // Region 1: stepping in x while the tangent is shallower than 45°.
        $dx = 0;
        $dy = $b;
        $d1 = $b2 - $a2 * $b + intdiv($a2, 4);
        $plot($dx, $dy);

        while ($b2 * ($dx + 1) < $a2 * ($dy - 0.5)) {
            if ($d1 < 0) {
                $d1 += $b2 * (2 * $dx + 3);
            } else {
                $d1 += $b2 * (2 * $dx + 3) + $a2 * (-2 * $dy + 2);
                $dy--;
            }
            $dx++;
            $plot($dx, $dy);
        }

        // Region 2: stepping in y for the rest.
        $d2 = $b2 * ($dx * $dx + $dx) + $a2 * ($dy * $dy - 2 * $dy + 1) - $a2 * $b2
            + intdiv($b2, 4);

        while ($dy > 0) {
            if ($d2 > 0) {
                $d2 += $a2 * (-2 * $dy + 3);
            } else {
                $d2 += $b2 * (2 * $dx + 2) + $a2 * (-2 * $dy + 3);
                $dx++;
            }
            $dy--;
            $plot($dx, $dy);
        }
    }

    // -------------------------------------------------------------------------
    // Damage
    // -------------------------------------------------------------------------

    /** What the drawing above has touched, in image coordinates. */
    public function damage(): Rect { return $this->damage; }

    /**
     * Take the accumulated damage and reset it — the handler's cue for how much
     * of the window to repaint. Reset on the way out so the next stroke starts
     * from nothing; a caller that only wants to look uses {@see damage()}.
     */
    public function takeDamage(): Rect
    {
        $damage             = $this->damage;
        $this->damage       = Rect::of(0, 0, 0, 0);
        $this->fullyDamaged = false;

        return $damage;
    }

    /** Whether anything has been drawn into the image. */
    public function isDrawnOn(): bool { return $this->drawnOn; }

    // -------------------------------------------------------------------------
    // Undo
    // -------------------------------------------------------------------------

    /**
     * An opaque copy of the image, for an undo stack or a drag preview.
     *
     * Cheap: it is the row array, and PHP shares those strings until one is
     * written to, so the cost is one row copied per row later touched rather
     * than a copy of the whole image. That is what makes the
     * restore-and-redraw preview in `example/paint.php` affordable on every
     * motion event — the trick the era did with an XOR pen because it had no
     * buffer to restore from.
     *
     * @return list<string>
     */
    public function snapshot(): array { return $this->rows; }

    /**
     * Put a {@see snapshot()} back. Nothing else is a valid argument — the
     * shape is this widget's own storage — and one of the wrong size is
     * refused rather than corrupting the image.
     *
     * @param list<string> $snapshot
     */
    public function restore(array $snapshot): bool
    {
        if (count($snapshot) !== $this->imageHeight) return false;

        $rowBytes = $this->imageWidth * self::BPP;
        foreach ($snapshot as $row) {
            if (strlen($row) !== $rowBytes) return false;
        }

        $this->rows = $snapshot;
        $this->damaged(Rect::of(0, 0, $this->imageWidth, $this->imageHeight));

        return true;
    }

    /**
     * Put back one *rectangle* of a snapshot — a blit from the saved image, and
     * the reason a shape tool's preview is cheap.
     *
     * A rubber-band preview has to un-draw the previous one before drawing the
     * next, and the era did that with an inverting pen (`SetROP2(R2_NOTXORPEN)`,
     * X11's `GXxor`) precisely because there was no saved copy to restore from:
     * drawing the same shape twice erased it. With a framebuffer the honest
     * version is available — restore what was under it — and unlike XOR it
     * leaves no stray outline behind when a drag is interrupted. Restoring the
     * whole image would work too, and would damage the whole image; this damages
     * only the band the preview was in.
     *
     * @param list<string> $snapshot
     */
    public function restoreRegion(array $snapshot, Rect $region): bool
    {
        if (count($snapshot) !== $this->imageHeight) return false;

        $rect = $region->intersect(Rect::of(0, 0, $this->imageWidth, $this->imageHeight));
        if ($rect->isEmpty()) return true;

        $offset = $rect->x * self::BPP;
        $length = $rect->width * self::BPP;

        for ($y = $rect->y; $y <= $rect->bottom(); $y++) {
            $this->rows[$y] = substr_replace(
                $this->rows[$y], substr($snapshot[$y], $offset, $length), $offset, $length,
            );
        }

        $this->damaged($rect);

        return true;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    /**
     * What a press, drag or release on the canvas means. One closure, like every
     * other widget's callback slot — a composite that owns a canvas wires this
     * in its own constructor.
     */
    public function setOnPaint(?\Closure $cb): void { $this->onPaint = $cb; }

    /**
     * Called by {@see \Cyrnetix\X11\UI\Handler\CanvasHandler} with the pointer
     * in *image* coordinates. The canvas itself draws nothing: what a stroke
     * means is the application's, and the tools live there.
     *
     * @param int $x         Pointer position in image coordinates, clamped to the paper.
     * @param int $y         As $x.
     * @param int $previousX Where the previous event of this stroke was; equal to
     *                       $x on {@see CanvasPhase::Begin}.
     * @param int $previousY As $previousX.
     */
    public function paintAt(int $x, int $y, int $previousX, int $previousY, CanvasPhase $phase): void
    {
        $event = new CanvasPaintEvent($this, $x, $y, $previousX, $previousY, $phase);

        if ($this->onPaint !== null) {
            ($this->onPaint)($event);
        }
        $this->dispatcher->dispatch($event);
    }

    // -------------------------------------------------------------------------
    // Theme
    // -------------------------------------------------------------------------

    /**
     * A theme switch can move the frame, and it decides the paper colour for a
     * canvas that hasn't been drawn on — so an undrawn display surface follows
     * the era, and a drawing does not get wiped by one.
     */
    public function relayout(): void
    {
        if ($this->imageFollowsWidget) {
            $this->resizeImage($this->contentWidth(), $this->contentHeight());
        }

        if (!$this->drawnOn && $this->paper === null) {
            $this->rows = array_fill(0, $this->imageHeight, $this->blankRow());
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** Interior width, which is what the paper tracks when it has no size of its own. */
    private function contentWidth(): int
    {
        return max(1, $this->width - 2 * $this->metrics()->canvasBorder);
    }

    /** The interior height. */
    private function contentHeight(): int
    {
        return max(1, $this->height - 2 * $this->metrics()->canvasBorder);
    }

    /**
     * One row of paper (or of $rgb).
     *
     * @param array{int,int,int}|null $rgb
     */
    private function blankRow(?array $rgb = null): string
    {
        return str_repeat(self::pack($rgb ?? $this->paperColour()), $this->imageWidth);
    }

    /**
     * The paper: what the application asked for, else the theme's content
     * surface, else the toolkit's default palette — the same fallback chain
     * {@see Widget::metrics()} uses, and for the same reason: a widget that
     * isn't attached yet still has to answer.
     *
     * @return array{int,int,int}
     */
    private function paperColour(): array
    {
        return $this->paper
            ?? $this->chrome()?->palette()->content
            ?? (new Palette())->content;
    }

    /**
     * One pixel, in the byte order {@see \Cyrnetix\X11\Drawing\Renderer::putImage()} wants.
     *
     * @param array{int,int,int} $rgb
     */
    private static function pack(array $rgb): string
    {
        // Alpha 0xFF, always: on the ARGB visual the toolkit prefers, a zero
        // there is a hole through the window rather than a black pixel.
        return pack('V', 0xFF000000 | Palette::pixel($rgb));
    }

    /** Widen the pending damage to cover $rect. */
    private function damaged(Rect $rect): void
    {
        if ($this->fullyDamaged) return;

        $this->damage = $this->damage->union($rect);

        $this->fullyDamaged = $this->damage->x <= 0
            && $this->damage->y <= 0
            && $this->damage->width  >= $this->imageWidth
            && $this->damage->height >= $this->imageHeight;
    }
}
