<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Canvas;

/**
 * A canvas: the theme's sunken well, and the widget's framebuffer blitted into
 * it. The frame is era, the pixels are the application's — this painter is the
 * only place the two meet.
 *
 * **It blits the clip, not the image.** A region repaint has already narrowed
 * the GC's clip to the few pixels that changed, and the server would discard
 * everything outside it anyway — but the bytes would still cross the socket, and
 * a framebuffer is the one thing in this toolkit big enough for that to matter:
 * a 400x300 canvas is 480 kB per repaint, versus 400 bytes for the 10x10 patch a
 * pencil actually touched. Asking {@see Renderer::clipRect()} is this painter's
 * version of `BitBlt(ps.hdc, ps.rcPaint, …)`, which is what a Win32 canvas did
 * with the update rectangle its `WM_PAINT` handed it.
 *
 * The interior is filled first regardless. The image may be smaller than the
 * widget, and on a server that cannot do image blits at all the fill is what is
 * left — an empty well rather than an unpainted hole.
 */
final class CanvasPainter
{
    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Canvas $canvas, Renderer $r): void
    {
        $chrome = $this->themes->chrome();

        $outer = $canvas->bounds();
        $chrome->edge($r, $outer, Edge::Sunken);
        $chrome->fill($r, $canvas->contentRect(), Surface::Content);

        $visible = $canvas->imageRect();
        $clip    = $r->clipRect();
        if ($clip !== null) {
            $visible = $visible->intersect($clip);
        }
        if ($visible->isEmpty()) return;

        // Back into image coordinates to crop the framebuffer, then out again to
        // say where it lands. One conversion each way, through the widget, so
        // the painter never re-derives the border inset.
        [$imageX, $imageY] = $canvas->toImage($visible->x, $visible->y);
        $source            = Rect::of($imageX, $imageY, $visible->width, $visible->height);

        $r->putImage(
            $canvas->region($source),
            $source->width, $source->height,
            $visible->x, $visible->y,
        );
    }
}
