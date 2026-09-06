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
    growth_csrf_check();
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    if ($action === 'obnovit_dite') {
        growth_child_restore(isset($_POST['id']) ? (int)$_POST['id'] : 0);
    } elseif ($action === 'obnovit_mereni') {
        growth_measurement_restore(
            isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0,
            isset($_POST['id']) ? (int)$_POST['id'] : 0
        );
    }
    header('Location: trash.php');
    exit;
}

$deletedChildren = growth_children_deleted();

/* Deleted measurements of children who are themselves still active - a
   measurement deleted along with its child is restored by restoring the
   child (growth_child_restore()'s cascade), so it has no separate entry here. */
$deletedMeasurements = array();
foreach (growth_children() as $child) {
    $rows = growth_measurements_deleted($child['id']);
    if ($rows) {
        $deletedMeasurements[] = array('child' => $child, 'rows' => $rows);
    }
}

growth_head(t('nav_trash'));
?>

<h1><?php echo th('nav_trash'); ?></h1>
<p class="growth-note">
  <?php echo th('trash_intro'); ?>
</p>

<h2><?php echo th('trash_children_heading'); ?></h2>
<?php if (!$deletedChildren): ?>
  <p class="growth-nodata"><?php echo th('trash_none'); ?></p>
<?php else: ?>
  <ul class="growth-list">
    <?php foreach ($deletedChildren as $child): ?>
      <li>
        <?php echo growth_h($child['name']); ?>
        <span class="growth-muted"><?php echo th('trash_deleted_at', array('when' => growth_h($child['deleted_at']))); ?></span>
        <form method="post">
          <?php echo growth_csrf_field(); ?>
          <input type="hidden" name="action" value="obnovit_dite">
          <input type="hidden" name="id" value="<?php echo (int)$child['id']; ?>">
          <button type="submit"><?php echo th('button_restore'); ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2><?php echo th('trash_measurements_heading'); ?></h2>
<?php if (!$deletedMeasurements): ?>
  <p class="growth-nodata"><?php echo th('trash_none'); ?></p>
<?php else: ?>
  <?php foreach ($deletedMeasurements as $group): ?>
    <h3><?php echo growth_h($group['child']['name']); ?></h3>
    <ul class="growth-list">
      <?php foreach ($group['rows'] as $row): ?>
        <li>
          <?php echo growth_h(growth_format_date($row['date'])); ?>
          <span class="growth-muted"><?php echo th('trash_deleted_at', array('when' => growth_h($row['deleted_at']))); ?></span>
          <form method="post">
            <?php echo growth_csrf_field(); ?>
            <input type="hidden" name="action" value="obnovit_mereni">
            <input type="hidden" name="child_id" value="<?php echo (int)$group['child']['id']; ?>">
            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
            <button type="submit"><?php echo th('button_restore'); ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
<?php endif; ?>

<p class="growth-links">
  <a href="index.php"><?php echo th('nav_back_to_children'); ?></a>
</p>

<?php growth_foot(); ?>
