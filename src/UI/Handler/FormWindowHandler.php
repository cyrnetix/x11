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
use Cyrnetix\X11\UI\Painter\FormWindowPainter;
use Cyrnetix\X11\UI\Widget\FormWindow;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Paints application windows and lets the user drag them by the caption.
 *
 * The caption is drawn by the painter rather than being a widget, so it's the
 * one thing here that needs hit-testing; everything inside a form is an ordinary
 * widget owned by an ordinary handler.
 *
 * A drag moves the *window*, in root coordinates, with nothing confining it to
 * the application — it's a real top-level window.
 *
 * The caption's own buttons are hit-tested here too, and *before* the drag: a
 * press on the close box must not also start moving the window. Which buttons a
 * dialog has is a theme metric ({@see \Cyrnetix\X11\Theme\Metrics::$dialogCaptionTrailing}),
 * so a theme whose era gave a dialog no widgets simply reports none and every
 * press falls through to the drag.
 */
final class FormWindowHandler extends WidgetHandler
{
    /** Non-null while dragging: [form, pointer offset into the window]. */
    private ?array $drag = null;

    /** The form whose caption button is held down, and which button. */
    private ?FormWindow   $captured = null;
    private ?CaptionButton $button  = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree          $tree,
        private readonly X11Client           $client,
        private readonly Renderer            $renderer,
        private readonly FormWindowPainter   $painter,
        private readonly ?DoubleClickDetector $doubleClick = null,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof FormWindow) return false;

        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $form = $this->formFor($event->windowId);
        if ($form === null) return false;

        // Under the pointer grab a modal window receives clicks from anywhere on
        // screen, in its own coordinates. Ones outside it are swallowed.
        if (!$form->outerRect()->contains($event->x, $event->y)) return true;

        // Buttons before the drag: a press on the close box must not also start
        // moving the window.
        $button = $form->hitTestCaptionButton($event->x, $event->y, $this->renderer);
        if ($button !== null) {
            // Double-clicking the window-menu box closes it — how those desktops
            // did it, and the *only* way out on a theme whose era had no close
            // box in a dialog's caption (Windows 3.1). Without a detector the
            // box is inert, which is worse than not drawing it.
            if ($button === CaptionButton::Menu
                && $this->doubleClick?->detect($form, $event->time, $event->rootX, $event->rootY)) {
                $form->requestClose();

                return true;
            }

            $this->captured = $form;
            $this->button   = $button;
            $form->setPressedButton($button);
            $this->repaint($this->client, $form);

            return true;
        }

        if ($form->hitTestCaption($event->x, $event->y, $this->renderer)) {
            $window = $this->client->findChildWindow($event->windowId);
            if ($window === null) return true;

            $this->drag = [
                $window,
                $event->rootX - $window->x(),
                $event->rootY - $window->y(),
            ];
            return true;
        }

        return false;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured !== null) {
            $form   = $this->captured;
            $button = $this->button;

            $this->captured = null;
            $this->button   = null;
            $form->setPressedButton(null);

            // Only if the release lands back on the same button - dragging off
            // one is how you change your mind about pressing it.
            $over = $form->hitTestCaptionButton($event->x, $event->y, $this->renderer);
            if ($over === $button && $button === CaptionButton::Close) {
                // The form decides what closing means; a dialog may want to ask
                // something first, which is why this is a hook and not a hide.
                $form->requestClose();
            } else {
                $this->repaint($this->client, $form);
            }

            return true;
        }

        if ($this->drag === null) return false;

        $this->drag = null;
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        if ($this->captured !== null) {
            // Held down and dragged off the button: show it letting go, and back
            // again if the pointer returns.
            $over = $this->captured->hitTestCaptionButton($event->x, $event->y, $this->renderer);
            $want = $over === $this->button ? $this->button : null;

            if ($this->captured->getPressedButton() !== $want) {
                $this->captured->setPressedButton($want);
                $this->repaint($this->client, $this->captured);
            }

            return true;
        }

        if ($this->drag === null) return false;

        [$window, $offsetX, $offsetY] = $this->drag;
        $window->moveTo($event->rootX - $offsetX, $event->rootY - $offsetY);

        return true;
    }

    /** The visible form painted into $windowId, if any. */
    private function formFor(int $windowId): ?FormWindow
    {
        $window = $this->client->findChildWindow($windowId);
        $root   = $window?->root();

        return $root instanceof FormWindow && $root->isVisible() ? $root : null;
    }
}
