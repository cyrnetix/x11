<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\StatusBar;

/**
 * Status bar: a strip with a divider along its top, one framed cell per pane,
 * and an optional resize grip in the corner.
 */
final class StatusBarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(StatusBar $bar, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $outer = Rect::of($bar->x, $bar->y, $bar->width, $m->statusBarHeight);
        $chrome->fill($r, $outer, Surface::Bar);

        // Divider along the top so the bar reads as recessed from the content.
        $chrome->separator($r, Rect::of($outer->x, $outer->y, $outer->width, 2), true);

        $cursor = $outer->x + 2;
        $paneY  = $outer->y + 3;
        $paneH  = $outer->height - 5;
        $widths = $bar->computePaneWidths();

        foreach ($bar->getPanes() as $i => $pane) {
            $paneW = $widths[$i];
            $this->paintPane($r, Rect::of($cursor, $paneY, $paneW, $paneH), $pane->text);
            $cursor += $paneW + 1;
        }

        if ($bar->showGrip) {
            $chrome->grip($r, Rect::of(
                $outer->right() - $m->statusBarGripWidth + 1,
                $outer->y + 2,
                $m->statusBarGripWidth,
                $outer->height - 4,
            ));
        }
    }

    /** Paints the pane. */
    private function paintPane(Renderer $r, Rect $rect, string $text): void
    {
        $chrome = $this->themes->chrome();

        $chrome->edge($r, $rect, Edge::SunkenThin);
        $chrome->text(
            $r,
            $text,
            $rect->x + $this->themes->metrics()->statusBarPanePadding,
            $r->baselineYForRect($rect->y, $rect->height),
            TextStyle::Normal,
        );
    }
}
