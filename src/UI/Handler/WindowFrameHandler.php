<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\Painter\WindowFramePainter;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Drives the toolkit-drawn window frame.
 *
 * Dragging and resizing are *not* implemented from motion events: the press
 * hands the gesture to the window manager via _NET_WM_MOVERESIZE, which keeps
 * the WM's snapping and constraints and means we never see the matching
 * release. Only the caption buttons capture like normal buttons do.
 *
 * Minimise and maximise are window operations the handler performs directly;
 * close is app policy and goes out as {@see WindowCloseRequestedEvent}.
 */
final class WindowFrameHandler extends WidgetHandler
{
    private ?WindowFrame $captured = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree          $tree,
        private readonly X11Client           $client,
        private readonly SyncEventDispatcher $dispatcher,
        private readonly DoubleClickDetector $doubleClick,
        /** A tab-style caption is sized to its title, so hit-testing measures text. */
        private readonly Renderer            $renderer,
        private readonly WindowFramePainter  $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof WindowFrame) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        // The frame is exempt from tree modality (see findFrame), so it's the
        // one handler that has to check *which* window the click came from: a
        // modal dialog grabs the pointer, and its window-local coordinates
        // would otherwise match the caption of the window behind it.
        if ($event->windowId !== $this->client->getWindowId()) return false;

        $frame = $this->findFrame();
        if ($frame === null || !$frame->drawsChrome) return false;

        // Caption buttons behave like ordinary buttons: capture now, act on
        // release if the pointer is still on them.
        $button = $frame->hitTestCaptionButton($event->x, $event->y, $this->renderer);
        if ($button !== null) {
            // Double-clicking the window-menu box closes the window — how every
            // one of these desktops did it, and the *only* way out on a theme
            // whose era had no close button in the caption (Windows 3.1).
            if ($button === CaptionButton::Menu
                && $this->doubleClick->detect($frame, $event->time, $event->rootX, $event->rootY)) {
                $this->dispatcher->dispatch(new WindowCloseRequestedEvent($frame));
                return true;
            }

            $this->captured = $frame;
            $frame->setPressedButton($button);
            $this->client->redraw();
            return true;
        }

        // Border: let the WM run the resize.
        $edge = $frame->hitTestResizeEdge($event->x, $event->y);
        if ($edge !== null) {
            $this->client->startWindowResize($edge, $event->rootX, $event->rootY, $event->button);
            return true;
        }

        if (!$frame->hitTestCaption($event->x, $event->y, $this->renderer)) return false;

        // Double-click the caption to maximize, the way every one of these
        // desktops did it — unless the window is fixed-size, where it is the
        // gesture equivalent of the button that is not there.
        if ($frame->resizable
            && $this->doubleClick->detect($frame, $event->time, $event->rootX, $event->rootY)) {
            $this->toggleMaximize($frame);
            return true;
        }

        $this->client->startWindowMove($event->rootX, $event->rootY, $event->button);
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;

        $frame   = $this->captured;
        $pressed = $frame->getPressedButton();

        $this->captured = null;
        $frame->setPressedButton(null);

        // Only a release still over the same button counts as a click.
        if ($pressed !== null && $frame->hitTestCaptionButton($event->x, $event->y, $this->renderer) === $pressed) {
            $this->activate($frame, $pressed);
        }

        $this->client->redraw();
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        $frame = $this->findFrame();
        if ($frame === null || !$frame->drawsChrome) return false;

        if ($frame->setHoveredButton($frame->hitTestCaptionButton($event->x, $event->y, $this->renderer))) {
            $this->client->redraw();
        }

        // Never consume motion — the widgets below still need it.
        return false;
    }

    /** Does what a caption button means: minimise, maximise, restore, or ask to close. */
    private function activate(WindowFrame $frame, CaptionButton $button): void
    {
        switch ($button) {
            case CaptionButton::Close:
                $this->dispatcher->dispatch(new WindowCloseRequestedEvent($frame));
                return;

            case CaptionButton::Minimize:
                $this->client->iconifyWindow();
                return;

            case CaptionButton::Maximize:
            case CaptionButton::Restore:
                // Belt and braces: a fixed-size frame reports no such button, so
                // this is unreachable through the caption. It stays because
                // activate() is also the programmatic route.
                if ($frame->resizable) $this->toggleMaximize($frame);
                return;

            case CaptionButton::Menu:
                // A real window menu would open here; nothing to do yet. A
                // double-click on it closes the window — see tryPress().
                return;
        }
    }

    /** Toggles the maximize. */
    private function toggleMaximize(WindowFrame $frame): void
    {
        $frame->setMaximized(!$frame->isMaximized());
        $this->client->toggleMaximize();
    }

    /** The find frame. */
    private function findFrame(): ?WindowFrame
    {
        // findRoot, not findFirst: the frame is furniture, and a modal dialog
        // must not take away the ability to move or close the window.
        $found = $this->tree->findRoot(static fn(Widget $w): bool => $w instanceof WindowFrame);
        return $found instanceof WindowFrame ? $found : null;
    }
}
