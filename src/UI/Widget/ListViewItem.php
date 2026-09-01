<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Closure;

/**
 * One row in a ListView. $values is a list of strings indexed by column —
 * missing entries paint as empty. The optional iconDrawer follows the same
 * shape menu items / tree nodes use. $data is a free-form payload (e.g. the
 * absolute path of the file the row represents).
 *
 * $sortGroup and $sortKeys exist because what a cell *shows* and what it
 * *sorts by* aren't always the same thing:
 *
 * - $sortGroup keeps rows that shouldn't interleave apart whatever the sort
 *   column is. A file dialog puts folders in group 0 and files in group 1, so
 *   sorting by size never scatters the folders through the files.
 * - $sortKeys[$column] overrides the value used to compare that cell. "47
 *   bytes" and "1.2 MB" sort wrongly as text, so the size column carries the
 *   raw byte count; the comparison is natural-order, so plain decimals in a
 *   key compare by magnitude.
 */
final class ListViewItem
{
    /** One row: a cell value per column, an optional icon, arbitrary attached data, and how it sorts. */
    public function __construct(
        /** @var list<string> */
        public readonly array $values,
        public readonly ?Closure $iconDrawer = null,
        public readonly mixed $data = null,
        public readonly int $sortGroup = 0,
        /** @var array<int, string> Sparse: column index => comparison value. */
        public readonly array $sortKeys = [],
    ) {}

    /** What column $column compares as — its sort key, else what it displays. */
    public function sortValue(int $column): string
    {
        return $this->sortKeys[$column] ?? $this->values[$column] ?? '';
    }
}
