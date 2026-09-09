<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Fluent;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * The current Windows look: rounded, flat, hairline-bordered, in light and dark.
 *
 * The first theme here from an era that expects a compositor, and the first with
 * two variants of equal standing — dark is not a recolouring of a look designed
 * light, it is half of how the era ships.
 *
 * **On the colours.** Every other theme in this toolkit was measured from
 * screenshots of the thing it imitates, and its comments say where each value
 * came from. These values were not: they are chosen to read like the era —
 * near-white surfaces a shade apart, a border a shade darker again, one accent —
 * and they are this project's own. Said plainly because the rest of `src/Theme`
 * sets the opposite expectation, and a reader is entitled to know which kind of
 * theme they are looking at.
 *
 * **What the era needs that the older ones did not:**
 *
 * - **A corner radius, twice.** {@see Metrics::$cornerRadius} rounds the
 *   controls and {@see Metrics::$windowCornerRadius} rounds the window, and they
 *   are separate because they are different mechanisms: one is drawn, the other
 *   is a hole cut with the SHAPE extension. A server without SHAPE keeps a
 *   square window and everything inside it still curves.
 * - **A palette with no bevels to describe.** The four face roles that carried
 *   depth for thirty years become a fill and a border here. They are still
 *   filled in — a widget may ask for any role — but `faceLight` is now simply
 *   "the surface a control sits on" and `frame` does the work that
 *   `faceShadow` used to.
 * - **Hover.** {@see FluentChrome::rendersButtonHover()} is the first `true` in
 *   the toolkit, which switches on a repaint path that had never fired.
 */
final class FluentTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'fluent'; }
    /** The name. */
    public function name(): string { return 'Fluent (Windows 11-style)'; }

    /** Light and dark, in that order. */
    public function variants(): array
    {
        return ['light' => 'Light', 'dark' => 'Dark'];
    }

    /** Builds the palette. */
    protected function buildPalette(string $variant): Palette
    {
        return $variant === 'dark' ? self::darkPalette() : self::lightPalette();
    }

    /** Near-white surfaces, a grey hairline, one blue. */
    private static function lightPalette(): Palette
    {
        $ink    = Palette::hex(0x1A1A1A);
        $white  = Palette::hex(0xFFFFFF);
        $accent = Palette::hex(0x0067C0);
        $border = Palette::hex(0xD6D6D6);

        return new Palette(
            face:                Palette::hex(0xF3F3F3),
            faceHighlight:       $white,
            // Not a bevel any more: this is the surface a control sits on, a
            // shade *up* from the window rather than a light edge.
            faceLight:           Palette::hex(0xFBFBFB),
            faceShadow:          Palette::hex(0xE5E5E5),
            faceDarkShadow:      $border,
            text:                $ink,
            textDisabled:        Palette::hex(0x9A9A9A),
            textEmboss:          $white,
            // Also the scrollbar thumb, which is why it is a mid grey rather
            // than a faint one.
            textDim:             Palette::hex(0x8A8A8A),
            content:             $white,
            contentText:         $ink,
            selection:           $accent,
            selectionText:       $white,
            desktop:             Palette::hex(0xE8E8E8),
            panel:               Palette::hex(0xFBFBFB),
            bar:                 Palette::hex(0xF9F9F9),
            menuBar:             Palette::hex(0xF3F3F3),
            menu:                Palette::hex(0xFBFBFB),
            menuText:            $ink,
            menuHighlight:       Palette::hex(0xE8F0FA),
            menuHighlightText:   $ink,
            track:               Palette::hex(0xEFEFEF),
            progressTrough:      Palette::hex(0xE1E1E1),
            progressBar:         $accent,
            // The title bar is the window: the same colour, flat, no gradient.
            captionActive:       Palette::hex(0xF3F3F3),
            captionActiveEnd:    Palette::hex(0xF3F3F3),
            captionActiveText:   $ink,
            captionInactive:     Palette::hex(0xF9F9F9),
            captionInactiveEnd:  Palette::hex(0xF9F9F9),
            captionInactiveText: Palette::hex(0x8A8A8A),
            frame:               $border,
            focus:               $accent,
            accent:              $accent,
            tooltip:             $white,
            tooltipText:         $ink,
            todayMarker:         $accent,
            iconInfo:            $accent,
            iconWarning:         Palette::hex(0xC98A00),
            iconError:           Palette::hex(0xC42B1C),
            iconQuestion:        Palette::hex(0x0F7B7B),
        );
    }

    /** The same look with the values inverted around a dark ground. */
    private static function darkPalette(): Palette
    {
        $ink    = Palette::hex(0xFFFFFF);
        $accent = Palette::hex(0x2F8FD6);
        $border = Palette::hex(0x3A3A3A);

        return new Palette(
            face:                Palette::hex(0x202020),
            faceHighlight:       Palette::hex(0x383838),
            // A shade *up* from the window, as in the light variant — the
            // direction is what carries over, not the value.
            faceLight:           Palette::hex(0x2D2D2D),
            faceShadow:          Palette::hex(0x1A1A1A),
            faceDarkShadow:      Palette::hex(0x101010),
            text:                $ink,
            textDisabled:        Palette::hex(0x6E6E6E),
            // Darker than the face, because the engraved effect it backs is a
            // shadow and a light one would read as a second label.
            textEmboss:          Palette::hex(0x141414),
            textDim:             Palette::hex(0x8A8A8A),
            content:             Palette::hex(0x2B2B2B),
            contentText:         $ink,
            selection:           Palette::hex(0x0F4C81),
            selectionText:       $ink,
            desktop:             Palette::hex(0x141414),
            panel:               Palette::hex(0x272727),
            bar:                 Palette::hex(0x202020),
            menuBar:             Palette::hex(0x202020),
            menu:                Palette::hex(0x2C2C2C),
            menuText:            $ink,
            menuHighlight:       Palette::hex(0x33445A),
            menuHighlightText:   $ink,
            track:               Palette::hex(0x2A2A2A),
            progressTrough:      Palette::hex(0x333333),
            progressBar:         $accent,
            captionActive:       Palette::hex(0x202020),
            captionActiveEnd:    Palette::hex(0x202020),
            captionActiveText:   $ink,
            captionInactive:     Palette::hex(0x1A1A1A),
            captionInactiveText: Palette::hex(0x7A7A7A),
            captionInactiveEnd:  Palette::hex(0x1A1A1A),
            frame:               $border,
            focus:               $accent,
            accent:              $accent,
            tooltip:             Palette::hex(0x2C2C2C),
            tooltipText:         $ink,
            todayMarker:         $accent,
            iconInfo:            $accent,
            iconWarning:         Palette::hex(0xE0B040),
            iconError:           Palette::hex(0xE05A4A),
            iconQuestion:        Palette::hex(0x40B0B0),
        );
    }

    /**
     * Builds the metrics.
     *
     * Bigger than the older eras throughout, and that is the era rather than a
     * preference: controls sized for a touch as well as a pointer, and text with
     * room around it. The two radii are the new numbers; everything else is a
     * measurement that moved.
     */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // One hairline, in both weights: there is no second bevel layer to
            // be thinner than.
            edge:                 1,
            thinEdge:             1,
            pressOffset:          0,
            checkBoxSize:         18,
            checkBoxGap:          8,
            radioSize:            18,
            radioGap:             8,
            scrollBarThickness:   14,
            scrollBarMinThumb:    24,
            textBoxBorder:        1,
            textBoxPadding:       6,
            listBoxBorder:        1,
            listBoxItemPadding:   8,
            listViewBorder:       1,
            listViewHeaderHeight: 28,
            listViewRowHeight:    24,
            listViewCellPadding:  8,
            treeBorder:           1,
            treeRowHeight:        24,
            treeIndent:           18,
            tabHeight:            32,
            tabBorder:            1,
            tabLabelPadding:      16,
            tabActiveLift:        0,
            groupBoxPaddingTop:   20,
            menuBarHeight:        30,
            menuBarLabelPadding:  12,
            menuItemHeight:       28,
            menuSeparatorHeight:  9,
            menuPopupBorder:      1,
            statusBarHeight:      26,
            statusBarPaneBorder:  0,
            statusBarPanePadding: 8,
            toolbarButtonSize:    30,
            toolbarPadding:       3,
            canvasBorder:         1,
            cornerRadius:         4,
            // Eight, and cut with SHAPE. Larger than the controls' four because
            // a window's corner is much bigger on screen and the same radius
            // would read as square.
            windowCornerRadius:   8,
            trackThickness:       4,
            trackbarThumbLong:    16,
            trackbarThumbShort:   16,
            trackbarThumbPoint:   0,
            dialogTitleHeight:    32,
            dialogButtonWidth:    88,
            dialogButtonHeight:   30,
            fieldHeight:          28,
            fieldButtonWidth:     26,
            fieldBorder:          1,
            fieldPadding:         6,
            upDownWidth:          20,
            upDownHeight:         14,
            progressBorder:       0,
            progressChunked:      false,
            captionHeight:        32,
            captionButtonSize:    28,
            captionButtonGap:     0,
            captionPadding:       0,
            captionTitleGap:      12,
            // A caption control here is a full-height cell that lights up, not
            // a small box inset in the bar — so no padding and no gap.
            captionLeading:       [],
            captionTrailing:      [CaptionButton::Minimize, CaptionButton::Maximize, CaptionButton::Close],
            windowBorder:         1,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new FluentChrome($palette, $metrics);
    }

    /** Segoe UI has no X11 equivalent; DejaVu Sans is the closest widely present face. */
    public function fontCandidates(): array
    {
        return [
            '-*-dejavu sans-book-r-normal-*-12-*-*-*-*-*-iso8859-1',
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** Captions are not bold in this era, but dialogs and headers use one. */
    public function boldFontCandidates(): array
    {
        return [
            '-*-dejavu sans-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
            '-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
        ];
    }

    /** Borrows the Windows icon set; nothing era-specific ships here. */
    public function iconSet(): ?string { return 'windows2000'; }
}
