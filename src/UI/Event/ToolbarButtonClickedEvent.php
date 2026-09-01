<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\Toolbar;
use Cyrnetix\X11\UI\Widget\ToolbarItem;

/** A toolbar button was activated. Carries the toolbar and the item, since one handler serves many. */
final class ToolbarButtonClickedEvent extends AbstractUiEvent
{
    /** Records the toolbar and the item. */
    public function __construct(
        public readonly Toolbar     $toolbar,
        public readonly ToolbarItem $item,
    ) {}
}
