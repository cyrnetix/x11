<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\UI\Event\UpDownChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win32-style Up-Down (a.k.a. Spin) control.
 *
 *   ┌────┐
 *   │ ▲  │  ← REGION_UP
 *   ├────┤
 *   │ ▼  │  ← REGION_DOWN
 *   └────┘
 *
 * Holds an integer value clamped to [$min, $max]. Optionally linked to a
 * "buddy" TextBox, and the link runs **both ways**: stepping the arrows writes
 * the field, and reading the value reads the field back.
 *
 * The read-back is the important half. Leaving it to the caller — as this used
 * to — means a control whose reported value silently disagrees with the number
 * the user is looking at: type 100 into the field, and getValue() still answers
 * with whatever the arrows were last on. Every caller has to remember to parse
 * the buddy itself, and the one that forgets has a bug that looks like the input
 * being ignored.
 *
 * So the field is the source of truth whenever there is one. Text that isn't a
 * number leaves the last good value in place, and a number outside the range is
 * clamped — but the text itself is left alone, because rewriting it while
 * someone is still typing is worse than reading it charitably.
 */
final class UpDown extends Widget
{
    public const REGION_UP   = 'up';
    public const REGION_DOWN = 'down';

    private int     $value;
    private ?string $pressedRegion = null;

    /** Takes position and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public readonly int $min,
        public readonly int $max,
        int $value,
        public readonly int $step,
        private readonly SyncEventDispatcher $dispatcher,
        public readonly ?TextBox $buddy = null,
    ) {
        parent::__construct($x, $y);
        $this->value = max($min, min($max, $value));
        $this->syncBuddy();
    }

    /**
     * The current value, read back from the buddy field when there is one.
     *
     * Deliberately not a pure accessor: the field is what the user last set,
     * whether by typing or by stepping, and a cached copy is exactly what goes
     * stale.
     */
    public function getValue(): int
    {
        $this->readBuddy();

        return $this->value;
    }

    /** The pressed region. */
    public function getPressedRegion(): ?string { return $this->pressedRegion; }

    /** Sets pressed region. */
    public function setPressedRegion(?string $region): void
    {
        $this->pressedRegion = $region;
    }

    /** Clamp + assign; fire UpDownChangedEvent and update buddy on change. */
    public function setValue(int $value): bool
    {
        // Read first, so setting the value a typed field already holds is
        // correctly a no-op rather than a spurious change event.
        $this->readBuddy();

        $clamped = max($this->min, min($this->max, $value));
        if ($clamped === $this->value) return false;
        $this->value = $clamped;
        $this->syncBuddy();
        $this->dispatcher->dispatch(new UpDownChangedEvent($this));
        return true;
    }

    // Stepping starts from what the field shows, so the arrows continue from a
    // typed number instead of jumping back to where they were.
    /** Steps the value up. False if it was already at the maximum. */
    public function increment(): bool { return $this->setValue($this->getValue() + $this->step); }
    /** Steps the value down. False if it was already at the minimum. */
    public function decrement(): bool { return $this->setValue($this->getValue() - $this->step); }

    /** Step in the direction of $region; null/unknown is a no-op returning false. */
    public function step(string $region): bool
    {
        return match ($region) {
            self::REGION_UP   => $this->increment(),
            self::REGION_DOWN => $this->decrement(),
            default           => false,
        };
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        $m = $this->metrics();
        return $mx >= $this->x && $mx < $this->x + $m->upDownWidth
            && $my >= $this->y && $my < $this->y + $m->upDownHeight;
    }

    /** Returns REGION_UP, REGION_DOWN, or null. */
    public function hitTestRegion(int $mx, int $my): ?string
    {
        if (!$this->hitTest($mx, $my)) return null;
        return ($my - $this->y) < $this->metrics()->upDownArrowHeight()
            ? self::REGION_UP
            : self::REGION_DOWN;
    }

    /** Writes the value into the text box this spinner is attached to, if it has one. */
    private function syncBuddy(): void
    {
        $this->buddy?->setText((string) $this->value);
    }

    /**
     * Adopt whatever the buddy field holds, if it holds a number.
     *
     * Anything else — empty, mid-edit, letters — leaves the last good value
     * alone. The field is not corrected here: a value clamped on read still
     * shows what the user typed until the arrows next write to it, which is the
     * lesser evil against rewriting text under a caret.
     */
    private function readBuddy(): void
    {
        if ($this->buddy === null) return;

        $text = trim($this->buddy->getText());
        if ($text === '' || preg_match('/^-?\d+$/', $text) !== 1) return;

        $this->value = max($this->min, min($this->max, (int) $text));
    }
}
