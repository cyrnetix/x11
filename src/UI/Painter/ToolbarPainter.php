<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\IconRegistry;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Toolbar;
use Cyrnetix\X11\UI\Widget\ToolbarItem;

/**
 * Icon toolbar. Whether a resting button is flat or bevelled is up to the theme
 * (Windows keeps it flat until hover; Motif has no flat buttons at all), so the
 * painter only decides state and where the icon goes.
 *
 * Icons come from the {@see IconRegistry} — unmapped ones render as an empty
 * button.
 */
final class ToolbarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly IconRegistry $icons,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Toolbar $tb, Renderer $r): void
    {
        $chrome = $this->themes->chrome();

        $chrome->fill($r, Rect::of($tb->x, $tb->y, $tb->width, $tb->height), Surface::Bar);

        foreach ($tb->getItems() as $i => $item) {
            $bounds = Rect::fromArray($tb->itemBounds($i));

            if ($item->isSeparator) {
                // Groove centred in the slot, inset top and bottom.
                $chrome->separator(
                    $r,
                    Rect::of($bounds->centerX() - 1, $bounds->y + 4, 2, $bounds->height - 8),
                    false,
                );
                continue;
            }

            $this->paintButton($r, $tb, $i, $item, $bounds);
        }
    }

    /** Paints the button. */
    private function paintButton(
        Renderer $r,
        Toolbar $tb,
        int $idx,
        ToolbarItem $item,
        Rect $rect,
    ): void {
        $chrome = $this->themes->chrome();

        $hovered = $idx === $tb->getHoveredIndex();
        $pressed = $idx === $tb->getPressedIndex() && $hovered;
        $checked = $item->isToggle && $item->checked;

        $state = ControlState::of($pressed, $hovered, $item->enabled);
        $chrome->toolbarButton($r, $rect, $state, $checked);

        if ($item->icon === null) return;
        $icon = $this->icons->get($item->icon);
        if ($icon === null) return;

        $size  = $this->themes->metrics()->toolbarIconSize;
        $shift = ($pressed || $checked) ? $this->themes->metrics()->pressOffset : 0;
        $box   = $rect->centeredSquare($size)->shift($shift, $shift);

        $icon->drawAt($r, $box->x, $box->y, $size);
    }
}
