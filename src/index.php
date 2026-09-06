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

growth_head('');
?>

<h1><?php echo th('page_title_home'); ?></h1>

<?php if (isset($_GET['deleted_at'])): ?>
  <p class="growth-note">
    <?php echo t('flash_moved_to_trash', array('name' => growth_h((string)$_GET['deleted_at']))); ?>
  </p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="growth-error"><?php echo growth_h($error); ?></p>
<?php endif; ?>

<?php if (!$children): ?>
  <p class="growth-intro">
    <?php echo t('intro_no_children'); ?>
  </p>
<?php else: ?>
  <ul class="growth-list">
    <?php foreach ($children as $child): ?>
      <?php
        $measurements = growth_measurements($child['id']);
        $last = null;
        for ($i = count($measurements) - 1; $i >= 0; $i--) {
            if ($measurements[$i]['height_cm'] !== null || $measurements[$i]['weight_kg'] !== null) {
                $last = $measurements[$i];
                break;
            }
        }
        $age = growth_decimal_age($child['birth_date'], date('Y-m-d'));
      ?>
      <li>
        <a href="child.php?id=<?php echo (int)$child['id']; ?>">
          <strong><?php echo growth_h($child['name']); ?></strong>
          <span class="growth-age"><?php echo growth_h(growth_format_age($age)); ?></span>
        </a>
        <?php if ($last): ?>
          <span class="growth-latest">
            <?php echo growth_h(growth_format_date($last['date'])); ?>:
            <?php if ($last['height_cm'] !== null): ?>
              <?php echo growth_h(growth_num(growth_display_length($last['height_cm']))); ?> <?php echo growth_length_unit(); ?><?php endif; ?>
            <?php if ($last['height_cm'] !== null && $last['weight_kg'] !== null): ?>,<?php endif; ?>
            <?php if ($last['weight_kg'] !== null): ?>
              <?php echo growth_h(growth_num(growth_display_weight($last['weight_kg']), growth_weight_decimals())); ?> <?php echo growth_weight_unit(); ?><?php endif; ?>
            <span class="growth-count"><?php echo th('count_measurements', array('n' => count($measurements))); ?></span>
          </span>
        <?php else: ?>
          <span class="growth-latest"><?php echo th('no_measurements_yet'); ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<details class="growth-panel"<?php echo $children ? '' : ' open'; ?>>
  <summary><?php echo th('add_child_summary'); ?></summary>
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
</details>

<p class="growth-links">
  <a href="import.php"><?php echo th('nav_import_csv'); ?></a>
  &middot;
  <a href="trash.php"><?php echo th('nav_trash'); ?></a>
</p>

<?php growth_foot(); ?>
