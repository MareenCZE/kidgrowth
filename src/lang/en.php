<?php

/**
 * English strings - the default locale (see i18n.inc). This is the
 * authoritative set: t() falls back to this file when a key is missing from
 * the active locale, so every key the application uses must exist here.
 */

return array(

    /* --------------------------------------------------------- navigation */
    'nav_back' => 'Back',
    'nav_back_to_children' => 'Back to the list of children',
    'nav_all_children' => 'All children',
    'nav_edit_child' => 'Edit child',
    'nav_delete_child' => 'Delete child',
    'nav_export_csv' => 'Export CSV',
    'nav_import_csv' => 'Import CSV',
    'nav_trash' => 'Trash',

    /* --------------------------------------------------------------- home */
    'page_title_home' => 'Children',
    'flash_moved_to_trash' => '{name} moved to the <a href="kos.php">trash</a>, where you can restore it.',
    'intro_no_children' => 'No children yet. Add the first one below, or import from RůstCZ via <a href="import.php">CSV import</a>.',
    'no_measurements_yet' => 'no measurements yet',
    'count_measurements' => '({n} measurements)',
    'add_child_summary' => 'Add a child',
    'note_parent_heights' => "Parents' heights are used to calculate the target (genetic) height. Everything else works without them.",

    /* ----------------------------------------------------------- edit/add */
    'page_title_edit' => 'Edit',
    'heading_edit_child' => 'Edit child',
    'label_name' => 'Name',
    'label_sex' => 'Sex',
    'sex_boy' => 'boy',
    'sex_girl' => 'girl',
    'label_birth_date' => 'Date of birth',
    'label_father_height' => "Father's height",
    'label_mother_height' => "Mother's height",
    'placeholder_optional' => 'optional',
    'placeholder_optional_height_imperial' => 'optional, e.g. 5\'10"',
    'label_breastfed' => 'Breastfed child',
    'note_breastfed_reference' => 'Unlocks the SZÚ reference for breastfed infants (0-1 year). Breastfed infants gain differently - faster up to about three months, slower afterwards.',
    'button_add' => 'Add',
    'button_save' => 'Save',
    'error_name_and_birth_required' => 'Enter a name and a date of birth.',
    'error_birth_in_future' => 'The date of birth cannot be in the future.',

    /* -------------------------------------------------------------- child */
    'page_title_not_found' => 'Not found',
    'heading_child_not_found' => 'Child not found',
    'subtitle_born_m' => 'born',
    'subtitle_born_f' => 'born',
    'subtitle_now' => 'now',
    'link_show_measured_range' => 'Show only the measured age range',
    'link_show_full_range' => 'Show the full range to adulthood',
    'link_hide_smoothing' => 'Show only the measured values',
    'link_show_smoothing' => 'Smooth out measurement noise',

    'nodata_metric_not_in_reference' => 'The {reference} reference does not include this measure.',
    'nodata_no_measurements_in_span' => 'The {reference} reference covers {index} {min}–{max} {unit}, and there are no measurements in that range. Choose a different reference.',
    'index_word_age' => 'age',
    'index_word_height' => 'height',
    'unit_years_word' => 'years',

    'note_scatter' => 'The typical spread of measurements is {scatter} – that is how much individual measurements differ from the smoothed curve.',
    'note_scatter_suspect' => 'The highlighted measurements ({n}) differ enough to be worth double-checking.',

    'bmi_explainer_summary' => 'What does BMI mean for a child?',
    'bmi_explainer_p1' => 'BMI puts weight in proportion to height (kg/m²), so on its own it does not favour tall or short children.',
    'bmi_explainer_p2' => "Fixed thresholds like those used for adults don't apply to children - the numbers 25 and 30 don't belong here. It is judged by percentile for age and sex instead: SZÚ puts the 90th–97th percentile band as overweight and above the 97th as obesity. Other references draw the line elsewhere (CDC uses the 85th and 95th), so the same child can come out differently depending on which reference is selected.",
    'bmi_explainer_p3' => 'Czech practice prefers the weight-for-height chart over BMI up to age five; only older children are assessed by BMI. Both charts are above on this page.',
    'bmi_explainer_p4' => 'BMI dropping in the preschool years is normal and no cause for concern: the median for boys rises to about 17.2 around eight months, falls to about 15.4 around six years, and only then rises again, reaching 21.7 by eighteen. For girls the dip comes a little earlier.',

    'note_weight_ceiling' => '{reference} only publishes weight-for-age up to {age} – in puberty, weight alone no longer separates height from body mass.',
    'note_hidden_measurements' => 'The {reference} reference covers {index} {min}–{max} {unit}; {n} measurement(s) outside that range are not shown on the chart.',

    'heading_velocity' => 'Growth velocity',
    'velocity_explainer_p1' => "How many centimetres the child gained over a year. The median pace is how much a child on the 50th percentile gains over the same period – not a percentile of velocity, but the growth of the median child. Velocity percentiles are deliberately absent here: they cannot be derived from attained-height tables, and nobody publishes them for this age range under a usable licence (WHO's only go to two years, SZÚ has none at all).",
    'velocity_explainer_p2' => 'Velocity is the most sensitive of all these figures to measurement imprecision – it is computed from the difference of two values, so their errors add up. That is why it is measured over a period of about a year, and why it pays to read the smoothed curve rather than the individual points.',

    'heading_sds' => 'SD over time',
    'sds_explainer_p1' => 'This chart answers a question the growth charts alone cannot: is the child holding their channel? A flat line means they are growing consistently relative to their peers – whether high or low. A rising or falling line means they are leaving that channel, and that is the signal worth a doctor’s attention. The grey band is the −2 to +2 SD range, where roughly 95% of children fall.',

    'heading_prediction' => 'Adult height prediction',
    'heading_measured_prediction' => 'From measured values',
    'range_cm' => 'range {low}–{high} {unit}',
    'note_projection' => 'Assumes the child stays in their growth channel ({z} SD), calculated from the last {n} height measurements. Marked on the chart with a blue marker at eighteen years. <strong>This does not hold during puberty</strong> – a growth spurt commonly moves a child between channels.',
    'nodata_reference_too_short' => 'The {reference} reference ends at age {age}, so adult height cannot be estimated from it. Switch to a reference that reaches adulthood.',
    'nodata_need_two_measurements' => 'Needs at least two height measurements.',
    'heading_target_height' => 'Target (genetic) height',
    'note_target_height' => "From the parents' heights alone ({father} and {mother} {unit}), regardless of measured values. The band is about {band} {unit} wide, so it is more of a sanity check than a prediction. Shown in brown on the chart.",
    'nodata_need_parent_heights' => "Enter the parents' heights in {link_open}the child's edit page{link_close}.",
    'note_bone_age' => 'More precise methods (Bayley–Pinneau, Tanner–Whitehouse) rely on bone age, determined from a hand X-ray, and are deliberately not included here.',

    'summary_percentile_sd' => 'What do percentile and SD mean?',
    'percentile_sd_p1' => 'A percentile says what percentage of children of the same age and sex are smaller. The 25th percentile means a quarter of children are smaller and three quarters larger. The 50th percentile is the median – the exact centre of the population.',
    'percentile_sd_p2' => 'SD (standard deviation, also SDS or z-score) measures the same thing on a different scale: how many deviations the child is above or below average. 0 SD is exactly average; a negative number means below average. Roughly two thirds of children fall between −1 and +1 SD, and 95% between −2 and +2 SD.',
    'percentile_sd_p3' => 'The conversion is fixed: −2 SD is roughly the 2nd percentile, −1 SD about the 16th, 0 SD exactly the 50th, +1 SD about the 84th, and +2 SD roughly the 98th.',
    'percentile_sd_p4' => 'Why both are here: for children near the average, a percentile reads more easily, but at the edges percentiles compress together – the gap between the 1st and 0.1st percentile is more than a whole standard deviation of growth. That is exactly why doctors track SD, not percentile, for very small or very large children. And a change in SD over time matters more than its value: a child growing steadily at −2 SD is most likely just small, while a child who moves from −0.5 to −1.5 SD over a year is leaving their channel, and that is what is worth a doctor’s attention.',

    'heading_measurements' => 'Measurements',
    'label_date' => 'Date',
    'label_height_cm' => 'Height',
    'placeholder_example_height' => 'e.g. 122.5',
    'placeholder_example_height_imperial' => 'e.g. 4\'10"',
    'label_weight_kg' => 'Weight',
    'placeholder_example_weight' => 'e.g. 20.5',
    'placeholder_example_weight_imperial' => 'e.g. 45',
    'note_measurement_save' => 'Only one of the two values is required. Saving the same date again overwrites the earlier entry.',
    'th_date' => 'Date',
    'th_age' => 'Age',
    'th_height' => 'Height',
    'th_weight' => 'Weight',
    'th_percentile_short' => 'Perc.',
    'th_velocity' => 'Growth velocity',
    'velocity_since' => 'since {date}, over {span}',
    'unit_year_short' => 'year',
    'note_velocity_table' => "Growth velocity is annualised and measured over roughly the preceding year, not since the previous measurement – hover over a value to see the exact period. A short gap would magnify measurement error along with growth: {small} {unit} of imprecision over four months comes out as an extra {large} {velocity_unit}, so even a steadily-growing child would appear to speed up and slow down.",
    'button_delete' => 'Delete',

    /* -------------------------------------------------------- delete/trash */
    'page_title_delete_measurement' => 'Delete measurement',
    'heading_delete_measurement' => 'Delete this measurement?',
    'confirm_delete_measurement' => 'Really delete {name}’s measurement from {date}?',
    'note_recoverable_from_trash' => 'It stays available in the <a href="kos.php">trash</a>, where it can be restored.',

    'page_title_delete_child' => 'Delete child',
    'heading_delete_child' => 'Delete {name}?',
    'confirm_delete_child' => 'This will move {name} and all {n} of their measurements to the trash at once.',
    'note_delete_child_confirm' => "It can be undone from the <a href=\"kos.php\">trash</a> – but to make sure this doesn't happen by accident, type the child's name exactly as shown above.",
    'error_name_mismatch' => "The name doesn't match; the child was not deleted.",
    'label_confirm_name' => 'Name, to confirm',
    'button_move_to_trash' => 'Move to trash',

    'trash_intro' => "Nothing disappears from here on its own – items stay until you restore them.",
    'trash_children_heading' => 'Deleted children',
    'trash_measurements_heading' => 'Deleted measurements',
    'trash_none' => 'None.',
    'trash_deleted_at' => '(deleted {when})',
    'button_restore' => 'Restore',

    /* -------------------------------------------------------------- import */
    'page_title_import' => 'Import',
    'heading_import' => 'CSV import',
    'import_error_upload_failed' => 'The file could not be uploaded.',
    'import_error_too_large' => 'The file is too large (limit {mb} MB).',
    'import_error_cannot_open' => 'The file could not be opened.',
    'import_error_not_text' => "The file doesn't look like text (CSV) – is this really the right attachment?",
    'import_error_empty_file' => 'Empty file.',
    'import_error_missing_column' => 'Missing column "{column}".',
    'import_error_too_many_rows' => 'The file has more than {max} rows; the rest was not processed.',
    'import_error_column_count' => "Row {line}: the number of columns doesn't match.",
    'import_error_invalid_birth' => 'Row {line}: invalid date of birth.',
    'import_summary' => 'Imported {n} measurements.',
    'import_skipped' => 'Skipped {n} row(s) with neither height nor weight.',
    'label_csv_file' => 'CSV file',
    'button_import' => 'Import',
    'import_format_heading' => 'Format',
    'import_format_intro' => 'The first row is the header, comma-separated:',
    'import_format_note' => 'Required are <code>dite</code>, <code>pohlavi</code> (m/z), <code>narozeni</code> and <code>datum</code>, both in the form YYYY-MM-DD. Height and weight may be blank – a blank cell means "not measured", not zero. Importing the same date again overwrites rather than duplicating it.',
    'import_from_rustcz_heading' => 'From RůstCZ',
    'import_from_rustcz_intro' => "Converting the old database to this CSV:",

    /* ---------------------------------------------------------------- footer */
    'footer_source_label' => 'Data source:',
    'footer_credits' => 'Reference data: SZÚ (CAV 2001 / 1991), Poland (Kułaga et al.), WHO, CDC. Does not replace a doctor.',

    /* --------------------------------------------------------------- charts */
    'button_fullscreen' => 'Show the chart full screen',
    'chart_title_growth' => 'Growth chart: {label}',
    'nodata_reference_missing' => 'This reference does not exist for this measure.',
    'nodata_no_reference_for_age' => 'No reference data is available for this age.',
    'chart_label_projection' => 'Prediction from measured values: {mid} {unit} at 18 years · range {low}–{high} {unit} · channel {z} SD · from the last {n} measurements',
    'chart_label_target' => "Target (genetic) height: {mid} {unit} · range {low}–{high} {unit} · from parents' heights only",
    'tooltip_percentile' => 'percentile {n}',
    'tooltip_age' => 'age {age}',
    'tooltip_height' => 'height {height} {unit}',
    'tooltip_suspect' => 'deviation {deviation} {unit} from the smoothed curve – check the entry',
    'tooltip_median_pace' => 'median pace {pace} {unit}',
    'legend_measured' => 'measured values',
    'legend_measured_velocity' => 'measured velocity',
    'legend_smoothed' => 'smoothed curve',
    'legend_projection' => 'prediction from measurements',
    'legend_target' => 'target height',
    'legend_median_pace' => 'median pace',

    /* ------------------------------------------------------------- metrics */
    'metric_height' => 'Height',
    'metric_weight' => 'Weight',
    'metric_bmi' => 'BMI',
    'metric_wfh' => 'Weight-for-height',
    'index_age' => 'age (years)',
    'index_height' => 'height ({unit})',

    /* -------------------------------------------------------- number/plural */
    'unit_year' => 'year',
    'unit_years' => 'years',
    'unit_month' => 'month',
    'unit_months' => 'months',
    'median_diff' => '{sign}{value} {unit} from median',
    'median_diff_exact' => 'exactly the median',
    'percentile_extrapolated_title' => "Outside the range SZÚ publishes (3rd–97th percentile); the value is extrapolated",

    /* ------------------------------------------------------- reference data */
    'ref_cav_label' => 'Czechia – CAV (SZÚ)',
    'ref_cav_note' => 'Height from CAV 2001, weight from CAV 1991. Czech national reference.',
    'ref_cav_source' => 'Statní zdravotní ústav, 6th Nationwide Anthropological Survey (CAV 2001 / 1991).',

    'ref_koj_label' => 'Czechia – breastfed infants',
    'ref_koj_note' => 'Reference for breastfed infants, 0-1 year. Breastfed infants gain differently than non-breastfed ones.',
    'ref_koj_source' => 'SZÚ / 3rd Faculty of Medicine, Charles University (4/2008), breastfed-infant reference charts, digitised from the published charts.',

    'ref_pol_label' => 'Poland',
    'ref_pol_note' => 'Polish national reference, ages 3-18. A neighbouring population, closer than WHO or CDC.',
    'ref_pol_source' => 'Kułaga Z. et al., Polish 2010 (ages 7-18, CC BY-NC) and Polish 2012 (ages 3-6, CC BY), Eur J Pediatr.',

    'ref_who_label' => 'WHO',
    'ref_who_note' => 'International WHO standards. WHO only publishes weight up to age 10.',
    'ref_who_source' => 'WHO Child Growth Standards (2006) and Growth Reference 5-19 (2007).',

    'ref_cdc_label' => 'USA – CDC 2000',
    'ref_cdc_note' => 'US reference CDC 2000, height and weight from birth to age 20.',
    'ref_cdc_source' => 'CDC, National Center for Health Statistics, growth charts 2000.',

);
