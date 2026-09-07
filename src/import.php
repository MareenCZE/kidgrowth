<?php
/*
 * Growth tracker - CSV import.
 *
 * The single documented way data gets in in bulk, including from the old
 * RustCZ database: tools/import_rustcz.php converts the binary .rcz file to
 * this CSV rather than the site carrying code for a dead Windows format.
 */
require_once __DIR__ . '/shell.inc';
require_once __DIR__ . '/import.inc';

$report = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    growth_csrf_check();
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = t('import_error_upload_failed');
    } elseif ($_FILES['file']['size'] > GROWTH_IMPORT_MAX_BYTES) {
        $error = t('import_error_too_large', array('mb' => GROWTH_IMPORT_MAX_BYTES / 1024 / 1024));
    } else {
        $handle = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$handle) {
            $error = t('import_error_cannot_open');
        } elseif (!growth_looks_like_text($_FILES['file']['tmp_name'])) {
            $error = t('import_error_not_text');
        } else {
            $report = growth_import_csv($handle);
            fclose($handle);
        }
    }
}

growth_head(t('page_title_import'));
?>

<h1><?php echo th('heading_import'); ?></h1>

<?php if ($error !== ''): ?>
  <p class="growth-error"><?php echo growth_h($error); ?></p>
<?php endif; ?>

<?php if ($report !== null): ?>
  <div class="growth-panel">
    <p><strong><?php echo th('import_summary', array('n' => (int)$report['rows'])); ?></strong></p>
    <ul>
      <?php foreach ($report['children'] as $name => $count): ?>
        <li><?php echo growth_h($name); ?>: <?php echo th('count_measurements', array('n' => (int)$count)); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($report['skipped']): ?>
      <p class="growth-note">
        <?php echo th('import_skipped', array('n' => (int)$report['skipped'])); ?>
      </p>
    <?php endif; ?>
    <?php foreach ($report['errors'] as $message): ?>
      <p class="growth-error"><?php echo growth_h($message); ?></p>
    <?php endforeach; ?>
    <p><a href="index.php"><?php echo th('nav_back_to_children'); ?></a></p>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="growth-form">
  <?php echo growth_csrf_field(); ?>
  <label><?php echo th('label_csv_file'); ?>
    <input type="file" name="file" accept=".csv,text/csv" required>
  </label>
  <button type="submit"><?php echo th('button_import'); ?></button>
</form>

<div class="growth-panel">
  <h2><?php echo th('import_format_heading'); ?></h2>
  <p><?php echo th('import_format_intro'); ?></p>
  <pre>child,sex,birth_date,father_cm,mother_cm,breastfed,date,height_cm,weight_kg,note
"Novak Jan",m,2018-03-14,180,165,1,2018-05-20,58,4.2,</pre>
  <p class="growth-note">
    <?php echo t('import_format_note'); ?>
  </p>
  <h2><?php echo th('import_from_rustcz_heading'); ?></h2>
  <p><?php echo th('import_from_rustcz_intro'); ?></p>
  <p><a href="import_rustcz.php"><?php echo th('nav_import_rustcz'); ?></a></p>
</div>

<p class="growth-links"><a href="index.php"><?php echo th('nav_back'); ?></a></p>

<?php growth_foot(); ?>
