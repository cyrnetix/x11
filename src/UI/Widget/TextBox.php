<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Event\TextSelectionChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Single-line text input with cursor + selection-anchor model:
 *   cursor == anchor → just a caret, no selection.
 *   cursor != anchor → selection covers [min, max) of the two positions.
 *
 * All editing ops (BS / Del / printable insert / paste) replace the current
 * selection if there is one — same shape as every Windows text field.
 *
 * Layout constants are public so the painter, the click-to-position math,
 * and the click hit-test all use exactly the same numbers.
 */
final class TextBox extends Widget implements EditableText, Bounded
{
    /** Stand-in glyph for a masked field. */
    public const MASK_CHARACTER = '*';

    private string $text    = '';
    private int    $cursor  = 0;
    private int    $anchor  = 0;
    private bool   $focused = false;
    private bool   $enabled = true;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public int $height,
        public readonly int $maxLength = 256,
        private readonly ?SyncEventDispatcher $dispatcher = null,
        /**
         * Render every character as {@see MASK_CHARACTER} instead of itself —
         * a password field.
         *
         * Only the *rendering* changes: {@see getText()} still returns what was
         * typed, because that's the value the application needs. Everything that
         * measures or positions goes through {@see displayText()} so the caret,
         * the click-to-position hit test and the horizontal scroll all line up
         * with the glyphs actually on screen rather than the ones underneath.
         */
        public readonly bool $masked = false,
    ) {
        parent::__construct($x, $y);
    }

    /** What a painter should draw and measure: the mask, or the text itself. */
    public function displayText(): string
    {
        return $this->masked
            ? str_repeat(self::MASK_CHARACTER, strlen($this->text))
            : $this->text;
    }

    /** Tells listeners the selection moved. */
    private function fireSelectionChanged(): void
    {
        $this->dispatcher?->dispatch(new TextSelectionChangedEvent($this));
    }

    /** The text. */
    public function getText(): string  { return $this->text; }
    /** The cursor. */
    public function getCursor(): int   { return $this->cursor; }
    /** Whether it is focused. */
    public function isFocused(): bool  { return $this->focused; }

    /** Whether it can take focus. */
    public function canTakeFocus(): bool { return $this->isEnabled(); }
    /** Whether it is enabled. */
    public function isEnabled(): bool  { return $this->enabled; }

    /** Whether it has a selection. */
    public function hasSelection(): bool     { return $this->cursor !== $this->anchor; }
    /** The selection start. */
    public function getSelectionStart(): int { return min($this->cursor, $this->anchor); }
    /** The selection end. */
    public function getSelectionEnd(): int   { return max($this->cursor, $this->anchor); }

    /** Sets text. */
    public function setText(string $text): void
    {
        $this->text   = substr($text, 0, $this->maxLength);
        $this->cursor = strlen($this->text);
        $this->anchor = $this->cursor;
    }

    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void
    {
        $this->focused = $focused;
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }

    /** A sunken well, filled edge to edge. */
    public function bounds(): Rect
    {
        return Rect::of($this->x, $this->y, $this->width, $this->height);
    }

    
    /** Whether this widget fills its own rectangle, so a repaint of it alone is safe. */
    public function paintsOwnBackground(): bool { return true; }

    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool
    {
        return $this->hitTest($mx, $my);
    }

    /** The copy. */
    public function copy(): ?string
    {
        // A masked field doesn't surrender its contents to the clipboard.
        if ($this->masked) return null;

        // Match Win behaviour: Ctrl+C with no selection copies nothing.
        if (!$this->hasSelection()) return null;
        return substr($this->text, $this->getSelectionStart(),
                      $this->getSelectionEnd() - $this->getSelectionStart());
    }

    /** {@inheritDoc} */
    public function paste(string $text): void
    {
        if (!$this->enabled) return;
        if ($this->hasSelection()) {
            $this->deleteSelection();
        }
        foreach (str_split($text) as $ch) {
            $this->insertPrintable($ch);
        }
    }

    /** {@inheritDoc} */
    public function selectCursor(int $pos, bool $extend): void
    {
        $pos = max(0, min(strlen($this->text), $pos));
        $oldCursor = $this->cursor;
        $oldAnchor = $this->anchor;
        $this->cursor = $pos;
        if (!$extend) $this->anchor = $pos;
        if ($this->cursor !== $oldCursor || $this->anchor !== $oldAnchor) {
            $this->fireSelectionChanged();
        }
    }

    /** {@inheritDoc} */
    public function selectAll(): void
    {
        $oldCursor = $this->cursor;
        $oldAnchor = $this->anchor;
        $this->anchor = 0;
        $this->cursor = strlen($this->text);
        if ($this->cursor !== $oldCursor || $this->anchor !== $oldAnchor) {
            $this->fireSelectionChanged();
        }
    }

    /**
     * Translate a window-local X coordinate into a character index by
     * walking through the visible glyphs and accumulating their widths.
     * The midpoint of each glyph is the split point — same convention every
     * text field uses for click-to-position.
     */
    public function pixelToCursor(int $mouseX, Renderer $r): int
    {
        $m     = $this->metrics();
        $textX = $this->x + $m->textBoxBorder + $m->textBoxPadding;
        $avail = $this->width - 2 * $m->textBoxBorder - 2 * $m->textBoxPadding;
        $start = $this->viewStart($r, $avail);

        if ($mouseX <= $textX) return $start;

        $shown = $this->displayText();
        $px    = $textX;
        $len   = strlen($shown);
        for ($i = $start; $i < $len; $i++) {
            $charW = $r->measureText($shown[$i]);
            if ($mouseX < $px + intdiv($charW, 2)) return $i;
            $px += $charW;
        }
        return $len;
    }

    /** {@inheritDoc} */
    public function viewStart(Renderer $r, int $availWidth): int
    {
        $shown = $this->displayText();
        $start = 0;
        while ($start < $this->cursor
            && $r->measureText(substr($shown, $start, $this->cursor - $start)) > $availWidth) {
            $start++;
        }
        return $start;
    }

    /**
     * Apply one translated key. Returns true if anything visible changed
     * (content / cursor / selection) so the caller can redraw.
     */
    public function handleKey(string $key): bool
    {
        if (!$this->enabled) return false;

        return match ($key) {
            'BS'           => $this->backspace(),
            'Del'          => $this->delete(),
            'Left'         => $this->moveCursor(-1, extend: false),
            'Right'        => $this->moveCursor(+1, extend: false),
            'Home'         => $this->moveCaretTo(0, extend: false),
            'End'          => $this->moveCaretTo(strlen($this->text), extend: false),
            'Shift+Left'   => $this->moveCursor(-1, extend: true),
            'Shift+Right'  => $this->moveCursor(+1, extend: true),
            'Shift+Home'   => $this->moveCaretTo(0, extend: true),
            'Shift+End'    => $this->moveCaretTo(strlen($this->text), extend: true),
            default        => $this->insertPrintable($key),
        };
    }

    /** Deletes backward from the caret, or the selection if there is one. True if anything changed. */
    private function backspace(): bool
    {
        if ($this->hasSelection()) {
            $this->deleteSelection();
            return true;
        }
        if ($this->cursor === 0) return false;
        $this->text   = substr($this->text, 0, $this->cursor - 1) . substr($this->text, $this->cursor);
        $this->cursor--;
        $this->anchor = $this->cursor;
        $this->fireSelectionChanged();
        return true;
    }

    /** Deletes forward from the caret, or the selection if there is one. True if anything changed. */
    private function delete(): bool
    {
        if ($this->hasSelection()) {
            $this->deleteSelection();
            return true;
        }
        if ($this->cursor >= strlen($this->text)) return false;
        $this->text = substr($this->text, 0, $this->cursor) . substr($this->text, $this->cursor + 1);
        // cursor/anchor don't move; nothing to fire here.
        return true;
    }

    /** Moves the caret by $delta characters. */
    private function moveCursor(int $delta, bool $extend): bool
    {
        return $this->moveCaretTo($this->cursor + $delta, $extend);
    }

    /** Puts the caret at $pos, extending the selection when asked instead of clearing it. */
    private function moveCaretTo(int $pos, bool $extend): bool
    {
        $pos = max(0, min(strlen($this->text), $pos));
        if ($pos === $this->cursor && (!$extend ? $this->anchor === $pos : true)) {
            if ($extend || $this->anchor === $this->cursor) return false;
        }
        $this->cursor = $pos;
        if (!$extend) $this->anchor = $pos;
        $this->fireSelectionChanged();
        return true;
    }

    /** Removes the selected text and leaves the caret where it was. */
    private function deleteSelection(): void
    {
        $start = $this->getSelectionStart();
        $end   = $this->getSelectionEnd();
        $this->text   = substr($this->text, 0, $start) . substr($this->text, $end);
        $this->cursor = $start;
        $this->anchor = $start;
        $this->fireSelectionChanged();
    }

    /** Types one character, replacing the selection if there is one. False if it was not printable. */
    private function insertPrintable(string $key): bool
    {
        if (strlen($key) !== 1 || ord($key) < 0x20) return false;

        if ($this->hasSelection()) {
            $this->deleteSelection();
        }
        if (strlen($this->text) >= $this->maxLength) return false;

        $this->text = substr($this->text, 0, $this->cursor) . $key . substr($this->text, $this->cursor);
        $this->cursor++;
        $this->anchor = $this->cursor;
        $this->fireSelectionChanged();
        return true;
    }
}
