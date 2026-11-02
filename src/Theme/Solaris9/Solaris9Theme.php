<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Solaris9;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * Solaris 9: CDE 1.5 as Sun shipped it, in the "Default" palette of its
 * Style Manager.
 *
 * It is not a recolour of the {@see \Cyrnetix\X11\Theme\Cde\CdeTheme}. That
 * theme is Motif as the textbooks draw it, with two-pixel shadows and a close
 * box; this one is what a Solaris 9 screen actually showed, and every number
 * here was read off GUIdebook's 1:1 screenshots of that release (the Style
 * Manager's Color, Window and Mouse dialogs, the File Manager, the Text
 * Editor's Open dialog, the Mailer, the Calculator) rather than recalled.
 * The differences are the whole look:
 *
 * - **The frame is rose.** The active window frame, its title bar and its
 *   buttons are `#B24D7A`, with shadows Motif computed from that colour
 *   (`#DCADC2` above, `#57253B` below). An inactive frame is the plain face.
 *   The toggles' "on" fill, the keyboard focus ring and the frame are all the
 *   same rose: CDE's colour set 1 does every one of those jobs.
 * - **Text areas are cream** (`#FFF7E9`), not white and not the face, and
 *   they are shaded from their *own* colour: `#99948B` above and to the left,
 *   `#CCC5BA` below and to the right. A selection in them is reverse video.
 * - **Shadows are one pixel.** Buttons, fields, lists, scrollbars, the menu
 *   bar, the title bar and its controls: every bevel measured one row of
 *   highlight and one of shadow. The frame alone has two outside and one in.
 *   (dtcalc's keys are the exception at two, and this is not a calculator.)
 * - **No close box.** dtwm gives a window a menu button, minimise and
 *   maximise, and nothing else; the way out is a double-click on the menu
 *   button. A dialog keeps only the menu button.
 * - **Motif 2.1 toggles**, not Motif 1.2's: round radio buttons that fill
 *   with rose, and square check boxes that sink, fill with the select colour
 *   and carry a tick.
 * - **One face, one weight.** Labels, menus and the title are the same 14 px
 *   Lucida Sans at medium weight — the title has one-pixel stems like the
 *   menu bar — so there is no bold face at all.
 *
 * The desktop behind it all is the "Iris" violet of the SolarisLogo backdrop,
 * `#574C8F`, which is what shows through beside a tab caption and nowhere
 * else here, since this caption spans the window.
 */
final class Solaris9Theme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'solaris9'; }
    /** The name. */
    public function name(): string { return 'Solaris 9'; }

    /** Builds the palette. */
    protected function buildPalette(string $variant): Palette
    {
        // The four swatches of the Style Manager's "Default" palette, and the
        // shadows the colour server derived from them, all sampled.
        $face      = Palette::hex(0xAEB2C3);
        $faceTop   = Palette::hex(0xDCDEE5);
        $faceBot   = Palette::hex(0x5D6069);
        $select    = Palette::hex(0x9397A5);   // the face darkened 15%: troughs, and a set toggle
        $rose      = Palette::hex(0xB24D7A);   // colour set 1: active frame, focus, radio fill
        $roseTop   = Palette::hex(0xDCADC2);
        $roseBot   = Palette::hex(0x57253B);
        $cream     = Palette::hex(0xFFF7E9);   // colour set 4: text areas
        $creamTop  = Palette::hex(0xCCC5BA);
        $creamBot  = Palette::hex(0x99948B);
        $violet    = Palette::hex(0x63639C);   // dtcalc's keys and the workspace switches
        $iris      = Palette::hex(0x574C8F);   // the SolarisLogo backdrop
        $black     = Palette::hex(0x000000);
        $white     = Palette::hex(0xFFFFFF);

        return new Palette(
            face:                $face,
            faceHighlight:       $faceTop,
            // Motif has no third grey: anything reaching for a light layer
            // gets the highlight, as under Windows 3.1.
            faceLight:           $faceTop,
            faceShadow:          $faceBot,
            // Nor a fourth: what asks for something darker than the shadow
            // gets the foreground.
            faceDarkShadow:      $black,
            text:                $black,
            // Motif stipples an insensitive label 50% rather than recolouring
            // it; the shadow is what that averages to on this face.
            textDisabled:        $faceBot,
            textEmboss:          $faceTop,
            textDim:             $faceBot,
            content:             $cream,
            contentText:         $black,
            contentHighlight:    $creamTop,
            contentShadow:       $creamBot,
            // XmList and XmText select by swapping foreground and background.
            selection:           $black,
            selectionText:       $cream,
            desktop:             $iris,
            bar:                 $face,
            menu:                $face,
            menuText:            $black,
            // Unused as a fill: an armed item rises instead (see the chrome).
            menuHighlight:       $face,
            menuHighlightText:   $black,
            track:               $select,
            progressTrough:      $select,
            progressBar:         $violet,
            captionActive:       $rose,
            captionActiveEnd:    $rose,
            captionActiveText:   $white,
            captionHighlight:    $roseTop,
            captionShadow:       $roseBot,
            // Sampled off the File Manager behind a dialog: the face, with
            // black text. Not grey, not a paler rose.
            captionInactive:     $face,
            captionInactiveEnd:  $face,
            captionInactiveText: $black,
            frame:               $faceBot,
            focus:               $rose,
            accent:              $rose,
            tooltip:             $cream,
            tooltipText:         $black,
            todayMarker:         $rose,
            // CDE's own message icons are black-and-white symbols; these
            // discs take the palette's two accents, and the warning keeps the
            // Motif theme's amber because nothing in this palette is yellow.
            iconInfo:            $violet,
            iconWarning:         Palette::hex(0xC69214),
            iconError:           $rose,
            iconQuestion:        $violet,
        );
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // A raised or sunken edge is one ring, whatever this says; the
            // chrome ignores it. It stays 2 because an *etched* edge is two
            // rows, and the group box clears that many behind its title.
            edge:                    2,
            thinEdge:                1,
            // A Motif label does not move when its button is armed.
            pressOffset:             0,

            // 12 px square, label 8 px on; 15 px circle, label 6 px on. From
            // the Style Manager's Window dialog.
            checkBoxSize:            12,
            checkBoxGap:             8,
            radioSize:               15,
            radioGap:                6,

            // 13 px: a one-pixel sunken ring with an 11 px slider inside it.
            scrollBarThickness:      13,
            scrollBarMinThumb:       13,

            // A field is one ring of highlight (the parent's colour, or rose
            // with the focus), one of shadow, then 23 rows of cream.
            textBoxBorder:           2,
            textBoxPadding:          4,
            listBoxBorder:           2,
            listViewBorder:          2,
            listViewHeaderHeight:    25,
            listViewRowHeight:       20,
            listViewCellPadding:     6,
            treeBorder:              2,
            treeRowHeight:           20,
            treeIndent:              18,
            treeToggleSize:          11,

            tabHeight:               26,
            tabLabelPadding:         10,
            groupBoxPaddingX:        10,
            groupBoxPaddingTop:      20,

            // 27 rows in the File Manager: highlight, 25 of face, shadow. The
            // first label starts 11 px in.
            menuBarHeight:           27,
            menuBarLabelPadding:     11,
            menuItemHeight:          25,
            menuSeparatorHeight:     8,
            menuPopupBorder:         2,

            statusBarHeight:         24,
            statusBarPaneBorder:     1,
            statusBarPanePadding:    6,

            // The Mailer's: 30 px square, one-pixel bevel, always raised.
            toolbarButtonSize:       30,
            toolbarSeparatorWidth:   8,
            toolbarPadding:          2,

            progressBorder:          2,
            progressChunked:         false,

            // A text field including its highlight ring; the combo's arrow
            // sits in a 22 px strip.
            fieldHeight:             27,
            fieldButtonWidth:        22,
            fieldPadding:            4,
            calendarHeaderHeight:    27,
            calendarDowHeight:       20,
            calendarCellWidth:       30,
            calendarCellHeight:      24,
            calendarNavButtonWidth:  24,
            upDownWidth:             18,
            upDownHeight:            27,

            // XmScale: a 15 px sunken trough with a 30 x 13 slider riding
            // inside its ring, grooved across the middle. No chevron.
            trackThickness:          15,
            trackbarThumbLong:       30,
            trackbarThumbShort:      13,
            trackbarThumbPoint:      0,
            tickLength:              5,

            // A dialog's title bar is the window's; its buttons are 25 rows
            // from highlight to shadow and the Style Manager's OK is 88 wide.
            dialogTitleHeight:       19,
            dialogButtonWidth:       88,
            dialogButtonHeight:      25,
            fileDialogButtonWidth:   88,

            tooltipPadX:             6,
            tooltipPadY:             3,
            // A Motif arrow is as wide as it is tall, so this is both; flat
            // arrows below 5 rows keep the base shape.
            arrowSize:               9,

            // dtwm's title bar: 19 rows, with 19 px buttons flush against each
            // other and against the frame, and the title panel bevelled in the
            // band between them. No close box anywhere.
            captionHeight:           19,
            captionButtonSize:       19,
            captionButtonGap:        0,
            captionPadding:          0,
            captionTitleGap:         0,
            captionTitleCentred:     true,
            captionLeading:          [CaptionButton::Menu],
            captionTrailing:         [CaptionButton::Minimize, CaptionButton::Maximize],
            dialogCaptionLeading:    [CaptionButton::Menu],
            dialogCaptionTrailing:   [],
            // Two rows of highlight, two of colour, one of shadow: 5. No
            // corner handles are drawn into it. The client area is ringed by
            // one raised pixel (dtfile, dtcalc and the Application Manager all
            // show it), and a dialog gets the same frame as a window.
            windowBorder:            5,
            windowClientEdge:        1,
            windowResizeCorner:      22,
            dialogBorder:            5,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new Solaris9Chrome($palette, $metrics);
    }

    /**
     * Sun's interface font is Lucida Sans, at 14 px here: the capitals in the
     * File Manager's menu bar and the Open dialog's labels are ten rows tall
     * with one-pixel stems. The CDE alias comes next for a server that is
     * actually running CDE, then the Motif theme's fallbacks.
     */
    public function fontCandidates(): array
    {
        return [
            '-b&h-lucida-medium-r-normal-sans-14-*-*-*-p-*-iso8859-1',
            '-dt-interface system-medium-r-normal-m*-*-*-*-*-*-*-*-*',
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /**
     * None. dtwm's title is the interface font at the same weight, sampled at
     * one-pixel stems, so the title falls back to the regular face on purpose.
     */
    public function boldFontCandidates(): array
    {
        return [];
    }
}
