<?php
declare(strict_types=1);

/**
 * The calculator example's arithmetic.
 *
 * It is a plain class with no idea it is driven by buttons, which is exactly what
 * makes this testable with no X server and no event loop.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/example/lib/Calculator.php';

use Cyrnetix\X11\Example\Calculator;

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

echo $fail === 0 ? "all calculator assertions passed\n" : "$fail assertions failed\n";
exit($fail === 0 ? 0 : 1);
