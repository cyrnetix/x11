<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\FormWindow;

/**
 * The chrome of an application window: body panel and themed caption.
 *
 * Everything inside is a real widget painted by its own painter, which is the
 * point of assembling a form from widgets rather than drawing it.
 *
 * The caption goes through the **same** chrome calls as the main window's
 * ({@see WindowFramePainter}) — `caption()`, `captionGrab()`, `captionTitle()`
 * and a `captionButton()` each — because a dialog's title bar is a title bar. The
 * body is still `dialogFrame()`, which is a deliberate hook: Windows 3.1 rings a
 * dialog in a thick flat band of the caption colour where its panels are
 * bevelled grey.
 */
final class FormWindowPainter
{
    private readonly CaptionPainter $caption;

    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes)
    {
        $this->caption = new CaptionPainter($themes);
    }

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(FormWindow $form, Renderer $r): void
    {
        if (!$form->isVisible()) return;

        $chrome = $this->themes->chrome();

        // The strip beside a tab caption: a real hole where the window has an
        // alpha channel, the theme's painted stand-in where it hasn't.
        $surround = $form->captionSurroundRect($r);
        if (!$surround->isEmpty()) {
            if ($r->isTranslucent()) {
                $r->fillTransparent($surround->x, $surround->y, $surround->width, $surround->height);
            } else {
                $chrome->captionSurround($r, $surround);
            }
        }

        // Body first, then the caption over its top edge — a tab caption
        // overlaps by a row and has to win.
        $chrome->dialogFrame($r, $form->bodyRect());

        // Exactly what the window frame draws, through the same painter - see
        // CaptionPainter for why that is not four copies of it.
        $this->caption->paint(
            $r,
            $form->captionRect($r),
            $form->captionTitleRect($r),
            $form->getTitle(),
            $form->captionButtons($r),
            active: true,
            pressed: $form->getPressedButton(),
            hovered: $form->getHoveredButton(),
        );
    }
}
