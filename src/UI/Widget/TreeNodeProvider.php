<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use React\Promise\PromiseInterface;

/**
 * Asynchronous source of TreeNode children. Implementations return a Promise
 * that resolves to a list of child nodes; implementations should NOT block
 * the event loop while computing them — schedule work through
 * Loop::futureTick / Loop::addTimer / a real async backend.
 *
 * The TreeView holds one provider; whenever a node with $loaded=false is
 * expanded for the first time, WidgetManager calls getChildren($node) and
 * splices the resolved list into $node when the promise resolves.
 */
interface TreeNodeProvider
{
    /** @return PromiseInterface<list<TreeNode>> */
    public function getChildren(TreeNode $parent): PromiseInterface;
}
