<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * Interaction state of a control, as the widget already tracks it. Themes
 * differ in which states they render distinctly — Win9x ignores Hovered on
 * plain buttons, CDE brightens the top shadow, Platinum inverts on press.
 */
enum ControlState
{
    case Normal;
    case Hovered;
    case Pressed;
    case Disabled;

    /** Resting state, but this is the dialog's default action. */
    case Default;

    /**
     * The state a control is in, from the three things that decide it. Disabled wins over the
     * rest.
     */
    public static function of(bool $pressed, bool $hovered = false, bool $enabled = true): self
    {
        if (!$enabled) return self::Disabled;
        if ($pressed)  return self::Pressed;
        if ($hovered)  return self::Hovered;
        return self::Normal;
    }

    /** Whether it is pressed. */
    public function isPressed(): bool { return $this === self::Pressed; }
}
