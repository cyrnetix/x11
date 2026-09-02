<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\WindowFrame;

/**
 * The window's border and caption. The frame widget owns the geometry — which
 * buttons exist and where they sit — so this only decides *state* and hands
 * each piece to the theme.
 *
 * Nothing is drawn when the frame isn't the one decorating the window (i.e. the
 * window manager still is).
 */
final class WindowFramePainter
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
    public function paint(WindowFrame $frame, Renderer $r): void
    {
        if (!$frame->drawsChrome) return;

        $chrome = $this->themes->chrome();
        $active = $frame->isActive();

        // The border frames the *body*: with a tab caption nothing is drawn above
        // or beside the tab, which is what makes it read as part of the outline
        // rather than as something sitting inside a full-width frame.
        $chrome->windowBorder($r, $frame->bodyRect($r));
        $chrome->clientEdge($r, $frame->contentRect());

        // A caption that only spans part of the top edge (BeOS's tab) leaves a
        // strip beside it for the theme to fill — the desktop, in that era.
        // Painted over the border so the tab reads as floating above the frame.
        $surround = $frame->captionSurroundRect($r);
        if (!$surround->isEmpty()) {
            // A real hole where the window has an alpha channel; the theme's
            // painted stand-in where it doesn't.
            if ($r->isTranslucent()) {
                $r->fillTransparent($surround->x, $surround->y, $surround->width, $surround->height);
            } else {
                $chrome->captionSurround($r, $surround);
            }
        }
        // Background, drag texture, title, buttons - the same painter every
        // dialog's caption goes through, so the two cannot drift apart.
        $this->caption->paint(
            $r,
            $frame->captionRect($r),
            $frame->captionTitleRect($r),
            $frame->getTitle(),
            $frame->captionButtons($r),
            $active,
            $frame->getPressedButton(),
            $frame->getHoveredButton(),
        );
    }
}
