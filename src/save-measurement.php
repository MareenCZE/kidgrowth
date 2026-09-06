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
growth_csrf_check();

$childId = isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0;
$child = growth_child($childId);
if (!$child) {
    header('Location: index.php');
    exit;
}

$reference = isset($_POST['ref']) ? (string)$_POST['ref'] : 'cav';
if (!in_array($reference, growth_reference_ids(), true)) {
    $reference = 'cav';
}
$back = 'child.php?id=' . $childId . '&ref=' . urlencode($reference);

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'save';

if ($action === 'delete') {
    growth_measurement_delete($childId, isset($_POST['id']) ? (int)$_POST['id'] : 0);
    header('Location: ' . $back);
    exit;
}

$date = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
$height = growth_parse_length_input(isset($_POST['height']) ? $_POST['height'] : '');
$weight = growth_parse_weight_input(isset($_POST['weight']) ? $_POST['weight'] : '');
$note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';

$valid = growth_valid_date($date)
    && $date >= $child['birth_date']
    && $date <= date('Y-m-d')
    && ($height !== null || $weight !== null);

if (!$valid) {
    header('Location: ' . $back . '&error=1');
    exit;
}

/* An edit addresses one row by id, so it can move the date; a plain save is
   keyed on the date itself and would leave the original row behind. */
if ($action === 'update') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    /* A row that is not this child's, or is already in the trash, means a
       stale page rather than a conflict - send them back to a fresh one
       instead of explaining a collision that did not happen. */
    $exists = false;
    foreach (growth_measurements($childId) as $row) {
        if ((int)$row['id'] === $id) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        header('Location: ' . $back);
        exit;
    }

    if (!growth_measurement_update($childId, $id, $date, $height, $weight, $note !== '' ? $note : null)) {
        $back .= '&error=collision';
    }
    header('Location: ' . $back);
    exit;
}

growth_measurement_save($childId, $date, $height, $weight, $note !== '' ? $note : null);
header('Location: ' . $back);
exit;
