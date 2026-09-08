<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Canvas;

/**
 * The pointer is drawing on a canvas.
 *
 * Coordinates are in the canvas's **image** space — (0, 0) is the top-left
 * pixel of the paper, not of the widget or the window — because that is the
 * space a tool writes in. They are clamped to the image, so a drag that leaves
 * the canvas keeps drawing along its edge rather than stopping dead or
 * scribbling out of bounds.
 *
 * ($previousX, $previousY) is where the last event of this stroke was, which is
 * what a freehand tool needs: pointer motion arrives in jumps of several pixels,
 * so a stroke is a chain of line segments and never a chain of points.
 */
final class CanvasPaintEvent extends AbstractUiEvent
{
    /** Records the canvas, where the pointer is, where it was, and which part of the stroke this is. */
    public function __construct(
        public readonly Canvas $canvas,
        public readonly int $x,
        public readonly int $y,
        public readonly int $previousX,
        public readonly int $previousY,
        public readonly CanvasPhase $phase,
    ) {}
}
