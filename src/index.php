<?php
/*
 * Growth tracker - list of children, and nothing else.
 *
 * Adding a child, importing, exporting and the trash all moved to manage.php.
 * They used to be here, which meant a permanent form taking most of the first
 * screen for something a family does once or twice ever, above the list it
 * pushed down. What this page is for is opening a child.
 */
require_once __DIR__ . '/shell.inc';

$reference = growth_selected_reference();
$children = growth_children();

growth_head('');
?>

<h1><?php echo th('page_title_home'); ?></h1>

<?php if (isset($_GET['deleted_at'])): ?>
  <p class="growth-note">
    <?php echo t('flash_moved_to_trash', array('name' => growth_h((string)$_GET['deleted_at']))); ?>
  </p>
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

<p class="growth-links"><a href="manage.php"><?php echo th('nav_manage'); ?></a></p>

<?php growth_foot(); ?>
