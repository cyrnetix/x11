<?php
declare(strict_types=1);

/**
 * The to-do example's list and its file format.
 *
 * The format is ordinary Markdown checkboxes on purpose, so the round-trip
 * through a text editor is part of what is tested here.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/example/lib/TaskList.php';

use Cyrnetix\X11\Example\TaskList;
$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-44s %-22s %s\n", $what, is_array($got) ? json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$l = new TaskList();
$check('a new list is empty',            $l->all(), []);
$check('and clean',                      $l->isDirty(), false);
$check('blank text is refused',          $l->add('   '), false);
$check('a task is added',                $l->add('milk'), true);
$check('which makes it dirty',           $l->isDirty(), true);
$l->add('bread');
$check('two tasks',                      count($l->all()), 2);
$check('both outstanding',               $l->remaining(), 2);
$check('ticking one',                    $l->toggle(0), true);
$check('leaves one left',                $l->remaining(), 1);
$check('ticking again unticks',          $l->toggle(0) && $l->remaining() === 2, true);
$check('a missing index is refused',     $l->toggle(99), false);
$check('removing works',                 $l->remove(0), true);
$check('leaving one',                    count($l->all()), 1);

// The format is ordinary Markdown, so it round-trips through a text editor.
$tmp = tempnam(sys_get_temp_dir(), 'todo') . '.md';
$l->reset();
$l->add('write a readme');
$l->add('tag a release');
$l->toggle(0);
$check('saving reports no error',        $l->save($tmp), null);
$check('and the list is clean again',    $l->isDirty(), false);
$check('the file reads as Markdown',
    file_get_contents($tmp), "- [x] write a readme\n- [ ] tag a release\n");

$back = new TaskList();
$check('reading it back reports no error', $back->load($tmp), null);
$check('with both tasks',                count($back->all()), 2);
$check('the ticked one still ticked',    $back->all()[0]->done, true);
$check('and the text intact',            $back->all()[1]->text, 'tag a release');
$check('it remembers the path',          $back->path(), $tmp);

// A hand-written file: bare lines count as outstanding, blanks are skipped.
file_put_contents($tmp, "- [ ] one\n\nbare line\n* [X] two\n");
$hand = new TaskList();
$check('a hand-written file loads',      $hand->load($tmp), null);
$check('skipping blank lines',           count($hand->all()), 3);
$check('a bare line is a task',          $hand->all()[1]->text, 'bare line');
$check('and is outstanding',             $hand->all()[1]->done, false);
$check('an uppercase X counts as done',  $hand->all()[2]->done, true);

$check('a missing file says so',         (new TaskList())->load('/nope/nope.md'), 'There is no file at that path.');
$check('an impossible folder says so',   $l->save('/nope/nope/x.md'), 'That folder no longer exists.');

unlink($tmp);
echo $fail === 0 ? "all to-do assertions passed\n" : "$fail assertions failed\n";
exit($fail === 0 ? 0 : 1);
