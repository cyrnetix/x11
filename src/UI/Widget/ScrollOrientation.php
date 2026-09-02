<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/** Whether a scrollbar runs down the side of its content or along the bottom. */
enum ScrollOrientation
{
    case Vertical;
    case Horizontal;
}
