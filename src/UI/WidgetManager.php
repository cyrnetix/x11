<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Cyrnetix\X11\Client\ChildWindow;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Handler\TreeViewHandler;
use Cyrnetix\X11\UI\Handler\WidgetHandler;
use Cyrnetix\X11\UI\Painter\MessageBoxPainter;
use Cyrnetix\X11\UI\Widget\EditableText;
use Cyrnetix\X11\UI\Widget\Focusable;
use Cyrnetix\X11\UI\Widget\FileDialog;
use Cyrnetix\X11\UI\Widget\MessageBox;
use Cyrnetix\X11\UI\Widget\ScrollBar;
use Cyrnetix\X11\UI\Widget\Scrollable;
use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeView;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use function React\Promise\resolve;

/**
 * Router. Owns the shared cross-cutting concerns (dialog modal state,
 * scroll-wheel target lookup, middle-click clipboard paste, Ctrl+A/C/X/V
 * shortcuts) and delegates everything else to an ordered list of
 * per-widget {@see WidgetHandler}s.
 *
 * Dispatch shape:
 *  - onButtonPress: wheel → middle-click → dialog → focus → handler walk.
 *  - onButtonRelease: dialog → handler walk.
 *  - onMotion: dialog drag → handler walk.
 *  - onKeyPress: clipboard shortcuts → handler walk → focused widget's
 *    own handleKey() as final fallback (typed characters into a TextBox).
 *
 * Handler order is policy — popup-owning handlers (Menu/DTP/DropDown/
 * ComboBox) run first so an open popup captures every click; ScrollBar
 * runs before the list-family handlers because scrollbars are children
 * of Scrollable containers and the inner hit-test must win.
 */
final class WidgetManager
{
    /** @var list<WidgetHandler> */
    private readonly array $handlers;

    private ?MessageBox $dialog        = null;
    private ?FileDialog $fileDialog    = null;
    private bool         $isDragging   = false;
    private int          $dragOffsetX  = 0;
    private int          $dragOffsetY  = 0;

    /**
     * @param list<WidgetHandler> $handlers In dispatch order (see class doc).
     */
    public function __construct(
        private readonly X11Client          $client,
        private readonly Renderer           $renderer,
        private readonly WidgetTree         $tree,
        private readonly MessageBoxPainter  $dialogPainter,
        private readonly KeyTranslator      $keyTranslator,
        private readonly LoggerInterface    $logger,
        array                                $handlers,
        /** Held separately so selectTreeNode() can delegate to it. */
        private readonly TreeViewHandler    $treeViewHandler,
    ) {
        $this->handlers = $handlers;
    }

    /** Adds a child. */
    /**
     * Does the application draw its own window caption and border?
     *
     * True when a {@see WindowFrame} root has `drawsChrome`. {@see X11Client} asks
     * at map time so it can decide whether to request window-manager decorations
     * by *default*: a window with both gets two title bars, one inside the other,
     * and that is what the widget gallery shipped with: it set the frame's flag and
     * forgot the client's.
     */
    public function drawsOwnWindowChrome(): bool
    {
        return $this->tree->findRoot(
            static fn(Widget $w): bool => $w instanceof WindowFrame && $w->drawsChrome,
        ) !== null;
    }

    public function addChild(Widget $widget): void
    {
        $this->tree->addRoot($widget);
    }

    /** Sets dialog. */
    public function setDialog(MessageBox $dialog): void
    {
        $this->dialog = $dialog;
    }

    /**
     * The file dialog paints into its own X11 window, so it's held here to be
     * kept out of the main window's paint walk and given its own.
     */
    public function setFileDialog(FileDialog $fileDialog): void
    {
        $this->fileDialog = $fileDialog;
    }

    /** Registers this manager's listeners for every X11 event it cares about. */
    public function register(ListenerRegistry $registry): void
    {
        $registry->addListener(X11ButtonPressEvent::class,   $this->onButtonPress(...));
        $registry->addListener(X11ButtonReleaseEvent::class, $this->onButtonRelease(...));
        $registry->addListener(X11MotionEvent::class,        $this->onMotion(...),    priority: -10);
        $registry->addListener(X11KeyPressEvent::class,      $this->onKeyPress(...),  priority: -10);
    }

    /**
     * Programmatic tree navigation entry point — delegates to TreeViewHandler
     * so listeners (e.g. a ListView double-click jumping into a folder) can
     * navigate the tree with the same busy-cursor + lazy-load semantics as
     * a manual click.
     */
    public function selectTreeNode(TreeView $tv, TreeNode $node): void
    {
        $this->treeViewHandler->selectNode($tv, $node);
    }

    // -------------------------------------------------------------------------
    // Paint
    // -------------------------------------------------------------------------

    /** Paints the whole main window: every root, then the overlays over them. */
    public function paintAll(): void
    {
        $paint = function (Widget $w): void {
            foreach ($this->handlers as $h) {
                if ($h->paint($w, $this->renderer)) return;
            }
        };

        // Main tree, minus anything that belongs to another window: those
        // subtrees live in this tree but are painted by paintChildWindow().
        foreach ($this->tree->getRoots() as $root) {
            if ($this->belongsToAnotherWindow($root)) continue;
            $this->tree->visitSubtree($root, $paint);
        }

        // Overlay subtrees (popup ListBoxes, etc.) — paint as normal
        // widgets but AFTER the main walk so they overlay siblings.
        foreach ($this->tree->getRoots() as $root) {
            if ($this->belongsToAnotherWindow($root)) continue;
            $this->tree->visitSubtreeOverlay($root, $paint);
        }

        // Handler-side overlays — popups not modelled as widget subtrees
        // (calendar grid, menu chain, dropdown's painter-driven popup).
        $this->paintOverlays($this->renderer, null);
    }

    /**
     * Handler-owned popups, but only the ones belonging to this window.
     *
     * $window is the root a window paints, or null for the main one. A popup
     * lives on its handler rather than in the tree, so nothing else can tell
     * which window it is in — and every window's walk calls this. Without the
     * check an open menu was painted into the preview window too, at the
     * coordinates it has in the main one.
     */
    private function paintOverlays(Renderer $r, ?Widget $window): void
    {
        foreach ($this->handlers as $h) {
            $anchor = $h->overlayAnchor();
            if ($anchor !== null && $this->windowRootOf($anchor) !== $window) continue;

            $h->paintOverlay($r);
        }
    }

    /** The window-owning root above $w, or null when it is in the main window. */
    private function windowRootOf(Widget $w): ?Widget
    {
        $root = $w->rootWidget();

        return $this->belongsToAnotherWindow($root) ? $root : null;
    }

    /**
     * Rectangles the dialog window occupies, for the client to shape it with.
     * Empty when the theme's caption spans the dialog, i.e. nothing to cut away.
     *
     * @return list<array{int, int, int, int}>
     */
    public function dialogShapeRects(Renderer $dialogRenderer): array
    {
        return $this->dialog?->shapeRects($dialogRenderer) ?? [];
    }

    /**
     * The file dialog's root, for the client to recognise a widget as living in
     * the dialog's window rather than the main one.
     */
    public function fileDialogRoot(): ?Widget { return $this->fileDialog; }

    /** Paints the dialog. */
    public function paintDialog(Renderer $dialogRenderer): void
    {
        if ($this->dialog !== null) {
            $this->dialogPainter->paint($this->dialog, $dialogRenderer);
        }
    }

    /**
     * Is this root painted into a window of its own rather than the main one?
     *
     * A root in a child window is still in this tree — that's what lets every
     * handler find its widgets through the usual findFirst — so the paint walk
     * is the one place that has to know the difference.
     */
    private function belongsToAnotherWindow(Widget $root): bool
    {
        if ($root === $this->fileDialog) return true;

        foreach ($this->client->getChildWindows() as $window) {
            if ($window->root() === $root) return true;
        }

        return false;
    }

    /**
     * Tell the tree which roots the window this event came from can reach.
     *
     * The paint walk already knows a root may live in a window of its own
     * ({@see belongsToAnotherWindow()}); hit-testing has to know the same thing,
     * because a child window's events arrive in its own coordinates and would
     * otherwise match a main-window widget at the same offset. Modality covered
     * that for a modal form; this covers every window, modal or not.
     *
     * The message box and the file dialog are left alone: both are modal while
     * they're up, and modality already narrows input to them.
     */
    private function scopeInput(int $windowId): void
    {
        // findChildWindow, not a loop over the list: it discards id 0, which is
        // every window that hasn't been realised yet.
        $window = $this->client->findChildWindow($windowId);
        if ($window !== null) {
            $root = $window->root();
            $this->tree->setInputScope($root !== null ? [$root] : []);
            return;
        }

        if ($windowId !== 0 && $windowId === $this->client->getWindowId()) {
            $this->tree->setInputScope(array_values(array_filter(
                $this->tree->getRoots(),
                fn(Widget $root): bool => !$this->belongsToAnotherWindow($root),
            )));
            return;
        }

        $this->tree->setInputScope(null);
    }

    /**
     * Paint one subtree into the main window, plus the overlays.
     *
     * For a clipped region repaint: only the widget that changed is walked, and
     * the overlay passes still run so a popup drawn over the region isn't lost.
     */
    public function paintSubtree(Widget $root, ?Renderer $renderer = null): void
    {
        // A subtree in a window of its own paints with that window's Renderer —
        // its coordinates are that window's, so painting it into the main one
        // draws the change at the same offset in the wrong place.
        $r = $renderer ?? $this->renderer;

        $paint = function (Widget $w) use ($r): void {
            foreach ($this->handlers as $h) {
                if ($h->paint($w, $r)) return;
            }
        };

        $this->tree->visitSubtree($root, $paint);
        $this->tree->visitSubtreeOverlay($root, $paint);

        $this->paintOverlays($r, $this->windowRootOf($root));
    }

    /**
     * Rectangles the file dialog's window occupies, for the client to shape it.
     *
     * @return list<array{int, int, int, int}>
     */
    public function fileDialogShapeRects(Renderer $fileDialogRenderer): array
    {
        return $this->fileDialog?->isVisible()
            ? $this->fileDialog->shapeRects($fileDialogRenderer)
            : [];
    }

    /**
     * Paint an application window: its widget subtree, or its paint callback.
     *
     * The same handler walk as {@see paintAll()}, with that window's Renderer —
     * which is what lets every painter and theme apply to a form in its own
     * window without knowing it's in one.
     */
    public function paintChildWindow(ChildWindow $window): void
    {
        $painter = $window->painter();
        if ($painter !== null) {
            $painter($window->renderer);
            return;
        }

        $root = $window->root();
        if ($root === null) return;

        $paint = function (Widget $w) use ($window): void {
            foreach ($this->handlers as $h) {
                if ($h->paint($w, $window->renderer)) return;
            }
        };

        $this->tree->visitSubtree($root, $paint);
        $this->tree->visitSubtreeOverlay($root, $paint);

        // Handler-owned popups (a dropdown's list) draw last, as in the main
        // window — but only this window's, not every window's.
        $this->paintOverlays($window->renderer, $root);
    }

    /**
     * Paint the file dialog's subtree into its own window.
     *
     * Same handler walk as {@see paintAll()}, just with that window's Renderer —
     * which is what lets every painter and theme apply to the dialog unchanged
     * despite it being a separate window.
     */
    public function paintFileDialog(Renderer $fileDialogRenderer): void
    {
        $dialog = $this->fileDialog;
        if ($dialog === null || !$dialog->isVisible()) return;

        $paint = function (Widget $w) use ($fileDialogRenderer): void {
            foreach ($this->handlers as $h) {
                if ($h->paint($w, $fileDialogRenderer)) return;
            }
        };

        $this->tree->visitSubtree($dialog, $paint);
        $this->tree->visitSubtreeOverlay($dialog, $paint);

        // Handler-owned popups (the type dropdown's list) draw last, as they do
        // in the main window.
        $this->paintOverlays($fileDialogRenderer, $dialog);
    }

    // -------------------------------------------------------------------------
    // Press
    // -------------------------------------------------------------------------

    /** Routes a press: focus first, then the handlers in order, until one claims it. */
    public function onButtonPress(X11ButtonPressEvent $event): PromiseInterface
    {
        $this->scopeInput($event->windowId);

        if ($event->button === 4 || $event->button === 5) {
            return $this->handleScrollWheel($event);
        }
        if ($event->button === 2) {
            return $this->handleMiddleClick($event);
        }
        if ($event->button !== 1) return resolve(null);

        if ($this->handleDialogPress($event)) return resolve(null);
        if ($this->dialog?->isVisible()) return resolve(null);

        // Set focus to whichever Focusable lies under the cursor — handlers
        // run after, so EditableTextHandler can react to a freshly-focused
        // TextBox, and any handler can read getFocused() to know what to
        // do for keys arriving later.
        $focusable    = $this->findFocusableHit($event->x, $event->y);
        $focusChanged = $this->tree->setFocused($focusable);

        foreach ($this->handlers as $h) {
            if ($h->tryPress($event)) return resolve(null);
        }

        if ($focusChanged) $this->client->redraw();
        return resolve(null);
    }

    // -------------------------------------------------------------------------
    // Release
    // -------------------------------------------------------------------------

    /** Routes a release to whichever handler is holding a capture. */
    public function onButtonRelease(X11ButtonReleaseEvent $event): PromiseInterface
    {
        $this->scopeInput($event->windowId);

        if ($event->button !== 1) return resolve(null);

        if ($this->isDragging) {
            $this->isDragging = false;
            return resolve(null);
        }

        if ($this->handleDialogRelease($event)) return resolve(null);
        if ($this->dialog?->isVisible()) return resolve(null);

        foreach ($this->handlers as $h) {
            if ($h->tryRelease($event)) return resolve(null);
        }

        return resolve(null);
    }

    // -------------------------------------------------------------------------
    // Motion
    // -------------------------------------------------------------------------

    /** Routes movement, for hover and for drags in progress. */
    public function onMotion(X11MotionEvent $event): PromiseInterface
    {
        $this->scopeInput($event->windowId);

        if ($this->isDragging) {
            $newX = $event->rootX - $this->dragOffsetX;
            $newY = $event->rootY - $this->dragOffsetY;
            $this->client->moveDialogWindow($newX, $newY);
            return resolve(null);
        }

        // Drag-to-extend (EditableTextHandler) needs to run even with the
        // dialog visible so a selection started before the dialog appeared
        // still extends — but that's unreachable because the press path
        // suppresses on dialog. So: gate everything else here.
        foreach ($this->handlers as $h) {
            if ($h->tryMotion($event)) return resolve(null);
        }
        return resolve(null);
    }

    // -------------------------------------------------------------------------
    // Key
    // -------------------------------------------------------------------------

    /** Routes a key: Tab traversal and clipboard shortcuts first, then the focused widget. */
    public function onKeyPress(X11KeyPressEvent $event): PromiseInterface
    {
        // Keys go wherever the *window manager* put the focus, which is not
        // necessarily the window holding the focused widget — so a key event
        // clears the scope the last pointer event left behind rather than
        // adopting its own window's.
        $this->tree->setInputScope(null);

        if ($this->dialog?->isVisible()) return resolve(null);

        $key = $this->keyTranslator->translate($event->keycode, $event->state);
        if ($key === null) return resolve(null);

        // Tab traversal is cross-cutting — it belongs to no single widget kind —
        // so it is answered here, before any handler sees the key. Otherwise a
        // text field would swallow Tab as a typed character.
        if ($key === 'Tab' || $key === 'Shift+Tab') {
            if ($this->tree->focusNext(backwards: $key === 'Shift+Tab')) {
                $this->client->redraw();
            }
            return resolve(null);
        }

        $focused = $this->tree->getFocused();
        if ($focused !== null && $this->handleClipboardShortcut($focused, $key)) {
            return resolve(null);
        }

        // Handlers run whether or not anything is focused: a modal dialog has to
        // answer Escape before its fields have been clicked in, and handlers
        // that do need a focused widget check for one themselves.
        foreach ($this->handlers as $h) {
            if ($h->tryKey($key)) return resolve(null);
        }

        // Final fallback — typed characters / caret keys handled by the
        // widget itself (TextBox, ComboBox, …).
        if ($focused?->handleKey($key)) {
            $this->client->redraw();
        }
        return resolve(null);
    }

    // -------------------------------------------------------------------------
    // Cross-cutting: scroll wheel
    // -------------------------------------------------------------------------

    /** Sends a wheel notch to whatever scrollable sits under the pointer. */
    private function handleScrollWheel(X11ButtonPressEvent $event): PromiseInterface
    {
        if ($this->dialog?->isVisible()) return resolve(null);

        $delta = $event->button === 4 ? -3 : +3;
        $sb    = $this->findScrollTargetUnder($event->x, $event->y);

        if ($sb !== null && $sb->scrollBy($delta)) {
            $this->client->redraw();
        }
        return resolve(null);
    }

    /**
     * Topmost wheel-receivable scrollbar under the cursor — either a
     * Scrollable container's embedded bar or a standalone ScrollBar.
     *
     * Overlay subtrees are searched first (visitAll), so the wheel scrolls an
     * open popup list rather than whatever it happens to cover.
     */
    private function findScrollTargetUnder(int $mx, int $my): ?ScrollBar
    {
        $found = null;
        $this->tree->visitAll(function (Widget $w) use ($mx, $my, &$found): void {
            if ($found !== null) return;
            if ($w instanceof Scrollable && $w->hitTest($mx, $my)) {
                $found = $w->getScrollBar();
            } elseif ($w instanceof ScrollBar && $w->hitTest($mx, $my)) {
                $found = $w;
            }
        });
        return $found;
    }

    // -------------------------------------------------------------------------
    // Cross-cutting: middle-click PRIMARY paste
    // -------------------------------------------------------------------------

    /** Pastes the PRIMARY selection into the focused widget. */
    private function handleMiddleClick(X11ButtonPressEvent $event): PromiseInterface
    {
        if ($this->dialog?->isVisible()) return resolve(null);

        $target = $this->findFocusableHit($event->x, $event->y);
        if ($target === null) return resolve(null);

        if ($this->tree->setFocused($target)) {
            $this->client->redraw();
        }

        $this->client->readPrimary(function (?string $text) use ($target): void {
            if ($text === null || $text === '') return;
            if ($this->tree->getFocused() !== $target) return;
            $target->paste($text);
            $this->logger->debug('PRIMARY paste', ['len' => strlen($text)]);
            $this->client->redraw();
        });

        return resolve(null);
    }

    // -------------------------------------------------------------------------
    // Cross-cutting: Ctrl+A/C/X/V
    // -------------------------------------------------------------------------

    /** Answers Ctrl+A, C, X and V on the focused widget. True if it was one of them. */
    private function handleClipboardShortcut(Focusable $focused, string $key): bool
    {
        if ($key === 'Ctrl+A' && $focused instanceof EditableText) {
            $focused->selectAll();
            $this->client->redraw();
            return true;
        }

        if ($key === 'Ctrl+C' || $key === 'Ctrl+X') {
            $text = $focused->copy();
            if ($text !== null) {
                $this->client->writeClipboard($text);
                $this->client->writePrimary($text);
                $this->logger->debug('Clipboard write', ['len' => strlen($text)]);
            }
            if ($key === 'Ctrl+X' && $text !== null) {
                $focused->handleKey('BS');
                $this->client->redraw();
            }
            return true;
        }

        if ($key === 'Ctrl+V') {
            $target = $focused;
            $this->client->readClipboard(function (?string $text) use ($target): void {
                if ($text === null || $text === '') return;
                if ($this->tree->getFocused() !== $target) return;
                $target->paste($text);
                $this->logger->debug('Clipboard paste', ['len' => strlen($text)]);
                $this->client->redraw();
            });
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Cross-cutting: dialog modal
    // -------------------------------------------------------------------------

    /** A press in the message box's own window - its buttons and its caption drag. */
    private function handleDialogPress(X11ButtonPressEvent $event): bool
    {
        $dialogWindowId = $this->client->getDialogWindowId();
        if ($dialogWindowId === 0 || $event->windowId !== $dialogWindowId) {
            return false;
        }
        // Clicks outside the dialog still arrive here under GrabPointer —
        // consume silently.
        if ($event->x < 0 || $event->x >= MessageBox::WIDTH
            || $event->y < 0 || $event->y >= MessageBox::HEIGHT) {
            return true;
        }
        // Title bar starts a window drag.
        if ($event->y < ($this->dialog?->metrics()->dialogTitleHeight ?? 20) + 2) {
            $this->isDragging  = true;
            $this->dragOffsetX = $event->x;
            $this->dragOffsetY = $event->y;
            return true;
        }
        $idx = $this->dialog?->hitTestButton($event->x, $event->y);
        if ($idx !== null && $idx !== -1) {
            $this->dialog->pressButton($idx);
            $this->client->redrawDialog();
        }
        return true;
    }

    /** A release in the message box's window, which is what completes a button press. */
    private function handleDialogRelease(X11ButtonReleaseEvent $event): bool
    {
        $dialogWindowId = $this->client->getDialogWindowId();
        if ($dialogWindowId === 0 || $event->windowId !== $dialogWindowId) {
            return false;
        }
        $pressedIdx = $this->dialog?->getPressedBtn() ?? -1;
        if ($pressedIdx !== -1) {
            $releaseIdx = $this->dialog->hitTestButton($event->x, $event->y);
            $this->dialog->releaseButton($pressedIdx, $releaseIdx === $pressedIdx);
            if (!$this->dialog->isVisible()) {
                $this->client->hideDialogWindow();
                $this->client->redraw();
            } else {
                $this->client->redrawDialog();
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Cross-cutting: focus lookup
    // -------------------------------------------------------------------------

    /** The focusable widget under the pointer, if any. */
    private function findFocusableHit(int $mx, int $my): ?Focusable
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof Focusable && $w->hitTestForFocus($mx, $my)
        );
        return $found instanceof Focusable ? $found : null;
    }
}
