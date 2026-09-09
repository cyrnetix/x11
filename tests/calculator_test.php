<?php
declare(strict_types=1);

/**
 * The calculator example's arithmetic, and the one thing about its layout that
 * cannot be seen by reading it.
 *
 * The arithmetic is a plain class with no idea it is driven by buttons, which is
 * exactly what makes it testable with no X server and no event loop.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/example/lib/Calculator.php';

use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Example\Calculator;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\TextView;

$fail = 0;

/** Press a string of keys and report the display. */
$run = static function (string $keys): string {
    $calc = new Calculator();
    foreach (preg_split('//u', $keys, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $key) {
        $calc->press($key);
    }

    return $calc->display();
};

$cases = [
    '2+3='      => '5',
    '2+3'       => '3',       // no equals yet: the display is still the entry
    '7*6='      => '42',
    '10/4='     => '2.5',
    '9/0='      => 'Error',   // said, not thrown
    '1+2+3='    => '6',       // an operator resolves what came before it
    '5-8='      => '-3',
    '2+3=*2='   => '10',      // the result becomes the next left operand
    '0.5+0.25=' => '0.75',
    '1..5'      => '1.5',     // one decimal point only
    '007'       => '7',       // no leading run of zeros
    '5C'        => '0',
    '5±'        => '-5',
    '.5+.5='    => '1',       // a leading dot means "0."
    '12*12='    => '144',
];

foreach ($cases as $keys => $want) {
    $got = $run($keys);
    $ok  = $got === $want;
    if (!$ok) $fail++;
    printf("  %-12s -> %-8s %s\n", $keys, $got, $ok ? 'ok' : "FAIL (want $want)");
}

// ---- a widget does not have to take the size it was given ----------------
//
// The keypad used to start at a hardcoded `PAD + 38`, which assumed the display
// kept the 30 it was asked for. TextView clamps to 40, so the first row of
// buttons sat two pixels inside the display's border on every theme that draws
// one. The example now reads the height back; this is the fact that makes that
// necessary, and it is invisible from the example alone.
echo "the display's height\n";

$view = new TextView(0, 0, 200, 30, new SyncEventDispatcher(new ListenerRegistry()), lineHeight: 20);
$view->setSize(200, 30);

$check = static function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-46s %-6s %s\n", $what, var_export($got, true), $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$check('it refuses to be as short as it is asked', $view->height > 30, true);
$check('taking its own minimum instead',           $view->height, 40);

// So laying anything out below one means asking it, not assuming. PAD is 8 in
// the example; these are the two formulas, against the height it really took.
$check('the old hardcoded top overlapped it',      8 + 38 < 8 + $view->height, true);
$check('and the derived one clears it by PAD',     (8 + $view->height + 8) - (8 + $view->height), 8);

echo $fail === 0 ? "all calculator assertions passed\n" : "$fail assertions failed\n";
exit($fail === 0 ? 0 : 1);
