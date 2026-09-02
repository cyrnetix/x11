<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\UI\Event\DropDownChangedEvent;
use Cyrnetix\X11\UI\Event\ListSelectionChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win2k-style dropdown. A sunken white field with the currently-selected
 * item on the left and a "▼" arrow on the right. Opening drops a real
 * {@see ListBox} below the field — complete with its own scrollbar when
 * the items overflow the popup height. The popup is exposed via
 * {@see Widget::getOverlayChildren()} so it paints after the main tree
 * and captures clicks before any sibling underneath.
 *
 * The inner ListBox runs on a private "silent" dispatcher (no listeners
 * are attached to it) so its ListSelectionChangedEvent doesn't leak —
 * we listen to that event ourselves and translate it into
 * DropDownChangedEvent on the dropdown's public dispatcher.
 */
final class DropDown extends Widget implements Focusable
{
    private bool $open    = false;
    private bool $focused = false;

    /** ListBox that's shown as the popup. Always allocated; visible only when open. */
    private readonly ListBox $listBox;

    /** Held so we can re-fire DropDownChangedEvent when the inner list selection changes. */
    private readonly ListenerRegistry $silentRegistry;

    private ?\Closure $onChanged = null;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        private readonly SyncEventDispatcher $dispatcher,
        public readonly int $itemHeight       = 16,
        public readonly int $maxVisibleItems  = 5,
    ) {
        parent::__construct($x, $y);

        // Silent dispatcher: a private registry that nobody outside the
        // widget knows about. ListBox dispatches into it; only the
        // listener we register here below ever fires.
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

        // Translate inner ListSelectionChangedEvent → DropDownChangedEvent.
        $this->silentRegistry->addListener(
            ListSelectionChangedEvent::class,
            function (ListSelectionChangedEvent $e): void {
                if ($e->list === $this->listBox) {
                    $this->dispatcher->dispatch(new DropDownChangedEvent($this));
                    if ($this->onChanged !== null) ($this->onChanged)($this);
                }
            }
        );

        // Click-on-item always closes (CBN_CLOSEUP), even if the user
        // re-clicked the already-selected row.
        $this->listBox->setOnItemClicked(function (int $idx): void {
            $this->close();
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

    /** Adds an item. */
    public function addItem(string $text): bool
    {
        $this->listBox->addItem($text);
        // Auto-select the first item so the field never shows "(empty)".
        if ($this->listBox->getSelectedIndex() === -1) {
            $this->listBox->setSelectedIndex(0);
        }
        return true;
    }

    /**
     * Selection-changed callback, for a composite that owns this field and can't
     * reach the listener registry.
     *
     * Use this rather than the inner list's own `setOnItemClicked`: that slot is
     * how the popup dismisses itself on a pick, and taking it stops the popup
     * closing.
     */
    public function setOnChanged(?\Closure $cb): void { $this->onChanged = $cb; }

    /** Drop every item, for a field whose choices change (a file dialog's type list). */
    public function clearItems(): void
    {
        $this->listBox->clearItems();
        $this->close();
    }

    /** @return list<string> */
    public function getItems(): array          { return $this->listBox->getItems(); }
    /** The selected index. */
    public function getSelectedIndex(): int    { return $this->listBox->getSelectedIndex(); }
    /** The selected item. */
    public function getSelectedItem(): ?string { return $this->listBox->getSelectedItem(); }

    /** Sets selected index. */
    public function setSelectedIndex(int $idx): bool
    {
        if ($idx < 0 || $idx >= count($this->listBox->getItems())) return false;
        return $this->listBox->setSelectedIndex($idx);
    }

    /** The list box. */
    public function getListBox(): ListBox { return $this->listBox; }

    // ---- Open / close ---------------------------------------------------

    /** Whether it is open. */
    public function isOpen(): bool { return $this->open; }

    /** Drops the list open. */
    public function open(): void
    {
        if ($this->open) return;
        $this->open = true;
        // Re-anchor the popup below the field — the field may have moved
        // since construction (tab page resize, parent reflow) and the
        // ListBox needs absolute coords to paint correctly.
        $this->relayout();
        $this->listBox->ensureSelectedVisible();
    }

    /** Closes the list. */
    public function close(): void { $this->open = false; }

    // ---- Overlay child --------------------------------------------------

    /** ListBox lives in the overlay layer only while the popup is open. */
    public function getOverlayChildren(): array
    {
        return $this->open ? [$this->listBox] : [];
    }

    // ---- Hit tests + Focusable -----------------------------------------

    /** Which field is at these coordinates, if any. */
    public function hitTestField(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->metrics()->fieldHeight;
    }

    /** Whether it is focused. */
    public function isFocused(): bool                       { return $this->focused; }

    /** No disabled state, so always. */
    public function canTakeFocus(): bool { return true; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void         { $this->focused = $focused; }
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool { return $this->hitTestField($mx, $my); }
    /** {@inheritDoc} */
    public function handleKey(string $key): bool            { return false; }
    /** The copy. */
    public function copy(): ?string                         { return $this->getSelectedItem(); }
    /** {@inheritDoc} */
    public function paste(string $text): void               {}
}
