<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\DropDown;

/**
 * Read-only drop-down. Paints the closed field only — the popup is a real
 * {@see \Cyrnetix\X11\UI\Widget\ListBox} the DropDown owns, so it paints through
 * {@see ListBoxPainter} on the overlay pass and gets a scrollbar for free.
 */
final class DropDownPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the field. */
    public function paintField(DropDown $dd, Renderer $r): void
    {
        $m     = $this->themes->metrics();
        $outer = Rect::of($dd->x, $dd->y, $dd->width, $m->fieldHeight);

        $chrome  = $this->themes->chrome();
        $content = $chrome->dropField($r, $outer, $dd->isOpen(), editable: false);

        $selected = $dd->getSelectedItem();
        if ($selected === null) return;

        $style = $chrome->dropFieldTextStyle(false);

        $chrome->text(
            $r,
            $selected,
            $content->x + 2,
            $r->baselineYForRect($outer->y, $outer->height),
            $style,
        );
    }
}
