<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

use Closure;
use React\EventLoop\LoopInterface;
use React\Filesystem\AdapterInterface;
use React\Filesystem\Node\DirectoryInterface;
use React\Filesystem\Node\FileInterface;
use React\Filesystem\Node\NodeInterface;
use React\Promise\PromiseInterface;
use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeNodeProvider;
use function React\Promise\resolve;

/**
 * Filesystem-backed TreeNodeProvider.
 *
 * Without an $adapter it reads the directory directly; with a react/filesystem
 * one the flow per expand is detect → ls → translate. Both return a promise, so
 * callers never learn which is in use — see {@see DirectoryLister} for why the
 * dependency is optional.
 *
 * $simulatedDelay > 0 wraps the whole pipeline in Loop::addTimer so the
 * busy mouse cursor is visibly toggled — useful for demos. Set to 0 for
 * production-style as-fast-as-possible loads.
 */
final class FilesystemTreeProvider implements TreeNodeProvider
{
    /** Takes the event loop. */
    public function __construct(
        private readonly LoopInterface     $loop,
        private readonly ?AdapterInterface $adapter         = null,
        private readonly ?Closure          $folderIcon      = null,
        private readonly ?Closure          $folderOpenIcon  = null,
        private readonly ?Closure          $fileIcon        = null,
        private readonly float             $simulatedDelay  = 0.0,
    ) {}

    /** The children. */
    public function getChildren(TreeNode $parent): PromiseInterface
    {
        $path = $parent->data;
        if (!is_string($path)) return resolve([]);

        if ($this->adapter === null) {
            $pipeline = resolve($this->readDirectly($path));
        } else {
            $pipeline = $this->adapter->detect($path)
                ->then(static function (NodeInterface $node): PromiseInterface {
                    if (!$node instanceof DirectoryInterface) {
                        return resolve([]);
                    }
                    return $node->ls();
                })
                ->then(fn(array $entries): array => $this->toTreeNodes($entries))
                ->then(null, static fn(\Throwable $_): array => []);
        }

        if ($this->simulatedDelay <= 0.0) {
            return $pipeline;
        }

        // Optional pad-the-load: keep the wait cursor on screen long enough
        // that the user can actually see it on small directories.
        return $pipeline->then(fn(array $children): PromiseInterface =>
            new \React\Promise\Promise(function ($resolveFn) use ($children): void {
                $this->loop->addTimer($this->simulatedDelay, static fn() => $resolveFn($children));
            })
        );
    }

    /**
     * scandir, straight to TreeNodes.
     *
     * @return list<TreeNode>
     */
    private function readDirectly(string $path): array
    {
        if (!is_dir($path) || !is_readable($path)) return [];

        $names = @scandir($path);
        if ($names === false) return [];

        $children = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;

            $full  = rtrim($path, '/') . '/' . $name;
            $isDir = is_dir($full);

            $children[] = $this->node($name, $full, $isDir);
        }

        return self::sorted($children);
    }

    /**
     * @param array<NodeInterface> $entries
     * @return list<TreeNode>
     */
    private function toTreeNodes(array $entries): array
    {
        $children = [];
        foreach ($entries as $entry) {
            $isDir  = $entry instanceof DirectoryInterface;
            $isFile = $entry instanceof FileInterface;
            // react/filesystem also yields NotExist / Unknown nodes; we
            // only know what to do with regular directories and files.
            if (!$isDir && !$isFile) continue;

            $children[] = $this->node($entry->name(), $entry->path() . $entry->name(), $isDir);
        }

        return self::sorted($children);
    }

    /** One node, built the same way whichever backend found it. */
    private function node(string $label, string $path, bool $isDir): TreeNode
    {
        return new TreeNode(
            label:              $label,
            iconDrawer:         $isDir ? $this->folderIcon : $this->fileIcon,
            loaded:             !$isDir,                       // dirs stay lazy
            data:               $path,
            expandedIconDrawer: $isDir ? $this->folderOpenIcon : null,
            // Hide files from the tree itself — the ListView paired
            // with the TreeView still picks them up via getChildren().
            treeVisible:        $isDir,
        );
    }

    /**
     * @param list<TreeNode> $children
     * @return list<TreeNode>
     */
    private static function sorted(array $children): array
    {
        usort($children, static function (TreeNode $a, TreeNode $b): int {
            $aDir = !$a->loaded;
            $bDir = !$b->loaded;
            if ($aDir !== $bDir) return $aDir ? -1 : 1;
            return strcasecmp($a->label, $b->label);
        });

        return array_values($children);
    }
}
