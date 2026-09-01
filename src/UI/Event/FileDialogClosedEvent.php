<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\FileDialog;
use Cyrnetix\X11\UI\Widget\FileDialogMode;

/**
 * A {@see FileDialog} closed. $path is the absolute path the user chose, or
 * null if they cancelled — so a listener's first question is always the same
 * one, and there's no separate "cancelled" event to forget to handle.
 *
 * In Save mode the path is what the user typed and need not exist yet; the
 * dialog guarantees only that its parent directory does.
 */
final class FileDialogClosedEvent extends AbstractUiEvent
{
    /** Records the dialog, the mode and the path. */
    public function __construct(
        public readonly FileDialog     $dialog,
        public readonly FileDialogMode $mode,
        public readonly ?string        $path,
    ) {}

    /** Whether the user dismissed the dialog without choosing anything. */
    public function wasCancelled(): bool
    {
        return $this->path === null;
    }
}
