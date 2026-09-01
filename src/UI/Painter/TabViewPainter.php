<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\TabView;

/**
 * Tab control. The active tab is handed a taller rectangle that starts higher
 * and runs past the panel's top border, which is what makes it read as being
 * in front; inactive tabs sit lower by the same amount. Painting the active tab
 * last keeps it over the seam.
 */
final class TabViewPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(TabView $tabView, Renderer $renderer): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $tabAreaH = $m->tabHeight;
        $tabs     = $tabView->getChildren();    // TabPage[]
        $bounds   = $tabView->tabBounds($renderer);
        $activeI  = $tabView->getActiveIndex();

        $panel = Rect::of(
            $tabView->x,
            $tabView->y + $tabAreaH,
            $tabView->width,
            $tabView->height - $tabAreaH,
        );
        $chrome->tabPanel($renderer, $panel);

        // Inactive tabs first; the active one last so it covers the seam.
        foreach ($tabs as $i => $tabPage) {
            if ($i === $activeI || !isset($bounds[$i])) continue;
            $this->paintTab($renderer, $tabView, $bounds[$i], $tabPage->label, false);
        }
        if (isset($tabs[$activeI], $bounds[$activeI])) {
            $this->paintTab($renderer, $tabView, $bounds[$activeI], $tabs[$activeI]->label, true);
        }
    }

    /** @param array{int, int} $bound [x, width] of this tab */
    private function paintTab(
        Renderer $renderer,
        TabView $tabView,
        array $bound,
        string $label,
        bool $active,
    ): void {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $lift        = $m->tabActiveLift;
        [$x, $tabW]  = $bound;

        $rect = $active
            ? Rect::of($x, $tabView->y,         $tabW, $m->tabHeight + $lift)
            : Rect::of($x, $tabView->y + $lift, $tabW, $m->tabHeight - $lift);

        $chrome->tab($renderer, $rect, $active);

        // Label centred on the header band, not on the (unequal) tab rects, so
        // active and inactive labels line up.
        $chrome->text(
            $renderer,
            $label,
            $x + intdiv($tabW - $renderer->measureText($label), 2),
            $renderer->baselineYForRect($tabView->y, $m->tabHeight),
            TextStyle::Normal,
        );
    }
}
