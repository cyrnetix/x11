<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * One complete look: its colours, its measurements, its drawing behaviour and
 * the server-side font it wants.
 *
 * A theme is pure description — it never touches the socket and never holds
 * per-window state, so switching themes is just swapping the object the
 * {@see ThemeManager} hands out.
 */
interface Theme
{
    /** Stable identifier used by `--theme=` and the demo's Theme menu. */
    public function id(): string;

    /** Human-readable name for menus. */
    public function name(): string;

    /** The palette. */
    public function palette(): Palette;

    /** The metrics. */
    public function metrics(): Metrics;

    /** The chrome. */
    public function chrome(): Chrome;

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
