<?php
/*
 * Growth tracker - adding a child, and the things done to the data as a whole.
 *
 * This used to live on the children list, on the reasoning that with a handful
 * of children a separate page would be a page you visit twice. Twice is about
 * right, and that was the problem: a permanent form taking most of the first
 * screen for something done once or twice in a family's life, above the
 * children it pushes down. Adding a child, importing, exporting and the trash
 * are all rare and all the same kind of thing, so they are together here and
 * the list is just a list.
 */
require_once __DIR__ . '/shell.inc';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_child') {
    growth_csrf_check();
    $name = trim((string)$_POST['name']);
    $sex = ($_POST['sex'] === 'f') ? 'f' : 'm';
    $born = trim((string)$_POST['born']);
    $father = growth_parse_length_input($_POST['father']);
    $mother = growth_parse_length_input($_POST['mother']);
    $breastfed = !empty($_POST['breastfed']);

    if ($name === '' || !growth_valid_date($born)) {
        $error = t('error_name_and_birth_required');
    } elseif (strtotime($born) > time()) {
        $error = t('error_birth_in_future');
    } else {
        $id = growth_child_upsert($name, $sex, $born, $father, $mother, $breastfed);
        header('Location: child.php?id=' . (int)$id);
        exit;
    }
}

$children = growth_children();

growth_head(t('page_title_manage'));
?>

<h1><?php echo th('heading_manage'); ?></h1>

<?php if ($error !== ''): ?>
  <p class="growth-error"><?php echo growth_h($error); ?></p>
<?php endif; ?>

<div class="growth-panel">
  <h2><?php echo th('add_child_summary'); ?></h2>
  <form method="post" class="growth-form">
    <?php echo growth_csrf_field(); ?>
    <input type="hidden" name="action" value="new_child">
    <label><?php echo th('label_name'); ?>
      <input type="text" name="name" required maxlength="60">
    </label>
    <label><?php echo th('label_sex'); ?>
      <select name="sex">
        <option value="m"><?php echo th('sex_boy'); ?></option>
        <option value="f"><?php echo th('sex_girl'); ?></option>
      </select>
    </label>
    <label><?php echo th('label_birth_date'); ?>
      <input type="date" name="born" required max="<?php echo date('Y-m-d'); ?>">
    </label>
    <?php $heightPlaceholder = (growth_units() === 'imperial') ? th('placeholder_optional_height_imperial') : th('placeholder_optional'); ?>
    <label><?php echo th('label_father_height'); ?> (<?php echo growth_length_unit(); ?>)
      <input type="text" inputmode="text" name="father" placeholder="<?php echo $heightPlaceholder; ?>">
    </label>
    <label><?php echo th('label_mother_height'); ?> (<?php echo growth_length_unit(); ?>)
      <input type="text" inputmode="text" name="mother" placeholder="<?php echo $heightPlaceholder; ?>">
    </label>
    <label class="growth-checkbox">
      <input type="checkbox" name="breastfed" value="1">
      <?php echo th('label_breastfed'); ?>
    </label>
    <button type="submit"><?php echo th('button_add'); ?></button>
  </form>
  <p class="growth-note">
    <?php echo th('note_parent_heights'); ?>
  </p>
</div>

<div class="growth-panel">
  <h2><?php echo th('manage_data_heading'); ?></h2>
  <ul class="growth-list-plain">
    <?php if ($children): ?>
      <li>
        <a href="export.php"><?php echo th('nav_export_all_csv'); ?></a>
        <span class="growth-count"><?php echo th('manage_export_note'); ?></span>
      </li>
    <?php endif; ?>
    <li><a href="import.php"><?php echo th('nav_import_csv'); ?></a></li>
    <li><a href="import_rustcz.php"><?php echo th('nav_import_rustcz'); ?></a></li>
    <li><a href="trash.php"><?php echo th('nav_trash'); ?></a></li>
  </ul>
</div>

<p class="growth-links"><a href="index.php"><?php echo th('nav_back_to_children'); ?></a></p>

<?php growth_foot(); ?>
