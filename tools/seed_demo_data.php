<?php

/**
 * Seeds two synthetic children with plausible, entirely invented measurement
 * histories, for a demo/Codespaces instance (see .devcontainer/postCreate.sh
 * and DEMO_MODE in src/config.php).
 *
 * Never a real family's own children's data, even anonymised: a date of
 * birth plus a growth curve is identifying. Every number below is
 * generated, not measured.
 *
 * The trick that makes the result look like a real child rather than noise:
 * pick one SD offset per child and hold it roughly constant across their
 * whole history, jittering it a little at each visit. A real child tracks
 * their own channel; pure random noise at every point does not.
 *
 * Usage: php tools/seed_demo_data.php
 */

require_once __DIR__ . '/../src/storage.inc';
require_once __DIR__ . '/../src/growth.inc';

function seed_measurements($childId, $sex, $birthDate, $ageYears, $targetZ)
{
    $heightRows = growth_reference_rows('cdc', 'height', $sex);
    $weightRows = growth_reference_rows('cdc', 'weight', $sex);

    $months = 0;
    while (true) {
        $age = $months / 12.0;
        if ($age > $ageYears) {
            break;
        }
        $date = date('Y-m-d', strtotime($birthDate . " +{$months} months"));

        $heightLms = growth_lms_at($heightRows, $age);
        $weightLms = growth_lms_at($weightRows, $age);

        /* Height and weight are correlated but not identical, so they get
           independent jitter around the same underlying channel. */
        $heightZ = $targetZ + (mt_rand(-12, 12) / 100.0);
        $weightZ = $targetZ + (mt_rand(-18, 18) / 100.0);

        $height = $heightLms ? round(growth_value_at_z($heightLms, $heightZ), 1) : null;
        $weight = $weightLms ? round(growth_value_at_z($weightLms, $weightZ), 2) : null;

        if ($height !== null || $weight !== null) {
            growth_measurement_save($childId, $date, $height, $weight, null);
        }

        /* Roughly every 2-3 months, irregularly - like an actual family, not
           a machine-generated schedule. */
        $months += mt_rand(2, 4);
    }
}

mt_srand(20260101); /* fixed seed: the same "invented" data every time this runs */

$alexBorn = date('Y-m-d', strtotime('-6 years -3 months'));
$alexId = growth_child_upsert('Alex Demo', 'm', $alexBorn, 180.0, 165.0, 0);
seed_measurements($alexId, 'm', $alexBorn, 6.25, 0.3);
fwrite(STDERR, "Seeded Alex Demo (id $alexId)\n");

$samBorn = date('Y-m-d', strtotime('-20 months'));
$samId = growth_child_upsert('Sam Demo', 'f', $samBorn, 172.0, 160.0, 1);
seed_measurements($samId, 'f', $samBorn, 20 / 12.0, -0.4);
fwrite(STDERR, "Seeded Sam Demo (id $samId)\n");
