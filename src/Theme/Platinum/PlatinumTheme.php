<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Platinum;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * Mac OS 9 — "Apple platinum".
 *
 * Every colour here was sampled out of Mac OS 9.0 screenshots rather than
 * recalled, which is why the values are the flat grey ramp (#EEE / #DDD / #CCC /
 * #BBB / #AAA / #999 / #888 / #777) with black outlines and no gradients.
 *
 * Two of them are worth calling out because they contradict the Windows
 * conventions the toolkit grew up with:
 *
 *  - **Selections are pale lavender (#CCCCFF) and keep their text black.** The
 *    Appearance control panel called this "Purple … for selected text".
 *  - **Menus do the opposite**: an open menu title and a highlighted item
 *    reverse to white on #333399, the "Lavender … for menus and controls"
 *    variation. Hence the separate menu roles.
 *
 * The menu bar is white, content wells are white with a black frame, tab panels
 * are #EEEEEE, and progress bars fill with a blue ramp that has a bright line
 * through its middle.
 */
final class PlatinumTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'platinum'; }
    /** The name. */
    public function name(): string { return 'Mac OS 9 Platinum'; }

    /** The Mac OS 9 system icons, decoded from PNG and box-scaled to 16px. */
    public function iconSet(): ?string { return 'macos90'; }

    /** Builds the palette. */
    protected function buildPalette(): Palette
    {
        $white   = Palette::hex(0xFFFFFF);
        $gray1   = Palette::hex(0xEEEEEE);   // tab panels, scrollbar troughs
        $face    = Palette::hex(0xDDDDDD);   // windows, dialogs, buttons, menus
        $caption = Palette::hex(0xCCCCCC);   // title bars, column headers
        $trough  = Palette::hex(0xBBBBBB);   // progress trough
        $shadow  = Palette::hex(0xAAAAAA);
        $dark    = Palette::hex(0x777777);
        $black   = Palette::hex(0x000000);

        $lavender  = Palette::hex(0xCCCCFF);  // "Purple", for selected text
        $menuBlue  = Palette::hex(0x333399);  // "Lavender", for menus
        $progress  = Palette::hex(0x9999FF);

        return new Palette(
            face:                $face,
            faceHighlight:       $white,
            faceLight:           $gray1,
            faceShadow:          $shadow,
            faceDarkShadow:      $dark,
            text:                $black,
            textDisabled:        Palette::hex(0x999999),
            textEmboss:          $white,
            textDim:             Palette::hex(0x888888),
            content:             $white,
            contentText:         $black,
            // A tint, with the label left black — the Finder's own convention.
            selection:           $lavender,
            selectionText:       $black,
            // Tab pages are a shade lighter than the window they sit in.
            panel:               $gray1,
            bar:                 $face,
            // The classic Mac menu bar is white.
            menuBar:             $white,
            menu:                $face,
            menuText:            $black,
            // …but menus reverse, unlike list selections.
            menuHighlight:       $menuBlue,
            menuHighlightText:   $white,
            track:               $gray1,
            progressTrough:      $trough,
            progressBar:         $progress,
            captionActive:       $caption,
            captionActiveEnd:    $caption,
            captionActiveText:   $black,
            captionInactive:     $caption,
            captionInactiveEnd:  $caption,
            captionInactiveText: Palette::hex(0x888888),
            frame:               $black,
            focus:               $menuBlue,
            accent:              $menuBlue,
            tooltip:             Palette::hex(0xFFFFCC),
            tooltipText:         $black,
            todayMarker:         Palette::hex(0xCC0000),
            iconInfo:            $menuBlue,
            iconWarning:         Palette::hex(0xE0A020),
            iconError:           Palette::hex(0xC03030),
            iconQuestion:        $menuBlue,
        );
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // Controls are outlined in black with a 2px shaded inset, so the
            // structural "edge" is thin and the wells only need a hairline.
            edge:                 2,
            thinEdge:             1,
            checkBoxSize:         12,
            checkBoxGap:          5,
            radioSize:            12,
            radioGap:             5,
            scrollBarThickness:   15,
            scrollBarMinThumb:    16,
            // Mac OS 8.5 onwards parked both arrows at the far end of the bar.
            scrollBarArrowsTogether: true,
            textBoxBorder:        2,
            listBoxBorder:        2,
            listViewBorder:       2,
            listViewHeaderHeight: 17,
            listViewRowHeight:    17,
            treeBorder:           2,
            treeRowHeight:        17,
            treeToggleSize:       10,
            // Trapezoid tabs whose sides slope in by 6px over their height.
            tabHeight:            20,
            tabBorder:            1,
            tabSlant:             6,
            tabActiveLift:        0,
            menuBarHeight:        20,
            menuItemHeight:       18,
            menuPopupBorder:      2,
            statusBarHeight:      20,
            toolbarButtonSize:    22,
            // One smooth blue bar, never a segment stack.
            progressBorder:       1,
            progressChunked:      false,
            fieldHeight:          20,
            fieldButtonWidth:     16,
            fieldPadding:         3,
            calendarHeaderHeight: 22,
            upDownWidth:          15,
            upDownHeight:         20,
            // Thin channel, and a shield-shaped thumb that points at the ticks.
            trackThickness:       5,
            trackbarThumbLong:    14,
            trackbarThumbShort:   16,
            trackbarThumbPoint:   5,
            tickLength:           4,
            dialogTitleHeight:    20,
            dialogButtonWidth:    62,
            dialogButtonHeight:   20,
            // Caption: title centred, close box at the *left*, and zoom then
            // collapse at the right — the real Mac OS 9 order.
            captionHeight:          16,
            captionButtonSize:      12,
            captionButtonGap:       4,
            // Boxes sit flush against the frame and share the top twelve rows
            // with the pinstripes; the bottom four stay plain.
            captionPadding:         0,
            captionInnerBottom:     4,
            captionTitleCentred:    true,
            captionLeading:         [CaptionButton::Close],
            captionTrailing:        [CaptionButton::Maximize, CaptionButton::Minimize],
            // A movable modal dialog in Mac OS 9 has the racing stripes and no
            // widgets at all: no close box, no zoom, no collapse.
            dialogCaptionLeading:   [],
            dialogCaptionTrailing:  [],
            // Six-tone window edge, and a window that only resizes from its
            // grow box, exactly as Mac OS did.
            windowBorder:           4,
            windowClientEdge:       2,
            windowResizeCorner:     16,
            windowResizeFromEdges:  false,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new PlatinumChrome($palette, $metrics);
    }

    /**
     * Charcoal — Mac OS 9's UI font — isn't on an X server. Lucida is the
     * closest in weight and width; a bold Helvetica is the next best stand-in.
     */
    public function fontCandidates(): array
    {
        return [
            '-*-lucida-medium-r-normal-sans-12-*-*-*-*-*-iso8859-1',
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** Window titles are bold in Mac OS 9 — Charcoal Bold, in the original. */
    public function boldFontCandidates(): array
    {
        return [
            '-*-lucida-bold-r-normal-sans-12-*-*-*-*-*-iso8859-1',
            '-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
        ];
    }
}
