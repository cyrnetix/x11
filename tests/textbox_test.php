<?php
declare(strict_types=1);

/**
 * TextBox::setOnChanged(): the contents, once per edit, from every way in.
 *
 * The field used to report only caret movement, and even that not for a plain
 * Del — so an application that wanted to react to typing (parse a number as it
 * is entered, say) had no way to hear it short of diffing the text after every
 * key event.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\UI\Widget\TextBox;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-48s %-22s %s\n", $what, is_array($got) ? json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$box  = new TextBox(0, 0, 100, 20);
$seen = [];
$box->setOnChanged(static function (string $text) use (&$seen): void { $seen[] = $text; });

$box->handleKey('a');
$box->handleKey('b');
$check('typing reports each character',        $seen, ['a', 'ab']);

$seen = [];
$box->handleKey('Left');
$box->handleKey('Shift+Home');
$check('moving the caret reports nothing',     $seen, []);

$box->handleKey('End');
$box->handleKey('BS');
$check('backspace reports',                    $seen, ['a']);

$seen = [];
$box->handleKey('Home');
$box->handleKey('Del');
$check('a plain Del reports',                  $seen, ['']);

$seen = [];
$box->handleKey('Del');
$box->handleKey('BS');
$check('deleting from an empty field does not', $seen, []);

$box->paste('0x1F');
$check('a paste reports once, not per char',   $seen, ['0x1F']);

$seen = [];
$box->selectAll();
$box->handleKey('BS');
$check('deleting a selection reports',         $seen, ['']);

$seen = [];
$box->setText('42');
$box->setText('42');
$check('setText reports a change, once',       $seen, ['42']);

$seen = [];
$box->handleKey('F5');
$check('a key that types nothing does not',    $seen, []);

$full = new TextBox(0, 0, 100, 20, maxLength: 2);
$full->setText('ab');
$fullSeen = [];
$full->setOnChanged(static function (string $t) use (&$fullSeen): void { $fullSeen[] = $t; });
$full->handleKey('c');
$check('a full field refusing a key does not', $fullSeen, []);

exit($fail === 0 ? 0 : 1);
