<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * Any widget that can hold keyboard focus: text fields, lists, and — so a form
 * can be tabbed through — buttons and checkboxes.
 *
 * WidgetManager tracks a single Focusable as $focused and routes X11 key
 * events to handleKey(). Click handling uses hitTestForFocus() to decide
 * which subregion of the widget actually takes focus (e.g. ComboBox focuses
 * only on its text area, not its arrow button).
 */
interface Focusable
{
    /** Whether it is focused. */
    public function isFocused(): bool;
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void;
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool;

    /**
     * Can this widget take focus *right now*?
     *
     * Tab traversal asks before offering focus, so a disabled control drops out
     * of the order instead of becoming a dead stop the user has to tab past.
     * Widgets with no disabled state return true.
     */
    public function canTakeFocus(): bool;

    /** Returns true if the key changed visible state (caller redraws). */
    public function handleKey(string $key): bool;

    /**
     * Text to copy onto the clipboard, or null if the widget has nothing
     * to offer right now. (No selection model yet — implementations return
     * the entire field contents.)
     */
    public function copy(): ?string;

    /**
     * Insert $text at the cursor (or wherever makes sense). Non-printable
     * characters should be silently dropped.
     */
    public function paste(string $text): void;
}
