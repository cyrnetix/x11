<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeView;

/**
 * A tree node was selected.
 *
 * Carries the tree as well as the node because a `TreeNode` has no parent pointer
 * — it cannot say which tree it belongs to, and an application with two trees
 * needs to know.
 */
final class TreeNodeSelectedEvent extends AbstractUiEvent
{
    /** Records the tree and the node that was selected. */
    public function __construct(
        public readonly TreeView $tree,
        public readonly TreeNode $node,
    ) {}
}
