<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;

/**
 * Everything a painter is allowed to draw, expressed as era-neutral
 * intentions: "a button in this state", "a sunken content well", "an arrow
 * pointing down". The theme's Chrome turns each into pixels.
 *
 * This is the seam the whole theming design rests on. A painter computes
 * *where* things go and *what* they say; the Chrome decides what they look
 * like. Painters therefore never touch {@see Renderer::setForeground()} with
 * a literal colour, and never hand-roll a bevel.
 *
 * Implementations are stateless and shared, exactly like the painters — they
 * receive the Renderer per call so the same Chrome can draw into the main
 * window and the dialog window.
 */
interface Chrome
{
    /** The palette. */
    public function palette(): Palette;

    /** The metrics. */
    public function metrics(): Metrics;

    // -------------------------------------------------------------------------
    // Surfaces and borders
    // -------------------------------------------------------------------------

    /** Flat fill of a semantic surface (may be patterned in some themes). */
    public function fill(Renderer $r, Rect $rect, Surface $surface): void;

    /** Border only, drawn *inside* $rect. */
    public function edge(Renderer $r, Rect $rect, Edge $edge): void;

    /** {@see fill()} then {@see edge()} — the common case. */
    public function panel(Renderer $r, Rect $rect, Surface $surface, Edge $edge): void;

    /**
     * Sunken content area: the frame plus its interior fill. Used by text
     * boxes, list boxes, tree/list views and combo fields.
     */
    public function well(Renderer $r, Rect $rect): void;

    // -------------------------------------------------------------------------
    // Controls
    // -------------------------------------------------------------------------

    /** Push button chrome (face + border), label drawn by the caller. */
    public function button(Renderer $r, Rect $rect, ControlState $state): void;

    /**
     * Does {@see button()} draw a hovered button differently from an idle one?
     *
     * Asked so a handler can skip a repaint that would change nothing. None of
     * the shipped themes do — Windows 95 and CDE buttons don't react to the
     * pointer at all, and that only arrived with XP — so tracking hover on a
     * plain button was costing a repaint per mouse move for no visible effect.
     *
     * Toolbar buttons are a separate matter: {@see toolbarButton()} does react,
     * which is why its handler still repaints.
     */
    public function rendersButtonHover(): bool;

    /** Flat toolbar button — usually only decorated when hovered/pressed. */
    public function toolbarButton(Renderer $r, Rect $rect, ControlState $state, bool $checked): void;

    /** Column header cell in a details-mode list. */
    public function headerCell(Renderer $r, Rect $rect, ControlState $state): void;

    /** One tab in a tab strip. $rect is the full tab shape including any lift. */
    public function tab(Renderer $r, Rect $rect, bool $active): void;

    /** The page panel a tab strip sits on. */
    public function tabPanel(Renderer $r, Rect $rect): void;

    /**
     * A drop-down field: the closed control of a combo box, drop-down or date
     * picker. Returns the content rectangle its caller should draw text into.
     *
     * The whole structure is the theme's to decide, not just its colours —
     * Windows and Motif sink the field and bevel a button into its right edge,
     * while Mac OS draws a *push button* with a stacked-arrow box. $editable
     * says whether the caller will show a caret in the content area, which is
     * what decides between a button face and a white well.
     */
    public function dropField(Renderer $r, Rect $rect, bool $open, bool $editable): Rect;

    /** Text role for the content area {@see dropField()} handed back. */
    public function dropFieldTextStyle(bool $editable): TextStyle;

    /** Draws a checkbox, ticked or not. Whether it sinks, raises or fills is the era's business. */
    public function checkBox(Renderer $r, Rect $rect, bool $checked, ControlState $state): void;

    /** Draws a radio button, chosen or not. */
    public function radioButton(Renderer $r, Rect $rect, bool $checked, ControlState $state): void;

    /** The [+]/[−] (or triangle) expander in a tree row. */
    public function treeToggle(Renderer $r, Rect $rect, bool $expanded, bool $onSelection): void;

    // -------------------------------------------------------------------------
    // Scrolling and sliders
    // -------------------------------------------------------------------------

    /**
     * Scrollbar trough. $scrollable is false when the content fits, which some
     * themes show differently — Mac OS 9 pales the trough right out and greys
     * its arrows rather than drawing a full-length thumb.
     */
    public function scrollTrack(Renderer $r, Rect $rect, bool $horizontal, bool $scrollable): void;

    /** Draws the box that rides a scrollbar's trough. */
    public function scrollThumb(Renderer $r, Rect $rect, bool $horizontal): void;

    /** Groove a slider's thumb rides along. */
    public function sliderTrack(Renderer $r, Rect $rect, bool $horizontal): void;

    /**
     * Slider thumb. $point says which way the chevron faces; themes without
     * a chevron just draw a raised block.
     */
    public function sliderThumb(Renderer $r, Rect $rect, Direction $point, bool $focused): void;

    /** Tick mark beside a slider. */
    public function tick(Renderer $r, Rect $rect): void;

    /**
     * Filled portion of a progress bar. The painter passes the already-clipped
     * region; the theme decides continuous vs. segmented.
     */
    public function progressFill(Renderer $r, Rect $rect, bool $horizontal): void;

    // -------------------------------------------------------------------------
    // Menus, popups, bars
    // -------------------------------------------------------------------------

    /** Popup body + frame for a menu. */
    public function menuPopup(Renderer $r, Rect $rect): void;

    /** Highlight behind the hovered/open menu item. */
    public function menuItemHighlight(Renderer $r, Rect $rect): void;

    /** Which text style a highlighted menu item's label should use. */
    public function menuHighlightTextStyle(): TextStyle;

    /** The always-visible menu strip across the top of a window. */
    public function menuBar(Renderer $r, Rect $rect): void;

    /** Popup body + frame for a non-menu popup (calendar). */
    public function popupFrame(Renderer $r, Rect $rect): void;

    /** Etched divider. A 1-pixel-thin $rect is treated as the line itself. */
    public function separator(Renderer $r, Rect $rect, bool $horizontal): void;

    /** Window/dialog caption: background plus the title text. */
    /**
     * The frame around a dialog or an application window of the toolkit's own
     * making — the message box, the file dialog, a {@see \Cyrnetix\X11\UI\Widget\FormWindow}.
     *
     * Distinct from {@see edge()} with {@see Edge::Raised} because an era may
     * frame a dialog differently from a raised panel: Windows 3.1 draws a thick
     * flat band in the *caption* colour, where its panels are bevelled grey.
     */
    public function dialogFrame(Renderer $r, Rect $rect): void;


    /**
     * Caption background only, for a window whose buttons and title the painter
     * places itself (see {@see \Cyrnetix\X11\UI\Painter\WindowFramePainter}).
     */
    public function caption(Renderer $r, Rect $rect, bool $active): void;

    /**
     * The strip left over beside a caption that doesn't span the window (see
     * {@see Metrics::$captionFitsTitle}). Empty for full-width captions.
     */
    public function captionSurround(Renderer $r, Rect $band): void;

    /**
     * Drag texture inside the caption — Mac OS 9's pinstripes. Drawn as a
     * separate pass over the band *between* the caption's buttons, so the
     * texture keeps its distance from them instead of running underneath.
     */
    public function captionGrab(Renderer $r, Rect $band, bool $active): void;

    /**
     * Caption title inside $rect — the space left between the button groups.
     * The theme decides the alignment, since that's era, not layout.
     */
    public function captionTitle(Renderer $r, Rect $rect, string $title, bool $active): void;

    /** One caption control, drawn to fill $rect. */
    public function captionButton(
        Renderer $r,
        Rect $rect,
        CaptionButton $button,
        ControlState $state,
        bool $active,
    ): void;

    /**
     * The resize border around the whole window, drawn as a ring so the client
     * area inside it is left untouched.
     */
    public function windowBorder(Renderer $r, Rect $rect): void;

    /**
     * Frame drawn just outside the client area, between it and the window
     * furniture. Only themes with a non-zero {@see Metrics::$windowClientEdge}
     * draw anything here.
     */
    public function clientEdge(Renderer $r, Rect $content): void;

    /** Resize grip in the corner of a status bar. */
    public function grip(Renderer $r, Rect $rect): void;

    /** Drag grip at the left edge of a rebar band. */
    public function bandGrip(Renderer $r, Rect $rect): void;

    /** Tooltip body + frame. */
    public function tooltip(Renderer $r, Rect $rect): void;

    // -------------------------------------------------------------------------
    // Glyphs and text
    // -------------------------------------------------------------------------

    /**
     * Solid triangle centred in $rect.
     *
     * @param array{int,int,int} $color
     * @param int|null           $size  Steps in the triangle; null = metrics default.
     */
    public function arrow(Renderer $r, Rect $rect, Direction $direction, array $color, ?int $size = null): void;

    /**
     * Check mark centred in $rect (menu item marks, checkbox interiors).
     *
     * @param array{int,int,int} $color
     */
    public function checkGlyph(Renderer $r, Rect $rect, array $color): void;

    /** Sort direction indicator in a column header. */
    public function sortIndicator(Renderer $r, Rect $rect, bool $ascending): void;

    /** Text caret in an editable field. */
    public function caret(Renderer $r, int $x, int $top, int $height): void;

    /** Keyboard-focus indicator drawn around $rect. */
    public function focusRect(Renderer $r, Rect $rect): void;

    /** Severity glyph for a message box. */
    public function messageIcon(Renderer $r, Rect $rect, MessageIcon $icon): void;

    /**
     * Draw a text run in the given role. Themes apply their own treatment —
     * Win9x engraves disabled labels, CDE simply greys them.
     *
     * Only the glyph pixels are touched, so the caller owes nothing about the
     * surface underneath: whatever was painted there shows between the
     * strokes, and a label can't paint over the border of the control it sits
     * in.
     */
    public function text(
        Renderer $r,
        string $text,
        int $x,
        int $baselineY,
        TextStyle $style,
    ): void;
}
