<?php
/*
 * "Kos" (trash): deleted children, and deleted measurements belonging to
 * still-active children, both restorable here.
 *
 * Purge is deliberately not offered. An automatic purge after N days would
 * reintroduce exactly the irreversibility soft delete exists to remove, so
 * there is no delete-forever button, only time and, eventually, a human
 * with direct access to the storage.
 */
require_once __DIR__ . '/shell.inc';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rust_csrf_check();
    $action = isset($_POST['akce']) ? (string)$_POST['akce'] : '';
    if ($action === 'obnovit_dite') {
        rust_child_restore(isset($_POST['id']) ? (int)$_POST['id'] : 0);
    } elseif ($action === 'obnovit_mereni') {
        rust_measurement_restore(
            isset($_POST['dite_id']) ? (int)$_POST['dite_id'] : 0,
            isset($_POST['id']) ? (int)$_POST['id'] : 0
        );
    }
    header('Location: trash.php');
    exit;
}

$deletedChildren = rust_children_deleted();

/* Deleted measurements of children who are themselves still active - a
   measurement deleted along with its child is restored by restoring the
   child (rust_child_restore()'s cascade), so it has no separate entry here. */
$deletedMeasurements = array();
foreach (rust_children() as $child) {
    $rows = rust_measurements_deleted($child['id']);
    if ($rows) {
        $deletedMeasurements[] = array('child' => $child, 'rows' => $rows);
    }
}

rust_head(t('nav_trash'));
?>

<h1><?php echo th('nav_trash'); ?></h1>
<p class="rust-poznamka">
  <?php echo th('trash_intro'); ?>
</p>

<h2><?php echo th('trash_children_heading'); ?></h2>
<?php if (!$deletedChildren): ?>
  <p class="rust-nodata"><?php echo th('trash_none'); ?></p>
<?php else: ?>
  <ul class="rust-seznam">
    <?php foreach ($deletedChildren as $child): ?>
      <li>
        <?php echo rust_h($child['jmeno']); ?>
        <span class="rust-slabe"><?php echo th('trash_deleted_at', array('when' => rust_h($child['smazano']))); ?></span>
        <form method="post">
          <?php echo rust_csrf_field(); ?>
          <input type="hidden" name="akce" value="obnovit_dite">
          <input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
          <button type="submit"><?php echo th('button_restore'); ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2><?php echo th('trash_measurements_heading'); ?></h2>
<?php if (!$deletedMeasurements): ?>
  <p class="rust-nodata"><?php echo th('trash_none'); ?></p>
<?php else: ?>
  <?php foreach ($deletedMeasurements as $group): ?>
    <h3><?php echo rust_h($group['child']['jmeno']); ?></h3>
    <ul class="rust-seznam">
      <?php foreach ($group['rows'] as $row): ?>
        <li>
          <?php echo rust_h(rust_date_cz($row['datum'])); ?>
          <span class="rust-slabe"><?php echo th('trash_deleted_at', array('when' => rust_h($row['smazano']))); ?></span>
          <form method="post">
            <?php echo rust_csrf_field(); ?>
            <input type="hidden" name="akce" value="obnovit_mereni">
            <input type="hidden" name="dite_id" value="<?php echo (int)$group['child']['id']; ?>">
            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
            <button type="submit"><?php echo th('button_restore'); ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
<?php endif; ?>

<p class="rust-odkazy">
  <a href="index.php"><?php echo th('nav_back_to_children'); ?></a>
</p>

<?php rust_foot(); ?>
