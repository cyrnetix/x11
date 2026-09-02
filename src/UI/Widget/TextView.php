<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * A read-only multi-line text view: a well full of lines you can scroll,
 * select and copy, but not edit.
 *
 * Deliberately *not* a multi-line {@see TextBox}. A caret that has to move
 * between lines turns every one of that widget's character indices into a
 * (line, column) pair — selection, `pixelToCursor()`, `viewStart()`, the
 * painter's horizontal scroll — and the single-line field is the one control
 * every form in the toolkit already depends on. What was actually needed is a
 * *viewer*: show a JSON document or a block of code, let the user take it to
 * the clipboard, and never let them change it.
 *
 * **Selection is by whole lines.** Click and drag picks a range of them, Ctrl+A
 * takes the lot, and {@see copy()} hands back what's selected — or the whole
 * document when nothing is. Selecting half a line is the one thing a caret
 * would buy, and for "copy this record into a test" it buys nothing.
 *
 * Two scrollbars, for the same reason {@see ListView} has two: code lines are
 * long, and wrapping them mid-token reads far worse than scrolling. The
 * horizontal bar arrives only when the widest line overflows and takes a row
 * back when it goes.
 */
final class TextView extends Widget implements Scrollable, Focusable
{
    /** @var list<string> */
    private array $lines = [];

    /** Widest line in pixels, measured lazily against the live font. */
    private int $contentWidth = 0;
    private bool $measured    = false;

    /** Selected line range, or -1 when nothing is selected. */
    private int $anchorLine = -1;
    private int $cursorLine = -1;

    private bool $focused = false;

    private ScrollBar $scrollBar;
    private ScrollBar $hScrollBar;

    /** Takes position, size, the event dispatcher and its text. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        SyncEventDispatcher $dispatcher,
        string $text = '',
        /**
         * Rows are a fixed height, given rather than derived: the painter and
         * {@see lineAt()} must agree to the pixel, and the widget has no
         * Renderer to ask the font. {@see ListBox} takes its item height the
         * same way.
         */
        public readonly int $lineHeight = 16,
    ) {
        parent::__construct($x, $y);

        $m = $this->metrics();

        $this->scrollBar = new ScrollBar(
            ScrollOrientation::Vertical,
            0, 0, $m->scrollBarThickness, 0,
            $dispatcher,
            0, 0, 1, 1,
        );
        $this->addChild($this->scrollBar);

        // Pixels, not lines: the bar measures how far the text can slide, and
        // characters are not all one width.
        $this->hScrollBar = new ScrollBar(
            ScrollOrientation::Horizontal,
            0, 0, 0, $m->scrollBarThickness,
            $dispatcher,
            0, 0, 1, $m->scrollBarThickness,
        );
        $this->addChild($this->hScrollBar);

        $this->setText($text);
        $this->relayout();
    }

    /** Replace the whole document. Scroll and selection start over. */
    public function setText(string $text): void
    {
        $this->lines      = $text === '' ? [] : explode("\n", str_replace("\r\n", "\n", $text));
        $this->measured   = false;
        $this->anchorLine = -1;
        $this->cursorLine = -1;

        $this->scrollBar->setValue(0);
        $this->hScrollBar->setValue(0);
        $this->relayout();
    }

    /** The text. */
    public function getText(): string { return implode("\n", $this->lines); }

    /** @return list<string> */
    public function getLines(): array { return $this->lines; }

    /** The line count. */
    public function lineCount(): int { return count($this->lines); }

    /** Sets size. */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(40, $width);
        $this->height = max(40, $height);
        $this->relayout();
    }

    /**
     * Measure the widest line, once, against the live font.
     *
     * The painter has the Renderer and the widget does not, so measuring is
     * pushed in from there rather than guessed at — and cached, because a JSON
     * document is measured on every paint otherwise.
     */
    public function measure(Renderer $r): void
    {
        if ($this->measured) return;

        $widest = 0;
        foreach ($this->lines as $line) {
            $widest = max($widest, $r->measureText($line));
        }

        $this->contentWidth = $widest;
        $this->measured     = true;
        $this->relayout();
    }

    /** A theme switch changes the font, so the measurement has to go. */
    public function relayoutForFont(): void
    {
        $this->measured = false;
    }

    /** {@inheritDoc} */
    public function relayout(): void
    {
        $m       = $this->metrics();
        $scrollH = $this->needsHorizontalScroll() ? $m->scrollBarThickness : 0;

        $this->scrollBar->relX   = $this->width - $m->scrollBarThickness - $m->textBoxBorder;
        $this->scrollBar->relY   = $m->textBoxBorder;
        $this->scrollBar->width  = $m->scrollBarThickness;
        $this->scrollBar->height = $this->height - 2 * $m->textBoxBorder - $scrollH;
        $this->scrollBar->setRange(0, count($this->lines), $this->visibleLineCount());

        $this->hScrollBar->relX   = $m->textBoxBorder;
        $this->hScrollBar->relY   = $this->height - $m->scrollBarThickness - $m->textBoxBorder;
        $this->hScrollBar->width  = $this->width - 2 * $m->textBoxBorder - $m->scrollBarThickness;
        $this->hScrollBar->height = $m->scrollBarThickness;
        $this->hScrollBar->setRange(0, $this->contentWidth, $this->viewportWidth());

        $this->reresolve();
    }

    /** The bar has to leave the walk, not just the screen, or it stays clickable. */
    public function getVisibleChildren(): array
    {
        return array_values(array_filter(
            parent::getVisibleChildren(),
            fn(Widget $child): bool => $child !== $this->hScrollBar || $this->needsHorizontalScroll(),
        ));
    }

    /** The viewport width. */
    public function viewportWidth(): int
    {
        $m = $this->metrics();

        return max(0, $this->width - 2 * $m->textBoxBorder - $m->scrollBarThickness
            - 2 * $m->textBoxPadding);
    }

    /** The viewport height. */
    public function viewportHeight(): int
    {
        $m = $this->metrics();

        return max(0, $this->height - 2 * $m->textBoxBorder
            - ($this->needsHorizontalScroll() ? $m->scrollBarThickness : 0));
    }

    /** The visible line count. */
    public function visibleLineCount(): int
    {
        return max(1, intdiv($this->viewportHeight(), $this->lineHeight));
    }

    /** The content width. */
    public function contentWidth(): int { return $this->contentWidth; }

    /** Whether the columns are wider than the viewport, so a horizontal bar is needed. */
    public function needsHorizontalScroll(): bool
    {
        return $this->contentWidth > $this->viewportWidth();
    }

    /**
     * How far the text is slid left, in pixels — zero while the bar is retired,
     * so a value left from a wider document can't shift one that has no way to
     * shift back.
     */
    public function horizontalOffset(): int
    {
        return $this->needsHorizontalScroll() ? $this->hScrollBar->getValue() : 0;
    }

    /** The scroll bar. */
    public function getScrollBar(): ScrollBar           { return $this->scrollBar; }
    /** The horizontal scroll bar. */
    public function getHorizontalScrollBar(): ScrollBar { return $this->hScrollBar; }

    // ---- selection ------------------------------------------------------

    /** Whether it has a selection. */
    public function hasSelection(): bool { return $this->anchorLine >= 0 && $this->cursorLine >= 0; }

    /** The selection start. */
    public function selectionStart(): int { return min($this->anchorLine, $this->cursorLine); }
    /** The selection end. */
    public function selectionEnd(): int   { return max($this->anchorLine, $this->cursorLine); }

    /** Whether it is line selected. */
    public function isLineSelected(int $line): bool
    {
        return $this->hasSelection()
            && $line >= $this->selectionStart()
            && $line <= $this->selectionEnd();
    }

    /** Start a selection at $line, or extend the current one to it. */
    public function selectLine(int $line, bool $extend = false): void
    {
        if ($this->lines === []) return;

        $line = max(0, min(count($this->lines) - 1, $line));

        if (!$extend || $this->anchorLine < 0) $this->anchorLine = $line;
        $this->cursorLine = $line;
    }

    /** Selects the whole document - what Ctrl+A does. */
    public function selectAll(): void
    {
        if ($this->lines === []) return;

        $this->anchorLine = 0;
        $this->cursorLine = count($this->lines) - 1;
    }

    /** Clears the selection. */
    public function clearSelection(): void
    {
        $this->anchorLine = -1;
        $this->cursorLine = -1;
    }

    /** Window-local Y to a line index, clamped to the document. */
    public function lineAt(int $my): int
    {
        $top = $this->y + $this->metrics()->textBoxBorder;
        $idx = intdiv($my - $top, $this->lineHeight) + $this->scrollBar->getValue();

        return max(0, min(max(0, count($this->lines) - 1), $idx));
    }

    /** Bring $line into view, scrolling the least that does it. */
    public function scrollTo(int $line): void
    {
        $first = $this->scrollBar->getValue();
        $page  = $this->visibleLineCount();

        if ($line < $first)                 $this->scrollBar->setValue($line);
        elseif ($line >= $first + $page)    $this->scrollBar->setValue($line - $page + 1);
    }

    // ---- Scrollable / Bounded -------------------------------------------

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }

    /** The bounds. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    /** A well with a frame: every pixel of the rectangle is ours. */
    public function paintsOwnBackground(): bool { return true; }

    // ---- Focusable -------------------------------------------------------

    /** Whether it is focused. */
    public function isFocused(): bool               { return $this->focused; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $f): void       { $this->focused = $f; }
    /** Whether it can take focus. */
    public function canTakeFocus(): bool            { return true; }
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool { return $this->hitTest($mx, $my); }

    /** Read-only: there is nowhere for pasted text to go. */
    public function paste(string $text): void {}

    /**
     * The selected lines, or the whole document when nothing is selected.
     *
     * Copying with no selection is the common case here — the view exists so a
     * record can be lifted into an editor — so it hands over everything rather
     * than nothing.
     */
    public function copy(): ?string
    {
        if ($this->lines === []) return null;

        if (!$this->hasSelection()) return $this->getText();

        return implode("\n", array_slice(
            $this->lines,
            $this->selectionStart(),
            $this->selectionEnd() - $this->selectionStart() + 1,
        ));
    }

    /** {@inheritDoc} */
    public function handleKey(string $key): bool
    {
        $page = $this->visibleLineCount();
        $last = max(0, count($this->lines) - 1);
        $here = $this->cursorLine >= 0 ? $this->cursorLine : $this->scrollBar->getValue();

        switch ($key) {
            case 'Ctrl+A':
                $this->selectAll();
                return true;

            case 'Up':          case 'Shift+Up':
            case 'Down':        case 'Shift+Down':
            case 'PageUp':      case 'Shift+PageUp':
            case 'PageDown':    case 'Shift+PageDown':
            case 'Home':        case 'Shift+Home':
            case 'End':         case 'Shift+End':
                $target = match (str_replace('Shift+', '', $key)) {
                    'Up'       => $here - 1,
                    'Down'     => $here + 1,
                    'PageUp'   => $here - $page,
                    'PageDown' => $here + $page,
                    'Home'     => 0,
                    default    => $last,
                };

                $this->selectLine($target, extend: str_starts_with($key, 'Shift+'));
                $this->scrollTo($this->cursorLine);
                return true;
        }

        return false;
    }
}
