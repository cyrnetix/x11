<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Example;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\CanvasPaintEvent;
use Cyrnetix\X11\UI\Event\CanvasPhase;
use Cyrnetix\X11\UI\Widget\Canvas;

/**
 * What a stroke means: the tools, the undo stack, and the rubber-band preview.
 *
 * A {@see Canvas} knows how to write pixels and nothing about why. This class is
 * the "why", and it is deliberately not a widget: it takes a canvas, it takes
 * paint events, and it has no idea an X server exists — which is what lets
 * `tests/paint_test.php` drive every tool with no display.
 *
 * ## The three things worth reading
 *
 * **One snapshot does two jobs.** The copy taken when the button goes down is
 * both the undo entry and the base the preview restores from. That is not a
 * saving so much as the original design: Paint's single-level undo *was* the
 * "before" bitmap, and a shape tool re-drawing itself needs exactly the same
 * image.
 *
 * **The preview restores, it does not invert.** A Win32 app with no backing
 * store rubber-banded with `SetROP2(R2_NOTXORPEN)` because drawing a shape twice
 * erased it. Here the previous preview's rectangle is copied back from the
 * snapshot before the next is drawn — which costs a blit of that band and, unlike
 * XOR, cannot leave a stray outline on screen if the drag is interrupted.
 *
 * **Only the band that changed is touched.** `$previewBounds` is the rectangle
 * the last preview occupied, so restoring is bounded even though the snapshot
 * holds the whole image. Restoring all of it would work and would repaint the
 * whole canvas on every motion event.
 *
 * @phpstan-type Rgb array{int, int, int}
 */
final class PaintDocument
{
    private PaintTool $tool = PaintTool::Pencil;

    /** @var Rgb */
    private array $colour;

    /** @var Rgb The eraser's colour, and what {@see clear()} paints. */
    private array $paper;

    private int $brushSize = 1;

    /**
     * Undo entries, oldest first. Each is a {@see Canvas::snapshot()}, which PHP
     * shares with the live image until a row is written — so a deep stack costs
     * about as much as the rows that have actually changed since.
     *
     * @var list<list<string>>
     */
    private array $undo = [];

    /** The snapshot this stroke began from: the undo entry, and the preview's base. */
    private ?array $strokeBase = null;

    /** Where the current shape stroke started, in image coordinates. */
    private int $anchorX = 0;
    private int $anchorY = 0;

    /** What the last preview covered, so the next one can put it back. */
    private ?Rect $previewBounds = null;

    /**
     * @param Rgb $colour Starting foreground colour.
     * @param Rgb $paper  Paper colour — the eraser's ink, and what a clear fills.
     */
    public function __construct(
        private readonly Canvas $canvas,
        array $colour = [0, 0, 0],
        array $paper = [255, 255, 255],
        private readonly int $undoLevels = 12,
    ) {
        $this->colour = $colour;
        $this->paper  = $paper;
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /** The tool. */
    public function tool(): PaintTool { return $this->tool; }

    /**
     * Switch tools. A shape stroke in flight is abandoned rather than committed:
     * the tool changed under it, so what it would draw is no longer what was
     * asked for.
     */
    public function setTool(PaintTool $tool): void
    {
        if ($this->strokeBase !== null && $this->tool->isShape()) {
            $this->cancelStroke();
        }

        $this->tool = $tool;
    }

    /** @return Rgb */
    public function colour(): array { return $this->colour; }

    /** @param Rgb $rgb */
    public function setColour(array $rgb): void { $this->colour = $rgb; }

    /** @return Rgb */
    public function paper(): array { return $this->paper; }

    /** Pencil/line thickness in pixels. */
    public function brushSize(): int { return $this->brushSize; }

    /** Sets the brush size, at least one pixel. */
    public function setBrushSize(int $size): void { $this->brushSize = max(1, $size); }

    // -------------------------------------------------------------------------
    // Strokes
    // -------------------------------------------------------------------------

    /**
     * Apply one paint event. Wire it straight to the widget:
     *
     *     $canvas->setOnPaint($document->apply(...));
     */
    public function apply(CanvasPaintEvent $event): void
    {
        match ($event->phase) {
            CanvasPhase::Begin => $this->begin($event->x, $event->y),
            CanvasPhase::Draw  => $this->draw($event->x, $event->y, $event->previousX, $event->previousY),
            CanvasPhase::End   => $this->end($event->x, $event->y, $event->previousX, $event->previousY),
        };
    }

    /** The pointer went down: remember how the image looked, then draw. */
    private function begin(int $x, int $y): void
    {
        $this->pushUndo();

        $this->anchorX       = $x;
        $this->anchorY       = $y;
        $this->previewBounds = null;

        if ($this->tool === PaintTool::Fill) {
            $this->floodFill($x, $y);
            return;
        }

        if ($this->tool->isShape()) {
            // A press with no movement yet is a degenerate shape — a dot for a
            // line, a 1x1 box — which is what previewing from the first event
            // gives, and it means release-without-motion still commits
            // something rather than nothing.
            $this->preview($x, $y);
            return;
        }

        $this->freehand($x, $y, $x, $y);
    }

    /** The pointer moved with the button held. */
    private function draw(int $x, int $y, int $previousX, int $previousY): void
    {
        if ($this->tool === PaintTool::Fill) return;

        if ($this->tool->isShape()) {
            $this->preview($x, $y);
            return;
        }

        $this->freehand($x, $y, $previousX, $previousY);
    }

    /**
     * The button came up.
     *
     * A shape commits the preview it was showing. A freehand tool draws one last
     * segment, because the release lands where the pointer *finally* was and
     * that is usually a few pixels past the last motion event — the server only
     * reports motion while the pointer is moving. Dropping it loses the end of
     * every quick stroke, which reads as the pencil lagging behind the cursor.
     */
    private function end(int $x, int $y, int $previousX, int $previousY): void
    {
        if ($this->tool->isShape()) {
            $this->preview($x, $y);
        } elseif ($this->tool !== PaintTool::Fill) {
            $this->freehand($x, $y, $previousX, $previousY);
        }

        $this->strokeBase    = null;
        $this->previewBounds = null;
    }

    /**
     * Pencil and eraser: join the previous point to this one.
     *
     * A segment rather than a point because motion events arrive in jumps of
     * several pixels — one pixel per event draws a dotted line at any speed
     * above a crawl.
     */
    private function freehand(int $x, int $y, int $previousX, int $previousY): void
    {
        $this->canvas->drawLine(
            $previousX, $previousY, $x, $y,
            $this->tool === PaintTool::Eraser ? $this->paper : $this->colour,
            $this->brushSize,
        );
    }

    /** Un-draw the last preview from the snapshot, then draw this one. */
    private function preview(int $x, int $y): void
    {
        $base = $this->strokeBase;
        if ($base === null) return;

        if ($this->previewBounds !== null) {
            $this->canvas->restoreRegion($base, $this->previewBounds);
        }

        $bounds = $this->shapeBounds($x, $y);
        $this->drawShape($x, $y);
        $this->previewBounds = $bounds;
    }

    /** Draw the current shape from the anchor to (x, y). */
    private function drawShape(int $x, int $y): void
    {
        $rect = $this->shapeRect($x, $y);

        match ($this->tool) {
            PaintTool::Line => $this->canvas->drawLine(
                $this->anchorX, $this->anchorY, $x, $y, $this->colour, $this->brushSize,
            ),
            PaintTool::Rectangle => $this->canvas->strokeRect(
                $rect->x, $rect->y, $rect->width, $rect->height, $this->colour, $this->brushSize,
            ),
            PaintTool::FilledRectangle => $this->canvas->fillRect(
                $rect->x, $rect->y, $rect->width, $rect->height, $this->colour,
            ),
            PaintTool::Ellipse => $this->canvas->strokeEllipse(
                $rect->x, $rect->y, $rect->width, $rect->height, $this->colour,
            ),
            default => null,
        };
    }

    /** The anchor and the cursor as a normalised rectangle, either drag direction. */
    private function shapeRect(int $x, int $y): Rect
    {
        return Rect::of(
            min($this->anchorX, $x),
            min($this->anchorY, $y),
            abs($x - $this->anchorX) + 1,
            abs($y - $this->anchorY) + 1,
        );
    }

    /**
     * What the shape will cover, with room for the brush.
     *
     * Grown by the brush, because a thick line is centred on its path and so
     * spills half its width outside the rectangle the two endpoints describe. An
     * under-sized bound here is the classic way to leave a one-pixel trail of a
     * previous preview on screen.
     */
    private function shapeBounds(int $x, int $y): Rect
    {
        return $this->shapeRect($x, $y)->grow(intdiv($this->brushSize, 2) + 1);
    }

    /** Drop a shape stroke without committing it. */
    private function cancelStroke(): void
    {
        $base = $this->strokeBase;

        if ($base !== null && $this->previewBounds !== null) {
            $this->canvas->restoreRegion($base, $this->previewBounds);
        }

        // The undo entry was pushed for a stroke that never happened.
        array_pop($this->undo);

        $this->strokeBase    = null;
        $this->previewBounds = null;
    }

    // -------------------------------------------------------------------------
    // Undo
    // -------------------------------------------------------------------------

    /** Whether it can undo. */
    public function canUndo(): bool { return $this->undo !== []; }

    /** How many strokes can still be taken back. */
    public function undoDepth(): int { return count($this->undo); }

    /** Take back the last stroke. False when there is nothing to take back. */
    public function undo(): bool
    {
        $snapshot = array_pop($this->undo);
        if ($snapshot === null) return false;

        // Whatever was in flight is gone with it.
        $this->strokeBase    = null;
        $this->previewBounds = null;

        return $this->canvas->restore($snapshot);
    }

    /** Paint the whole image with the paper colour. Undoable, like a stroke. */
    public function clear(): void
    {
        $this->pushUndo();
        $this->strokeBase = null;
        $this->canvas->clear($this->paper);
    }

    /** Remember the image as it is now, dropping the oldest entry when full. */
    private function pushUndo(): void
    {
        $snapshot         = $this->canvas->snapshot();
        $this->strokeBase = $snapshot;
        $this->undo[]     = $snapshot;

        if (count($this->undo) > $this->undoLevels) {
            array_shift($this->undo);
        }
    }

    // -------------------------------------------------------------------------
    // Fill
    // -------------------------------------------------------------------------

    /**
     * Flood fill from a seed, span by span.
     *
     * Scanline rather than four-way recursion: a per-pixel stack on a
     * canvas-sized region is both slow and deep enough to matter, while filling
     * whole runs keeps the stack to one entry per span. Out-of-bounds reads come
     * back null, which matches nothing, so the edges of the image stop it
     * without a bounds test of their own.
     */
    private function floodFill(int $seedX, int $seedY): void
    {
        $target = $this->canvas->pixelAt($seedX, $seedY);
        if ($target === null || $target === $this->colour) return;

        $stack = [[$seedX, $seedY]];

        while ($stack !== []) {
            [$x, $y] = array_pop($stack);
            if ($this->canvas->pixelAt($x, $y) !== $target) continue;

            $left = $x;
            while ($this->canvas->pixelAt($left - 1, $y) === $target) $left--;

            $right = $x;
            while ($this->canvas->pixelAt($right + 1, $y) === $target) $right++;

            $this->canvas->fillRect($left, $y, $right - $left + 1, 1, $this->colour);

            foreach ([$y - 1, $y + 1] as $row) {
                for ($scan = $left; $scan <= $right; $scan++) {
                    if ($this->canvas->pixelAt($scan, $row) !== $target) continue;

                    $stack[] = [$scan, $row];
                    // One seed per run: the rest of this span is reachable from it.
                    while ($scan + 1 <= $right && $this->canvas->pixelAt($scan + 1, $row) === $target) {
                        $scan++;
                    }
                }
            }
        }
    }
}
