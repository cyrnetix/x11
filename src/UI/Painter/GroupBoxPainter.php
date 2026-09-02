<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\GroupBox;

/**
 * Group box: an etched frame whose top edge is interrupted by the title. The
 * frame line runs through the vertical middle of the text, so the painter fills
 * a gap behind the title before drawing it.
 */
final class GroupBoxPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(GroupBox $group, Renderer $renderer): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();
        $palette = $this->themes->palette();

        // A group box doesn't introduce a surface of its own: it fills with
        // whatever it was dropped onto, so its frame and title blend in.
        $background = $group->background ?? $palette->forSurface($group->surface());

        $outer = Rect::of($group->x, $group->y, $group->width, $group->height);

        // Fill first so the children and the erased title gap blend in.
        $renderer->setForeground(...$background);
        $renderer->fillRect($outer->x, $outer->y, $outer->width, $outer->height);

        // The frame starts halfway down the title text.
        $titleMid = intdiv($renderer->fontAscent() + $renderer->fontDescent(), 2);
        $frame    = $outer->insetEach(0, $titleMid, 0, 0);
        $chrome->edge($renderer, $frame, Edge::Etched);

        if ($group->title === '') return;

        $titleX = $outer->x + $m->groupBoxTitleOffsetX;
        $textW  = $renderer->measureText($group->title);

        // Break the frame line behind the text.
        $renderer->setForeground(...$background);
        $renderer->fillRect($titleX - 2, $frame->y, $textW + 4, $m->edge);

        $chrome->text(
            $renderer,
            $group->title,
            $titleX,
            $outer->y + $renderer->fontAscent(),
            TextStyle::Normal,
        );
    }
}
