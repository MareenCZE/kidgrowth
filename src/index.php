<?php
/*
 * Growth tracker - list of children.
 *
 * Also the place a new child is added, since with a handful of children a
 * separate management page would be a page you visit twice.
 */
require_once __DIR__ . '/shell.inc';

$error = '';
$reference = growth_selected_reference();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['akce']) && $_POST['akce'] === 'nove_dite') {
    growth_csrf_check();
    $name = trim((string)$_POST['jmeno']);
    $sex = ($_POST['pohlavi'] === 'z') ? 'z' : 'm';
    $born = trim((string)$_POST['narozeni']);
    $father = growth_parse_length_input($_POST['otec']);
    $mother = growth_parse_length_input($_POST['matka']);
    $breastfed = !empty($_POST['kojeno']);

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

growth_head('');
?>

<h1><?php echo th('page_title_home'); ?></h1>

<?php if (isset($_GET['smazano'])): ?>
  <p class="rust-poznamka">
    <?php echo t('flash_moved_to_trash', array('name' => growth_h((string)$_GET['smazano']))); ?>
  </p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo growth_h($error); ?></p>
<?php endif; ?>

<?php if (!$children): ?>
  <p class="rust-uvod">
    <?php echo t('intro_no_children'); ?>
  </p>
<?php else: ?>
  <ul class="rust-seznam">
    <?php foreach ($children as $child): ?>
      <?php
        $measurements = growth_measurements($child['id']);
        $last = null;
        for ($i = count($measurements) - 1; $i >= 0; $i--) {
            if ($measurements[$i]['vyska_cm'] !== null || $measurements[$i]['hmotnost_kg'] !== null) {
                $last = $measurements[$i];
                break;
            }
        }
        $age = growth_decimal_age($child['datum_narozeni'], date('Y-m-d'));
      ?>
      <li>
        <a href="child.php?id=<?php echo (int)$child['id']; ?>">
          <strong><?php echo growth_h($child['jmeno']); ?></strong>
          <span class="rust-vek"><?php echo growth_h(growth_format_age($age)); ?></span>
        </a>
        <?php if ($last): ?>
          <span class="rust-posledni">
            <?php echo growth_h(growth_format_date($last['datum'])); ?>:
            <?php if ($last['vyska_cm'] !== null): ?>
              <?php echo growth_h(growth_num(growth_display_length($last['vyska_cm']))); ?> <?php echo growth_length_unit(); ?><?php endif; ?>
            <?php if ($last['vyska_cm'] !== null && $last['hmotnost_kg'] !== null): ?>,<?php endif; ?>
            <?php if ($last['hmotnost_kg'] !== null): ?>
              <?php echo growth_h(growth_num(growth_display_weight($last['hmotnost_kg']), growth_weight_decimals())); ?> <?php echo growth_weight_unit(); ?><?php endif; ?>
            <span class="rust-pocet"><?php echo th('count_measurements', array('n' => count($measurements))); ?></span>
          </span>
        <?php else: ?>
          <span class="rust-posledni"><?php echo th('no_measurements_yet'); ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<details class="rust-panel"<?php echo $children ? '' : ' open'; ?>>
  <summary><?php echo th('add_child_summary'); ?></summary>
  <form method="post" class="rust-formular">
    <?php echo growth_csrf_field(); ?>
    <input type="hidden" name="akce" value="nove_dite">
    <label><?php echo th('label_name'); ?>
      <input type="text" name="jmeno" required maxlength="60">
    </label>
    <label><?php echo th('label_sex'); ?>
      <select name="pohlavi">
        <option value="m"><?php echo th('sex_boy'); ?></option>
        <option value="z"><?php echo th('sex_girl'); ?></option>
      </select>
    </label>
    <label><?php echo th('label_birth_date'); ?>
      <input type="date" name="narozeni" required max="<?php echo date('Y-m-d'); ?>">
    </label>
    <?php $heightPlaceholder = (growth_units() === 'imperial') ? th('placeholder_optional_height_imperial') : th('placeholder_optional'); ?>
    <label><?php echo th('label_father_height'); ?> (<?php echo growth_length_unit(); ?>)
      <input type="text" inputmode="text" name="otec" placeholder="<?php echo $heightPlaceholder; ?>">
    </label>
    <label><?php echo th('label_mother_height'); ?> (<?php echo growth_length_unit(); ?>)
      <input type="text" inputmode="text" name="matka" placeholder="<?php echo $heightPlaceholder; ?>">
    </label>
    <label class="rust-zaskrtnuti">
      <input type="checkbox" name="kojeno" value="1">
      <?php echo th('label_breastfed'); ?>
    </label>
    <button type="submit"><?php echo th('button_add'); ?></button>
  </form>
  <p class="rust-poznamka">
    <?php echo th('note_parent_heights'); ?>
  </p>
</details>

<p class="rust-odkazy">
  <a href="import.php"><?php echo th('nav_import_csv'); ?></a>
  &middot;
  <a href="trash.php"><?php echo th('nav_trash'); ?></a>
</p>

<?php growth_foot(); ?>
