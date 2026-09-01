<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\BeOs;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * BeOS R5, sampled from R5.0.1 screenshots.
 *
 * Its panel grey is #D8D8D8 with controls a shade lighter at #E8E8E8, single
 * pixel bevels throughout, and the famous **yellow #FFCB00 window tab** that
 * only spans its own title — hence `captionFitsTitle`, with the desktop blue
 * showing beside it.
 *
 * Two things here are judgement calls rather than measurements, because no
 * reference shot had them on screen: the list selection and the menu highlight.
 * Both are set to muted greys that suit a grey menu, rather than inventing a
 * saturated colour the era never used.
 *
 * The tab carries a close box at the left and the zoom box at the right, and
 * nothing else — BeOS had no minimise button there, so the caption metrics name
 * only those two.
 */
final class BeOsTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'beos'; }
    /** The name. */
    public function name(): string { return 'BeOS R5'; }

    /** Builds the palette. */
    protected function buildPalette(): Palette
    {
        $panel   = Palette::hex(0xD8D8D8);
        $control = Palette::hex(0xE8E8E8);
        $light   = Palette::hex(0xEFEFEF);
        $mid     = Palette::hex(0x989898);
        $dark    = Palette::hex(0x646464);
        $black   = Palette::hex(0x000000);
        $white   = Palette::hex(0xFFFFFF);
        $yellow  = Palette::hex(0xFFCB00);
        $blue    = Palette::hex(0x30659A);   // the BeOS desktop

        return new Palette(
            face:                $panel,
            faceHighlight:       $white,
            faceLight:           $light,
            faceShadow:          $mid,
            faceDarkShadow:      $dark,
            text:                $black,
            textDisabled:        $mid,
            textEmboss:          $white,
            textDim:             Palette::hex(0x808080),
            content:             $white,
            contentText:         $black,
            // Judgement call: no reference shot showed a selected row.
            selection:           Palette::hex(0xC8C8E0),
            selectionText:       $black,
            desktop:             $blue,
            panel:               $control,
            bar:                 $panel,
            menuBar:             $panel,
            menu:                $panel,
            menuText:            $black,
            // Likewise — a grey band suits a grey menu better than a colour.
            menuHighlight:       Palette::hex(0xB8B8B8),
            menuHighlightText:   $black,
            track:               Palette::hex(0xC8C8C8),
            progressTrough:      Palette::hex(0xC8C8C8),
            progressBar:         $blue,
            captionActive:       $yellow,
            captionActiveEnd:    $yellow,
            captionActiveText:   $black,
            captionInactive:     $panel,
            captionInactiveEnd:  $panel,
            captionInactiveText: $dark,
            frame:               $black,
            focus:              Palette::hex(0x3060A8),
            accent:              $yellow,
            tooltip:             Palette::hex(0xFFFFCC),
            tooltipText:         $black,
            todayMarker:         Palette::hex(0xC00000),
            iconInfo:            $blue,
            iconWarning:         $yellow,
            iconError:           Palette::hex(0xC00000),
            iconQuestion:        $blue,
        );
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // Everything is a single pixel in this era.
            edge:                 1,
            thinEdge:             1,
            checkBoxSize:         12,
            checkBoxGap:          5,
            radioSize:            12,
            radioGap:             5,
            scrollBarThickness:   14,
            scrollBarMinThumb:    16,
            textBoxBorder:        2,
            listBoxBorder:        2,
            listViewBorder:       2,
            listViewHeaderHeight: 18,
            listViewRowHeight:    18,
            treeBorder:           2,
            treeRowHeight:        18,
            treeToggleSize:       10,
            tabHeight:            20,
            tabBorder:            1,
            menuBarHeight:        20,
            menuItemHeight:       18,
            menuPopupBorder:      2,
            statusBarHeight:      20,
            toolbarButtonSize:    22,
            progressBorder:       1,
            progressChunked:      false,
            fieldHeight:          20,
            fieldButtonWidth:     16,
            upDownWidth:          16,
            upDownHeight:         20,
            // Fat channel, pale knob, green marker.
            trackThickness:       14,
            trackbarThumbLong:    12,
            trackbarThumbShort:   14,
            trackbarThumbPoint:   6,
            tickLength:           4,
            dialogTitleHeight:    18,
            dialogButtonWidth:    70,
            dialogButtonHeight:   22,
            // The tab: only as wide as its contents, close at the left, zoom at
            // the right, and no minimise — BeOS didn't put one there.
            // Measured off a StyledEdit tab: 20 rows tall with a 14px box inset
            // four from the top and two from the bottom, title centred.
            captionHeight:          20,
            captionButtonSize:      14,
            captionButtonGap:       6,
            captionPadding:         4,
            captionInnerBottom:     2,
            captionTitleCentred:    true,
            captionFitsTitle:       true,
            captionLeading:         [CaptionButton::Close],
            captionTrailing:        [CaptionButton::Maximize],
            // A BeOS modal window's tab carries its title and nothing more, and
            // the tab is only as wide as the title anyway.
            dialogCaptionLeading:   [],
            dialogCaptionTrailing:  [],
            windowBorder:           3,
            windowResizeCorner:     14,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new BeOsChrome($palette, $metrics);
    }

    /**
     * BeOS shipped Swiss/Dutch (Bitstream Vera's ancestors). Helvetica is the
     * closest thing an X server reliably has.
     */
    public function fontCandidates(): array
    {
        return [
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            '-*-lucida-medium-r-normal-sans-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** Window tabs and menu titles are bold. */
    public function boldFontCandidates(): array
    {
        return ['-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1'];
    }
}
