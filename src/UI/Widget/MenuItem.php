<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Closure;

/**
 * One row in a popup Menu. Plain data — no behaviour besides holding the
 * closure that fires when the item is clicked.
 *
 * - $shortcut    : right-aligned accelerator text ("Ctrl+S"), display only.
 * - $onClick     : null or a Closure called when the item is clicked.
 * - $enabled     : disabled items render grayed-out and ignore clicks.
 * - $checked     : draws a check-mark in the icon column. Mutable, so a menu
 *                  can reflect live state (the demo's theme picker).
 * - $iconDrawer  : optional Closure(Renderer, x, y, size) for a custom 14×14
 *                  icon drawn in the icon column. Overrides $checked.
 * - $submenu     : non-null → row shows a ▶ on the right; clicking it opens
 *                  the submenu to the right instead of firing onClick.
 */
final class MenuItem
{
    /** Takes its label. */
    public function __construct(
        public readonly string   $label,
        public readonly ?string  $shortcut    = null,
        public readonly ?Closure $onClick     = null,
        public readonly bool     $enabled     = true,
        public bool              $checked     = false,
        public readonly ?Closure $iconDrawer  = null,
        public readonly ?Menu    $submenu     = null,
    ) {}
}
