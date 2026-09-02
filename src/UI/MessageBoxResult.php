<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

/** Return value from a closed MessageBox, matching Win32 IDOK / IDCANCEL / … */
enum MessageBoxResult: int
{
    case Ok     = 1;
    case Cancel = 2;
    case Yes    = 6;
    case No     = 7;
}
