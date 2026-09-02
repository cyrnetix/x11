<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Win9x;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * Windows 9x / 2000 — the toolkit's original look, and the reference the
 * {@see Palette} and {@see Metrics} defaults were transcribed from, so this
 * theme is deliberately just the defaults plus its chrome.
 */
final class Win9xTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'win9x'; }
    /** The name. */
    public function name(): string { return 'Windows 9x / 2000'; }

    /** Builds the palette. */
    protected function buildPalette(): Palette { return new Palette(); }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics { return new Metrics(); }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new Win9xChrome($palette, $metrics);
    }

    /** The icon set. */
    public function iconSet(): ?string { return 'windows2000'; }

    /** Tahoma/MS Sans Serif have no X11 equivalent; Helvetica 12 stands in. */
    public function fontCandidates(): array
    {
        return [
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** Windows 2000 captions are bold. */
    public function boldFontCandidates(): array
    {
        return ['-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1'];
    }
}
