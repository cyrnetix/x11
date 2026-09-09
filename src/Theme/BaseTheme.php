<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * Lazily assembles a theme's parts so a concrete theme reads as a few short
 * builder methods and nothing else.
 *
 * Caching is **per variant**, which is the whole trick to supporting them here:
 * a variant changes the palette, and the chrome holds a palette, so both have to
 * be built again for a second variant and neither may be built twice. A theme
 * with one variant behaves exactly as before.
 */
abstract class BaseTheme implements Theme
{
    /** The id a theme with nothing to choose between uses. */
    public const SOLE_VARIANT = 'default';

    /** @var array<string, Palette> */
    private array $palettes = [];

    /** @var array<string, Chrome> */
    private array $chromes = [];

    private ?Metrics $metrics = null;

    /**
     * Builds the palette of a variant.
     *
     * A theme with one look ignores the argument. One with several switches on
     * it, and should treat anything it does not recognise as its default —
     * {@see resolveVariant()} has already done that, so the argument is always
     * an id from {@see variants()}.
     */
    abstract protected function buildPalette(string $variant): Palette;

    /** Builds the metrics. */
    abstract protected function buildMetrics(): Metrics;

    /** Builds the chrome. */
    abstract protected function buildChrome(Palette $palette, Metrics $metrics): Chrome;

    /** One variant, unless a theme says otherwise. */
    public function variants(): array
    {
        return [self::SOLE_VARIANT => $this->name()];
    }

    /** The first variant offered, unless a theme says otherwise. */
    public function defaultVariant(): string
    {
        return array_key_first($this->variants()) ?? self::SOLE_VARIANT;
    }

    /**
     * A usable variant id: the one asked for if this theme has it, else its
     * default.
     *
     * Forgiving on purpose. A variant is a preference, and one that has been
     * renamed or removed between versions should leave the user looking at the
     * theme rather than at an exception.
     */
    protected function resolveVariant(?string $variant): string
    {
        return $variant !== null && isset($this->variants()[$variant])
            ? $variant
            : $this->defaultVariant();
    }

    /** The palette. */
    public function palette(?string $variant = null): Palette
    {
        $id = $this->resolveVariant($variant);

        return $this->palettes[$id] ??= $this->buildPalette($id);
    }

    /** The metrics. */
    public function metrics(): Metrics
    {
        return $this->metrics ??= $this->buildMetrics();
    }

    /** The chrome. */
    public function chrome(?string $variant = null): Chrome
    {
        $id = $this->resolveVariant($variant);

        return $this->chromes[$id] ??= $this->buildChrome($this->palette($id), $this->metrics());
    }

    /** Themes that don't care about the font fall back to the server's 'fixed'. */
    public function fontCandidates(): array
    {
        return ['fixed'];
    }

    /** No icon set of its own; the app's default is used. */
    public function iconSet(): ?string
    {
        return null;
    }

    /** No bold face by default: titles then just use the regular one. */
    public function boldFontCandidates(): array
    {
        return [];
    }
}
