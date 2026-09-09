<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * One complete look: its colours, its measurements, its drawing behaviour and
 * the server-side font it wants.
 *
 * A theme is pure description — it never touches the socket and never holds
 * per-window state, so switching themes is just swapping the object the
 * {@see ThemeManager} hands out. That includes which *variant* is showing: a
 * theme offers its variants and builds either on request, and the manager
 * remembers which one is live.
 *
 * ## Variants are palettes, and deliberately nothing else
 *
 * A variant is a second set of colours for the same era — a dark mode, a
 * high-contrast scheme, the colour schemes the control panels of the nineties
 * shipped. It changes {@see palette()} and, through it, {@see chrome()}, which
 * holds a palette and draws with it. It does **not** change {@see metrics()}.
 *
 * That line is worth stating because the temptation runs the other way. If a
 * look needs different sizes — thicker bevels, taller rows, a caption that
 * behaves differently — it is not a variant of an era, it is a different era,
 * and it should be its own theme. Keeping variants to colour is what lets every
 * widget keep its geometry across a switch: nothing has to relayout, because
 * nothing moved.
 */
interface Theme
{
    /** Stable identifier used by `--theme=` and the demo's Theme menu. */
    public function id(): string;

    /** Human-readable name for menus. */
    public function name(): string;

    /**
     * The variants this theme offers, as id => human name, in menu order.
     *
     * Every theme has at least one. A theme with a single look returns just
     * that, and callers need not care whether a theme has variants at all.
     *
     * @return array<string, string>
     */
    public function variants(): array;

    /** Which variant a theme shows when nothing has been chosen. */
    public function defaultVariant(): string;

    /**
     * The palette of a variant, or of {@see defaultVariant()} for null.
     *
     * An unknown id falls back to the default rather than failing: a saved
     * preference naming a variant that has since been renamed should show the
     * theme, not an error.
     */
    public function palette(?string $variant = null): Palette;

    /** The metrics, which every variant of a theme shares. */
    public function metrics(): Metrics;

    /** The chrome of a variant, holding that variant's palette. */
    public function chrome(?string $variant = null): Chrome;

    /**
     * X11 font names (XLFD patterns or aliases) in order of preference. The
     * client probes them with ListFonts and opens the first one the server
     * actually has, so the last entry should be something universal like
     * 'fixed'.
     *
     * @return list<string>
     */
    public function fontCandidates(): array;

    /**
     * Directory name of the icon set this theme wants under `resource/themes/`,
     * or null to use the application's default set. Era-appropriate icons matter
     * as much as era-appropriate chrome — a Mac OS 9 window full of Windows 2000
     * .ico files looks wrong immediately.
     */
    public function iconSet(): ?string;

    /**
     * Bold companions to {@see fontCandidates()}, used for window titles. An
     * empty list — or nothing matching — simply means no bold, and titles fall
     * back to the regular face rather than to something universal.
     *
     * @return list<string>
     */
    public function boldFontCandidates(): array;
}
