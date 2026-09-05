<?php
/*
 * Confirmation step before deleting a child.
 *
 * This cascades to years of measurements at once, so it gets a stronger
 * confirmation than a single measurement does (smazat-mereni.php): retyping
 * the child's name, not just clicking past a warning. Still a soft delete -
 * see kos.php - but the point of asking for the name is to stop the click
 * that was never meant to happen, not to make it easy to undo after the fact.
 */
require_once __DIR__ . '/shell.inc';

$childId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$child = rust_child($childId);
if (!$child) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rust_csrf_check();
    $confirmName = trim((string)(isset($_POST['potvrzeni']) ? $_POST['potvrzeni'] : ''));
    if ($confirmName !== $child['jmeno']) {
        $error = t('error_name_mismatch');
    } else {
        rust_child_delete($childId);
        header('Location: index.php?smazano=' . urlencode($child['jmeno']));
        exit;
    }
}

$measurementCount = count(rust_measurements($childId));

rust_head(t('page_title_delete_child'), $childId);
?>

<h1><?php echo t('heading_delete_child', array('name' => rust_h($child['jmeno']))); ?></h1>
<p>
  <?php echo t('confirm_delete_child', array(
      'name' => '<strong>' . rust_h($child['jmeno']) . '</strong>',
      'n' => '<strong>' . (int)$measurementCount . '</strong>',
  )); ?>
</p>
<p class="rust-poznamka">
  <?php echo t('note_delete_child_confirm'); ?>
</p>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo rust_h($error); ?></p>
<?php endif; ?>

<form method="post" class="rust-formular">
  <?php echo rust_csrf_field(); ?>
  <input type="hidden" name="id" value="<?php echo (int)$childId; ?>">
  <label><?php echo th('label_confirm_name'); ?>
    <input type="text" name="potvrzeni" required autocomplete="off">
  </label>
  <button type="submit"><?php echo th('button_move_to_trash'); ?></button>
</form>

<p class="rust-odkazy">
  <a href="dite.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_back'); ?></a>
</p>

<?php rust_foot(); ?>
