<?php
/*
 * Correcting one measurement, as a page of its own.
 *
 * The dialog in growth.js is the usual way in; this is what it enhances, and
 * what someone with JavaScript off gets instead. Both post the same fields to
 * the same endpoint, so there is one code path behind them - see
 * save-measurement.php's 'update' action.
 */
require_once __DIR__ . '/shell.inc';

$childId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$child = growth_child($childId);
if (!$child) {
    header('Location: index.php');
    exit;
}

$measurementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ref = isset($_GET['ref']) ? (string)$_GET['ref'] : '';

$measurement = null;
foreach (growth_measurements($childId) as $row) {
    if ((int)$row['id'] === $measurementId) {
        $measurement = $row;
        break;
    }
}
if (!$measurement) {
    header('Location: child.php?id=' . $childId . '&ref=' . urlencode($ref));
    exit;
}

$imperial = growth_units() === 'imperial';

growth_head(t('page_title_edit_measurement'), $childId);
?>

<h1><?php echo th('heading_edit_measurement'); ?></h1>
<p class="growth-note">
  <?php echo t('note_edit_measurement', array('name' => growth_h($child['name']))); ?>
</p>

<form method="post" action="save-measurement.php" class="growth-form">
  <?php echo growth_csrf_field(); ?>
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="child_id" value="<?php echo (int)$childId; ?>">
  <input type="hidden" name="id" value="<?php echo (int)$measurementId; ?>">
  <input type="hidden" name="ref" value="<?php echo growth_h($ref); ?>">
  <label><?php echo th('label_date'); ?>
    <input type="date" name="date" required max="<?php echo date('Y-m-d'); ?>"
           min="<?php echo growth_h($child['birth_date']); ?>"
           value="<?php echo growth_h($measurement['date']); ?>">
  </label>
  <label><?php echo th('label_height_cm'); ?> (<?php echo growth_length_unit(); ?>)
    <input type="text" inputmode="<?php echo $imperial ? 'text' : 'decimal'; ?>" name="height"
           value="<?php echo $measurement['height_cm'] === null ? '' : growth_h(growth_num(growth_display_length($measurement['height_cm']))); ?>">
  </label>
  <label><?php echo th('label_weight_kg'); ?> (<?php echo growth_weight_unit(); ?>)
    <input type="text" inputmode="decimal" name="weight"
           value="<?php echo $measurement['weight_kg'] === null ? '' : growth_h(growth_num(growth_display_weight($measurement['weight_kg']), growth_weight_decimals())); ?>">
  </label>
  <label><?php echo th('label_note'); ?>
    <input type="text" name="note" maxlength="255"
           placeholder="<?php echo th('placeholder_note'); ?>"
           value="<?php echo growth_h((string)$measurement['note']); ?>">
  </label>
  <button type="submit"><?php echo th('button_save'); ?></button>
</form>

<p class="growth-links">
  <a href="child.php?id=<?php echo (int)$childId; ?>&amp;ref=<?php echo growth_h($ref); ?>"><?php echo th('nav_back'); ?></a>
  &middot;
  <a href="delete-measurement.php?child_id=<?php echo (int)$childId; ?>&amp;id=<?php echo (int)$measurementId; ?>&amp;ref=<?php echo growth_h($ref); ?>"><?php echo th('nav_delete_measurement'); ?></a>
</p>

<?php growth_foot(); ?>
