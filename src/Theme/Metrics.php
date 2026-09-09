<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * Every layout number a theme is allowed to move. Widgets read these for
 * hit-testing and painters read the same values for drawing, so the two can
 * never drift apart when a theme changes sizes — a Motif scrollbar really is
 * wider, and its track really does hit-test wider.
 *
 * Defaults are the Windows 9x/2000 measurements the toolkit shipped with, so
 * `new Metrics()` reproduces the original look exactly and a theme overrides
 * only the handful of values it actually changes.
 */
final class Metrics
{
    /** Every measurement, each defaulting to the Windows 9x value, so a theme overrides only what it moves. */
    public function __construct(
        // ---- Bevels -----------------------------------------------------
        /** Thickness of a Raised/Sunken edge. */
        public readonly int $edge = 2,
        /** Thickness of a RaisedThin/SunkenThin edge. */
        public readonly int $thinEdge = 1,
        /** How far a label/icon shifts down-right while pressed. */
        public readonly int $pressOffset = 1,

        // ---- Checkbox / radio ------------------------------------------
        public readonly int $checkBoxSize = 13,
        public readonly int $checkBoxGap  = 4,
        public readonly int $radioSize    = 13,
        public readonly int $radioGap     = 4,

        // ---- Scrollbar --------------------------------------------------
        /** Width of a vertical bar / height of a horizontal one; also the arrow cap size. */
        public readonly int $scrollBarThickness = 16,
        public readonly int $scrollBarMinThumb  = 14,
        /**
         * true = both arrows sit together at the far end of the bar, the way
         * Mac OS 8/9 arranged them; false = one at each end.
         */
        public readonly bool $scrollBarArrowsTogether = false,

        // ---- Text entry -------------------------------------------------
        public readonly int $textBoxBorder  = 2,
        public readonly int $textBoxPadding = 3,

        // ---- ListBox ----------------------------------------------------
        public readonly int $listBoxBorder      = 2,
        public readonly int $listBoxItemPadding = 4,

        // ---- ListView ---------------------------------------------------
        public readonly int $listViewBorder       = 2,
        public readonly int $listViewHeaderHeight = 20,
        public readonly int $listViewRowHeight    = 18,
        public readonly int $listViewIconWidth    = 16,
        public readonly int $listViewIconGap      = 4,
        public readonly int $listViewCellPadding  = 4,

        // ---- TreeView ---------------------------------------------------
        public readonly int $treeBorder     = 2,
        public readonly int $treeRowHeight  = 18,
        public readonly int $treeIndent     = 16,
        public readonly int $treeToggleSize = 9,
        public readonly int $treeToggleGap  = 4,
        public readonly int $treeIconSize   = 16,

        // ---- Tabs -------------------------------------------------------
        public readonly int $tabHeight     = 22,
        public readonly int $tabBorder     = 2,
        /** Horizontal inset of each tab's sloped sides; 0 = square tabs. */
        public readonly int $tabSlant      = 0,
        /** Space either side of a tab's label. */
        public readonly int $tabLabelPadding = 10,
        /** How far the active tab grows past the panel's top border. */
        public readonly int $tabActiveLift = 2,

        // ---- GroupBox ---------------------------------------------------
        public readonly int $groupBoxPaddingX     = 8,
        public readonly int $groupBoxPaddingTop   = 18,
        public readonly int $groupBoxTitleOffsetX = 8,

        // ---- Menus ------------------------------------------------------
        public readonly int $menuBarHeight        = 20,
        public readonly int $menuBarLabelPadding  = 8,
        public readonly int $menuItemHeight       = 18,
        public readonly int $menuSeparatorHeight  = 6,
        public readonly int $menuIconColumnWidth  = 20,
        public readonly int $menuShortcutGap      = 20,
        public readonly int $menuRightPad         = 10,
        public readonly int $menuRightPadSubmenu  = 16,
        public readonly int $menuPopupBorder      = 2,

        // ---- Status bar -------------------------------------------------
        public readonly int $statusBarHeight           = 22,
        public readonly int $statusBarGripWidth        = 16,
        public readonly int $statusBarPaneBorder       = 2,
        public readonly int $statusBarPanePadding      = 4,
        public readonly int $statusBarPaneDefaultWidth = 80,

        // ---- Toolbar ----------------------------------------------------
        public readonly int $toolbarButtonSize      = 22,
        public readonly int $toolbarIconSize        = 16,
        public readonly int $toolbarSeparatorWidth  = 6,
        public readonly int $toolbarPadding         = 1,

        // ---- Rebar ------------------------------------------------------
        public readonly int $rebarGripWidth  = 8,
        public readonly int $rebarRowPad     = 2,
        public readonly int $rebarBandGap    = 1,
        public readonly int $rebarCaptionGap = 4,

        // ---- Progress bar -----------------------------------------------
        public readonly int  $progressBorder          = 2,
        public readonly int  $progressSegmentWidth    = 7,
        public readonly int  $progressSegmentGap      = 1,
        public readonly int  $progressMarqueeSegments = 5,
        /** false = one continuous bar instead of Win9x's segment stack. */
        public readonly bool $progressChunked         = true,

        // ---- Combo / dropdown / date field ------------------------------
        public readonly int $fieldHeight      = 22,
        public readonly int $fieldButtonWidth = 18,
        public readonly int $fieldBorder      = 2,
        public readonly int $fieldPadding     = 3,
        /** Left inset of the date picker's first segment. */
        public readonly int $datePickerPadding = 4,

        // ---- Calendar popup ---------------------------------------------
        public readonly int $calendarHeaderHeight    = 24,
        public readonly int $calendarDowHeight       = 18,
        public readonly int $calendarCellWidth       = 28,
        public readonly int $calendarCellHeight      = 22,
        public readonly int $calendarNavButtonWidth  = 22,

        // ---- Up-down spinner --------------------------------------------
        public readonly int $upDownWidth  = 16,
        public readonly int $upDownHeight = 18,

        // ---- Rounded corners --------------------------------------------
        /**
         * Corner radius for controls a theme rounds — buttons, fields, thumbs.
         * Zero, the default, means square, which is every era before the 2010s.
         */
        public readonly int $cornerRadius = 0,
        /**
         * Corner radius of the *window* itself, cut with the SHAPE extension.
         *
         * Separate from {@see $cornerRadius} because they are different
         * mechanisms with different costs: a control's corner is drawn, while a
         * window's is a hole cut in the window, and a server without SHAPE
         * cannot have one at all. Zero leaves the window a plain rectangle.
         */
        public readonly int $windowCornerRadius = 0,

        // ---- Canvas -----------------------------------------------------
        /**
         * Frame a {@see \Cyrnetix\X11\UI\Widget\Canvas} sinks its image behind.
         * Read by the widget to place the image and by the painter to blit it,
         * so a theme with a thinner edge really does show more pixels.
         */
        public readonly int $canvasBorder = 2,

        // ---- Trackbar ---------------------------------------------------
        public readonly int $trackThickness      = 4,
        public readonly int $trackbarThumbLong   = 12,
        public readonly int $trackbarThumbShort  = 22,
        /** Chevron point height; 0 gives a plain rectangular thumb. */
        public readonly int $trackbarThumbPoint  = 5,
        public readonly int $tickLength          = 4,
        public readonly int $tickGap             = 2,

        // ---- Dialog / message box ---------------------------------------
        // The dialog *window* size stays fixed (MessageBox::WIDTH/HEIGHT) —
        // it's a real X11 window created at setup, so themes size only its
        // contents.
        public readonly int $dialogTitleHeight  = 20,
        public readonly int $dialogButtonWidth  = 55,
        public readonly int $dialogButtonHeight = 20,
        public readonly int $dialogButtonGap    = 8,
        public readonly int $dialogIconRadius   = 12,

        // ---- File dialog -------------------------------------------------
        // Unlike the message box this one is a panel inside the window, so it
        // *can* be sized by the theme. Width/height are a preference: the
        // dialog shrinks to fit a small window and never grows past these.
        public readonly int $fileDialogWidth        = 560,
        public readonly int $fileDialogHeight       = 400,
        public readonly int $fileDialogPadding      = 10,
        public readonly int $fileDialogGap          = 8,
        public readonly int $fileDialogPlacesWidth  = 132,
        public readonly int $fileDialogCrumbPadding = 8,
        public readonly int $fileDialogCrumbGap     = 2,
        public readonly int $fileDialogButtonWidth  = 76,
        public readonly int $fileDialogNavWidth     = 46,
        // Name / Modified take what's left; Size is the one that can be fixed
        // because a formatted size never gets wider than "999.9 GB".
        public readonly int $fileDialogSizeColumn   = 76,
        public readonly int $fileDialogDateColumn   = 116,

        // ---- Window frame (when the toolkit draws its own caption) -------
        public readonly int  $captionHeight       = 20,
        public readonly int  $captionButtonSize   = 14,
        public readonly int  $captionButtonGap    = 2,
        public readonly int  $captionPadding      = 2,
        /**
         * Rows kept clear at the *bottom* of the caption, below the buttons and
         * the drag texture. Mac OS 9 leaves four so the bar reads as taller than
         * its furniture; Windows and Motif centre theirs and leave none.
         */
        public readonly int  $captionInnerBottom  = 0,
        /** Gap between the button groups and the title text. */
        public readonly int  $captionTitleGap     = 4,
        /**
         * Inset of the title from the caption's own edge, where no button
         * intervenes.
         *
         * Zero, because every era up to now put a button at the leading end — a
         * window-menu box or a close box — and the title was spaced off *that*
         * by {@see $captionTitleGap}. A theme with no leading button had its
         * title flush against the frame, which nobody noticed until one arrived
         * that has none.
         *
         * Applies at both ends, so a centred title stays centred in what is
         * left.
         */
        public readonly int  $captionTitleMargin  = 0,
        public readonly bool $captionTitleCentred = false,
        /**
         * true = the caption is only as wide as its buttons and title, leaving
         * the rest of the window's top edge alone. That's BeOS's yellow tab; the
         * other eras run their caption the full width.
         */
        public readonly bool $captionFitsTitle    = false,
        /**
         * Buttons at the left end of the caption.
         * @var list<CaptionButton>
         */
        public readonly array $captionLeading = [CaptionButton::Menu],
        /**
         * Buttons at the right end, in left-to-right order.
         * @var list<CaptionButton>
         */
        public readonly array $captionTrailing = [
            CaptionButton::Minimize,
            CaptionButton::Maximize,
            CaptionButton::Close,
        ],
        /**
         * Buttons on a **dialog's** caption — a form, the file picker, a message
         * box — at the left and the right end.
         *
         * A dialog draws the very same caption as the window behind it; what
         * differs is what it can do. It cannot be minimised or maximised, so the
         * window's set would offer two buttons that do nothing. The default is
         * the Windows answer: a close box and nothing else.
         *
         * A theme whose era gave a dialog no widgets at all says so with two
         * empty lists — Mac OS 9's movable modal and BeOS's modal tab carry a
         * title and nothing more — and one that closed a dialog by
         * double-clicking its window-menu box (Windows 3.1) leads with Menu and
         * trails with nothing.
         *
         * @var list<CaptionButton>
         */
        public readonly array $dialogCaptionLeading = [],
        /** @var list<CaptionButton> */
        public readonly array $dialogCaptionTrailing = [CaptionButton::Close],
        /** Thickness of the resize border drawn around the window. */
        public readonly int  $windowBorder = 4,
        /**
         * Extra frame drawn immediately around the client area, inside both the
         * border and the caption. Mac OS 9 rules its content off from the window
         * furniture this way; Windows and Motif don't.
         */
        public readonly int  $windowClientEdge = 0,
        /** Size of the corner grab areas. */
        public readonly int  $windowResizeCorner = 16,
        /**
         * false = the window can only be resized from its corners, the way
         * Mac OS did it with its grow box.
         */
        public readonly bool $windowResizeFromEdges = true,

        // ---- Tooltip ----------------------------------------------------
        public readonly int $tooltipPadX = 4,
        public readonly int $tooltipPadY = 2,

        // ---- Glyphs -----------------------------------------------------
        /** Rows/columns in a full-size arrow triangle. */
        public readonly int $arrowSize      = 4,
        /** Rows/columns in a compact arrow (sort indicators, spinners). */
        public readonly int $smallArrowSize = 3,
    ) {}

    /**
     * Width a tab-style caption wants: its padding, buttons, and the title.
     * Returns 0 when the caption spans its host instead (see
     * {@see $captionFitsTitle}), so callers can treat 0 as "full width".
     *
     * Shared by the window frame and the message box so the two can't disagree
     * about where a tab ends — which matters, because that edge is also where the
     * window gets cut away.
     */
    public function captionTabWidth(int $titleWidth, int $buttonCount): int
    {
        if (!$this->captionFitsTitle) return 0;

        $size = min(
            $this->captionButtonSize,
            max(0, $this->captionHeight - $this->captionPadding - $this->captionInnerBottom),
        );

        return 2 * $this->captionPadding
            + $buttonCount * ($size + $this->captionButtonGap)
            + 2 * $this->captionTitleGap
            + $titleWidth;
    }

    /** Height of one half of an up-down spinner. */
    public function upDownArrowHeight(): int
    {
        return intdiv($this->upDownHeight, 2);
    }

    /** Distance from a progress segment's start to the next one's. */
    public function progressSegmentStep(): int
    {
        return $this->progressSegmentWidth + $this->progressSegmentGap;
    }

    /** Shared default set — used by widgets that aren't attached to a tree yet. */
    public static function defaults(): self
    {
        static $defaults = null;
        return $defaults ??= new self();
    }
}
