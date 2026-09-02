<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\RadioButton;

/**
 * Radio button: themed indicator plus a label. The indicator's shape belongs to
 * the era — a circle under Windows and Platinum, a diamond under Motif.
 */
final class RadioButtonPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(RadioButton $rb, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();
        $surface = $this->themes->palette()->forSurface($rb->surface());

        $box = Rect::of($rb->x, $rb->y, $m->radioSize, $m->radioSize);

        // The indicator is round, so clear its cell on the host surface first —
        // otherwise the corners keep whatever was underneath.
        $r->setForeground(...$surface);
        $r->fillRect($box->x, $box->y, $box->width, $box->height);

        $chrome->radioButton($r, $box, $rb->isChecked(), ControlState::Normal);

        $chrome->text(
            $r,
            $rb->label,
            $box->x + $m->radioSize + $m->radioGap,
            $r->baselineYForRect($box->y, $m->radioSize),
            TextStyle::Normal,
        );
    }
}
