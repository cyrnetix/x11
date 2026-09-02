<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\ListView;
use Cyrnetix\X11\UI\Widget\ListViewItem;

/**
 * A details-list row was selected.
 *
 * `$item` is null when the selection was cleared, which is a different thing from
 * selecting the first row.
 */
final class ListViewSelectionChangedEvent extends AbstractUiEvent
{
    /** Records the list view and the item. */
    public function __construct(
        public readonly ListView $listView,
        public readonly ?ListViewItem $item,
    ) {}
}
