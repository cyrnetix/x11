<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * Which part of a window's border was grabbed. Handed to the client, which
 * translates it into the direction the window manager expects.
 */
enum ResizeEdge
{
    case TopLeft;
    case Top;
    case TopRight;
    case Right;
    case BottomRight;
    case Bottom;
    case BottomLeft;
    case Left;
}
