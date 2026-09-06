<?php
/*
 * Exports measurements as CSV, in the same shape import.php reads.
 *
 * Round-tripping matters more than it looks: it means the data is never locked
 * in here the way it was locked inside RustCZ, which is the whole reason this
 * application exists.
 */
require_once __DIR__ . '/data.inc';

if (growth_demo_mode()) {
    require_once __DIR__ . '/shell.inc';
    growth_head(t('nav_export_csv'));
    echo '<h1>' . th('nav_export_csv') . '</h1><p class="rust-nodata">' . th('demo_export_disabled') . '</p>';
    echo '<p class="rust-odkazy"><a href="index.php">' . th('nav_back_to_children') . '</a></p>';
    growth_foot();
    exit;
}

$childId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$children = $childId ? array_filter(growth_children(), function ($c) use ($childId) {
    return (int)$c['id'] === $childId;
}) : growth_children();

if (!$children) {
    header('Location: index.php');
    exit;
}

$filename = 'rust-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
/* A UTF-8 BOM, so Excel opens Czech names correctly instead of as mojibake. */
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, array('dite', 'pohlavi', 'narozeni', 'otec_cm', 'matka_cm',
                    'datum', 'vyska_cm', 'hmotnost_kg', 'poznamka'));

foreach ($children as $child) {
    foreach (growth_measurements($child['id']) as $row) {
        fputcsv($out, array(
            $child['jmeno'],
            $child['pohlavi'],
            $child['datum_narozeni'],
            $child['vyska_otce_cm'],
            $child['vyska_matky_cm'],
            $row['datum'],
            $row['vyska_cm'],
            $row['hmotnost_kg'],
            $row['poznamka'],
        ));
    }
}
fclose($out);
