<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Checkbox;

/**
 * Checkbox: a themed box plus a label to its right, baseline-centred against
 * the box. What lands *in* the box is entirely the theme's call — Windows ticks
 * it, Motif sinks and colours it — so nothing about the mark appears here.
 */
final class CheckboxPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Checkbox $cb, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $box = Rect::of($cb->x, $cb->y, $m->checkBoxSize, $m->checkBoxSize);
        $chrome->checkBox($r, $box, $cb->isChecked(), ControlState::Normal);

        $labelX = $box->x + $m->checkBoxSize + $m->checkBoxGap;

        $chrome->text(
            $r,
            $cb->label,
            $labelX,
            $r->baselineYForRect($box->y, $m->checkBoxSize),
            TextStyle::Normal,
        );

        // Focus rings the *label*, not the box — where Windows put it, because
        // the box already has a sunken well to distinguish it.
        if ($cb->isFocused() && $cb->isEnabled()) {
            $chrome->focusRect($r, Rect::of(
                $labelX - 2,
                $box->y,
                $r->measureText($cb->label) + 4,
                $m->checkBoxSize,
            ));
        }
    }
}
