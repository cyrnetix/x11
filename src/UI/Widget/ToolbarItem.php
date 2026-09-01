<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Closure;
use Cyrnetix\X11\Drawing\IconName;

/**
 * One slot in a {@see Toolbar} — either a button (push or toggle) or a
 * thin separator. Items are constructed via the factories so callers
 * don't have to think about which flags pair together.
 *
 * State that flips at runtime (checked, enabled) stays mutable on the
 * item; the immutable bits (id, icon, label, onClick, isSeparator,
 * isToggle) are readonly.
 */
final class ToolbarItem
{
    /** Takes its label. */
    public function __construct(
        public readonly string    $id,
        public readonly ?IconName $icon,
        public readonly string    $label,
        public readonly ?Closure  $onClick,
        public readonly bool      $isSeparator,
        public readonly bool      $isToggle,
        public bool               $checked = false,
        public bool               $enabled = true,
    ) {}

    /** A toolbar button: an icon, an optional label and what pressing it does. */
    public static function button(string $id, IconName $icon, string $label = '', ?Closure $onClick = null): self
    {
        return new self($id, $icon, $label, $onClick, isSeparator: false, isToggle: false);
    }

    /** A toolbar button that stays in, like a bold button. */
    public static function toggle(string $id, IconName $icon, string $label = '', bool $checked = false, ?Closure $onClick = null): self
    {
        return new self($id, $icon, $label, $onClick, isSeparator: false, isToggle: true, checked: $checked);
    }

    /** A gap between groups of toolbar buttons. */
    public static function separator(): self
    {
        return new self(id: '', icon: null, label: '', onClick: null, isSeparator: true, isToggle: false);
    }
}
