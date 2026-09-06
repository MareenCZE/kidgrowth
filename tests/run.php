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
    /* Some test files cannot share this process. no_personal_data_test.php is
       a script with its own exit code rather than a set of test_*() functions,
       and a storage backend's tests load storage/<backend>.inc - two of those
       in one process redeclare every growth_storage_*() function and take the
       whole run down with a fatal. Both kinds say so with an @standalone
       marker in their header and are run as subprocesses instead. */
    if (strpos(file_get_contents($file), '@standalone') !== false) {
        /* Run as a subprocess so it still gates the suite. */
        $out = array();
        $code = 0;
        exec('php ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        if ($code === 0) {
            $totalPass++;
            /* Echoed even on success, deliberately: the guard reports whether
               it actually had a denylist to check against, and a silent skip
               that looks exactly like a clean run is the failure mode this
               whole check exists to avoid. */
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
