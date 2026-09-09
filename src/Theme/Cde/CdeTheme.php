<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Cde;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * CDE / Motif, as shipped on Solaris (and every other 90s Unix workstation).
 *
 * The palette is the default CDE blue-grey: #AEB2C3 face with a bright
 * #DFDFE0 top shadow and a near-black #55555F bottom shadow. Content areas are
 * a light blue-grey rather than white, troughs are *darker* than the face, and
 * the accent is CDE's teal.
 *
 * Motif widgets are physically chunkier than their Windows counterparts, so
 * this theme moves real measurements: taller menu bars and rows, 18px
 * scrollbars, 15px toggles, thicker sliders.
 */
final class CdeTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'cde'; }
    /** The name. */
    public function name(): string { return 'CDE / Motif'; }

    /** Builds the palette. */
    protected function buildPalette(string $variant): Palette
    {
        $face      = Palette::hex(0xAEB2C3);
        $topShadow = Palette::hex(0xDFDFE0);
        $light     = Palette::hex(0xC6CAD9);
        $botShadow = Palette::hex(0x55555F);
        $dkShadow  = Palette::hex(0x3A3A42);
        $black     = Palette::hex(0x000000);
        $teal      = Palette::hex(0x1F7A78);
        $content   = Palette::hex(0xDDDFE6);

        return new Palette(
            face:                $face,
            faceHighlight:       $topShadow,
            faceLight:           $light,
            faceShadow:          $botShadow,
            faceDarkShadow:      $dkShadow,
            text:                $black,
            textDisabled:        Palette::hex(0x74788A),
            textEmboss:          $topShadow,
            textDim:             Palette::hex(0x7C8092),
            content:             $content,
            contentText:         $black,
            selection:           Palette::hex(0x4A5578),
            selectionText:       Palette::hex(0xFFFFFF),
            bar:                 $face,
            menu:                $face,
            menuText:            $black,
            // Unused for fills — armed items rise instead (see CdeChrome) —
            // but kept coherent for anything that asks for the colour.
            menuHighlight:       $light,
            menuHighlightText:   $black,
            // Motif troughs are darker than the face, the opposite of Windows.
            track:               Palette::hex(0x8E92A4),
            progressTrough:      Palette::hex(0x8E92A4),
            progressBar:         $teal,
            captionActive:       Palette::hex(0xBFC3D4),
            captionActiveEnd:    Palette::hex(0xBFC3D4),
            captionActiveText:   $black,
            captionInactive:     $face,
            captionInactiveEnd:  $face,
            captionInactiveText: Palette::hex(0x5A5E70),
            frame:               $dkShadow,
            focus:               $black,
            accent:              $teal,
            tooltip:             Palette::hex(0xFFFFCC),
            tooltipText:         $black,
            todayMarker:         Palette::hex(0xA02020),
            iconInfo:            $teal,
            iconWarning:         Palette::hex(0xC69214),
            iconError:           Palette::hex(0xA02020),
            iconQuestion:        $teal,
        );
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // Motif's shadowThickness = 2, drawn uniformly.
            edge:                    2,
            checkBoxSize:            15,
            checkBoxGap:             6,
            radioSize:               15,
            radioGap:                6,
            scrollBarThickness:      18,
            scrollBarMinThumb:       16,
            listViewHeaderHeight:    22,
            listViewRowHeight:       20,
            treeRowHeight:           20,
            treeToggleSize:          11,
            tabHeight:               26,
            menuBarHeight:           24,
            menuBarLabelPadding:     10,
            menuItemHeight:          22,
            menuSeparatorHeight:     8,
            menuPopupBorder:         3,
            statusBarHeight:         24,
            statusBarPanePadding:    6,
            toolbarButtonSize:       24,
            toolbarSeparatorWidth:   8,
            toolbarPadding:          2,
            progressBorder:          2,
            // One continuous gauge, no segments.
            progressChunked:         false,
            fieldHeight:             24,
            fieldButtonWidth:        20,
            calendarHeaderHeight:    26,
            upDownWidth:             18,
            upDownHeight:            22,
            trackThickness:          6,
            trackbarThumbLong:       14,
            trackbarThumbShort:      24,
            // Rectangular XmScale slider, no chevron.
            trackbarThumbPoint:      0,
            tickLength:              5,
            dialogTitleHeight:       24,
            dialogButtonWidth:       62,
            dialogButtonHeight:      24,
            tooltipPadX:             6,
            tooltipPadY:             3,
            arrowSize:               5,
            // dtwm's title area: tall, centred title, window-menu button at the
            // left, and a chunky frame with visible corner handles.
            captionHeight:           26,
            captionButtonSize:       20,
            captionPadding:          3,
            captionTitleCentred:     true,
            captionLeading:          [CaptionButton::Menu],
            captionTrailing:         [
                CaptionButton::Minimize,
                CaptionButton::Maximize,
                CaptionButton::Close,
            ],
            // A Motif dialog keeps the window-menu button and loses the rest;
            // its own buttons are the way out.
            dialogCaptionLeading:    [CaptionButton::Menu],
            dialogCaptionTrailing:   [],
            windowBorder:            6,
            windowResizeCorner:      22,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new CdeChrome($palette, $metrics);
    }

    /** CDE's interface font, then plain Helvetica, then whatever exists. */
    public function fontCandidates(): array
    {
        return [
            '-dt-interface user-medium-r-normal-s*-*-*-*-*-*-*-*-*',
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** dtwm draws window titles bold. */
    public function boldFontCandidates(): array
    {
        return [
            '-dt-interface user-bold-r-normal-s*-*-*-*-*-*-*-*-*',
            '-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
        ];
    }
}
