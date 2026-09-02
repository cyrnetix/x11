<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Theme\Surface;

/**
 * One page within a TabView. Just a labelled container — its content area
 * coincides with its own origin (no padding) since the parent TabView already
 * accounts for the tab header and panel border.
 */
final class TabPage extends Widget
{
    /** Takes its label. */
    public function __construct(
        public readonly string $label,
    ) {
        parent::__construct(0, 0);
    }

    /** Everything on a page sits on the tab panel, which some themes lighten. */
    public function childSurface(): ?Surface { return Surface::Panel; }
}
