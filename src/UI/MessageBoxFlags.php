<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

/**
 * Bitwise flag constants for MessageBox, mirroring the Win32 MB_* values.
 *
 * Usage:
 *   MessageBoxFlags::BUTTONS_OK | MessageBoxFlags::ICON_INFO
 *   MessageBoxFlags::BUTTONS_YES_NO | MessageBoxFlags::ICON_QUESTION
 */
final class MessageBoxFlags
{
    // ---- Button sets (bits 0–3) -------------------------------------------
    public const BUTTONS_OK            = 0x00;
    public const BUTTONS_OK_CANCEL     = 0x01;
    public const BUTTONS_YES_NO_CANCEL = 0x03;
    public const BUTTONS_YES_NO        = 0x04;

    // ---- Icons (bits 4–7) ------------------------------------------------
    public const ICON_NONE     = 0x00;
    public const ICON_ERROR    = 0x10;  // MB_ICONERROR
    public const ICON_QUESTION = 0x20;  // MB_ICONQUESTION
    public const ICON_WARNING  = 0x30;  // MB_ICONWARNING / MB_ICONEXCLAMATION
    public const ICON_INFO     = 0x40;  // MB_ICONINFORMATION

    // ---- Extraction masks ------------------------------------------------
    public const MASK_BUTTONS = 0x0F;
    public const MASK_ICON    = 0xF0;
}
