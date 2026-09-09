<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Material;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * Material: tonal surfaces, pill buttons, underlined fields, in light and dark.
 *
 * The second theme here from an era that never ran on X11 at all, and the point
 * of having it beside {@see \Cyrnetix\X11\Theme\Fluent\FluentTheme} is that
 * "flat and rounded" is not one look. They differ in kind rather than in colour:
 *
 * | | Fluent | Material |
 * |---|---|---|
 * | depth | a hairline border | a lighter tone |
 * | buttons | small even radius | pills |
 * | fields | outlined | filled, with a rule under them |
 * | active tab | a filled shape | a bar beneath it |
 * | selection | solid accent | an accent tint |
 *
 * Two of those needed the palette to be read differently rather than recoloured.
 * `frame` here is not a border colour — this era draws almost no borders — it is
 * the *outline* role: the rule under a field, and the ring of an unchecked box.
 * And `faceLight` is the surface a control sits on, a tone above the window,
 * which is the whole depth cue.
 *
 * **On the colours.** They are this project's own, chosen to read like the idiom:
 * a neutral with a trace of the accent in it, one tone up for anything raised,
 * one accent used for every emphasis. Not measured from any published palette,
 * and said plainly because the older themes here *were* measured from
 * screenshots and their comments say so.
 */
final class MaterialTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'material'; }
    /** The name. */
    public function name(): string { return 'Material'; }

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

    /** A neutral with a trace of the accent in it, and one tone above for surfaces. */
    private static function lightPalette(): Palette
    {
        $ink     = Palette::hex(0x1C1B1F);
        $white   = Palette::hex(0xFFFFFF);
        $accent  = Palette::hex(0x6A4FA3);
        $outline = Palette::hex(0x79747E);

        return new Palette(
            face:                Palette::hex(0xF6F3F9),
            faceHighlight:       $white,
            // The surface a control sits on: a tone above the window, and the
            // only depth cue this era has.
            faceLight:           $white,
            faceShadow:          Palette::hex(0xE7E0EC),
            faceDarkShadow:      Palette::hex(0xCAC4D0),
            text:                $ink,
            textDisabled:        Palette::hex(0xA9A5AE),
            textEmboss:          $white,
            textDim:             $outline,
            content:             $white,
            contentText:         $ink,
            // A tint rather than a fill, so a selected row keeps its own text
            // colour and the accent reads as emphasis rather than as a block.
            selection:           Palette::hex(0xE7DEF8),
            selectionText:       $white,
            desktop:             Palette::hex(0xE6E0EA),
            panel:               $white,
            bar:                 Palette::hex(0xF6F3F9),
            menuBar:             Palette::hex(0xF6F3F9),
            menu:                $white,
            menuText:            $ink,
            menuHighlight:       Palette::hex(0xE7DEF8),
            menuHighlightText:   $ink,
            track:               Palette::hex(0xE7E0EC),
            progressTrough:      Palette::hex(0xE7E0EC),
            progressBar:         $accent,
            captionActive:       Palette::hex(0xF6F3F9),
            captionActiveEnd:    Palette::hex(0xF6F3F9),
            captionActiveText:   $ink,
            captionInactive:     Palette::hex(0xEFEAF4),
            captionInactiveEnd:  Palette::hex(0xEFEAF4),
            captionInactiveText: Palette::hex(0x8B8792),
            // Not a border colour: the outline role, for the rule under a field
            // and the ring of an empty checkbox.
            frame:               $outline,
            focus:               $accent,
            accent:              $accent,
            tooltip:             Palette::hex(0x322F35),
            tooltipText:         Palette::hex(0xF5EFF7),
            todayMarker:         $accent,
            iconInfo:            $accent,
            iconWarning:         Palette::hex(0xB3701F),
            iconError:           Palette::hex(0xB3261E),
            iconQuestion:        Palette::hex(0x1F7A6F),
        );
    }

    /** The same tones inverted: a near-black ground, surfaces a step above it. */
    private static function darkPalette(): Palette
    {
        $ink     = Palette::hex(0xE6E0E9);
        $accent  = Palette::hex(0xB49CF0);
        $outline = Palette::hex(0x938F99);

        return new Palette(
            face:                Palette::hex(0x141218),
            faceHighlight:       Palette::hex(0x2B2930),
            faceLight:           Palette::hex(0x211F26),
            faceShadow:          Palette::hex(0x100E14),
            faceDarkShadow:      Palette::hex(0x0A090D),
            text:                $ink,
            textDisabled:        Palette::hex(0x6F6B75),
            textEmboss:          Palette::hex(0x0A090D),
            textDim:             $outline,
            content:             Palette::hex(0x1D1B20),
            contentText:         $ink,
            selection:           Palette::hex(0x4A4458),
            selectionText:       $ink,
            desktop:             Palette::hex(0x0C0B0F),
            panel:               Palette::hex(0x1D1B20),
            bar:                 Palette::hex(0x141218),
            menuBar:             Palette::hex(0x141218),
            menu:                Palette::hex(0x211F26),
            menuText:            $ink,
            menuHighlight:       Palette::hex(0x4A4458),
            menuHighlightText:   $ink,
            track:               Palette::hex(0x36343B),
            progressTrough:      Palette::hex(0x36343B),
            progressBar:         $accent,
            captionActive:       Palette::hex(0x141218),
            captionActiveEnd:    Palette::hex(0x141218),
            captionActiveText:   $ink,
            captionInactive:     Palette::hex(0x100E14),
            captionInactiveEnd:  Palette::hex(0x100E14),
            captionInactiveText: Palette::hex(0x746F7A),
            frame:               $outline,
            focus:               $accent,
            accent:              $accent,
            tooltip:             Palette::hex(0xE6E0E9),
            tooltipText:         Palette::hex(0x322F35),
            todayMarker:         $accent,
            iconInfo:            $accent,
            iconWarning:         Palette::hex(0xE0B040),
            iconError:           Palette::hex(0xF2B8B5),
            iconQuestion:        Palette::hex(0x60C0B0),
        );
    }

    /**
     * Builds the metrics.
     *
     * Roomier than Fluent's, which is roomier than the nineties': the eras get
     * more generous as screens get denser. The pill buttons need the height —
     * a 20-pixel pill is a lozenge, not a button — and the tabs are tall
     * because their indicator lives inside them.
     */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // Not zero: FormWindow insets its caption and content by this, so a
            // zero would let content touch the window's own edge. One pixel of
            // breathing room, and no bevel drawn in it.
            edge:                 1,
            thinEdge:             1,
            pressOffset:          0,
            checkBoxSize:         18,
            checkBoxGap:          10,
            radioSize:            20,
            radioGap:             10,
            scrollBarThickness:   12,
            scrollBarMinThumb:    28,
            textBoxBorder:        1,
            textBoxPadding:       8,
            listBoxBorder:        0,
            listBoxItemPadding:   12,
            listViewBorder:       0,
            listViewHeaderHeight: 32,
            listViewRowHeight:    28,
            listViewCellPadding:  12,
            treeBorder:           0,
            treeRowHeight:        28,
            treeIndent:           20,
            tabHeight:            40,
            tabBorder:            0,
            tabLabelPadding:      20,
            tabActiveLift:        0,
            groupBoxPaddingTop:   22,
            menuBarHeight:        34,
            menuBarLabelPadding:  14,
            menuItemHeight:       32,
            menuSeparatorHeight:  9,
            menuPopupBorder:      0,
            statusBarHeight:      28,
            statusBarPaneBorder:  0,
            statusBarPanePadding: 10,
            toolbarButtonSize:    34,
            toolbarPadding:       4,
            canvasBorder:         1,
            cornerRadius:         4,
            // Larger than Fluent's eight: this era rounds its windows and
            // dialogs generously, and the difference is visible side by side.
            windowCornerRadius:   12,
            trackThickness:       4,
            trackbarThumbLong:    20,
            trackbarThumbShort:   20,
            trackbarThumbPoint:   0,
            dialogTitleHeight:    36,
            dialogButtonWidth:    96,
            dialogButtonHeight:   34,
            fieldHeight:          32,
            fieldButtonWidth:     28,
            fieldBorder:          1,
            fieldPadding:         8,
            upDownWidth:          22,
            upDownHeight:         16,
            progressBorder:       0,
            progressChunked:      false,
            captionHeight:        36,
            captionButtonSize:    28,
            captionButtonGap:     2,
            captionPadding:       4,
            captionTitleGap:      14,
            // No leading button, so the title's only spacing from the frame is
            // this. Without it the text sits flush against the window's edge.
            captionTitleMargin:   10,
            captionLeading:       [],
            captionTrailing:      [CaptionButton::Minimize, CaptionButton::Maximize, CaptionButton::Close],
            windowBorder:         0,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new MaterialChrome($palette, $metrics);
    }

    /** Roboto has no X11 equivalent; DejaVu Sans stands in. */
    public function fontCandidates(): array
    {
        return [
            '-*-dejavu sans-book-r-normal-*-12-*-*-*-*-*-iso8859-1',
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** A medium weight for titles and headers. */
    public function boldFontCandidates(): array
    {
        return [
            '-*-dejavu sans-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
            '-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
        ];
    }
}
