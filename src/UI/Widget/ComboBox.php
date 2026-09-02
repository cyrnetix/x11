<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Event\ComboBoxChangedEvent;
use Cyrnetix\X11\UI\Event\ListSelectionChangedEvent;
use Cyrnetix\X11\UI\Event\TextSelectionChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style editable combobox. The popup is a real {@see ListBox} held
 * as an overlay-child so it gets a scrollbar for free when items overflow.
 * Text-edit + caret + drag-to-select on the text area are driven by
 * EditableTextHandler; the arrow button toggles the popup; clicking an
 * item in the popup picks it and closes (via the inner ListBox's
 * onItemClicked hook).
 */
final class ComboBox extends Widget implements EditableText
{
    private string $text          = '';
    private int    $cursor        = 0;
    private int    $anchor        = 0;
    private bool   $focused       = false;
    private bool   $open          = false;

    private readonly ListBox          $listBox;
    private readonly ListenerRegistry $silentRegistry;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        private readonly SyncEventDispatcher $dispatcher,
        public readonly int $maxLength       = 256,
        public readonly int $itemHeight      = 16,
        public readonly int $maxVisibleItems = 5,
    ) {
        parent::__construct($x, $y);

        $this->silentRegistry = new ListenerRegistry();
        $silent               = new SyncEventDispatcher($this->silentRegistry);

        $this->listBox = new ListBox(
            $x,
            $y + $this->metrics()->fieldHeight - 1,
            $width,
            $this->popupHeight(),
            $silent,
            $this->itemHeight,
        );

        // Inner-listbox selection event → re-fire on combo's public
        // dispatcher? We only translate via pickFromList(), which fires
        // ComboBoxChangedEvent. So the silent listener does nothing — the
        // onItemClicked path drives the public event chain.
        $this->silentRegistry->addListener(ListSelectionChangedEvent::class, static fn() => null);

        // Click on a popup item: pick it. Mirrors Win's CBN_SELENDOK.
        $this->listBox->setOnItemClicked(function (int $idx): void {
            $this->pickFromList($idx);
        });
    }

    /** The popup is owned, not a child, so it needs the theme handed to it. */
    protected function ownedWidgets(): array
    {
        return [$this->listBox];
    }

    /** Re-anchor and re-size the popup after a metrics change. */
    public function relayout(): void
    {
        $this->listBox->moveTo($this->x, $this->y + $this->metrics()->fieldHeight - 1);
        $this->listBox->resize($this->width, $this->popupHeight());
    }

    /** Height of the drop-down list: N rows inside the listbox's border. */
    private function popupHeight(): int
    {
        return $this->maxVisibleItems * $this->itemHeight
             + 2 * $this->metrics()->listBoxBorder;
    }

    // ---- Text / focus ---------------------------------------------------

    /** The text. */
    public function getText(): string    { return $this->text; }
    /** The cursor. */
    public function getCursor(): int     { return $this->cursor; }
    /** Whether it is focused. */
    public function isFocused(): bool    { return $this->focused; }

    /** No disabled state, so always. */
    public function canTakeFocus(): bool { return true; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $f): void { $this->focused = $f; }

    /** Whether it has a selection. */
    public function hasSelection(): bool     { return $this->cursor !== $this->anchor; }
    /** The selection start. */
    public function getSelectionStart(): int { return min($this->cursor, $this->anchor); }
    /** The selection end. */
    public function getSelectionEnd(): int   { return max($this->cursor, $this->anchor); }

    /** Sets text. */
    public function setText(string $text): void
    {
        $this->text          = substr($text, 0, $this->maxLength);
        $this->cursor        = strlen($this->text);
        $this->anchor        = $this->cursor;
        $this->listBox->setSelectedIndex($this->indexOfItem($this->text));
    }

    // ---- Items / list state --------------------------------------------

    /** Adds an item. */
    public function addItem(string $text): void
    {
        $this->listBox->addItem($text);
    }

    /** @return list<string> */
    public function getItems(): array       { return $this->listBox->getItems(); }
    /** The selected index. */
    public function getSelectedIndex(): int { return $this->listBox->getSelectedIndex(); }
    /** The list box. */
    public function getListBox(): ListBox   { return $this->listBox; }

    /** Whether it is open. */
    public function isOpen(): bool { return $this->open; }

    /** Drops the list open. */
    public function open(): void
    {
        if ($this->open) return;
        $this->open = true;
        $this->relayout();
        $this->listBox->ensureSelectedVisible();
    }

    /** Closes the list. */
    public function close(): void { $this->open = false; }

    /** The overlay children. */
    public function getOverlayChildren(): array
    {
        return $this->open ? [$this->listBox] : [];
    }

    /** Takes item $idx as the value. False if there is no such item. */
    public function pickFromList(int $idx): bool
    {
        $items = $this->listBox->getItems();
        if (!isset($items[$idx])) return false;

        $this->text   = $items[$idx];
        $this->cursor = strlen($this->text);
        $this->anchor = $this->cursor;
        $this->listBox->setSelectedIndex($idx);
        $this->open   = false;
        $this->dispatcher->dispatch(new ComboBoxChangedEvent($this, fromList: true));
        $this->fireSelectionChanged();
        return true;
    }

    // ---- Hit tests ------------------------------------------------------

    /** Which field is at these coordinates, if any. */
    public function hitTestField(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->metrics()->fieldHeight;
    }

    /** Which button is at these coordinates, if any. */
    public function hitTestButton(int $mx, int $my): bool
    {
        $m    = $this->metrics();
        $btnX = $this->x + $this->width - $m->fieldButtonWidth - $m->fieldBorder;
        return $mx >= $btnX && $mx < $this->x + $this->width - $m->fieldBorder
            && $my >= $this->y + $m->fieldBorder && $my < $this->y + $m->fieldHeight - $m->fieldBorder;
    }

    /** Which text area is at these coordinates, if any. */
    public function hitTestTextArea(int $mx, int $my): bool
    {
        $m    = $this->metrics();
        $btnX = $this->x + $this->width - $m->fieldButtonWidth - $m->fieldBorder;
        return $mx >= $this->x + $m->fieldBorder && $mx < $btnX
            && $my >= $this->y + $m->fieldBorder && $my < $this->y + $m->fieldHeight - $m->fieldBorder;
    }

    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool
    {
        return $this->hitTestTextArea($mx, $my);
    }

    /** The copy. */
    public function copy(): ?string
    {
        if (!$this->hasSelection()) return null;
        return substr($this->text, $this->getSelectionStart(),
                      $this->getSelectionEnd() - $this->getSelectionStart());
    }

    /** {@inheritDoc} */
    public function paste(string $text): void
    {
        if ($this->hasSelection()) {
            $this->deleteSelection();
            $this->onTextEdited();
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

    /** Tells listeners the text selection moved. */
    private function fireSelectionChanged(): void
    {
        $this->dispatcher->dispatch(new TextSelectionChangedEvent($this));
    }

    /** Window-local X → character index, using the combobox's text-area layout. */
    public function pixelToCursor(int $mouseX, Renderer $r): int
    {
        $m     = $this->metrics();
        $textX = $this->x + $m->fieldBorder + $m->fieldPadding;
        $btnX  = $this->x + $this->width - $m->fieldButtonWidth - $m->fieldBorder;
        $avail = $btnX - $textX - $m->fieldPadding;
        $start = $this->viewStart($r, $avail);

        if ($mouseX <= $textX) return $start;

        $px  = $textX;
        $len = strlen($this->text);
        for ($i = $start; $i < $len; $i++) {
            $charW = $r->measureText($this->text[$i]);
            if ($mouseX < $px + intdiv($charW, 2)) return $i;
            $px += $charW;
        }
        return $len;
    }

    /** {@inheritDoc} */
    public function viewStart(Renderer $r, int $availWidth): int
    {
        $start = 0;
        while ($start < $this->cursor
            && $r->measureText(substr($this->text, $start, $this->cursor - $start)) > $availWidth) {
            $start++;
        }
        return $start;
    }

    // ---- Key handling ---------------------------------------------------

    /** {@inheritDoc} */
    public function handleKey(string $key): bool
    {
        return match ($key) {
            'BS'           => $this->backspace(),
            'Del'          => $this->delete(),
            'Left'         => $this->moveCaretTo($this->cursor - 1, extend: false),
            'Right'        => $this->moveCaretTo($this->cursor + 1, extend: false),
            'Home'         => $this->moveCaretTo(0, extend: false),
            'End'          => $this->moveCaretTo(strlen($this->text), extend: false),
            'Shift+Left'   => $this->moveCaretTo($this->cursor - 1, extend: true),
            'Shift+Right'  => $this->moveCaretTo($this->cursor + 1, extend: true),
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
            $this->onTextEdited();
            return true;
        }
        if ($this->cursor === 0) return false;
        $this->text   = substr($this->text, 0, $this->cursor - 1) . substr($this->text, $this->cursor);
        $this->cursor--;
        $this->anchor = $this->cursor;
        $this->onTextEdited();
        $this->fireSelectionChanged();
        return true;
    }

    /** Deletes forward from the caret, or the selection if there is one. True if anything changed. */
    private function delete(): bool
    {
        if ($this->hasSelection()) {
            $this->deleteSelection();
            $this->onTextEdited();
            return true;
        }
        if ($this->cursor >= strlen($this->text)) return false;
        $this->text = substr($this->text, 0, $this->cursor) . substr($this->text, $this->cursor + 1);
        $this->onTextEdited();
        return true;
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
        if (strlen($key) !== 1 || ord($key) < 0x20)       return false;
        if ($this->hasSelection()) {
            $this->deleteSelection();
        }
        if (strlen($this->text) >= $this->maxLength)      return false;

        $this->text = substr($this->text, 0, $this->cursor) . $key . substr($this->text, $this->cursor);
        $this->cursor++;
        $this->anchor = $this->cursor;
        $this->onTextEdited();
        $this->fireSelectionChanged();
        return true;
    }

    /** After typing: re-syncs which list item matches, if any. */
    private function onTextEdited(): void
    {
        $this->listBox->setSelectedIndex($this->indexOfItem($this->text));
        $this->dispatcher->dispatch(new ComboBoxChangedEvent($this, fromList: false));
    }

    /** The index of the item with exactly this text, or -1. */
    private function indexOfItem(string $text): int
    {
        foreach ($this->listBox->getItems() as $i => $item) {
            if ($item === $text) return $i;
        }
        return -1;
    }
}
