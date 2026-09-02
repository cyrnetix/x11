<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Event;

use Cyrnetix\X11\UI\Widget\WindowFrame;

/**
 * The caption's close button was clicked. Minimising and maximising are pure
 * window operations the frame handler performs itself, but closing is app
 * policy — save prompts, "are you sure", multi-window bookkeeping — so it's
 * dispatched and left to the application to honour with
 * {@see \Cyrnetix\X11\Client\X11Client::closeWindow()}.
 */
final class WindowCloseRequestedEvent extends AbstractUiEvent
{
    /** Records the frame. */
    public function __construct(public readonly WindowFrame $frame) {}
}
