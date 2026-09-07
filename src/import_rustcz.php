<?php
/*
 * Growth tracker - importing a RustCZ backup from the browser.
 *
 * The last thing in this project that needed a command line. The audience for
 * this application is people with a RustCZ database and nowhere to put it,
 * and an instruction beginning "open a terminal" excludes most of them at the
 * first word.
 *
 * Two screens, not the three the design expected. Reading peda.rcz - see
 * rustcz.inc - removed the reason for the third: the measurements carry the
 * GUID of the child they belong to, so there is no pairing to guess at, no
 * score to show, and no text export to ask for. What is left is upload, look
 * at what was found, confirm.
 *
 * Confirming does not write measurements itself. It builds the CSV that
 * import.inc already reads and hands it over, so there stays exactly one
 * piece of code that decides what a measurement is, and it is the one with
 * the tests on it.
 */
require_once __DIR__ . '/shell.inc';
require_once __DIR__ . '/import.inc';
require_once __DIR__ . '/rustcz.inc';

/* A backup of a large family is still a small file: 397 bytes per child and
   68 per measurement, so this is a hundred children with a thousand
   measurements each and room to spare. */
const GROWTH_RCZ_MAX_BYTES = 4 * 1024 * 1024;

$error = '';
$found = null;      /* what the uploaded files turned out to hold */
$report = null;     /* what was written, once confirmed */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    growth_csrf_check();
    if (growth_demo_mode()) {
        $error = t('demo_import_disabled');
    } elseif (isset($_POST['payload'])) {
        list($report, $error) = growth_rustcz_write((string)$_POST['payload'],
            isset($_POST['include']) ? (array)$_POST['include'] : array(),
            isset($_POST['name']) ? (array)$_POST['name'] : array());
    } else {
        list($found, $error) = growth_rustcz_upload();
    }
}

/**
 * Turns the uploaded pair into the list the confirmation screen shows.
 *
 * Which file is which is worked out from the files themselves rather than
 * asked for. Their names are RustCZ's own and mean nothing to the person
 * uploading them, and a form that fails because two identical-looking files
 * were selected in the wrong order is a form that blames somebody for its own
 * design.
 */
function growth_rustcz_upload()
{
    if (empty($_FILES['files']['name'][0])) {
        return array(null, t('import_error_upload_failed'));
    }

    $bodies = array();
    foreach ($_FILES['files']['error'] as $i => $code) {
        if ($code !== UPLOAD_ERR_OK) {
            return array(null, t('import_error_upload_failed'));
        }
        if ($_FILES['files']['size'][$i] > GROWTH_RCZ_MAX_BYTES) {
            return array(null, t('import_error_too_large',
                array('mb' => GROWTH_RCZ_MAX_BYTES / 1024 / 1024)));
        }
        $bodies[] = (string)file_get_contents($_FILES['files']['tmp_name'][$i]);
    }

    if (count($bodies) !== 2) {
        return array(null, t('rcz_error_need_both'));
    }

    /* Which file is which is settled by trying it rather than by arithmetic.
       Sizes very nearly decide it - 397 bytes a child against 68 a
       measurement - but "nearly" is the wrong standard when a file divisible
       by both would be read as the wrong one, and the person uploading has no
       way to know which of two files RustCZ named is which. So: parse it both
       ways round and keep the way that produces children. */
    $children = null;
    $measurements = null;
    foreach (array(array($bodies[0], $bodies[1]), array($bodies[1], $bodies[0])) as $attempt) {
        $asChildren = growth_rustcz_children($attempt[0]);
        if ($asChildren['children']) {
            $children = $asChildren;
            $measurements = growth_rustcz_measurements($attempt[1]);
            break;
        }
    }
    if ($children === null || $measurements === null) {
        return array(null, t('rcz_error_need_both'));
    }
    if (!$measurements['groups']) {
        return array(null, $measurements['errors']
            ? implode(' ', $measurements['errors'])
            : t('rcz_error_need_both'));
    }

    $errors = array_merge($children['errors'], $measurements['errors']);
    if (!$children['children']) {
        return array(null, $errors ? implode(' ', $errors) : t('rcz_error_no_children'));
    }

    $paired = growth_rustcz_pair($children['children'], $measurements['groups']);
    $paired['errors'] = $errors;
    $paired['skipped'] = $measurements['skipped'];
    return array($paired, '');
}

/**
 * Writes what the confirmation screen was showing.
 *
 * The payload came back through a form, so it is input like any other and is
 * put through the same validators the CSV importer uses rather than trusted
 * for having been ours a moment ago.
 */
function growth_rustcz_write($payload, array $include, array $names)
{
    $decoded = json_decode((string)base64_decode($payload, true), true);
    if (!is_array($decoded) || !$decoded) {
        return array(null, t('rcz_error_lost_payload'));
    }

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, array('child', 'sex', 'birth_date', 'father_cm', 'mother_cm',
                           'date', 'height_cm', 'weight_kg', 'note'));
    $rows = 0;

    foreach ($decoded as $i => $child) {
        if (!isset($include[$i])) {
            continue;
        }
        $sex = growth_input_sex(isset($child['sex']) ? $child['sex'] : '');
        $born = isset($child['birth_date']) ? trim((string)$child['birth_date']) : '';
        if ($sex === null || !growth_valid_date($born)) {
            continue;
        }
        /* The name is the one field somebody was invited to change, so it is
           the one field most worth checking. */
        $name = isset($names[$i]) ? trim((string)$names[$i]) : '';
        if ($name === '') {
            $name = isset($child['name']) ? trim((string)$child['name']) : '';
        }
        $name = preg_replace('~[\x00-\x1F\x7F]~u', '', $name);
        /* 60 characters, the column's width - and without mbstring, which is
           absent often enough on shared hosting that the first real run of
           this page found it missing. The /u modifier makes the dot a UTF-8
           character rather than a byte, so a Czech name is not cut in half. */
        $name = (string)preg_replace('~^(.{0,60}).*$~us', '$1', (string)$name);
        if (trim($name) === '') {
            continue;
        }

        $father = growth_input_number(isset($child['father_cm']) ? $child['father_cm'] : '');
        $mother = growth_input_number(isset($child['mother_cm']) ? $child['mother_cm'] : '');

        foreach ((array)(isset($child['measurements']) ? $child['measurements'] : array()) as $row) {
            $date = isset($row['date']) ? trim((string)$row['date']) : '';
            if (!growth_valid_date($date)) {
                continue;
            }
            if (++$rows > GROWTH_IMPORT_MAX_ROWS) {
                break 2;
            }
            fputcsv($handle, array($name, $sex, $born, $father, $mother, $date,
                isset($row['height_cm']) ? $row['height_cm'] : '',
                isset($row['weight_kg']) ? $row['weight_kg'] : '',
                ''));
        }
    }

    if ($rows === 0) {
        fclose($handle);
        return array(null, t('rcz_error_nothing_selected'));
    }
    rewind($handle);
    $report = growth_import_csv($handle);
    fclose($handle);
    return array($report, '');
}

growth_head(t('page_title_import_rustcz'));
?>

<h1><?php echo th('heading_import_rustcz'); ?></h1>

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
    <?php foreach ($report['errors'] as $message): ?>
      <p class="growth-error"><?php echo growth_h($message); ?></p>
    <?php endforeach; ?>
    <p><a href="index.php"><?php echo th('nav_back_to_children'); ?></a></p>
  </div>

<?php elseif ($found !== null): ?>
  <div class="growth-panel">
    <h2><?php echo th('rcz_found_heading'); ?></h2>
    <p><?php echo th('rcz_found_intro'); ?></p>
    <form method="post" class="growth-form">
      <?php echo growth_csrf_field(); ?>
      <input type="hidden" name="payload"
             value="<?php echo growth_h(base64_encode((string)json_encode($found['children']))); ?>">
      <div class="growth-table-wrap">
        <table class="growth-table">
          <thead>
            <tr>
              <th><?php echo th('rcz_column_import'); ?></th>
              <th><?php echo th('label_name'); ?></th>
              <th><?php echo th('label_sex'); ?></th>
              <th><?php echo th('label_birth_date'); ?></th>
              <th><?php echo th('rcz_column_parents'); ?></th>
              <th><?php echo th('rcz_column_measurements'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($found['children'] as $i => $child): ?>
            <tr>
              <td><input type="checkbox" name="include[<?php echo (int)$i; ?>]" value="1" checked></td>
              <td><input type="text" name="name[<?php echo (int)$i; ?>]" maxlength="60"
                         value="<?php echo growth_h($child['name']); ?>"></td>
              <td><?php echo th($child['sex'] === 'f' ? 'sex_girl' : 'sex_boy'); ?></td>
              <td><?php echo growth_h(growth_format_date($child['birth_date'])); ?></td>
              <td>
                <?php echo $child['father_cm'] === null ? '–' : (int)$child['father_cm'] . '&nbsp;cm'; ?> /
                <?php echo $child['mother_cm'] === null ? '–' : (int)$child['mother_cm'] . '&nbsp;cm'; ?>
              </td>
              <td>
                <?php echo th('count_measurements', array('n' => count($child['measurements']))); ?>
                <?php if ($child['first_date'] !== null): ?>
                  <span class="growth-count">
                    <?php echo growth_h(growth_format_date($child['first_date'])); ?>
                    – <?php echo growth_h(growth_format_date($child['last_date'])); ?>
                  </span>
                <?php endif; ?>
                <?php if ((int)$child['stated_measurements'] !== count($child['measurements'])): ?>
                  <span class="growth-error">
                    <?php echo th('rcz_count_mismatch',
                        array('stated' => (int)$child['stated_measurements'])); ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($found['orphan_measurements'])): ?>
        <p class="growth-error">
          <?php echo th('rcz_orphans', array('n' => (int)$found['orphan_measurements'])); ?>
        </p>
      <?php endif; ?>
      <?php foreach ($found['errors'] as $message): ?>
        <p class="growth-error"><?php echo growth_h($message); ?></p>
      <?php endforeach; ?>
      <button type="submit"><?php echo th('rcz_button_confirm'); ?></button>
    </form>
    <p class="growth-note"><?php echo th('rcz_found_note'); ?></p>
  </div>

<?php else: ?>
  <form method="post" enctype="multipart/form-data" class="growth-form">
    <?php echo growth_csrf_field(); ?>
    <label><?php echo th('rcz_label_files'); ?>
      <input type="file" name="files[]" accept=".rcz" multiple required>
    </label>
    <button type="submit"><?php echo th('rcz_button_read'); ?></button>
  </form>

  <div class="growth-panel">
    <h2><?php echo th('rcz_where_heading'); ?></h2>
    <p><?php echo t('rcz_where_intro'); ?></p>
    <p class="growth-note"><?php echo t('rcz_where_note'); ?></p>
  </div>
<?php endif; ?>

<p class="growth-links">
  <a href="import.php"><?php echo th('nav_import_csv'); ?></a>
  &middot;
  <a href="manage.php"><?php echo th('nav_back_to_manage'); ?></a>
</p>

<?php growth_foot(); ?>
