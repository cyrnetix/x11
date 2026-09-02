<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Rebar;
use Cyrnetix\X11\UI\Widget\RebarBand;

/**
 * Rebar: the strip behind a row of bands, each with an optional drag grip and
 * caption. Bands don't paint their own internals — the child Widget does — so
 * this only draws the chrome around them.
 */
final class RebarPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Rebar $rebar, Renderer $r): void
    {
        $chrome = $this->themes->chrome();

        $chrome->fill($r, Rect::of($rebar->x, $rebar->y, $rebar->width, $rebar->height), Surface::Bar);

        foreach ($rebar->getRows() as $row) {
            foreach ($row as $band) {
                $this->paintBand($r, $rebar, $band);
            }
        }
    }

    /** Paints the band. */
    private function paintBand(Renderer $r, Rebar $rebar, RebarBand $band): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $bx = $rebar->x + $band->x;
        $by = $rebar->y + $band->y;

        if ($band->showGrip) {
            $chrome->bandGrip($r, Rect::of($bx, $by, $m->rebarGripWidth, $band->h));
        }

        if ($band->caption !== '') {
            $chrome->text(
                $r,
                $band->caption,
                $bx + ($band->showGrip ? $m->rebarGripWidth : 0),
                $r->baselineYForRect($by, $band->h),
                TextStyle::Normal,
            );
        }
    }
}
