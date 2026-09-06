<?php

/**
 * Checks our percentile and z-score maths against RustCZ's own output.
 *
 * RustCZ's text export prints, for every measurement it holds, the percentile
 * and z-score it computed from the same Czech CAV reference we use. That makes
 * the export a ready-made test set of real values covering a decade of a real
 * child's growth - far better evidence than a handful of hand-checked numbers.
 *
 * Usage:  php tools/check_rustcz_percentiles.php <export.txt> [<export.txt> ...]
 *
 * Exit status is non-zero if any comparison exceeds tolerance, so this can gate
 * a change to the reference data.
 *
 * Ages are taken from the export as printed rather than recomputed from dates,
 * deliberately: the point is to test the reference lookup, not date arithmetic.
 * The export rounds age to two decimals, which is +/- 1.8 days and is the main
 * reason the tolerances below are not tighter.
 */

require_once __DIR__ . '/../src/growth.inc';

/* RustCZ prints percentiles as whole numbers and z to two decimals, and its
   ages are rounded too, so exact equality is not achievable. These bounds are
   loose enough to absorb that and tight enough that a wrong reference table or
   a broken LMS interpolation could not slip through. */
const TOLERANCE_Z = 0.10;
const TOLERANCE_PCT = 3.0;

/* Results are reported in two bands, because only one of them is a fair test.
 *
 * SZU publishes the 3rd to 97th percentiles and nothing else, so our L and S
 * are fitted over z in [-1.88, +1.88]. Inside that band the fit is pinned down
 * by real published numbers and we expect to match RustCZ closely. Outside it
 * both programs are extrapolating a shape the published data never constrained,
 * and RustCZ is extrapolating from the original LMS parameters that SZU never
 * released. Disagreement out there reflects missing source data, not a defect
 * we can fix, and it is largest for weight, whose distribution is strongly
 * skewed. It is also of no practical consequence: at those values every honest
 * presentation says "below the 3rd percentile" rather than a precise number.
 */
const IN_RANGE_Z = 1.8807936081512509;

/* Known, understood in-range disagreements, all weight in early infancy.
 *
 * Investigated rather than waved through. For a girl at age 0.00, weight 3.2 kg:
 * SZU's published girls' table reads 2.4 / 2.6 / 2.8 / 3.0 / 3.3 / 3.6 / 3.9,
 * so 3.2 kg sits about two thirds of the way from the median to P75 - close to
 * the 67th percentile. We report 69, RustCZ reports 61. Our value is the one
 * consistent with the published table; RustCZ is working from the original LMS
 * parameters behind it, which SZU never released, and its weight tables are
 * CAV 1991 while its heights are CAV 2001.
 *
 * So this is not a defect to fix - we cannot be more faithful to the source
 * than the source is to itself. The number is pinned here so that a real
 * regression, which would push the count above it, still fails the build.
 */
const KNOWN_IN_RANGE_FAILURES = 3;

$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "usage: php tools/check_rustcz_percentiles.php <export.txt> ...\n");
    exit(2);
}

$totalChecked = 0;
$totalFailed = 0;

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "no such file: $file\n");
        exit(2);
    }
    $text = file_get_contents($file);
    /* RustCZ is a Windows program, so an export may arrive as CP1250. The
       samples in hand are already UTF-8; the /u modifier failing to match is a
       cheap validity probe, and iconv avoids depending on ext/mbstring. */
    if (@preg_match('~~u', $text) !== 1) {
        $text = iconv('Windows-1250', 'UTF-8//TRANSLIT', $text);
    }
    $lines = preg_split('~\R~', $text);

    /* --- header: who is this --- */
    $sex = null;
    $name = basename($file);
    foreach ($lines as $line) {
        if (preg_match('~Pohlav.*:\s*(\S+)~u', $line, $m)) {
            $sex = (stripos($m[1], 'chlapec') !== false) ? 'm' : 'f';
        }
        if (preg_match('~jm.no:\s*(.+)$~u', $line, $m)) {
            $name = trim($m[1]);
        }
        if ($sex !== null && $name !== basename($file)) {
            break;
        }
    }
    if ($sex === null) {
        fwrite(STDERR, "$file: could not determine sex from the header\n");
        exit(2);
    }

    /* --- body: one table per measurement, each introduced by a header row ---
       Every data row starts with a decimal age, so the header tells us how to
       read the columns that follow. Classifying every "Perc." header, including
       the ones we do not want, matters: an export may also carry head
       circumference or arm circumference, and a parser that only recognises
       height and weight keeps reading a later table under the previous
       heading - which is how a 36 cm head became a 36 kg newborn. */
    $section = null;
    $cases = array();
    foreach ($lines as $line) {
        if (strpos($line, 'Perc.') !== false) {
            if (strpos($line, 'Výška') !== false) {
                $section = 'height';
            } elseif (strpos($line, 'Hmotnost') !== false) {
                $section = 'weight';
            } else {
                $section = 'other';
            }
            continue;
        }
        if ($section === null || $section === 'other') {
            continue;
        }

        $tokens = preg_split('~\s+~', trim($line), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens || !preg_match('~^\d+\.\d+$~', $tokens[0])) {
            continue;
        }
        $age = (float)$tokens[0];

        if ($section === 'height') {
            /* age, heightAge, height, percentile, z */
            if (count($tokens) < 5 || !is_numeric($tokens[2])) {
                continue;
            }
            $cases[] = array('metric' => 'height', 'age' => $age, 'value' => (float)$tokens[2],
                             'pct' => (float)$tokens[3], 'z' => (float)$tokens[4]);
        } else {
            /* Columns: age, weight, percentile, z, then the same two against
               height age, then BMI with its own percentile and z. Rows where
               RustCZ could not compute the height-age pair print "---" there
               and stop, which is why the BMI columns are only read when the
               row is long enough to have them. */
            if (count($tokens) < 4 || !is_numeric($tokens[1])) {
                continue;
            }
            $cases[] = array('metric' => 'weight', 'age' => $age, 'value' => (float)$tokens[1],
                             'pct' => (float)$tokens[2], 'z' => (float)$tokens[3]);

            if (count($tokens) >= 9 && is_numeric($tokens[6]) && is_numeric($tokens[8])) {
                $cases[] = array('metric' => 'bmi', 'age' => $age, 'value' => (float)$tokens[6],
                                 'pct' => (float)$tokens[7], 'z' => (float)$tokens[8]);
            }
        }
    }

    /* --- compare --- */
    $checked = 0;
    $failed = 0;
    $worstZ = 0.0;
    $worstZWhere = '';
    $skipped = 0;
    $report = array();
    $tailChecked = 0;
    $tailWorst = 0.0;

    foreach ($cases as $case) {
        $lms = growth_lms_at(growth_reference_rows('cav', $case['metric'], $sex), $case['age']);
        if (!$lms) {
            $skipped++;
            continue;
        }
        $z = growth_zscore($case['value'], $lms);
        $pct = growth_percentile($z);
        if ($z === null) {
            $skipped++;
            continue;
        }

        $dz = abs($z - $case['z']);

        /* Judge only what the published percentiles actually constrain. Either
           program's own z landing beyond P3/P97 puts the case in the tail band,
           which is reported but not failed. */
        if (abs($z) > IN_RANGE_Z || abs($case['z']) > IN_RANGE_Z) {
            $tailChecked++;
            $tailWorst = max($tailWorst, $dz);
            continue;
        }

        $checked++;
        /* RustCZ clamps its printed percentile at 0 and 100, so only compare
           percentiles away from the rails; z is the real test in the tails. */
        $comparablePct = $case['pct'] > 0 && $case['pct'] < 100;
        $dp = $comparablePct ? abs($pct - $case['pct']) : 0.0;

        if ($dz > $worstZ) {
            $worstZ = $dz;
            $worstZWhere = sprintf('%s at age %.2f', $case['metric'], $case['age']);
        }
        if ($dz > TOLERANCE_Z || $dp > TOLERANCE_PCT) {
            $failed++;
            $report[] = sprintf(
                '    age %5.2f  %-6s %6.1f   RustCZ z=%6.2f p=%3d   ours z=%6.2f p=%5.1f   dz=%.2f',
                $case['age'], $case['metric'], $case['value'],
                $case['z'], (int)$case['pct'], $z, $pct, $dz
            );
        }
    }

    printf("%s (%s)\n", $name, $sex === 'm' ? 'boy' : 'girl');
    printf("  within P3-P97: %d compared, %d outside tolerance, worst |dz| %.3f (%s)\n",
        $checked, $failed, $worstZ, $worstZWhere === '' ? 'none' : $worstZWhere);
    printf("  beyond P3/P97: %d compared, worst |dz| %.3f (extrapolated, not judged)\n",
        $tailChecked, $tailWorst);
    if ($skipped) {
        printf("  skipped %d (age outside the reference)\n", $skipped);
    }
    foreach (array_slice($report, 0, 12) as $line) {
        echo $line, "\n";
    }
    if (count($report) > 12) {
        printf("    ... and %d more\n", count($report) - 12);
    }
    echo "\n";

    $totalChecked += $checked;
    $totalFailed += $failed;
}

printf("TOTAL within P3-P97: %d compared, %d outside tolerance (|dz| <= %.2f, |dp| <= %.1f)\n",
    $totalChecked, $totalFailed, TOLERANCE_Z, TOLERANCE_PCT);
printf("Known and explained baseline: %d. ", KNOWN_IN_RANGE_FAILURES);

if ($totalFailed > KNOWN_IN_RANGE_FAILURES) {
    printf("REGRESSION - %d more than expected.\n", $totalFailed - KNOWN_IN_RANGE_FAILURES);
    exit(1);
}
if ($totalFailed < KNOWN_IN_RANGE_FAILURES) {
    printf("Improved - update KNOWN_IN_RANGE_FAILURES to %d.\n", $totalFailed);
    exit(0);
}
echo "As expected.\n";
exit(0);
