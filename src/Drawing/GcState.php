<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * What a graphics context is believed to hold, shared by everyone who draws
 * through it.
 *
 * The toolkit has one GC and several {@see Renderer}s — the main window, the
 * message box, the file dialog, each application window. They all point at that
 * same server-side object, so a colour cache kept privately by one renderer
 * would let it skip a write that another renderer had already invalidated: set
 * red here, blue there, and the first renderer still believes red.
 *
 * So the cache lives with the GC rather than with the renderer, and every
 * renderer sharing a GC shares this.
 */
final class GcState
{
    public ?int $foreground = null;
    public ?int $background = null;

    /** Anything that might have moved the GC's colours calls this. */
    public function forget(): void
    {
        $this->foreground = null;
        $this->background = null;
    }
}
