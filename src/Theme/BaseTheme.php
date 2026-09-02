<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * Lazily assembles a theme's three parts so a concrete theme reads as three
 * short builder methods and nothing else.
 */
abstract class BaseTheme implements Theme
{
    private ?Palette $palette = null;
    private ?Metrics $metrics = null;
    private ?Chrome  $chrome  = null;

    /** Builds the palette. */
    abstract protected function buildPalette(): Palette;

    /** Builds the metrics. */
    abstract protected function buildMetrics(): Metrics;

    /** Builds the chrome. */
    abstract protected function buildChrome(Palette $palette, Metrics $metrics): Chrome;

    /** The palette. */
    public function palette(): Palette
    {
        return $this->palette ??= $this->buildPalette();
    }

    /** The metrics. */
    public function metrics(): Metrics
    {
        return $this->metrics ??= $this->buildMetrics();
    }

    /** The chrome. */
    public function chrome(): Chrome
    {
        return $this->chrome ??= $this->buildChrome($this->palette(), $this->metrics());
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
