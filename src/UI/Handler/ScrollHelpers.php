<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\UI\Widget\ScrollBar;
use Cyrnetix\X11\UI\Widget\TreeView;

/**
 * Tiny shared helpers for keyboard-nav scrolling. Used by the list-family
 * handlers (ListBox, ListView, TreeView) to keep the selection inside the
 * visible viewport after Up/Down/PgUp/PgDn.
 */
final class ScrollHelpers
{
    /** Adjust $sb so row index $idx is inside the viewport of $pageSize rows. */
    public static function ensureIndexVisible(ScrollBar $sb, int $idx, int $pageSize): void
    {
        $top = $sb->getValue();
        if ($idx < $top) {
            $sb->setValue($idx);
        } elseif ($idx >= $top + $pageSize) {
            $sb->setValue($idx - $pageSize + 1);
        }
    }

    /** Scroll the TreeView so its currently-selected node is visible. */
    public static function ensureTreeRowVisible(TreeView $tv): void
    {
        $sel = $tv->getSelected();
        if ($sel === null) return;
        foreach ($tv->flattenVisibleRows() as $i => [$node, $_]) {
            if ($node === $sel) {
                self::ensureIndexVisible($tv->getScrollBar(), $i, $tv->getVisibleRowCount());
                return;
            }
        }
    }
}
