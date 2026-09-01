<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\Focusable;
use Cyrnetix\X11\UI\Widget\Widget;

/**
 * Shared view of the root widget tree + focus state.
 *
 * Two parallel walks:
 *  - main:     getVisibleChildren() — the normal widget tree (TabView only
 *              returns its active page, etc.).
 *  - overlay:  getOverlayChildren() — popup subtrees that need to paint on
 *              top of everything else and capture clicks before the main
 *              tree gets them.
 *
 * paint uses visit() then visitOverlay() so popups land last in z-order.
 * Hit-test (findFirst) walks overlay first so popups capture priority.
 *
 * The tree is also where widgets pick up the live theme: every root gets the
 * {@see ThemeManager} on attach, and {@see refreshTheme()} re-pushes it after
 * a switch so each widget relayouts against the new metrics.
 */
final class WidgetTree
{
    /** @var list<Widget> */
    private array $roots = [];

    private ?Focusable $focused = null;

    /** Non-null while a modal widget owns input. @see setModal() */
    private ?Widget $modal = null;

    /** Roots reachable to the event being handled. @see setInputScope() */
    private ?array $inputScope = null;

    /** Takes the theme manager. */
    public function __construct(private readonly ThemeManager $themes) {}

    /** The themes. */
    public function themes(): ThemeManager { return $this->themes; }

    /**
     * While a modal widget is set, hit-tests and cursor sweeps see only that
     * widget's subtree — so a click on the dialog's own background can't press
     * an application button that happens to sit behind it.
     *
     * Painting is deliberately unaffected: everything still draws, the modal
     * just stops being reachable. The window frame is exempt too — it's
     * furniture rather than content, and a modal dialog shouldn't take away the
     * ability to move or close the window (see {@see findRoot()}).
     */
    public function setModal(?Widget $modal): void { $this->modal = $modal; }

    /** The modal. */
    public function getModal(): ?Widget { return $this->modal; }

    /**
     * Narrow input to the roots painted into the window an event came from.
     *
     * A window of an application's own is a real X11 window, so its events
     * arrive in *its* coordinates — and a click at (40, 60) in a preview window
     * would otherwise happily match a main-window widget sitting at (40, 60).
     * Modality solves that for a modal form by narrowing input for as long as
     * it's up; a non-modal window needs the same narrowing per event, which is
     * this: {@see \Cyrnetix\X11\UI\WidgetManager} names the window each pointer
     * event came from and only that window's roots are reachable.
     *
     * Null means every root, which is what a key event wants: keys are
     * delivered to whichever window the *window manager* focused, and the
     * focused widget may well be in another one.
     *
     * @param list<Widget>|null $roots
     */
    public function setInputScope(?array $roots): void { $this->inputScope = $roots; }

    /** Roots that input may reach right now. */
    private function reachableRoots(): array
    {
        if ($this->modal !== null) return [$this->modal];

        return $this->inputScope ?? $this->roots;
    }

    /**
     * First *root* matching $pred, ignoring modality. For the window frame,
     * which stays usable while a modal dialog is open.
     *
     * @param callable(Widget): bool $pred
     */
    public function findRoot(callable $pred): ?Widget
    {
        foreach ($this->roots as $root) {
            if ($pred($root)) return $root;
        }
        return null;
    }

    /**
     * Register a root, and hand it the live theme.
     *
     * **Idempotent.** A window's root is now registered by
     * {@see \Cyrnetix\X11\Client\X11Client::createChildWindow()} as well as by
     * whatever the application does, and adding the same widget twice would paint
     * it twice and walk it twice.
     */
    public function addRoot(Widget $w): void
    {
        if (!in_array($w, $this->roots, true)) $this->roots[] = $w;

        $w->attachThemes($this->themes);
    }

    /**
     * Re-hand the live theme to every root, which relayouts the whole tree.
     * Call after {@see ThemeManager::select()} and before repainting.
     */
    public function refreshTheme(): void
    {
        foreach ($this->roots as $root) {
            $root->attachThemes($this->themes);
        }
    }

    /** @return list<Widget> */
    public function getRoots(): array { return $this->roots; }

    /** The widget with keyboard focus, if any. */
    public function getFocused(): ?Focusable { return $this->focused; }

    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(?Focusable $next): bool
    {
        if ($next === $this->focused) return false;
        $this->focused?->setFocused(false);
        $next?->setFocused(true);
        $this->focused = $next;
        return true;
    }

    /**
     * Main-tree walk: depth-first pre-order over getVisibleChildren().
     * Overlay children are NOT visited here — see {@see visitOverlay()}.
     */
    public function visit(callable $fn): void
    {
        foreach ($this->roots as $root) {
            $this->visitMain($root, $fn);
        }
    }

    /**
     * One subtree, main-walk order. For a subtree painted into its own window:
     * the caller supplies that window's Renderer instead of the main one.
     */
    public function visitSubtree(Widget $root, callable $fn): void
    {
        $this->visitMain($root, $fn);
    }

    /** Overlay subtrees within one subtree (a popup inside a dialog). */
    public function visitSubtreeOverlay(Widget $root, callable $fn): void
    {
        $this->collectOverlay($root, $fn);
    }

    /** Walks a widget and its visible children, depth first. */
    private function visitMain(Widget $node, callable $fn): void
    {
        $fn($node);
        foreach ($node->getVisibleChildren() as $child) {
            $this->visitMain($child, $fn);
        }
    }

    /**
     * Every widget in the tree, overlay subtrees first.
     *
     * For sweeps that ask "which widget is under the cursor" or "did any
     * widget's hover state change" — those have to see popups too, and in
     * front-to-back order so the topmost one answers first. {@see visit()} is
     * for the main tree alone, which is what paintAll wants because it paints
     * the overlays separately and afterwards.
     */
    public function visitAll(callable $fn): void
    {
        foreach ($this->reachableRoots() as $root) {
            $this->collectOverlay($root, $fn);
        }
        foreach ($this->reachableRoots() as $root) {
            $this->visitMain($root, $fn);
        }
    }

    /**
     * Overlay walk: every overlay subtree in the entire tree, visited as
     * its own depth-first pre-order. Called from paintAll AFTER the main
     * walk so popups land on top in z-order.
     */
    public function visitOverlay(callable $fn): void
    {
        foreach ($this->roots as $root) {
            $this->collectOverlay($root, $fn);
        }
    }

    /**
     * Walks the overlay children hanging off a widget - popups, which are not ordinary
     * children.
     */
    private function collectOverlay(Widget $node, callable $fn): void
    {
        foreach ($node->getOverlayChildren() as $overlay) {
            $this->visitMain($overlay, $fn);
            // Nested overlays inside an overlay subtree (rare but valid).
            $this->collectOverlay($overlay, $fn);
        }
        foreach ($node->getVisibleChildren() as $child) {
            $this->collectOverlay($child, $fn);
        }
    }

    /**
     * Every focusable widget that input can currently reach, in tree order.
     *
     * Tree order *is* the tab order: depth-first, in the order children were
     * added, which is the order they were laid out in and therefore the order
     * they read on screen. A widget wanting to be somewhere else in the sequence
     * should be added somewhere else.
     *
     * Scoped by modality, so tabbing inside a form cannot walk out of it into
     * the window behind.
     *
     * @return list<Focusable>
     */
    public function focusables(): array
    {
        $found = [];

        $collect = static function (Widget $w) use (&$found): void {
            // canTakeFocus, not just the interface: a disabled control would
            // otherwise be a dead stop the user has to tab past.
            if ($w instanceof Focusable && $w->canTakeFocus()) $found[] = $w;
        };

        foreach ($this->reachableRoots() as $root) {
            $this->visitMain($root, $collect);
            $this->collectOverlay($root, $collect);
        }

        return $found;
    }

    /**
     * Move focus to the next (or previous) focusable, wrapping around.
     *
     * Returns false when there is nothing to move to — no focusables, or only
     * the one that already has focus — so the caller knows not to repaint.
     */
    public function focusNext(bool $backwards = false): bool
    {
        $order = $this->focusables();
        $count = count($order);
        if ($count === 0) return false;

        $current = $this->focused;
        $at      = $current === null ? -1 : array_search($current, $order, strict: true);

        if ($at === false) {
            // Focused on something no longer reachable — start from the top.
            $at = -1;
        }

        $next = $at === -1
            ? ($backwards ? $count - 1 : 0)
            : ($at + ($backwards ? -1 : 1) + $count) % $count;

        if ($order[$next] === $current) return false;

        return $this->setFocused($order[$next]);
    }

    /**
     * First widget for which $pred returns true. Overlay subtrees are
     * checked BEFORE the main tree so popups (open dropdown lists, open
     * calendar grids) win hit-test against any sibling that would
     * otherwise sit under the cursor at the same coords.
     *
     * @param callable(Widget): bool $pred
     */
    public function findFirst(callable $pred): ?Widget
    {
        // Overlay subtrees first.
        foreach ($this->reachableRoots() as $root) {
            $f = $this->findInOverlay($root, $pred);
            if ($f !== null) return $f;
        }
        // Then the main tree.
        foreach ($this->reachableRoots() as $root) {
            $f = $this->findInMain($root, $pred);
            if ($f !== null) return $f;
        }
        return null;
    }

    /** The first widget in this subtree matching the predicate. */
    private function findInMain(Widget $node, callable $pred): ?Widget
    {
        if ($pred($node)) return $node;
        foreach ($node->getVisibleChildren() as $child) {
            $f = $this->findInMain($child, $pred);
            if ($f !== null) return $f;
        }
        return null;
    }

    /**
     * The first overlay widget matching the predicate, so an open popup wins over what is
     * under it.
     */
    private function findInOverlay(Widget $node, callable $pred): ?Widget
    {
        foreach ($node->getOverlayChildren() as $overlay) {
            $f = $this->findInMain($overlay, $pred);
            if ($f !== null) return $f;
            $f = $this->findInOverlay($overlay, $pred);
            if ($f !== null) return $f;
        }
        foreach ($node->getVisibleChildren() as $child) {
            $f = $this->findInOverlay($child, $pred);
            if ($f !== null) return $f;
        }
        return null;
    }
}
