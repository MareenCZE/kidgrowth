<?php
/*
 * Growth tracker - edit a child's details.
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
    $name = trim((string)$_POST['jmeno']);
    $sex = ($_POST['pohlavi'] === 'z') ? 'z' : 'm';
    $born = trim((string)$_POST['narozeni']);
    $father = rust_input_number($_POST['otec']);
    $mother = rust_input_number($_POST['matka']);
    $breastfed = !empty($_POST['kojeno']);

    if ($name === '' || !rust_valid_date($born)) {
        $error = t('error_name_and_birth_required');
    } elseif (strtotime($born) > time()) {
        $error = t('error_birth_in_future');
    } else {
        rust_child_update($childId, $name, $sex, $born, $father, $mother, $breastfed);
        header('Location: dite.php?id=' . $childId);
        exit;
    }
    $child = array_merge($child, array(
        'jmeno' => $name, 'pohlavi' => $sex, 'datum_narozeni' => $born,
        'vyska_otce_cm' => $father, 'vyska_matky_cm' => $mother, 'kojeno' => $breastfed ? 1 : 0,
    ));
}

rust_head(t('page_title_edit') . ' – ' . $child['jmeno'], $childId);
?>

<h1><?php echo th('heading_edit_child'); ?></h1>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo rust_h($error); ?></p>
<?php endif; ?>

<form method="post" class="rust-formular">
  <?php echo rust_csrf_field(); ?>
  <input type="hidden" name="id" value="<?php echo (int)$childId; ?>">
  <label><?php echo th('label_name'); ?>
    <input type="text" name="jmeno" required maxlength="60"
           value="<?php echo rust_h($child['jmeno']); ?>">
  </label>
  <label><?php echo th('label_sex'); ?>
    <select name="pohlavi">
      <option value="m"<?php echo $child['pohlavi'] === 'm' ? ' selected' : ''; ?>><?php echo th('sex_boy'); ?></option>
      <option value="z"<?php echo $child['pohlavi'] === 'z' ? ' selected' : ''; ?>><?php echo th('sex_girl'); ?></option>
    </select>
  </label>
  <label><?php echo th('label_birth_date'); ?>
    <input type="date" name="narozeni" required max="<?php echo date('Y-m-d'); ?>"
           value="<?php echo rust_h($child['datum_narozeni']); ?>">
  </label>
  <label><?php echo th('label_father_height'); ?>
    <input type="text" inputmode="decimal" name="otec"
           value="<?php echo rust_h(rust_num($child['vyska_otce_cm'])); ?>">
  </label>
  <label><?php echo th('label_mother_height'); ?>
    <input type="text" inputmode="decimal" name="matka"
           value="<?php echo rust_h(rust_num($child['vyska_matky_cm'])); ?>">
  </label>
  <label class="rust-zaskrtnuti">
    <input type="checkbox" name="kojeno" value="1"<?php echo !empty($child['kojeno']) ? ' checked' : ''; ?>>
    <?php echo th('label_breastfed'); ?>
  </label>
  <p class="rust-poznamka">
    <?php echo th('note_breastfed_reference'); ?>
  </p>
  <button type="submit"><?php echo th('button_save'); ?></button>
</form>

<p class="rust-odkazy">
  <a href="dite.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_back'); ?></a>
  &middot;
  <a href="smazat-dite.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_delete_child'); ?></a>
</p>

<?php rust_foot(); ?>
