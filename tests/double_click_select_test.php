<?php
declare(strict_types=1);

/**
 * Double-clicking a text field selects everything in it, as it does on Windows.
 *
 * Driven through EditableTextHandler alone, which is where the press lands once
 * WidgetManager has focused the field — so TextBox and ComboBox's text area get
 * it from one place. The drag that a press normally starts must not survive a
 * double-click, or the pointer drifting before the release shrinks the
 * selection back to wherever it ended up.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Handler\EditableTextHandler;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\ComboBox;
use Cyrnetix\X11\UI\Widget\EditableText;
use Cyrnetix\X11\UI\Widget\TextBox;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-52s %-14s %s\n", $what, is_array($got) ? json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$logger = new Logger('double-click');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Error));
$themes   = new ThemeManager(new Win9xTheme());
$renderer = new Renderer();
// Not connected: redraw() has nothing to write to, which is all this needs.
$client   = new X11Client(Factory::create(), new AsyncEventDispatcher(new ListenerRegistry(), $logger), $logger, $renderer, $themes);
$tree     = new WidgetTree($themes);
$handler  = new EditableTextHandler($tree, $client, $renderer);

$box   = new TextBox(10, 10, 120, 20);
$combo = new ComboBox(10, 50, 120, new SyncEventDispatcher(new ListenerRegistry()));
$tree->addRoot($box);
$tree->addRoot($combo);

$press   = static fn(int $x, int $y, int $t): bool
    => $handler->tryPress(new X11ButtonPressEvent(0, 1, $x, $y, $x, $y, $t, 0));
$release = static fn(int $x, int $y, int $t): bool
    => $handler->tryRelease(new X11ButtonReleaseEvent(0, 1, $x, $y, $x, $y, $t, 0));
$range   = static fn(EditableText $w): array => [$w->getSelectionStart(), $w->getSelectionEnd()];

foreach (['TextBox' => [$box, 15], 'ComboBox' => [$combo, 55]] as $name => [$field, $y]) {
    echo "$name\n";
    $field->setText('hello world');
    $tree->setFocused($field);
    $t = 10_000 * ($y + 1);

    $press(40, $y, $t);      $release(40, $y, $t + 50);
    $check('a single click leaves no selection',          $field->hasSelection(), false);

    $press(40, $y, $t + 150);
    $check('a second click soon after selects it all',    $range($field), [0, 11]);
    $handler->tryMotion(new X11MotionEvent(0, 20, $y, 20, $y, $t + 180, 0));
    $check('and the pointer moving does not undo it',     $range($field), [0, 11]);
    $release(20, $y, $t + 200);

    $press(40, $y, $t + 1_000);  $release(40, $y, $t + 1_050);
    $press(40, $y, $t + 1_900);
    $check('two clicks far apart in time do not',         $field->hasSelection(), false);
    $release(40, $y, $t + 1_950);

    $press(20, $y, $t + 3_000);  $release(20, $y, $t + 3_050);
    $press(90, $y, $t + 3_150);
    $check('nor two far apart on screen',                 $field->hasSelection(), false);
    $release(90, $y, $t + 3_200);
}

exit($fail === 0 ? 0 : 1);
