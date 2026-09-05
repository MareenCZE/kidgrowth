<?php
/*
 * Saves or deletes one measurement, then redirects back to the child.
 *
 * POST only and never renders anything: a page that redirects after writing
 * cannot be re-submitted by a browser reload, which with a measurement table
 * would otherwise silently rewrite the same row.
 */
require_once __DIR__ . '/data.inc';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: POST');
    exit;
}

$childId = isset($_POST['dite_id']) ? (int)$_POST['dite_id'] : 0;
$child = rust_child($childId);
if (!$child) {
    header('Location: index.php');
    exit;
}

$reference = isset($_POST['ref']) ? (string)$_POST['ref'] : 'cav';
if (!in_array($reference, rust_reference_ids(), true)) {
    $reference = 'cav';
}
$back = 'dite.php?id=' . $childId . '&ref=' . urlencode($reference);

$action = isset($_POST['akce']) ? (string)$_POST['akce'] : 'ulozit';

if ($action === 'smazat') {
    rust_measurement_delete($childId, isset($_POST['id']) ? (int)$_POST['id'] : 0);
    header('Location: ' . $back);
    exit;
}

$date = isset($_POST['datum']) ? trim((string)$_POST['datum']) : '';
$height = rust_input_number(isset($_POST['vyska']) ? $_POST['vyska'] : '');
$weight = rust_input_number(isset($_POST['hmotnost']) ? $_POST['hmotnost'] : '');
$note = isset($_POST['poznamka']) ? trim((string)$_POST['poznamka']) : '';

$valid = preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)
    && $date >= $child['datum_narozeni']
    && $date <= date('Y-m-d')
    && ($height !== null || $weight !== null);

if ($valid) {
    rust_measurement_save($childId, $date, $height, $weight, $note !== '' ? $note : null);
} else {
    $back .= '&chyba=1';
}

header('Location: ' . $back);
exit;
