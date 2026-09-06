<?php

/**
 * Zero-dependency test runner: walks tests/*_test.php and calls every
 * function it defines whose name starts with test_.
 *
 * No Composer dependency for tests alone: `git clone && php tests/run.php`
 * should be the whole story.
 *
 * Usage:  php tests/run.php
 * Exit status is non-zero if any test failed, so this gates CI.
 */

require_once __DIR__ . '/asserts.php';

$totalPass = 0;
$totalFail = 0;

$files = glob(__DIR__ . '/*_test.php');
sort($files);

foreach ($files as $file) {
    /* A test file that loads a storage backend cannot share this process:
       two backends in one run redeclare every growth_storage_*() function
       and take the whole suite down with a fatal. Such a file says so with an
       @standalone marker in its header, brings its own exit code, and is run
       as a subprocess instead. */
    if (strpos(file_get_contents($file), '@standalone') !== false) {
        /* Run as a subprocess so it still gates the suite. */
        $out = array();
        $code = 0;
        exec('php ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        if ($code === 0) {
            $totalPass++;
            /* Echoed even on success: a subprocess reports its own count, and
               a skipped file that looks exactly like a clean run is the thing
               worth seeing. */
            foreach ($out as $line) {
                echo "$line\n";
            }
        } else {
            $totalFail++;
            echo "FAIL " . basename($file) . "\n";
            foreach ($out as $line) {
                echo "  $line\n";
            }
        }
        continue;
    }

    $before = get_defined_functions();
    require $file;
    $after = get_defined_functions();
    $new = array_diff($after['user'], $before['user']);

    foreach ($new as $fn) {
        if (strpos($fn, 'test_') !== 0) {
            continue;
        }
        growth_test_reset_failures();
        $fn();
        $failures = growth_test_failures();
        if ($failures) {
            $totalFail++;
            echo "FAIL $fn\n";
            foreach ($failures as $f) {
                echo "  $f\n";
            }
        } else {
            $totalPass++;
        }
    }
}

echo "\n$totalPass passed, $totalFail failed\n";
exit($totalFail > 0 ? 1 : 0);
