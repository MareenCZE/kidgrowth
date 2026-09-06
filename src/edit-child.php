<?php
/*
 * Growth tracker - edit a child's details.
 */
require_once __DIR__ . '/shell.inc';

$childId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$child = growth_child($childId);
if (!$child) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        growth_child_update($childId, $name, $sex, $born, $father, $mother, $breastfed);
        header('Location: child.php?id=' . $childId);
        exit;
    }
    $child = array_merge($child, array(
        'name' => $name, 'sex' => $sex, 'birth_date' => $born,
        'father_height_cm' => $father, 'mother_height_cm' => $mother, 'breastfed' => $breastfed ? 1 : 0,
    ));
}

growth_head(t('page_title_edit') . ' – ' . $child['name'], $childId);
?>

<h1><?php echo th('heading_edit_child'); ?></h1>

<?php if ($error !== ''): ?>
  <p class="growth-error"><?php echo growth_h($error); ?></p>
<?php endif; ?>

<form method="post" class="growth-form">
  <?php echo growth_csrf_field(); ?>
  <input type="hidden" name="id" value="<?php echo (int)$childId; ?>">
  <label><?php echo th('label_name'); ?>
    <input type="text" name="name" required maxlength="60"
           value="<?php echo growth_h($child['name']); ?>">
  </label>
  <label><?php echo th('label_sex'); ?>
    <select name="sex">
      <option value="m"<?php echo $child['sex'] === 'm' ? ' selected' : ''; ?>><?php echo th('sex_boy'); ?></option>
      <option value="f"<?php echo $child['sex'] === 'f' ? ' selected' : ''; ?>><?php echo th('sex_girl'); ?></option>
    </select>
  </label>
  <label><?php echo th('label_birth_date'); ?>
    <input type="date" name="born" required max="<?php echo date('Y-m-d'); ?>"
           value="<?php echo growth_h($child['birth_date']); ?>">
  </label>
  <label><?php echo th('label_father_height'); ?> (<?php echo growth_length_unit(); ?>)
    <input type="text" inputmode="text" name="father"
           value="<?php echo growth_h(growth_num(growth_display_length($child['father_height_cm']))); ?>">
  </label>
  <label><?php echo th('label_mother_height'); ?> (<?php echo growth_length_unit(); ?>)
    <input type="text" inputmode="text" name="mother"
           value="<?php echo growth_h(growth_num(growth_display_length($child['mother_height_cm']))); ?>">
  </label>
  <label class="growth-checkbox">
    <input type="checkbox" name="breastfed" value="1"<?php echo !empty($child['breastfed']) ? ' checked' : ''; ?>>
    <?php echo th('label_breastfed'); ?>
  </label>
  <p class="growth-note">
    <?php echo th('note_breastfed_reference'); ?>
  </p>
  <button type="submit"><?php echo th('button_save'); ?></button>
</form>

<p class="growth-links">
  <a href="child.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_back'); ?></a>
  &middot;
  <a href="delete-child.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_delete_child'); ?></a>
</p>

<?php growth_foot(); ?>
