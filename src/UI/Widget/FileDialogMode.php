<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * What a {@see FileDialog} is being opened for. This is the single switch the
 * user asked to be configurable, and everything else follows from it: which
 * button label appears, whether a filename may be typed, whether files are
 * listed at all, and what counts as a valid choice.
 */
enum FileDialogMode: string
{
    case OpenFile   = 'open-file';
    case SaveFile   = 'save-file';
    case OpenFolder = 'open-folder';

    /** Save is the only mode where the user names something that isn't there yet. */
    public function isSave(): bool
    {
        return $this === self::SaveFile;
    }

    /** Folder mode lists directories only — a file can't be the answer. */
    public function picksFolder(): bool
    {
        return $this === self::OpenFolder;
    }

    /** No filename to type when the target is a folder you navigate to. */
    public function hasNameField(): bool
    {
        return !$this->picksFolder();
    }

    /** Nothing to filter when only directories are shown. */
    public function hasFilterField(): bool
    {
        return !$this->picksFolder();
    }

    /** The default title. */
    public function defaultTitle(): string
    {
        return match ($this) {
            self::OpenFile   => 'Open',
            self::SaveFile   => 'Save As',
            self::OpenFolder => 'Select Folder',
        };
    }

    /** The accept label. */
    public function acceptLabel(): string
    {
        return match ($this) {
            self::OpenFile   => 'Open',
            self::SaveFile   => 'Save',
            self::OpenFolder => 'Select',
        };
    }
}
