<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Event\CheckboxToggledEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style checkbox. Width auto-derives from label length so callers only
 * supply position. Click anywhere on the box or label toggles state and fires
 * CheckboxToggledEvent.
 */
final class Checkbox extends Widget implements Focusable, Bounded
{
    private bool $checked = false;
    private bool $enabled = true;

    /** Takes its label, position and the event dispatcher. */
    public function __construct(
        public readonly string $label,
        int $x, int $y,
        private readonly SyncEventDispatcher $dispatcher,
        bool $checked = false,
    ) {
        parent::__construct($x, $y);
        $this->checked = $checked;
    }

    private ?\Closure $onToggled = null;
    private bool $focused = false;

    /** Whether it is checked. */
    public function isChecked(): bool { return $this->checked; }
    /** Whether it is enabled. */
    public function isEnabled(): bool { return $this->enabled; }

    /** Enable or disable. A disabled box keeps its state but ignores clicks. */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** @see \Cyrnetix\X11\UI\Widget\ListView::setOnSelectionChanged() for why. */
    public function setOnToggled(?\Closure $cb): void { $this->onToggled = $cb; }

    /** Sets checked. */
    public function setChecked(bool $checked): void
    {
        if (!$this->enabled || $checked === $this->checked) return;

        $this->checked = $checked;
        $this->dispatcher->dispatch(new CheckboxToggledEvent($this));
        if ($this->onToggled !== null) ($this->onToggled)($this->checked);
    }

    /** Flips the tick. */
    public function toggle(): void
    {
        $this->setChecked(!$this->checked);
    }

    // ---- Focusable ------------------------------------------------------

    /** Whether it is focused. */
    public function isFocused(): bool { return $this->focused; }

    /** Whether it can take focus. */
    public function canTakeFocus(): bool { return $this->isEnabled(); }

    /**
     * The box plus its label. The label's width is estimated rather than
     * measured — a repaint region has no Renderer to hand — so it is generous:
     * a region slightly too big costs a little work, one too small leaves
     * pixels stale.
     */
    public function bounds(): Rect
    {
        $m = $this->metrics();

        return Rect::of(
            $this->x,
            $this->y,
            $m->checkBoxSize + $m->checkBoxGap + 7 * strlen($this->label),
            $m->checkBoxSize,
        );
    }

    /** Glyphs on whatever is behind: the surface shows between the strokes. */
    public function paintsOwnBackground(): bool { return false; }

    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void
    {
        $this->focused = $focused;
    }

    /**
     * Focus follows a click anywhere on the box or its label. Measured without a
     * Renderer, which the focus lookup doesn't have, so the label's width is
     * estimated — generous rather than exact, since a focus miss is worse than a
     * focus gained a few pixels early.
     */
    public function hitTestForFocus(int $mx, int $my): bool
    {
        if (!$this->enabled) return false;

        $m     = $this->metrics();
        $width = $m->checkBoxSize + $m->checkBoxGap + 7 * strlen($this->label);

        return $mx >= $this->x && $mx < $this->x + $width
            && $my >= $this->y && $my < $this->y + $m->checkBoxSize;
    }

    /** {@inheritDoc} */
    public function handleKey(string $key): bool
    {
        if (!$this->enabled || $key !== ' ') return false;

        $this->toggle();
        return true;
    }

    /** The copy. */
    public function copy(): ?string { return null; }

    /** {@inheritDoc} */
    public function paste(string $text): void {}

    /** The height. */
    public function getHeight(): int
    {
        return $this->metrics()->checkBoxSize;
    }

    /** The width. */
    public function getWidth(Renderer $renderer): int
    {
        $m = $this->metrics();
        return $m->checkBoxSize + $m->checkBoxGap + $renderer->measureText($this->label);
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my, Renderer $renderer): bool
    {
        $w = $this->getWidth($renderer);
        return $mx >= $this->x && $mx < $this->x + $w
            && $my >= $this->y && $my < $this->y + $this->metrics()->checkBoxSize;
    }
}
