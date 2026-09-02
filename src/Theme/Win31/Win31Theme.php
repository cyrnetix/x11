<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Win31;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * Windows 3.1x, in its default colour scheme.
 *
 * Every value here was sampled from screenshots — the PIF Editor, File Manager
 * and the common Open dialog — rather than recalled, because the era disagrees
 * with Windows 95 in ways that are easy to assume away:
 *
 * - **Menus are white**, both the bar and the popups, with one black rule under
 *   the bar. It's the loudest difference on screen and the one most often got
 *   wrong.
 * - **An inactive title bar is white with black text**, not grey and not a
 *   dimmed blue. The active one is a flat `#000080` with the title *centred* in
 *   bold white.
 * - **There is no close button.** The box at the left is the window menu;
 *   double-clicking it closes the window, which is what the whole era did.
 * - **Greys are three, not five**: `#C0C0C0`, `#808080`, `#000000`, plus white.
 *   No `#DFDFDF` light layer and no `#404040` — a raised control is outlined in
 *   black with two pixels of white and two of `#808080` inside it.
 *
 * The desktop, the face and the scrollbar trough are all `#C0C0C0` in this
 * scheme, which is why the shots read as flatter than Windows 95's.
 */
final class Win31Theme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'win31'; }
    /** The name. */
    public function name(): string { return 'Windows 3.1x'; }

    /** Builds the palette. */
    protected function buildPalette(): Palette
    {
        $black  = [  0,   0,   0];
        $white  = [255, 255, 255];
        $face   = [192, 192, 192];
        $shadow = [128, 128, 128];
        $navy   = [  0,   0, 128];

        return new Palette(
            face:           $face,
            faceHighlight:  $white,
            // The era has no light-grey bevel layer, so anything reaching for
            // one gets white — which is what 3.1 would have drawn there.
            faceLight:      $white,
            faceShadow:     $shadow,
            // Win95's #404040 doesn't exist here: the hard edge is pure black.
            faceDarkShadow: $black,
            text:           $black,
            textDisabled:   $shadow,
            textEmboss:     $white,
            textDim:        $shadow,
            content:        $white,
            contentText:    $black,
            selection:      $navy,
            selectionText:  $white,
            bar:            $face,
            // Sampled #FFFFFF in every reference shot, bar and popup alike.
            menuBar:        $white,
            menu:           $white,
            menuText:       $black,
            menuHighlight:  $navy,
            menuHighlightText: $white,
            // The scrollbar trough is the face colour, not a dithered pattern —
            // that arrived with Windows 95.
            track:          $face,
            progressTrough: $face,
            progressBar:    $navy,
            captionActive:      $navy,
            captionActiveEnd:   $navy,     // flat; the gradient is a 98/2000 thing
            captionActiveText:  $white,
            // White with black text. Not a dimmed blue, and not grey.
            captionInactive:     $white,
            captionInactiveEnd:  $white,
            captionInactiveText: $black,
            frame:          $black,
            focus:          $black,
            accent:         $navy,
            tooltip:        $white,
            tooltipText:    $black,
        );
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics
    {
        return new Metrics(
            // The dialog frame: one pixel of black, three of navy, one of
            // highlight. `edge` is what FormWindow insets its caption and its
            // content by, so it has to match what dialogFrame() actually draws.
            // A field's *own* border stays two pixels — edge(Sunken) draws its
            // two rings regardless of this number.
            edge:      5,
            thinEdge:  1,

            radioSize: 12,

            // 18 rows of white plus the black rule beneath, sampled at y=23..41
            // of the PIF Editor.
            menuBarHeight:   19,
            menuItemHeight:  18,
            // One black line, no inner highlight.
            menuPopupBorder: 1,

            // The status line's groove is a single pixel of shadow over one of
            // highlight; sampled 20 rows tall.
            statusBarHeight:     20,
            statusBarPaneBorder: 1,

            // A field is 2px of border, ~16 of white, 2px of border.
            fieldHeight: 20,
            fieldBorder: 2,

            // Setup's progress bar was a solid blue fill, not Win95's blocks.
            progressChunked: false,

            // 18 rows of navy plus the black rule closing the caption off.
            captionHeight:       19,
            // 18 rows of navy plus the rule, and the dialog's caption sits
            // inside the 5px frame: sampled at y=5..22 with the rule at 23.
            dialogTitleHeight:   19,
            captionButtonSize:   18,
            // The caption's black rule is the *only* line under a control. Leave
            // its row alone and the control cannot double it.
            captionInnerBottom:  1,
            // The buttons sit shoulder to shoulder — their black outlines are
            // what separates them, so any gap here is wrong.
            captionButtonGap:    0,
            captionPadding:      0,
            captionTitleCentred: true,
            captionLeading:      [CaptionButton::Menu],
            // No close button: 3.1 closed a window by double-clicking the
            // window-menu box at the left.
            captionTrailing:     [CaptionButton::Minimize, CaptionButton::Maximize],
            // A 3.1 dialog carries the window-menu box and nothing else - which
            // is also how you closed it, by double-clicking that box.
            dialogCaptionLeading:  [CaptionButton::Menu],
            dialogCaptionTrailing: [],
            // Sampled 000000 / C0C0C0 C0C0C0 / 000000 across the top edge.
            windowBorder:        4,
        );
    }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new Win31Chrome($palette, $metrics);
    }

    /** No icon set of its own yet, so it borrows the one that ships. */
    public function iconSet(): ?string { return 'windows2000'; }

    /**
     * 3.1 drew everything in one face — "System", a semibold bitmap with no X11
     * equivalent — so Helvetica **bold** stands in for it rather than the medium
     * weight the later themes use. Zoom in on any reference shot and the menu
     * bar, the labels and the title are all the same weight, and all heavier
     * than Windows 95's Sans Serif.
     */
    public function fontCandidates(): array
    {
        return [
            '-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
            '-*-lucida-bold-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** The same face: there was no second weight to switch to. */
    public function boldFontCandidates(): array
    {
        return ['-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1'];
    }
}
