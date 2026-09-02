<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Renderer;

/**
 * A Focusable that holds an editable, single-line string with a caret and
 * an optional selection range. WidgetManager uses this interface to wire up
 * click-to-position, drag-to-extend, and Ctrl+A — the per-key editing
 * remains inside the widget's own handleKey().
 *
 * Selection is modelled as a cursor + anchor pair:
 *   cursor == anchor → no selection (just a caret)
 *   cursor != anchor → selection covers [min, max) of the two positions
 */
interface EditableText extends Focusable
{
    /** Convert a window-local X coordinate to a character index in the text. */
    public function pixelToCursor(int $mouseX, Renderer $r): int;

    /**
     * Move the caret to $pos. With $extend = false, anchor follows cursor
     * (clearing any selection); with $extend = true, anchor is preserved
     * (growing or shrinking the selection).
     */
    public function selectCursor(int $pos, bool $extend): void;

    /** Cursor at end of text, anchor at 0 — the Ctrl+A behaviour. */
    public function selectAll(): void;

    /** Whether it has a selection. */
    public function hasSelection(): bool;
    /** The selection start. */
    public function getSelectionStart(): int;
    /** The selection end. */
    public function getSelectionEnd(): int;

    /**
     * Painter helper: the leftmost visible character index given the field's
     * available render width. Lives on the widget so painter + click-to-
     * position resolve to identical values.
     */
    public function viewStart(Renderer $r, int $availWidth): int;
}
