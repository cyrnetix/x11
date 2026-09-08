<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Example;

/**
 * The tools `example/paint.php` offers, and the two kinds they fall into.
 *
 * {@see isShape()} is the whole distinction that matters to
 * {@see PaintDocument}: a freehand tool commits every segment as the pointer
 * moves, while a shape tool draws and re-draws one preview until the button
 * comes up. Nothing else about a tool changes how a stroke is handled.
 */
enum PaintTool
{
    case Pencil;
    case Eraser;
    case Line;
    case Rectangle;
    case FilledRectangle;
    case Ellipse;
    case Fill;

    /** Rubber-banded from where the press landed, so it previews until release. */
    public function isShape(): bool
    {
        return match ($this) {
            self::Line, self::Rectangle, self::FilledRectangle, self::Ellipse => true,
            default => false,
        };
    }

    /** The name shown in the tool list and the status bar. */
    public function label(): string
    {
        return match ($this) {
            self::Pencil          => 'Pencil',
            self::Eraser          => 'Eraser',
            self::Line            => 'Line',
            self::Rectangle       => 'Box',
            self::FilledRectangle => 'Filled box',
            self::Ellipse         => 'Ellipse',
            self::Fill            => 'Fill',
        };
    }
}
