<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Closure;

/**
 * One node in a TreeView. Holds its label, optional small icon drawer
 * (same Closure(Renderer, x, y, size) shape menu items use), and any
 * children. The `expanded` flag is mutable — clicking the [+]/[−] toggle
 * flips it, and TreeView re-walks the tree to recompute the visible rows.
 */
final class TreeNode
{
    /** @var list<TreeNode> */
    private array $children = [];

    /** Children have been materialised (true) or a provider still owes us a list (false). */
    public bool $loaded;

    /** A provider call is currently in flight — painter shows "…". */
    public bool $loading = false;

    /** Takes its label. */
    public function __construct(
        public readonly string   $label,
        public bool              $expanded   = false,
        public readonly ?Closure $iconDrawer = null,
        bool                     $loaded     = true,
        /** Free-form payload for providers (e.g. an absolute filesystem path). */
        public readonly mixed    $data       = null,
        /**
         * Alternate icon used while the node is expanded. Painter falls
         * back to {@see $iconDrawer} when this is null — so callers that
         * don't care about the open/closed distinction (a leaf, a fixed
         * icon) just leave it unset.
         */
        public readonly ?Closure $expandedIconDrawer = null,
        /**
         * When false the node is still kept in its parent's children
         * (so listeners reading getChildren() see it) but TreeView skips
         * it during its row flatten — used to hide files from the tree
         * while keeping them available to the ListView paired with it.
         */
        public readonly bool     $treeVisible = true,
    ) {
        $this->loaded = $loaded;
    }

    /** Adds a child. */
    public function addChild(TreeNode $child): self
    {
        $this->children[] = $child;
        return $this;
    }

    /** @return list<TreeNode> */
    public function getChildren(): array { return $this->children; }

    /**
     * Treat unloaded nodes as non-leaves — they probably have children, we
     * just haven't asked the provider yet. After loading, a directory that
     * turned out to be empty IS a leaf and gets no toggle.
     */
    public function isLeaf(): bool { return $this->loaded && $this->children === []; }
}
