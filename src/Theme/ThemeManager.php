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
 *
 * It also remembers which **variant** of the current theme is live. A theme is
 * pure description and holds no state, so this is the only place that knows —
 * and because painters ask this object for a palette rather than asking a
 * theme, a variant switch reaches every painter for free.
 *
 * A variant changes colours and nothing else (see {@see Theme}), so switching
 * one needs a repaint but no relayout. Switching *theme* may move sizes and
 * needs both, which is why {@see onChange()} listeners are told what changed.
 */
final class ThemeManager
{
    /** @var array<string, Theme> */
    private array $themes = [];

    private Theme $current;

    /** Which variant of $current is live. Always a variant that theme has. */
    private string $variant;

    /** @var list<callable(Theme, string, bool):void> */
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
        $this->variant = $themes[0]->defaultVariant();
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

    /** The live variant's id. */
    public function currentVariant(): string { return $this->variant; }

    /** The live variant's human name, for a menu or a status bar. */
    public function currentVariantName(): string
    {
        return $this->current->variants()[$this->variant] ?? $this->variant;
    }

    /** @return array<string, string> The current theme's variants, id => name. */
    public function variants(): array { return $this->current->variants(); }

    /** Whether the current theme has more than one variant to choose between. */
    public function hasVariants(): bool { return count($this->current->variants()) > 1; }

    // Shortcuts — painters use these constantly.
    /** The palette. */
    public function palette(): Palette { return $this->current->palette($this->variant); }
    /** The metrics. */
    public function metrics(): Metrics { return $this->current->metrics(); }
    /** The chrome. */
    public function chrome(): Chrome   { return $this->current->chrome($this->variant); }

    /**
     * Make $id the live theme, optionally in a named variant.
     *
     * With no variant the new theme shows its default — which is what a menu of
     * themes wants, since carrying "dark" across to a theme that has no such
     * variant would land on its default anyway and carrying it to one that does
     * is a guess about intent. Pass one explicitly to restore a saved pair.
     *
     * Returns false for an unknown id or a switch that changes nothing, so
     * callers can skip the relayout and repaint.
     */
    public function select(string $id, ?string $variant = null): bool
    {
        $theme = $this->themes[$id] ?? null;
        if ($theme === null) return false;

        // Ask the theme to vet the variant, so an unknown one lands on its
        // default here rather than being stored and resolved on every paint.
        $wanted = $theme->variants()[$variant] ?? null;
        $next   = $wanted !== null ? (string) $variant : $theme->defaultVariant();

        if ($theme === $this->current && $next === $this->variant) return false;

        $themeChanged  = $theme !== $this->current;
        $this->current = $theme;
        $this->variant = $next;

        $this->announce($themeChanged);

        return true;
    }

    /**
     * Switch the current theme's variant, keeping the theme.
     *
     * False for a variant this theme does not have, or one already live — so a
     * caller can tell "nothing to do" from "done" without comparing state.
     */
    public function selectVariant(string $variant): bool
    {
        if (!isset($this->current->variants()[$variant])) return false;
        if ($variant === $this->variant)                  return false;

        $this->variant = $variant;
        $this->announce(themeChanged: false);

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

    /** Cycle to the next variant of the current theme. */
    public function selectNextVariant(): bool
    {
        $ids = array_keys($this->current->variants());
        if (count($ids) < 2) return false;

        $at = array_search($this->variant, $ids, true);

        return $this->selectVariant($ids[(((int) $at) + 1) % count($ids)]);
    }

    /** Tell the listeners, saying whether the era moved or only its colours. */
    private function announce(bool $themeChanged): void
    {
        foreach ($this->listeners as $listener) {
            $listener($this->current, $this->variant, $themeChanged);
        }
    }

    /**
     * Called after every successful {@see select()} or {@see selectVariant()}.
     * Listeners run in registration order; the app registers relayout + repaint
     * here.
     *
     * The third argument says whether the *theme* changed or only its variant. A
     * variant is colours alone, so it needs a repaint and no relayout — and an
     * application that reflows on every listener call will do visible work for
     * nothing every time someone toggles dark mode.
     *
     * @param callable(Theme, string, bool):void $listener
     */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }
}
