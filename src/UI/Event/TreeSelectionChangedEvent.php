<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeView;

/**
 * The set of selected nodes in a tree changed.
 *
 * {@see TreeNodeSelectedEvent} says "this node was picked", which is all a
 * single-select tree ever needs. A multi-select tree also *loses* nodes —
 * Ctrl+click on a selected row takes it out — and that has no node to name, so
 * a listener that cares about the whole selection listens to this instead.
 * Fired by every tree whenever the set changes, multi-select or not.
 */
final class TreeSelectionChangedEvent extends AbstractUiEvent
{
    /**
     * @param list<TreeNode> $nodes The selection as it is now, in row order.
     */
    public function __construct(
        public readonly TreeView $tree,
        public readonly array    $nodes,
    ) {}
}
