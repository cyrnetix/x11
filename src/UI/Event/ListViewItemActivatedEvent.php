<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\ListView;
use Cyrnetix\X11\UI\Widget\ListViewItem;

/**
 * Fired when a ListView item is "activated" — the canonical interaction is
 * a double-click on the row, but the same event would fire on Enter while
 * a row is selected (once we wire that up).
 */
final class ListViewItemActivatedEvent extends AbstractUiEvent
{
    /** Records the list view and the item. */
    public function __construct(
        public readonly ListView $listView,
        public readonly ListViewItem $item,
    ) {}
}
