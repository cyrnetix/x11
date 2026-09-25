<?php
declare(strict_types=1);

/**
 * The clipboard, from WidgetManager::register() alone.
 *
 * Copying out and pasting in both depend on three selection events reaching
 * X11Client, and each application used to register them itself. Only the
 * gallery did, so every other app — the examples, and the tools built on this —
 * could neither copy to another program nor paste from one, with nothing to say
 * why. Under WSLg the other program is Windows, which is how it was noticed.
 *
 * So this wires nothing by hand: a manager is registered and the other side of
 * the conversation is played through the listeners that produced, exactly as the
 * server would deliver it.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11SelectionClearEvent;
use Cyrnetix\X11\Event\X11SelectionNotifyEvent;
use Cyrnetix\X11\Event\X11SelectionRequestEvent;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Handler\TreeViewHandler;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter\MessageBoxPainter;
use Cyrnetix\X11\UI\Painter\TreeViewPainter;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-52s %-22s %s\n", $what, is_array($got) ? json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

/** Keeps whatever the client writes. Only write() does anything. */
final class ClipboardWire implements ConnectionInterface
{
    public string $written = '';
    public function write($data): bool { $this->written .= $data; return true; }
    public function isWritable(): bool { return true; }
    public function isReadable(): bool { return true; }
    public function pause(): void {}
    public function resume(): void {}
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface { return $dest; }
    public function end($data = null): void {}
    public function close(): void {}
    public function getRemoteAddress(): ?string { return null; }
    public function getLocalAddress(): ?string { return null; }
    public function on($event, callable $listener): void {}
    public function once($event, callable $listener): void {}
    public function removeListener($event, callable $listener): void {}
    public function removeAllListeners($event = null): void {}
    public function listeners($event = null): array { return []; }
    public function emit($event, array $arguments = []): void {}

    /** The requests written since the last call, as [opcode, bytes], and forget them. */
    public function take(): array
    {
        $out = [];
        for ($o = 0; $o + 4 <= strlen($this->written);) {
            $len   = unpack('v', substr($this->written, $o + 2, 2))[1] * 4;
            $out[] = [ord($this->written[$o]), substr($this->written, $o, $len)];
            $o    += max(4, $len);
        }
        $this->written = '';
        return $out;
    }
}

const WINDOW    = 0x100;
const OTHER     = 0x500;   // the other application's window
const UTF8      = 300;
const DATA_PROP = 301;
const CLIPBOARD = 302;
const THEIR_PROP = 77;

$logger = new Logger('clipboard');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Error));
$themes   = new ThemeManager(new Win9xTheme());
$renderer = new Renderer();
$wire     = new ClipboardWire();
$client   = new X11Client(Factory::create(), new AsyncEventDispatcher(new ListenerRegistry(), $logger), $logger, $renderer, $themes);
(new ReflectionProperty(X11Client::class, 'windowId'))->setValue($client, WINDOW);
(new ReflectionProperty(X11Client::class, 'conn'))->setValue($client, $wire);
(new ReflectionProperty(X11Client::class, 'atoms'))->setValue($client, [
    'UTF8_STRING' => UTF8, 'PHP_X11_CLIPBOARD_DATA' => DATA_PROP, 'CLIPBOARD' => CLIPBOARD, 'TARGETS' => 303,
]);

$tree     = new WidgetTree($themes);
$manager  = new WidgetManager(
    $client, $renderer, $tree, new MessageBoxPainter($themes), new KeyTranslator(), $logger,
    [], new TreeViewHandler($tree, $client, new DoubleClickDetector(), new TreeViewPainter($themes)),
);
$registry = new ListenerRegistry();
$manager->register($registry);

/** Delivers an event the way the dispatcher would: every listener, in order. */
$deliver = static function (object $event) use ($registry): int {
    $n = 0;
    foreach ($registry->getListenersForEvent($event) as $listener) { $listener($event); $n++; }
    return $n;
};
$opcodes = static fn(array $requests): array => array_map(static fn(array $r): int => $r[0], $requests);

echo "registered by the manager\n";
foreach ([
    'SelectionRequest' => new X11SelectionRequestEvent(0, WINDOW, OTHER, CLIPBOARD, UTF8, THEIR_PROP),
    'SelectionNotify'  => new X11SelectionNotifyEvent(0, WINDOW, CLIPBOARD, UTF8, 0),
    'SelectionClear'   => new X11SelectionClearEvent(0, WINDOW, CLIPBOARD),
] as $name => $event) {
    $check("$name has exactly one listener", count(iterator_to_array($registry->getListenersForEvent($event), false)), 1);
}
$wire->take();

echo "copying out\n";
$client->writeClipboard('0x2F5F7');
$check('Ctrl+C takes the CLIPBOARD (SetSelectionOwner)', $opcodes($wire->take()), [22]);

$deliver(new X11SelectionRequestEvent(0, WINDOW, OTHER, CLIPBOARD, UTF8, THEIR_PROP));
$sent = $wire->take();
$check('a request is answered: ChangeProperty, SendEvent', $opcodes($sent), [18, 25]);
$check('with the text copied, into their property',
    [unpack('V', substr($sent[0][1], 8, 4))[1], substr($sent[0][1], 24, 7)], [THEIR_PROP, '0x2F5F7']);
$check('and told where it is',                   unpack('V', substr($sent[1][1], 12 + 20, 4))[1], THEIR_PROP);

echo "pasting in\n";
$pasted = 'nothing yet';
$client->readClipboard(static function (?string $text) use (&$pasted): void { $pasted = $text; });
$check('Ctrl+V asks the owner (ConvertSelection)', $opcodes($wire->take()), [24]);

$deliver(new X11SelectionNotifyEvent(0, WINDOW, CLIPBOARD, UTF8, DATA_PROP));
$check('their answer is read back (GetProperty)', $opcodes($wire->take()), [20]);

// The GetProperty reply: format 8, value length at 16, the bytes from 32.
$reply    = "\x01\x08" . str_repeat("\0", 14) . pack('V', 6) . str_repeat("\0", 12) . '194039';
$handlers = new ReflectionProperty(X11Client::class, 'replyHandlers');
$queue    = $handlers->getValue($client);
(array_shift($queue))($reply);
$handlers->setValue($client, $queue);
$check('and reaches the widget that asked',      $pasted, '194039');

echo "losing the selection\n";
$deliver(new X11SelectionClearEvent(0, WINDOW, CLIPBOARD));
$deliver(new X11SelectionRequestEvent(0, WINDOW, OTHER, CLIPBOARD, UTF8, THEIR_PROP));
$sent = $wire->take();
$check('once another app copies, a request is refused', [$opcodes($sent), unpack('V', substr($sent[0][1], 12 + 20, 4))[1]], [[25], 0]);

exit($fail === 0 ? 0 : 1);
