<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

use InvalidArgumentException;

/**
 * The one mutable piece of the theming design: holds the registered themes and
 * which one is live, and notifies listeners when that changes.
 *
 * Painters, widgets and the client all keep a reference to *this* rather than
 * to a Theme, so a switch reaches everything without re-wiring the object
 * graph. Widgets pull metrics from here through {@see \Cyrnetix\X11\UI\Widget\Widget::metrics()},
 * which is why hit-testing and painting can never disagree about sizes.
 */
final class ThemeManager
{
    /** @var array<string, Theme> */
    private array $themes = [];

    private Theme $current;

    /** @var list<callable(Theme):void> */
    private array $listeners = [];

    /**
     * @param Theme ...$themes Registered in order; the first becomes current.
     */
    public function __construct(Theme ...$themes)
    {
        if ($themes === []) {
            throw new InvalidArgumentException('ThemeManager needs at least one theme');
        }
        foreach ($themes as $theme) {
            $this->themes[$theme->id()] = $theme;
        }
        $this->current = $themes[0];
    }

    /**
     * Adds a theme, keyed by its id. Registration order is the order a menu should offer them
     * in.
     */
    public function register(Theme $theme): void
    {
        $this->themes[$theme->id()] = $theme;
    }

    /** @return array<string, Theme> keyed by id, in registration order */
    public function all(): array { return $this->themes; }

    /** @return list<string> */
    public function ids(): array { return array_keys($this->themes); }

    /** Whether a theme with this id is registered. */
    public function has(string $id): bool { return isset($this->themes[$id]); }

    /** The theme with this id, or null. */
    public function get(string $id): ?Theme { return $this->themes[$id] ?? null; }

    /** The current. */
    public function current(): Theme  { return $this->current; }
    /** The current id. */
    public function currentId(): string { return $this->current->id(); }

    // Shortcuts — painters use these constantly.
    /** The palette. */
    public function palette(): Palette { return $this->current->palette(); }
    /** The metrics. */
    public function metrics(): Metrics { return $this->current->metrics(); }
    /** The chrome. */
    public function chrome(): Chrome   { return $this->current->chrome(); }

    /**
     * Make $id the live theme. Returns false for an unknown id or a no-op
     * switch, so callers can skip the relayout + repaint.
     */
    public function select(string $id): bool
    {
        $theme = $this->themes[$id] ?? null;
        if ($theme === null || $theme === $this->current) {
            return false;
        }

        $this->current = $theme;
        foreach ($this->listeners as $listener) {
            $listener($theme);
        }
        return true;
    }

    /** Cycle to the next registered theme — handy for a keyboard shortcut. */
    public function selectNext(): bool
    {
        $ids  = $this->ids();
        $at   = array_search($this->current->id(), $ids, true);
        $next = $ids[(((int) $at) + 1) % count($ids)];
        return $this->select($next);
    }

    /**
     * Called after every successful {@see select()}. Listeners run in
     * registration order; the app registers relayout + repaint here.
     *
     * @param callable(Theme):void $listener
     */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }
}
