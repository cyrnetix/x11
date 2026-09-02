<?php
declare(strict_types=1);

/**
 * Every test in this directory, in one run.
 *
 * There is no PHPUnit here on purpose: each test is a plain script that prints
 * what it checked and exits non-zero if anything failed, so it reads as a
 * transcript and needs no configuration. `composer test` runs this.
 */
$dir   = __DIR__;
$files = glob($dir . '/*_test.php') ?: [];
sort($files);

$failed = [];
foreach ($files as $file) {
    printf("=== %s\n", basename($file));
    passthru(sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $status);
    if ($status !== 0) $failed[] = basename($file);
    echo "\n";
}

printf("%d suite%s run", count($files), count($files) === 1 ? '' : 's');
if ($failed === []) {
    echo ", all passed\n";
    exit(0);
}

printf(", %d failed: %s\n", count($failed), implode(', ', $failed));
exit(1);
