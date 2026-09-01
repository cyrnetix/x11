<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Painter\ButtonPainter;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Button — press captures, release fires the click only when the cursor is
 * still over the same button (Win's "abandon click by dragging away" idiom).
 * Motion paints the hover state on every Button so hovered buttons can
 * highlight.
 */
final class ButtonHandler extends WidgetHandler
{
    private ?Button $captured = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree      $tree,
        private readonly X11Client       $client,
        private readonly LoggerInterface $logger,
        private readonly ButtonPainter   $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof Button) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $btn = $this->findHit($event->x, $event->y);
        if ($btn === null) return false;

        $this->captured = $btn;
        $btn->setPressed(true);
        $this->logger->debug('Widget button pressed', ['label' => $btn->getLabel()]);
        $this->repaint($this->client, $btn);
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;

        $captured       = $this->captured;
        $this->captured = null;

        $isClick = $captured->hitTest($event->x, $event->y);
        $captured->release($isClick);

        $this->logger->debug('Widget button released', [
            'label'   => $captured->getLabel(),
            'clicked' => $isClick,
        ]);

        // Deliberately the whole window: release() fires the click, and a
        // listener may have changed anything at all. Narrowing this would leave
        // whatever it touched stale — see the note on repaint()'s contract.
        $this->client->redraw();
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        // Hover repaint — only the buttons whose state actually flipped, which
        // is at most two (the one left and the one entered). This runs on every
        // motion event, so repainting the window here was the most expensive
        // thing the toolkit did outside a drag.
        $changed = [];
        $this->tree->visitAll(function (Widget $w) use ($event, &$changed): void {
            if ($w instanceof Button && $w->setHovered($w->hitTest($event->x, $event->y))) {
                $changed[] = $w;
            }
        });
        // Only if the theme actually draws it. The state is still tracked, so a
        // theme that starts drawing hover needs no change here — but none of the
        // shipped ones do, and repainting for an invisible change on every mouse
        // move is the most pointless work the toolkit could do.
        if ($changed !== [] && ($changed[0]->chrome()?->rendersButtonHover() ?? true)) {
            $this->repaint($this->client, $changed);
        }
        return false;
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?Button
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof Button && $w->hitTest($mx, $my)
        );
        return $found instanceof Button ? $found : null;
    }
}
