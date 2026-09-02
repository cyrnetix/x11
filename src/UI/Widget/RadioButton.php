<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Event\RadioToggledEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style radio button. Grouping is implicit: when one radio is checked,
 * every other RadioButton sharing the same parent widget is unchecked. That
 * matches the VB6 / Win32 convention — drop radios into the same Frame /
 * GroupBox and they group automatically.
 */
final class RadioButton extends Widget implements Bounded
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

    /** Whether it is checked. */
    public function isChecked(): bool { return $this->checked; }

    /** The dot plus its label, with the label's width estimated. */
    public function bounds(): Rect
    {
        $m = $this->metrics();

        return Rect::of(
            $this->x,
            $this->y,
            $m->radioSize + $m->radioGap + 7 * strlen($this->label),
            $m->radioSize,
        );
    }

    /** Glyphs on whatever is behind. */
    public function paintsOwnBackground(): bool { return false; }
    /** Whether it is enabled. */
    public function isEnabled(): bool { return $this->enabled; }

    /**
     * Activate this radio. Unchecks every other RadioButton sharing the same
     * parent, firing a RadioToggledEvent for each affected radio (siblings
     * first, then self) so listeners can observe the full state change.
     * No-op if already checked or disabled.
     */
    public function setChecked(bool $checked = true): void
    {
        if (!$this->enabled) return;
        if ($checked === $this->checked) return;

        if ($checked && $this->parent !== null) {
            foreach ($this->parent->getChildren() as $sibling) {
                if ($sibling instanceof self
                    && $sibling !== $this
                    && $sibling->checked
                ) {
                    $sibling->checked = false;
                    $sibling->dispatcher->dispatch(new RadioToggledEvent($sibling));
                }
            }
        }

        $this->checked = $checked;
        $this->dispatcher->dispatch(new RadioToggledEvent($this));
    }

    /** The height. */
    public function getHeight(): int { return $this->metrics()->radioSize; }

    /** The width. */
    public function getWidth(Renderer $renderer): int
    {
        $m = $this->metrics();
        return $m->radioSize + $m->radioGap + $renderer->measureText($this->label);
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my, Renderer $renderer): bool
    {
        $w = $this->getWidth($renderer);
        return $mx >= $this->x && $mx < $this->x + $w
            && $my >= $this->y && $my < $this->y + $this->metrics()->radioSize;
    }
}
