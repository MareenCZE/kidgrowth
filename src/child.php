<?php
/*
 * Growth tracker - one child: charts, predictions and the measurement table.
 */
require_once __DIR__ . '/shell.inc';
require_once __DIR__ . '/chart.inc';

$childId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$child = growth_child($childId);
if (!$child) {
    header('HTTP/1.1 404 Not Found');
    growth_head(t('page_title_not_found'));
    echo '<h1>' . th('heading_child_not_found') . '</h1><p><a href="index.php">' . th('nav_back_to_children') . '</a></p>';
    growth_foot();
    exit;
}

$referenceId = growth_selected_reference();
$availableRefs = growth_reference_ids_for($child);
if (!in_array($referenceId, $availableRefs, true)) {
    $referenceId = reset($availableRefs) ?: 'cdc';
}
$reference = growth_reference($referenceId);
$fullRange = isset($_GET['rozsah']) && $_GET['rozsah'] === 'vse';
$smoothing = isset($_GET['vyhlazeni']) && $_GET['vyhlazeni'] === '1';

$sex = $child['sex'];
$born = $child['birth_date'];
$measurements = growth_measurements($childId);

/* Split into per-metric series once; the charts and the table both need them.
   BMI and weight-for-height need both measurements from the same visit, and
   weight-for-height is plotted against height rather than against age - so its
   index is the height, which is what makes it able to tell "small for their
   age" apart from "underweight". */
$series = array('height' => array(), 'weight' => array(), 'bmi' => array(), 'wfh' => array());
foreach ($measurements as $row) {
    $age = growth_decimal_age($born, $row['date']);
    if ($row['height_cm'] !== null) {
        $series['height'][] = array('age' => $age, 'value' => $row['height_cm'], 'date' => $row['date']);
    }
    if ($row['weight_kg'] !== null) {
        $series['weight'][] = array('age' => $age, 'value' => $row['weight_kg'], 'date' => $row['date']);
    }
    if ($row['height_cm'] !== null && $row['weight_kg'] !== null) {
        $bmi = growth_bmi($row['height_cm'], $row['weight_kg']);
        if ($bmi !== null) {
            $series['bmi'][] = array('age' => $age, 'value' => $bmi, 'date' => $row['date']);
        }
        $series['wfh'][] = array(
            'age' => (float)$row['height_cm'],   /* the index here is the height */
            'value' => $row['weight_kg'],
            'date' => $row['date'],
        );
    }
}
/* weight-for-height is drawn against height, so it has to be ordered by it */
usort($series['wfh'], function ($a, $b) {
    return $a['age'] <=> $b['age'];
});

$target = growth_target_height($child['father_height_cm'], $child['mother_height_cm'], $sex);
$projection = growth_channel_projection($born, $sex, $measurements, $referenceId);
$velocities = growth_velocities($born, $measurements);
$currentAge = growth_decimal_age($born, date('Y-m-d'));

$linkParams = array('id' => $childId);
if ($fullRange) {
    $linkParams['rozsah'] = 'vse';
}
if ($smoothing) {
    $linkParams['vyhlazeni'] = '1';
}

/* Smooth each metric once, up front: the charts need the curve, the notes need
   the scatter, and the dots need to know which of them look mis-recorded. */
$smooth = array();
if ($smoothing) {
    foreach (growth_metrics() as $metricId => $metricMeta) {
        $fit = growth_smooth_series($series[$metricId],
            growth_reference_rows($referenceId, $metricId, $sex),
            array('noise' => $metricMeta['noise']));
        if (!$fit) {
            continue;
        }
        $smooth[$metricId] = $fit;
        foreach ($fit['suspect'] as $index => $deviation) {
            $series[$metricId][$index]['suspect'] = $deviation;
        }
    }
}

growth_head($child['name'], $childId);
?>

<h1><?php echo growth_h($child['name']); ?></h1>
<p class="growth-subtitle">
  <?php echo th($sex === 'f' ? 'subtitle_born_f' : 'subtitle_born_m'); ?>
  <?php echo growth_h(growth_format_date($born)); ?>,
  <?php echo th('subtitle_now'); ?> <?php echo growth_h(growth_format_age($currentAge)); ?>
</p>

<?php growth_reference_picker($referenceId, $linkParams, $availableRefs); ?>

<?php
  $baseLink = array('id' => $childId, 'ref' => $referenceId);
  $rangeLink = $baseLink; if (!$fullRange) { $rangeLink['rozsah'] = 'vse'; }
  if ($smoothing) { $rangeLink['vyhlazeni'] = '1'; }
  $smoothLink = $baseLink; if ($fullRange) { $smoothLink['rozsah'] = 'vse'; }
  if (!$smoothing) { $smoothLink['vyhlazeni'] = '1'; }
?>
<p class="growth-range">
  <a href="?<?php echo growth_h(http_build_query($rangeLink)); ?>"><?php
    echo $fullRange ? th('link_show_measured_range') : th('link_show_full_range');
  ?></a>
  &middot;
  <a href="?<?php echo growth_h(http_build_query($smoothLink)); ?>"><?php
    echo $smoothing ? th('link_hide_smoothing') : th('link_show_smoothing');
  ?></a>
</p>

<?php
/* Draw each metric, or explain its absence. A reference not covering an age is
   normal rather than exceptional - WHO publishes no weight-for-age past 10 -
   and saying so plainly beats an empty box or, worse, an extrapolated curve. */
foreach (growth_metrics() as $metric => $meta):
    $span = growth_reference_span($referenceId, $metric, $sex);

    /* Whether to draw depends on the measurements, not on how old the child is
       now. The breastfed reference only covers the first year, and both these
       children are long past it - but looking back at how they tracked as
       infants is exactly what it is for. */
    $pointsInSpan = 0;
    if ($span) {
        foreach ($series[$metric] as $point) {
            if ($point['age'] >= $span[0] && $point['age'] <= $span[1]) {
                $pointsInSpan++;
            }
        }
    }
    /* only meaningful for the age-indexed metrics */
    $childCovered = ($span && $meta['index'] === 'age' && $currentAge !== null
        && $currentAge >= $span[0] && $currentAge <= $span[1]);
    $indexWord = ($meta['index'] === 'age') ? th('index_word_age') : th('index_word_height');
    ?>
    <section class="growth-section">
      <h2><?php echo growth_h($meta['label']); ?></h2>
      <?php if (!$span): ?>
        <p class="growth-nodata">
          <?php echo t('nodata_metric_not_in_reference', array('reference' => growth_h($reference['label']))); ?>
        </p>
      <?php elseif (!$pointsInSpan && !$childCovered): ?>
        <p class="growth-nodata">
          <?php echo t('nodata_no_measurements_in_span', array(
              'reference' => growth_h($reference['label']),
              'index' => $indexWord,
              'min' => growth_h(growth_num(growth_display_metric_index_value($metric, $span[0]))),
              'max' => growth_h(growth_num(growth_display_metric_index_value($metric, $span[1]))),
              'unit' => growth_display_metric_index_unit($metric) ?: th('unit_years_word'),
          )); ?>
        </p>
      <?php else: ?>
        <?php
          echo growth_chart_svg($referenceId, $metric, $sex, $series[$metric], array(
              'full_range' => $fullRange,
              'target' => ($metric === 'height') ? $target : null,
              'projection' => ($metric === 'height') ? $projection : null,
              'smooth' => isset($smooth[$metric]) ? $smooth[$metric] : null,
          ));
        ?>
        <?php if (isset($smooth[$metric])): ?>
          <?php $fit = $smooth[$metric]; ?>
          <p class="growth-note">
            <?php
              $scatterDecimals = (growth_metric_value_kind($metric) === 'weight')
                  ? growth_weight_decimals() : $meta['decimals'];
            ?>
            <?php echo t('note_scatter', array(
                'scatter' => '<strong>&plusmn;' . growth_h(growth_num(growth_display_metric_value($metric, $fit['scatter']), $scatterDecimals))
                    . ' ' . growth_h(growth_display_metric_unit($metric) ?: $meta['unit']) . '</strong>',
            )); ?>
            <?php if ($fit['suspect']): ?>
              <?php echo t('note_scatter_suspect', array('n' => count($fit['suspect']))); ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($metric === 'bmi'): ?>
          <details class="growth-panel">
            <summary><?php echo th('bmi_explainer_summary'); ?></summary>
            <p><?php echo t('bmi_explainer_p1'); ?></p>
            <p><?php echo t('bmi_explainer_p2'); ?></p>
            <p><?php echo t('bmi_explainer_p3'); ?></p>
            <p class="growth-note"><?php echo t('bmi_explainer_p4'); ?></p>
          </details>
        <?php endif; ?>
        <?php if ($metric === 'weight' && $span[1] < 17.5): ?>
          <p class="growth-note">
            <?php echo t('note_weight_ceiling', array(
                'reference' => growth_h($reference['label']),
                'age' => growth_h(growth_format_years($span[1])),
            )); ?>
          </p>
        <?php endif; ?>
        <?php
          /* No reference covers every age - Poland starts at 3, the breastfed
             curves stop at 1 - so say which measurements fell outside rather
             than letting them quietly vanish from the chart. */
          $hidden = count($series[$metric]) - $pointsInSpan;
        ?>
        <?php if ($hidden > 0): ?>
          <p class="growth-note">
            <?php echo t('note_hidden_measurements', array(
                'reference' => growth_h($reference['label']),
                'index' => $indexWord,
                'min' => growth_h(growth_num(growth_display_metric_index_value($metric, $span[0]))),
                'max' => growth_h(growth_num(growth_display_metric_index_value($metric, $span[1]))),
                'unit' => growth_display_metric_index_unit($metric) ?: th('unit_years_word'),
                'n' => (int)$hidden,
            )); ?>
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php
/* The SDS trend. Only the age-indexed metrics belong here - plotting
   weight-for-height against age would mix two different x axes on one chart. */
$sdsSeries = array();
foreach (array('height', 'weight', 'bmi') as $metric) {
    $rows = growth_reference_rows($referenceId, $metric, $sex);
    if (!$rows) {
        continue;
    }
    $points = array();
    foreach ($series[$metric] as $point) {
        $lms = growth_lms_at($rows, $point['age']);
        if (!$lms) {
            continue;
        }
        $z = growth_zscore($point['value'], $lms);
        if ($z === null) {
            continue;
        }
        $points[] = array('age' => $point['age'], 'z' => $z,
                          'date' => $point['date'], 'value' => $point['value'],
                          'median' => $lms['m']);
    }
    if ($points) {
        $sdsSeries[$metric] = $points;
    }
}
$sdsChart = $sdsSeries ? growth_sds_chart_svg($sdsSeries, array('smooth' => $smooth)) : '';
?>
<?php
$velocityChart = growth_velocity_chart_svg($referenceId, $sex, $velocities, array(
    'smooth' => isset($smooth['height']) ? $smooth['height'] : null,
    'full_range' => $fullRange,
));
?>
<?php if ($velocityChart !== ''): ?>
  <section class="growth-section">
    <h2><?php echo th('heading_velocity'); ?></h2>
    <?php echo $velocityChart; ?>
    <p class="growth-note"><?php echo t('velocity_explainer_p1'); ?></p>
    <p class="growth-note"><?php echo t('velocity_explainer_p2'); ?></p>
  </section>
<?php endif; ?>

<?php if ($sdsChart !== ''): ?>
  <section class="growth-section">
    <h2><?php echo th('heading_sds'); ?></h2>
    <?php echo $sdsChart; ?>
    <p class="growth-note"><?php echo t('sds_explainer_p1'); ?></p>
  </section>
<?php endif; ?>

<section class="growth-section">
  <h2><?php echo th('heading_prediction'); ?></h2>
  <div class="growth-prediction">
    <?php /* Measured first: it is built from this child's own growth, whereas
             the mid-parental target is a ~17 cm band that says the same thing
             for every child of the same two parents. */ ?>
    <div class="growth-card growth-card-main">
      <h3><?php echo th('heading_measured_prediction'); ?></h3>
      <?php if ($projection): ?>
        <p class="growth-number"><?php echo growth_h(growth_num(growth_display_length($projection['mid']))); ?> <?php echo growth_length_unit(); ?></p>
        <p class="growth-scatter">
          <?php echo t('range_cm', array(
              'low' => growth_h(growth_num(growth_display_length($projection['low']))),
              'high' => growth_h(growth_num(growth_display_length($projection['high']))),
              'unit' => growth_length_unit(),
          )); ?>
        </p>
        <p class="growth-note">
          <?php echo t('note_projection', array(
              'z' => growth_h(growth_num($projection['z'], 2)),
              'n' => (int)$projection['based_on'],
          )); ?>
        </p>
      <?php else: ?>
        <?php
          /* Two quite different reasons for having nothing to show, and the
             difference matters: one is fixed by measuring again, the other by
             switching reference. */
          $heightSpan = growth_reference_span($referenceId, 'height', $sex);
          $reachesAdulthood = $heightSpan && $heightSpan[1] >= 17.5;
        ?>
        <?php if (!$reachesAdulthood): ?>
          <p class="growth-nodata">
            <?php echo t('nodata_reference_too_short', array(
                'reference' => growth_h($reference['label']),
                'age' => growth_h(growth_format_years($heightSpan[1])),
            )); ?>
          </p>
        <?php else: ?>
          <p class="growth-nodata"><?php echo th('nodata_need_two_measurements'); ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="growth-card">
      <h3><?php echo th('heading_target_height'); ?></h3>
      <?php if ($target): ?>
        <p class="growth-number"><?php echo growth_h(growth_num(growth_display_length($target['mid']))); ?> <?php echo growth_length_unit(); ?></p>
        <p class="growth-scatter">
          <?php echo t('range_cm', array(
              'low' => growth_h(growth_num(growth_display_length($target['low']))),
              'high' => growth_h(growth_num(growth_display_length($target['high']))),
              'unit' => growth_length_unit(),
          )); ?>
        </p>
        <p class="growth-note">
          <?php echo t('note_target_height', array(
              'father' => growth_h(growth_num(growth_display_length($child['father_height_cm']))),
              'mother' => growth_h(growth_num(growth_display_length($child['mother_height_cm']))),
              'band' => growth_h(growth_num(growth_display_length($target['high'] - $target['low']))),
              'unit' => growth_length_unit(),
          )); ?>
        </p>
      <?php else: ?>
        <p class="growth-nodata">
          <?php echo t('nodata_need_parent_heights', array(
              'link_open' => '<a href="edit-child.php?id=' . (int)$childId . '">',
              'link_close' => '</a>',
          )); ?>
        </p>
      <?php endif; ?>
    </div>
  </div>
  <p class="growth-note"><?php echo t('note_bone_age'); ?></p>
</section>

<details class="growth-panel">
  <summary><?php echo th('summary_percentile_sd'); ?></summary>
  <p><?php echo t('percentile_sd_p1'); ?></p>
  <p><?php echo t('percentile_sd_p2'); ?></p>
  <p><?php echo t('percentile_sd_p3'); ?></p>
  <p class="growth-note"><?php echo t('percentile_sd_p4'); ?></p>
</details>

<section class="growth-section">
  <h2><?php echo th('heading_measurements'); ?></h2>

  <form method="post" action="save-measurement.php" class="growth-form growth-row">
    <?php echo growth_csrf_field(); ?>
    <input type="hidden" name="child_id" value="<?php echo (int)$childId; ?>">
    <input type="hidden" name="ref" value="<?php echo growth_h($referenceId); ?>">
    <label><?php echo th('label_date'); ?>
      <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required
             max="<?php echo date('Y-m-d'); ?>">
    </label>
    <?php $imperial = growth_units() === 'imperial'; ?>
    <label><?php echo th('label_height_cm'); ?> (<?php echo growth_length_unit(); ?>)
      <input type="text" inputmode="<?php echo $imperial ? 'text' : 'decimal'; ?>" name="height"
             placeholder="<?php echo $imperial ? th('placeholder_example_height_imperial') : th('placeholder_example_height'); ?>">
    </label>
    <label><?php echo th('label_weight_kg'); ?> (<?php echo growth_weight_unit(); ?>)
      <input type="text" inputmode="decimal" name="weight"
             placeholder="<?php echo $imperial ? th('placeholder_example_weight_imperial') : th('placeholder_example_weight'); ?>">
    </label>
    <button type="submit"><?php echo th('button_save'); ?></button>
  </form>
  <p class="growth-note">
    <?php echo th('note_measurement_save'); ?>
  </p>

  <?php if ($measurements): ?>
    <?php
      /* velocity keyed by date, so the table can show it on the right row */
      $velocityByDate = array();
      foreach ($velocities as $velocity) {
          $velocityByDate[$velocity['date']] = $velocity;
      }
    ?>
    <div class="growth-table-wrap">
    <table class="growth-table">
      <thead>
        <tr>
          <th><?php echo th('th_date'); ?></th><th><?php echo th('th_age'); ?></th>
          <th><?php echo th('th_height'); ?></th><th><?php echo th('th_percentile_short'); ?></th><th>SD</th>
          <th><?php echo th('th_weight'); ?></th><th><?php echo th('th_percentile_short'); ?></th><th>SD</th>
          <th>BMI</th><th><?php echo th('th_percentile_short'); ?></th>
          <th><?php echo th('th_velocity'); ?></th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_reverse($measurements) as $row): ?>
        <?php
          $age = growth_decimal_age($born, $row['date']);
          $h = growth_evaluate($born, $sex, $row['date'], 'height', $row['height_cm'], $referenceId);
          $w = growth_evaluate($born, $sex, $row['date'], 'weight', $row['weight_kg'], $referenceId);
          $bmiValue = growth_bmi($row['height_cm'], $row['weight_kg']);
          $b = growth_evaluate($born, $sex, $row['date'], 'bmi', $bmiValue, $referenceId);
        ?>
        <tr>
          <td><?php echo growth_h(growth_format_date($row['date'])); ?></td>
          <td class="growth-muted"><?php echo growth_h(growth_format_age($age)); ?></td>

          <td><?php echo $row['height_cm'] === null ? '' : growth_h(growth_num(growth_display_length($row['height_cm']))) . '&nbsp;' . growth_length_unit(); ?></td>
          <td><?php echo $row['height_cm'] === null ? '' : growth_format_percentile($h['percentile'], $h['z'], $referenceId, 'height'); ?></td>
          <td class="growth-muted"><?php echo $h['z'] === null ? '' : growth_h(growth_num($h['z'], 2)); ?></td>

          <td><?php echo $row['weight_kg'] === null ? '' : growth_h(growth_num(growth_display_weight($row['weight_kg']), growth_weight_decimals())) . '&nbsp;' . growth_weight_unit(); ?></td>
          <td><?php echo $row['weight_kg'] === null ? '' : growth_format_percentile($w['percentile'], $w['z'], $referenceId, 'weight'); ?></td>
          <td class="growth-muted"><?php echo $w['z'] === null ? '' : growth_h(growth_num($w['z'], 2)); ?></td>

          <td><?php echo $bmiValue === null ? '' : growth_h(growth_num($bmiValue, 1)); ?></td>
          <td><?php echo $bmiValue === null ? '' : growth_format_percentile($b['percentile'], $b['z'], $referenceId, 'bmi'); ?></td>

          <td class="growth-muted">
            <?php if (isset($velocityByDate[$row['date']])): $v = $velocityByDate[$row['date']]; ?>
              <span title="<?php echo th('velocity_since', array(
                  'date' => growth_h(growth_format_date($v['from_date'])),
                  'span' => growth_h(growth_format_years_span($v['years'])),
              )); ?>">
                <?php echo growth_h(growth_num(growth_display_velocity($v['cm_per_year']))); ?>&nbsp;<?php echo growth_velocity_unit(); ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <a href="delete-measurement.php?child_id=<?php echo (int)$childId; ?>&amp;id=<?php echo (int)$row['id']; ?>&amp;ref=<?php echo growth_h($referenceId); ?>"
               class="growth-delete" title="<?php echo th('button_delete'); ?>">&times;</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="growth-note"><?php echo t('note_velocity_table', array(
        'small' => growth_h(growth_num(growth_display_length(0.5), 2)),
        'unit' => growth_length_unit(),
        'large' => growth_h(growth_num(growth_display_velocity(1.5))),
        'velocity_unit' => growth_velocity_unit(),
    )); ?></p>
  <?php endif; ?>
</section>

<p class="growth-links">
  <a href="edit-child.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_edit_child'); ?></a>
  &middot;
  <a href="export.php?id=<?php echo (int)$childId; ?>"><?php echo th('nav_export_csv'); ?></a>
  &middot;
  <a href="index.php"><?php echo th('nav_all_children'); ?></a>
</p>

<?php growth_foot($referenceId); ?>
