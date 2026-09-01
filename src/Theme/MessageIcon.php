<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/** Severity glyph a message box asks its theme to draw. */
enum MessageIcon
{
    case Information;
    case Warning;
    case Error;
    case Question;
}
