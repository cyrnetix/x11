<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\UI\Event\ButtonClickedEvent;
use Cyrnetix\X11\UI\Event\ButtonPressedEvent;
use Cyrnetix\X11\UI\Event\ButtonReleasedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * A push button.
 *
 * Focusable, so a form can be tabbed through, and activated by Space or `Enter`
 * as well as by clicking. Give it a job with {@see setOnClick()}, or listen for
 * {@see \Cyrnetix\X11\UI\Event\ButtonClickedEvent}.
 */
final class Button extends Widget implements Focusable, Bounded
{
    private bool $pressed = false;
    private bool $focused = false;
    private ?\Closure $onClick = null;
    private bool $hovered = false;
    private bool $enabled = true;

    /** Takes its label, position, size and the event dispatcher. */
    public function __construct(
        private string $label,
        int $x, int $y,
        public readonly int $width,
        public readonly int $height,
        private readonly SyncEventDispatcher $dispatcher,
    ) {
        parent::__construct($x, $y);
    }

    /** The label. */
    public function getLabel(): string { return $this->label; }

    /**
     * A closure fired on click, alongside the dispatched event.
     *
     * For a composite that owns its buttons — a file dialog's Cancel — since it
     * can't reach the listener registry, and hit-testing its own buttons from a
     * handler would consume the release that {@see ButtonHandler} needs to clear
     * the pressed state. @see \Cyrnetix\X11\UI\Widget\ListView::setOnSelectionChanged()
     */
    public function setOnClick(?\Closure $cb): void { $this->onClick = $cb; }

    /**
     * Relabel in place. A dialog that reads "Open" or "Save" depending on why
     * it was opened is one button, not three — and the width stays put so the
     * button row doesn't reflow under the user.
     */
    public function setLabel(string $label): void { $this->label = $label; }
    /** Whether it is pressed. */
    public function isPressed(): bool  { return $this->pressed; }
    /** Whether it is hovered. */
    public function isHovered(): bool  { return $this->hovered; }
    /** Whether it is enabled. */
    public function isEnabled(): bool  { return $this->enabled; }

    /**
     * Enable or disable the button.
     *
     * A disabled button drops any press it was holding: leaving `pressed` set
     * would strand it looking pushed in, since the release it was waiting for
     * is now ignored.
     */
    public function setEnabled(bool $enabled): void
    {
        if ($enabled === $this->enabled) return;

        $this->enabled = $enabled;
        if (!$enabled) {
            $this->pressed = false;
            $this->hovered = false;
            $this->focused = false;
        }
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }

    /** A bevelled face over the whole rectangle. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    
    /** Whether this widget fills its own rectangle, so a repaint of it alone is safe. */
    public function paintsOwnBackground(): bool { return true; }

    /** Sets pressed. */
    public function setPressed(bool $pressed): void
    {
        if (!$this->enabled) return;

        $this->pressed = $pressed;
        if ($pressed) {
            $this->dispatcher->dispatch(new ButtonPressedEvent($this));
        }
    }

    /** Returns true when the hover state actually changed — callers redraw only then. */
    public function setHovered(bool $hovered): bool
    {
        if ($this->hovered === $hovered) return false;
        $this->hovered = $hovered;
        return true;
    }

    // ---- Focusable ------------------------------------------------------
    //
    // A button takes keyboard focus so a form can be tabbed through, and Space
    // or Enter presses it — the convention every toolkit of this era used.

    /** Whether it is focused. */
    public function isFocused(): bool { return $this->focused; }

    /** Whether it can take focus. */
    public function canTakeFocus(): bool { return $this->isEnabled(); }

    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void
    {
        $this->focused = $focused;
    }

    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool
    {
        return $this->enabled && $this->hitTest($mx, $my);
    }

    /** {@inheritDoc} */
    public function handleKey(string $key): bool
    {
        // 'Enter' is the toolkit's name for it — see KeyTranslator::SPECIAL.
        if (!$this->enabled || ($key !== ' ' && $key !== 'Enter')) {
            return false;
        }

        $this->activate();
        return true;
    }

    /** A button has nothing to put on the clipboard. */
    public function copy(): ?string { return null; }

    /** {@inheritDoc} */
    public function paste(string $text): void {}

    /**
     * Fire as though clicked, without the press-and-release the pointer goes
     * through. For the keyboard, where there is no press to hold.
     */
    public function activate(): void
    {
        if (!$this->enabled) return;

        $this->dispatcher->dispatch(new ButtonClickedEvent($this));
        if ($this->onClick !== null) ($this->onClick)($this);
    }

    /** Lets the button up. A release on the button is a click; one that wandered off is not. */
    public function release(bool $isClick): void
    {
        if (!$this->enabled) return;

        $this->pressed = false;
        $this->dispatcher->dispatch(new ButtonReleasedEvent($this));

        if ($isClick) {
            $this->dispatcher->dispatch(new ButtonClickedEvent($this));
            if ($this->onClick !== null) ($this->onClick)($this);
        }
    }
}
