<?php
/*
 * Confirmation step before deleting one measurement.
 *
 * A JS-only confirm() does nothing with JavaScript off - a real page here,
 * requiring a real second request with its own CSRF token, is what actually
 * stops an accidental click or a cross-site POST. The deletion itself is
 * still done by save-measurement.php, which this page's form posts to; it is a soft
 * delete, recoverable from trash.php.
 */
require_once __DIR__ . '/shell.inc';

$childId = isset($_GET['dite_id']) ? (int)$_GET['dite_id'] : 0;
$child = rust_child($childId);
if (!$child) {
    header('Location: index.php');
    exit;
}

$measurementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ref = isset($_GET['ref']) ? (string)$_GET['ref'] : '';

$measurement = null;
foreach (rust_measurements($childId) as $row) {
    if ((int)$row['id'] === $measurementId) {
        $measurement = $row;
        break;
    }
}
if (!$measurement) {
    header('Location: child.php?id=' . $childId . '&ref=' . urlencode($ref));
    exit;
}

rust_head(t('page_title_delete_measurement'), $childId);
?>

<h1><?php echo th('heading_delete_measurement'); ?></h1>
<p>
  <?php echo t('confirm_delete_measurement', array(
      'name' => '<strong>' . rust_h($child['jmeno']) . '</strong>',
      'date' => '<strong>' . rust_h(rust_date_cz($measurement['datum'])) . '</strong>',
  )); ?>
</p>
<p class="rust-poznamka">
  <?php echo t('note_recoverable_from_trash'); ?>
</p>

<form method="post" action="save-measurement.php" class="rust-formular">
  <?php echo rust_csrf_field(); ?>
  <input type="hidden" name="akce" value="smazat">
  <input type="hidden" name="dite_id" value="<?php echo (int)$childId; ?>">
  <input type="hidden" name="id" value="<?php echo (int)$measurementId; ?>">
  <input type="hidden" name="ref" value="<?php echo rust_h($ref); ?>">
  <button type="submit"><?php echo th('button_delete'); ?></button>
</form>

<p class="rust-odkazy">
  <a href="child.php?id=<?php echo (int)$childId; ?>&amp;ref=<?php echo rust_h($ref); ?>"><?php echo th('nav_back'); ?></a>
</p>

<?php rust_foot(); ?>
