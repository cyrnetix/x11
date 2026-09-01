<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Painter\TreeViewPainter;
use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeView;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * TreeView — toggle clicks expand/collapse (triggering a lazy load if the
 * children haven't been fetched yet); row label clicks select + browse +
 * double-click toggles expansion (Explorer's behaviour). Keyboard nav
 * walks flattenVisibleRows; Right/Left expand/collapse with Right also
 * descending into expanded nodes and Left jumping to the parent. Lazy
 * loads flip the busy cursor on globally while any load is in flight —
 * the counter lives here since this handler owns the only call site.
 */
final class TreeViewHandler extends WidgetHandler
{
    /** Count of in-flight TreeNodeProvider requests — busy cursor while > 0. */
    private int $pendingLoads = 0;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree          $tree,
        private readonly X11Client           $client,
        private readonly DoubleClickDetector $doubleClick,
        private readonly TreeViewPainter     $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof TreeView) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $tv = $this->findHit($event->x, $event->y);
        if ($tv === null) return false;

        $hit = $tv->hitTestRow($event->x, $event->y);
        if ($hit === null) return true;

        [$region, $node] = $hit;

        if ($region === 'toggle') {
            $tv->toggleExpanded($node);
            if ($node->expanded && !$node->loaded && !$node->loading
                && $tv->getProvider() !== null) {
                $this->loadChildren($tv, $node);
            }
        } else {
            // Row label click: select + browse.
            $tv->setSelected($node);
            if (!$node->loaded && !$node->loading && $tv->getProvider() !== null) {
                $this->loadChildren($tv, $node);
            }
            // Double-click on a label toggles expansion (Explorer style).
            if ($this->doubleClick->detect($node, $event->time, $event->x, $event->y)
                && !$node->isLeaf()) {
                $tv->toggleExpanded($node);
                if ($node->expanded && !$node->loaded && !$node->loading
                    && $tv->getProvider() !== null) {
                    $this->loadChildren($tv, $node);
                }
            }
        }
        $this->client->redraw();
        return true;
    }

    /**
     * Handles key if it belongs to this widget kind. True means the event was claimed and no later
     * handler sees it.
     */
    public function tryKey(string $key): bool
    {
        $tv = $this->tree->getFocused();
        if (!$tv instanceof TreeView) return false;

        $rows  = $tv->flattenVisibleRows();
        $total = count($rows);
        if ($total === 0) return false;

        $current  = $tv->getSelected();
        $curIdx   = -1;
        $curDepth = 0;
        foreach ($rows as $i => [$node, $depth]) {
            if ($node === $current) { $curIdx = $i; $curDepth = $depth; break; }
        }

        $page = $tv->getVisibleRowCount();

        switch ($key) {
            case 'Up':
                $nextIdx = $curIdx <= 0 ? 0 : $curIdx - 1;
                break;
            case 'Down':
                $nextIdx = $curIdx < 0 ? 0 : min($total - 1, $curIdx + 1);
                break;
            case 'Home':
                $nextIdx = 0;
                break;
            case 'End':
                $nextIdx = $total - 1;
                break;
            case 'PgUp':
                $nextIdx = max(0, ($curIdx < 0 ? 0 : $curIdx) - $page);
                break;
            case 'PgDn':
                $nextIdx = min($total - 1, ($curIdx < 0 ? 0 : $curIdx) + $page);
                break;

            case 'Right':
                if ($current === null || $current->isLeaf()) {
                    $this->client->redraw();
                    return true;
                }
                if (!$current->expanded) {
                    $tv->toggleExpanded($current);
                    if (!$current->loaded && !$current->loading
                        && $tv->getProvider() !== null) {
                        $this->loadChildren($tv, $current);
                    }
                } else {
                    // Descend to first child if visible.
                    if (isset($rows[$curIdx + 1]) && $rows[$curIdx + 1][1] > $curDepth) {
                        $tv->setSelected($rows[$curIdx + 1][0]);
                        ScrollHelpers::ensureTreeRowVisible($tv);
                    }
                }
                $this->client->redraw();
                return true;

            case 'Left':
                if ($current === null) { $this->client->redraw(); return true; }
                if (!$current->isLeaf() && $current->expanded) {
                    $tv->toggleExpanded($current);
                    $this->client->redraw();
                    return true;
                }
                // Walk backwards for first row at depth - 1 (no parent ptr).
                if ($curDepth > 0) {
                    for ($i = $curIdx - 1; $i >= 0; $i--) {
                        if ($rows[$i][1] === $curDepth - 1) {
                            $tv->setSelected($rows[$i][0]);
                            ScrollHelpers::ensureTreeRowVisible($tv);
                            break;
                        }
                    }
                }
                $this->client->redraw();
                return true;

            case 'Enter':
                if ($current === null || $current->isLeaf()) {
                    $this->client->redraw();
                    return true;
                }
                $tv->toggleExpanded($current);
                if ($current->expanded && !$current->loaded && !$current->loading
                    && $tv->getProvider() !== null) {
                    $this->loadChildren($tv, $current);
                }
                $this->client->redraw();
                return true;

            default:
                return false;
        }

        if (!isset($rows[$nextIdx])) {
            $this->client->redraw();
            return true;
        }
        $tv->setSelected($rows[$nextIdx][0]);
        ScrollHelpers::ensureTreeRowVisible($tv);
        $this->client->redraw();
        return true;
    }

    /**
     * Programmatic equivalent of clicking a tree row label — select the
     * node and kick off a lazy load if its children aren't in memory yet.
     * Public so external code (e.g. a ListView double-click listener) can
     * navigate the tree without going through a synthetic click.
     */
    public function selectNode(TreeView $tv, TreeNode $node): void
    {
        $tv->setSelected($node);
        if (!$node->loaded && !$node->loading && $tv->getProvider() !== null) {
            $this->loadChildren($tv, $node);
        }
        $this->client->redraw();
    }

    /**
     * Resolve a TreeNode's children from its TreeView's provider. Flips
     * the busy cursor on while at least one load is in flight, and
     * re-syncs the scrollbar's range on resolve (since the row count
     * changes).
     */
    private function loadChildren(TreeView $tv, TreeNode $node): void
    {
        $provider = $tv->getProvider();
        if ($provider === null) return;

        $node->loading = true;
        $this->pendingLoads++;
        if ($this->pendingLoads === 1) {
            $this->client->setBusyCursor(true);
        }

        $provider->getChildren($node)->then(function (array $children) use ($tv, $node): void {
            foreach ($children as $child) {
                $node->addChild($child);
            }
            $node->loaded  = true;
            $node->loading = false;
            $tv->refreshScrollRange();
            // Match Explorer's "expand also selects" — gives listeners
            // (e.g. the ListView) a TreeNodeSelectedEvent to react to
            // without the user needing a second click on the row label.
            $tv->setSelected($node, force: true);

            $this->pendingLoads--;
            if ($this->pendingLoads === 0) {
                $this->client->setBusyCursor(false);
            }
            $this->client->redraw();
        });
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?TreeView
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof TreeView && $w->hitTest($mx, $my)
        );
        return $found instanceof TreeView ? $found : null;
    }
}
