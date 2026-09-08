<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

use React\Stream\WritableStreamInterface;

/**
 * Thin wrapper around the X11 drawing opcodes.
 *
 * Call {@see init()} once the connection and resources are set up; every
 * method silently no-ops until then (safe to call from handlers before the
 * window is ready).
 *
 * Colour methods change the shared GC's foreground/background attribute, so
 * the last {@see setForeground()} / {@see setBackground()} call wins.
 *
 * Angle parameters are in degrees (0 = 3 o'clock, counter-clockwise).
 */
final class Renderer
{
    // Fallback metrics used before QueryFont reply arrives. Tuned for "fixed".
    private const FALLBACK_CHAR_W  = 6;
    private const FALLBACK_ASCENT  = 9;
    private const FALLBACK_DESCENT = 2;

    private ?WritableStreamInterface $conn = null;

    /**
     * Requests written but not yet sent.
     *
     * X11 requests are tiny — a filled rectangle is 20 bytes — and one socket
     * write per request meant a repaint cost over a thousand syscalls to move
     * 24 kB, around 19 bytes each. They are accumulated here instead and sent in
     * one go by {@see flush()}.
     *
     * Safe because nothing the renderer sends produces a reply, so buffering
     * cannot disturb the reply FIFO in X11Client. What it *could* disturb is
     * order against that client's own writes — a ClearArea arriving after the
     * drawing it was meant to precede — so every paint path flushes at its end
     * and the buffer is empty by the time the client writes anything.
     */
    private string $pending = '';
    private int $windowId                  = 0;
    private int $gcId                      = 0;

    /** The clip in force, so {@see withClip()} can nest and restore it. */
    private ?Rect $clip = null;

    /**
     * What {@see putImage()} needs to know about the server, none of which the
     * other opcodes care about: a PutImage carries raw memory rather than
     * protocol fields, so it must state the drawable's depth, be laid out in the
     * server's byte order, and fit inside one request.
     *
     * A depth of 0 means nobody has said — {@see X11Client} calls
     * {@see setImageFormat()} wherever it initialises a renderer — and
     * {@see putImage()} then declines rather than guessing at a malformed
     * request.
     */
    private int  $imageDepth     = 0;
    private bool $imageLsbFirst  = true;
    private int  $maxRequestWords = 65535;

    /** Server font ids. The bold one is 0 until (and unless) one is opened. */
    private int $fontId     = 0;
    private int $boldFontId = 0;

    private ?FontMetrics $fontMetrics     = null;
    private ?FontMetrics $boldFontMetrics = null;

    /** Which of the two fonts the shared GC currently points at. */
    private bool $bold = false;

    /**
     * Starts un-initialised. Every draw is a no-op until init() attaches it to a connection,
     * so a renderer can exist before the server does.
     */
    public function __construct()
    {
        // Replaced by init() with the shared one; this keeps an un-initialised
        // renderer (the smoke tests build several) from tripping over it.
        $this->gc = new GcState();
    }

    /**
     * Alpha bits OR'd into every pixel this renderer sets: 0xFF000000 on an
     * ARGB window, 0 on an ordinary one (where the top byte is ignored).
     */
    private int $opaqueAlpha = 0;

    /**
     * What the shared GC is believed to hold.
     *
     * Half of every repaint was ChangeGC: painters set a colour before each
     * primitive, and a bevel or a row of text sets the same one over and over.
     * The GC is server-side state, so a request that changes nothing is pure
     * cost — measured at 1147 of 2307 requests for one Windows 9x repaint.
     *
     * Shared rather than private, because several renderers draw through one GC.
     * @see GcState
     */
    private GcState $gc;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * @param GcState|null $gc State of the shared GC. Renderers drawing through
     *        the same GC must be given the same one; a private one is only
     *        correct when this renderer is the sole user.
     */
    public function init(
        WritableStreamInterface $conn,
        int $windowId,
        int $gcId,
        ?GcState $gc = null,
    ): void {
        $this->conn     = $conn;
        $this->windowId = $windowId;
        $this->gcId     = $gcId;
        $this->gc       = $gc ?? new GcState();

        $this->gc->forget();
    }

    /** Whether it is ready. */
    public function isReady(): bool
    {
        return $this->conn !== null;
    }

    /** Queue one request. */
    private function send(string $request): void
    {
        $this->pending .= $request;
    }

    /**
     * Send everything queued, as a single write.
     *
     * Called at the end of each paint path. Also the boundary that keeps request
     * order honest against {@see \Cyrnetix\X11\Client\X11Client}'s own writes:
     * nothing may be left queued when the client next writes.
     */
    public function flush(): void
    {
        if ($this->pending === '' || $this->conn === null) return;

        $this->conn->write($this->pending);
        $this->pending = '';
    }

    /**
     * Tell the renderer its window has an alpha channel (a 32-bit ARGB
     * visual). Every colour it sets then carries alpha 0xFF so ordinary
     * drawing stays opaque, and {@see fillTransparent()} can punch a hole
     * that the compositor shows straight through.
     */
    public function setTranslucent(bool $translucent): void
    {
        $this->opaqueAlpha = $translucent ? 0xFF000000 : 0;
    }

    /** True when a hole punched with {@see fillTransparent()} is really see-through. */
    public function isTranslucent(): bool
    {
        return $this->opaqueAlpha !== 0;
    }

    /**
     * Tell the renderer what a {@see putImage()} has to look like: the depth of
     * the window it draws into, whether the server reads pixels little-endian,
     * and the longest request it will accept (in 4-byte words).
     *
     * @see \Cyrnetix\X11\Event\X11SetupCompleteEvent::$imageByteOrder
     */
    public function setImageFormat(int $depth, bool $lsbFirst, int $maxRequestWords): void
    {
        $this->imageDepth      = $depth;
        $this->imageLsbFirst   = $lsbFirst;
        // Six words are the PutImage header, so seven is the smallest value
        // that can carry a single pixel. Floored there rather than at something
        // comfortable: a bigger floor would silently ignore what the server
        // said, and the chunking is the one thing that depends on it.
        $this->maxRequestWords = max(7, $maxRequestWords);
    }

    /** True when {@see putImage()} knows enough about the server to send one. */
    public function canPutImage(): bool
    {
        return $this->isReady() && $this->imageDepth !== 0;
    }

    /**
     * The clip in force, or null when drawing is unbounded.
     *
     * For a painter that blits a framebuffer: the server would clip a whole-image
     * PutImage correctly, but the bytes still cross the socket. Asking what the
     * clip is turns a region repaint into a blit of just that region — which is
     * what `BitBlt(ps.hdc, ps.rcPaint, …)` was doing in the window-message model
     * this borrows from.
     */
    public function clipRect(): ?Rect
    {
        return $this->clip;
    }

    /** Sets font metrics. */
    public function setFontMetrics(FontMetrics $fontMetrics): void
    {
        $this->fontMetrics = $fontMetrics;
    }

    /**
     * Tell the renderer which server fonts back {@see setBold()}. A bold id of 0
     * means the server had no bold face, and bold requests become no-ops.
     */
    public function setFonts(int $fontId, int $boldFontId = 0): void
    {
        $this->fontId     = $fontId;
        $this->boldFontId = $boldFontId;
        $this->bold       = false;
    }

    /** Sets bold font metrics. */
    public function setBoldFontMetrics(FontMetrics $metrics): void
    {
        $this->boldFontMetrics = $metrics;
    }

    /** Whether it has a bold font. */
    public function hasBoldFont(): bool { return $this->boldFontId !== 0; }

    /**
     * Point the shared GC at the bold or regular font. Measurements follow, so
     * text centred while bold is centred against the bold widths.
     *
     * The GC is shared with the dialog window's renderer, so this always writes
     * rather than trusting a cached flag — and callers must switch back when
     * they're done.
     */
    public function setBold(bool $bold): void
    {
        if (!$this->isReady() || $this->boldFontId === 0 || $this->fontId === 0) return;

        $this->bold = $bold;

        // ChangeGC: value-mask 0x4000 (font).
        $this->send(pack(
            'CCvVVV', 56, 0, 4, $this->gcId, 0x4000,
            $bold ? $this->boldFontId : $this->fontId,
        ));
    }

    /** Run $draw with the bold font selected, then switch back. */
    public function withBold(callable $draw): void
    {
        $this->setBold(true);
        try {
            $draw();
        } finally {
            $this->setBold(false);
        }
    }

    /** Metrics of whichever font is currently selected. */
    private function activeMetrics(): ?FontMetrics
    {
        return $this->bold
            ? ($this->boldFontMetrics ?? $this->fontMetrics)
            : $this->fontMetrics;
    }

    /** Pixel width of $text in the current font. Falls back to monospace 6-wide. */
    public function measureText(string $text): int
    {
        return $this->activeMetrics()?->measureText($text)
            ?? strlen($text) * self::FALLBACK_CHAR_W;
    }

    /** The font ascent. */
    public function fontAscent(): int  { return $this->activeMetrics()?->ascent  ?? self::FALLBACK_ASCENT;  }
    /** The font descent. */
    public function fontDescent(): int { return $this->activeMetrics()?->descent ?? self::FALLBACK_DESCENT; }

    /**
     * Baseline Y so that text is vertically centred inside a rect of $height
     * starting at $top. Uses ascent/descent to keep the optical centre right.
     */
    public function baselineYForRect(int $top, int $height): int
    {
        return $top + intdiv($height + $this->fontAscent() - $this->fontDescent(), 2);
    }

    // -------------------------------------------------------------------------
    // GC colour helpers  (ChangeGC, opcode 56)
    // -------------------------------------------------------------------------

    /** Set the drawing foreground colour (applies to all subsequent draw calls). */
    public function setForeground(int $r, int $g, int $b): void
    {
        if (!$this->isReady()) return;

        $pixel = $this->opaqueAlpha | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
        if ($pixel === $this->gc->foreground) return;

        $this->gc->foreground = $pixel;
        // ChangeGC: length=4w, value-mask=0x04 (foreground), value=pixel
        $this->send(pack('CCvVVV', 56, 0, 4, $this->gcId, 0x04, $pixel));
    }

    /** Set the drawing background colour (used by drawText for the character cell). */
    public function setBackground(int $r, int $g, int $b): void
    {
        if (!$this->isReady()) return;

        $pixel = $this->opaqueAlpha | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
        if ($pixel === $this->gc->background) return;

        $this->gc->background = $pixel;
        // ChangeGC: value-mask=0x08 (background)
        $this->send(pack('CCvVVV', 56, 0, 4, $this->gcId, 0x08, $pixel));
    }

    // -------------------------------------------------------------------------
    // Points  (PolyPoint, opcode 64)
    // -------------------------------------------------------------------------

    /** Draws the pixel. */
    public function drawPixel(int $x, int $y): void
    {
        $this->drawPixels([[$x, $y]]);
    }

    /**
     * Draw multiple pixels in one request.
     *
     * @param array<int, array{0:int,1:int}> $points  [[x,y], ...]
     */
    public function drawPixels(array $points): void
    {
        if (!$this->isReady() || $points === []) return;

        $n    = count($points);
        $data = pack('CCvVV', 64, 0, 3 + $n, $this->windowId, $this->gcId);
        foreach ($points as [$px, $py]) {
            $data .= pack('ss', $px, $py);
        }
        $this->send($data);
    }

    // -------------------------------------------------------------------------
    // Lines  (PolySegment opcode 66, PolyLine opcode 65)
    // -------------------------------------------------------------------------

    /** Draw a single straight line segment. */
    public function drawLine(int $x1, int $y1, int $x2, int $y2): void
    {
        if (!$this->isReady()) return;
        // PolySegment: length=5w (header 3w + 1 segment 2w)
        $this->send(pack('CCvVVssss', 66, 0, 5, $this->windowId, $this->gcId,
            $x1, $y1, $x2, $y2));
    }

    /**
     * Draw multiple disconnected line segments in one request.
     *
     * @param array<int, array{0:int,1:int,2:int,3:int}> $segments  [[x1,y1,x2,y2], ...]
     */
    public function drawLines(array $segments): void
    {
        if (!$this->isReady() || $segments === []) return;

        $n    = count($segments);
        $data = pack('CCvVV', 66, 0, 3 + 2 * $n, $this->windowId, $this->gcId);
        foreach ($segments as [$x1, $y1, $x2, $y2]) {
            $data .= pack('ssss', $x1, $y1, $x2, $y2);
        }
        $this->send($data);
    }

    /**
     * Draw a connected polyline through the given points.
     *
     * @param array<int, array{0:int,1:int}> $points  [[x,y], ...] (≥ 2 points)
     */
    public function drawPolyline(array $points): void
    {
        if (!$this->isReady() || count($points) < 2) return;

        $n    = count($points);
        $data = pack('CCvVV', 65, 0, 3 + $n, $this->windowId, $this->gcId);
        foreach ($points as [$px, $py]) {
            $data .= pack('ss', $px, $py);
        }
        $this->send($data);
    }

    // -------------------------------------------------------------------------
    // Rectangles  (PolyRectangle opcode 67, PolyFillRectangle opcode 70)
    // -------------------------------------------------------------------------

    /** Draw a rectangle outline. */
    public function drawRect(int $x, int $y, int $width, int $height): void
    {
        if (!$this->isReady()) return;
        // PolyRectangle: length=5w (header 3w + 1 rect 2w)  rect layout: ss vv
        $this->send(pack('CCvVVssvv', 67, 0, 5, $this->windowId, $this->gcId,
            $x, $y, $width, $height));
    }

    /** Draw a filled rectangle. */
    public function fillRect(int $x, int $y, int $width, int $height): void
    {
        if (!$this->isReady()) return;
        // PolyFillRectangle: same layout as PolyRectangle, opcode 70
        $this->send(pack('CCvVVssvv', 70, 0, 5, $this->windowId, $this->gcId,
            $x, $y, $width, $height));
    }

    /**
     * Confine every subsequent draw to $rect, or lift the restriction with null.
     *
     * The server discards anything outside it, so a repaint can be aimed at the
     * part of the window that actually changed without every painter having to
     * know about the region. That's what turns a scrollbar drag from a
     * full-window flash into a repaint of the list.
     *
     * Uses SetClipRectangles (opcode 59) to set and ChangeGC's clip-mask = None
     * to clear, because there is no "one rectangle covering everything" that is
     * honestly unbounded.
     */
    public function setClipRect(?Rect $rect): void
    {
        $this->clip = $rect;

        if (!$this->isReady()) return;

        if ($rect === null) {
            // ChangeGC, value-mask 0x080000 = clip-mask, value 0 = None.
            $this->send(pack('CCvVVV', 56, 0, 4, $this->gcId, 0x080000, 0));
            return;
        }

        // SetClipRectangles: ordering = UnSorted(0), then the clip origin and
        // one RECTANGLE. Length is 3 words of header plus 2 per rectangle.
        $this->send(
            pack('CCvVss', 59, 0, 3 + 2, $this->gcId, 0, 0)
            . pack('ssvv', $rect->x, $rect->y, max(0, $rect->width), max(0, $rect->height)),
        );
    }

    /**
     * Draw inside $rect and put the previous clip back afterwards.
     *
     * Nests: the new clip is the *overlap* with whatever was already in force,
     * so a widget that clips its own content can't undo the region clip a
     * partial repaint set up around it — which is what a bare
     * {@see setClipRect()} pair would do, letting the rest of the tree paint
     * over the whole window.
     */
    public function withClip(Rect $rect, callable $fn): void
    {
        $previous = $this->clip;

        $this->setClipRect($previous === null ? $rect : $previous->intersect($rect));
        try {
            $fn();
        } finally {
            $this->setClipRect($previous);
        }
    }

    /**
     * Punch a fully transparent rectangle — alpha 0, so a compositor shows
     * whatever is behind the window.
     *
     * A no-op on a window without an alpha channel: there the same pixel is
     * just opaque black, which looks far worse than the flat colour a caller
     * falls back to. Branch on {@see isTranslucent()} to pick between them.
     */
    public function fillTransparent(int $x, int $y, int $width, int $height): void
    {
        if (!$this->isReady() || !$this->isTranslucent()) return;
        if ($width <= 0 || $height <= 0) return;

        // Straight to ChangeGC: setForeground() would OR the alpha back in.
        // Recorded, so the colour cache doesn't believe a stale value.
        $this->gc->foreground = 0;
        $this->send(pack('CCvVVV', 56, 0, 4, $this->gcId, 0x04, 0));
        $this->send(pack('CCvVVssvv', 70, 0, 5, $this->windowId, $this->gcId,
            $x, $y, $width, $height));
    }

    /**
     * Fill a rectangle with a left-to-right linear gradient between the two
     * colours. Drawn as one 1-pixel-wide column per X step — fine for Win2k
     * title bars; not appropriate for huge surfaces.
     *
     * @param array{int, int, int} $start  RGB at x = $x
     * @param array{int, int, int} $end    RGB at x = $x + $width - 1
     */
    public function fillHorizontalGradient(
        int $x, int $y, int $width, int $height, array $start, array $end
    ): void {
        if (!$this->isReady() || $width <= 0 || $height <= 0) return;

        foreach (self::gradientBands($width, $start, $end) as [$offset, $span, $rgb]) {
            $this->setForeground(...$rgb);
            $this->fillRect($x + $offset, $y, $span, $height);
        }
    }

    /**
     * A gradient as runs of one colour, rather than one column per pixel.
     *
     * A 900-pixel caption has at most 256 distinguishable steps per channel and
     * usually far fewer, so most adjacent columns round to the same colour.
     * Drawing them individually cost 900 fills and 900 ChangeGCs for a caption
     * that renders identically as a few dozen wider bands — measured at 958 of
     * 2307 requests in a Windows 9x repaint.
     *
     * Identical output, not an approximation: a band is emitted only where the
     * rounded colour actually changes.
     *
     * @param array{int, int, int} $start
     * @param array{int, int, int} $end
     * @return list<array{int, int, array{int, int, int}}> [offset, span, rgb]
     */
    private static function gradientBands(int $length, array $start, array $end): array
    {
        [$r1, $g1, $b1] = $start;
        [$r2, $g2, $b2] = $end;
        $denom = max($length - 1, 1);

        $bands     = [];
        $runStart  = 0;
        $runColour = null;

        for ($i = 0; $i < $length; $i++) {
            $t      = $i / $denom;
            $colour = [
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            ];

            if ($runColour === null) {
                $runColour = $colour;
                continue;
            }
            if ($colour === $runColour) continue;

            $bands[]   = [$runStart, $i - $runStart, $runColour];
            $runStart  = $i;
            $runColour = $colour;
        }

        if ($runColour !== null) {
            $bands[] = [$runStart, $length - $runStart, $runColour];
        }

        return $bands;
    }

    /**
     * Fill a rectangle with a top-to-bottom linear gradient. Drawn as one
     * 1-pixel-tall row per Y step — the vertical counterpart to
     * {@see fillHorizontalGradient()}, used by themes whose controls shade
     * downward (Mac OS Platinum buttons and thumbs).
     *
     * @param array{int, int, int} $start  RGB at y = $y
     * @param array{int, int, int} $end    RGB at y = $y + $height - 1
     */
    public function fillVerticalGradient(
        int $x, int $y, int $width, int $height, array $start, array $end
    ): void {
        if (!$this->isReady() || $width <= 0 || $height <= 0) return;

        foreach (self::gradientBands($height, $start, $end) as [$offset, $span, $rgb]) {
            $this->setForeground(...$rgb);
            $this->fillRect($x, $y + $offset, $width, $span);
        }
    }

    // -------------------------------------------------------------------------
    // Images  (PutImage, opcode 72)
    // -------------------------------------------------------------------------

    /**
     * Blit a client-side framebuffer into the window.
     *
     * This is the escape hatch from drawing *primitives* to drawing *pixels*: a
     * caller that has computed an image itself — a rasterised shape, a scaled
     * bitmap, a paint document — hands the memory over and the server copies it
     * in. It is the X11 counterpart of Win32's `CreateDIBSection` + `BitBlt`
     * pair, and it has the same shape: one buffer that is both a plain array of
     * pixels to write into and something the display can blit.
     *
     * $pixels is row-major, 4 bytes per pixel, `$width` pixels per row, each
     * pixel a little-endian `0xAARRGGBB` — the same value {@see setForeground()}
     * builds, so a canvas and a bevel drawn beside it agree about what a colour
     * is. The alpha byte matters on an ARGB window and is ignored elsewhere, so
     * a framebuffer should carry 0xFF there rather than 0: a zero alpha punches
     * a hole through the window exactly like {@see fillTransparent()}.
     *
     * $pixels holds *exactly* the rectangle being blitted — the caller crops,
     * because it is the one that knows how its framebuffer is stored, and a
     * repaint of part of a canvas should cost only that part. Handing over the
     * whole image and letting the GC clip throw most of it away is correct but
     * pays for every byte.
     *
     * ZPixmap at 32 bits per pixel is assumed, which is what every server
     * reports for depths 24 and 32. The rest of the toolkit already assumes a
     * TrueColor visual with 0x00RRGGBB masks — {@see \Cyrnetix\X11\Theme\Palette::pixel()}
     * builds pixel values that way — so this narrows nothing that was open.
     *
     * @param string $pixels $width * $height pixels, row-major, 4 bytes each.
     */
    public function putImage(
        string $pixels,
        int $width, int $height,
        int $destX, int $destY,
    ): void {
        if (!$this->canPutImage())       return;
        if ($width <= 0 || $height <= 0) return;

        $rowBytes = $width * 4;
        if (strlen($pixels) < $rowBytes * $height) return;

        // The request's length field is 16 bits of 4-byte words, so a big image
        // goes out as several PutImages. Chunks are whole rows: a row only fails
        // to fit above ~65 000 pixels wide, which X11's 16-bit coordinates rule
        // out anyway.
        $budget   = ($this->maxRequestWords - 6) * 4;
        $rowsPer  = max(1, intdiv($budget, $rowBytes));

        for ($row = 0; $row < $height; $row += $rowsPer) {
            $rows = min($rowsPer, $height - $row);
            $data = substr($pixels, $row * $rowBytes, $rows * $rowBytes);

            if (!$this->imageLsbFirst) {
                $data = self::swapPixelBytes($data);
            }

            $pad = (4 - (strlen($data) % 4)) % 4;

            $this->send(
                // format 2 = ZPixmap, left-pad 0 (required for ZPixmap).
                pack(
                    'CCvVVvvssCCv', 72, 2, 6 + intdiv(strlen($data) + $pad, 4),
                    $this->windowId, $this->gcId,
                    $width, $rows, $destX, $destY + $row, 0, $this->imageDepth, 0,
                )
                . $data . str_repeat("\x00", $pad),
            );
        }
    }

    /**
     * Reverse every 4-byte pixel, for a server that reads them big-endian.
     *
     * The pixels are memory rather than protocol fields, so nothing byte-swaps
     * them on the way out. Done a slice at a time because the whole chunk can be
     * a quarter of a megabyte: `pack('N*', ...)` over that in one call spreads
     * 65 000 arguments, and the point of a rarely-taken path is not to be the
     * one that falls over.
     */
    private static function swapPixelBytes(string $data): string
    {
        $swapped = '';

        // A multiple of 4, so no pixel is split across two slices.
        foreach (str_split($data, 4096) as $slice) {
            $swapped .= pack('N*', ...array_values((array) unpack('V*', $slice)));
        }

        return $swapped;
    }

    // -------------------------------------------------------------------------
    // Arcs  (PolyArc opcode 68, PolyFillArc opcode 71)
    // Angles are in degrees; X11 stores them as 1/64 ° units.
    // angle1 = start angle (0 = 3 o'clock, counter-clockwise).
    // angle2 = arc extent  (negative = clockwise; 360 = full circle).
    // -------------------------------------------------------------------------

    /** Draw an arc outline inside the bounding box (x, y, width, height). */
    public function drawArc(int $x, int $y, int $width, int $height,
                            int $angle1Deg = 0, int $angle2Deg = 360): void
    {
        if (!$this->isReady()) return;
        // Clamp to INT16 range (though normal degree values are well within it)
        $a1 = max(-32768, min(32767, $angle1Deg * 64));
        $a2 = max(-32768, min(32767, $angle2Deg * 64));
        // PolyArc: length=6w (header 3w + 1 arc 3w)  arc layout: ss vv ss
        $this->send(pack('CCvVVssvvss', 68, 0, 6, $this->windowId, $this->gcId,
            $x, $y, $width, $height, $a1, $a2));
    }

    /** Draw a filled arc (pie slice) inside the bounding box. */
    public function fillArc(int $x, int $y, int $width, int $height,
                            int $angle1Deg = 0, int $angle2Deg = 360): void
    {
        if (!$this->isReady()) return;
        $a1 = max(-32768, min(32767, $angle1Deg * 64));
        $a2 = max(-32768, min(32767, $angle2Deg * 64));
        // PolyFillArc: opcode 71, same layout
        $this->send(pack('CCvVVssvvss', 71, 0, 6, $this->windowId, $this->gcId,
            $x, $y, $width, $height, $a1, $a2));
    }

    /** Draw a circle outline centred at (cx, cy). */
    public function drawCircle(int $cx, int $cy, int $radius): void
    {
        $this->drawArc($cx - $radius, $cy - $radius, $radius * 2, $radius * 2);
    }

    /** Draw a filled circle centred at (cx, cy). */
    public function fillCircle(int $cx, int $cy, int $radius): void
    {
        $this->fillArc($cx - $radius, $cy - $radius, $radius * 2, $radius * 2);
    }

    // -------------------------------------------------------------------------
    // Text  (ImageText8, opcode 76)
    // -------------------------------------------------------------------------

    /**
     * Draw a string at pixel position (x, y) using the GC's current font.
     * Baseline of the first character is at y.
     *
     * ImageText8 also fills the glyph cell with the GC's *background* colour,
     * so call {@see setBackground()} with whatever surface the text sits on
     * first — otherwise the run paints a stale-coloured box behind itself.
     */
    public function drawText(string $text, int $x, int $y): void
    {
        if (!$this->isReady() || $text === '') return;

        $n   = strlen($text);
        $pad = (4 - ($n % 4)) % 4;
        $len = 4 + intdiv($n + $pad, 4);

        $this->send(
            pack('CCvVVss', 76, $n, $len, $this->windowId, $this->gcId, $x, $y)
            . $text . str_repeat("\x00", $pad),
        );
    }

    /**
     * Draw a string touching *only* the glyph pixels (PolyText8, opcode 74).
     *
     * This is the right call for a label on a surface that has just been
     * painted, which is every label in the toolkit — `paintAll` repaints the
     * whole tree, so there is never stale text to cover up. {@see drawText()}'s
     * opaque cell is as tall as the font ascent + descent, so on a control only
     * a couple of pixels taller than its text it paints over the control's own
     * border; these glyphs cannot.
     */
    public function drawGlyphs(string $text, int $x, int $y): void
    {
        if (!$this->isReady() || $text === '') return;

        // A TEXTITEM8 counts its string in one byte, so long runs go out as
        // several items. The delta is 0: each item continues where the last
        // glyph ended.
        $items = '';
        foreach (str_split($text, 254) as $chunk) {
            $items .= chr(strlen($chunk)) . "\x00" . $chunk;
        }

        $pad = (4 - (strlen($items) % 4)) % 4;
        $len = 4 + intdiv(strlen($items) + $pad, 4);

        $this->send(
            pack('CCvVVss', 74, 0, $len, $this->windowId, $this->gcId, $x, $y)
            . $items . str_repeat("\x00", $pad),
        );
    }
}
