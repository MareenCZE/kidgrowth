<?php
/*
 * Growth tracker - CSV import.
 *
 * The single documented way data gets in in bulk, including from the old
 * RustCZ database: tools/import_rustcz.php converts the binary .rcz file to
 * this CSV rather than the site carrying code for a dead Windows format.
 */
require_once __DIR__ . '/shell.inc';

const RUST_IMPORT_MAX_BYTES = 2 * 1024 * 1024; /* generous for a file this narrow - even 10 000 rows is a fraction of this */
const RUST_IMPORT_MAX_ROWS = 10000;

$report = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rust_csrf_check();
    if (!isset($_FILES['soubor']) || $_FILES['soubor']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Nepodařilo se nahrát soubor.';
    } elseif ($_FILES['soubor']['size'] > RUST_IMPORT_MAX_BYTES) {
        $error = 'Soubor je příliš velký (limit ' . (RUST_IMPORT_MAX_BYTES / 1024 / 1024) . ' MB).';
    } else {
        $handle = fopen($_FILES['soubor']['tmp_name'], 'r');
        if (!$handle) {
            $error = 'Soubor nelze otevřít.';
        } elseif (!rust_looks_like_text($_FILES['soubor']['tmp_name'])) {
            $error = 'Soubor nevypadá jako text (CSV) - je to opravdu ta správná příloha?';
        } else {
            $report = rust_import_csv($handle);
            fclose($handle);
        }
    }
}

/**
 * A cheap, dependency-free "is this actually text" check: a genuine text
 * file - CSV included - has no null bytes anywhere in it, which every binary
 * format (images, the old .rcz format, a mistakenly attached spreadsheet
 * file) does within the first few bytes almost without exception.
 */
function rust_looks_like_text($path)
{
    $sample = @file_get_contents($path, false, null, 0, 8192);
    return $sample !== false && strpos($sample, "\0") === false;
}

/**
 * Reads the CSV and writes it into the database.
 *
 * Deliberately tolerant about what it accepts and strict about what it stores:
 * an empty height cell becomes NULL rather than zero, because a stored zero
 * would be read back as a real measurement of 0 cm and would wreck every chart
 * and trend built on it.
 */
function rust_import_csv($handle)
{
    $report = array('children' => array(), 'rows' => 0, 'skipped' => 0, 'errors' => array());

    $header = fgetcsv($handle);
    if (!$header) {
        $report['errors'][] = 'Prázdný soubor.';
        return $report;
    }
    /* strip a UTF-8 BOM off the first column name if the file has one */
    $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', $header[0]);
    $header = array_map(function ($name) {
        return strtolower(trim((string)$name));
    }, $header);

    $required = array('dite', 'pohlavi', 'narozeni', 'datum');
    foreach ($required as $column) {
        if (!in_array($column, $header, true)) {
            $report['errors'][] = 'Chybí sloupec "' . $column . '".';
            return $report;
        }
    }

    $childIds = array();
    $line = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if ($line - 1 > RUST_IMPORT_MAX_ROWS) {
            $report['errors'][] = 'Soubor má víc než ' . RUST_IMPORT_MAX_ROWS
                . ' řádků, zbytek nebyl zpracován.';
            break;
        }
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $data = @array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row, 0, count($header))
        );
        if (!$data) {
            $report['errors'][] = 'Řádek ' . $line . ': nesedí počet sloupců.';
            continue;
        }

        $name = trim((string)$data['dite']);
        $date = trim((string)$data['datum']);
        if ($name === '' || !rust_valid_date($date)) {
            $report['skipped']++;
            continue;
        }

        if (!isset($childIds[$name])) {
            $sex = (trim((string)$data['pohlavi']) === 'z') ? 'z' : 'm';
            $born = trim((string)$data['narozeni']);
            if (!rust_valid_date($born)) {
                $report['errors'][] = 'Řádek ' . $line . ': neplatné datum narození.';
                continue;
            }
            $childIds[$name] = rust_child_upsert(
                $name, $sex, $born,
                rust_input_number(isset($data['otec_cm']) ? $data['otec_cm'] : ''),
                rust_input_number(isset($data['matka_cm']) ? $data['matka_cm'] : '')
            );
            $report['children'][$name] = 0;
        }

        $height = rust_input_number(isset($data['vyska_cm']) ? $data['vyska_cm'] : '');
        $weight = rust_input_number(isset($data['hmotnost_kg']) ? $data['hmotnost_kg'] : '');
        if ($height === null && $weight === null) {
            $report['skipped']++;
            continue;
        }

        $note = isset($data['poznamka']) ? trim((string)$data['poznamka']) : '';
        rust_measurement_save($childIds[$name], $date, $height, $weight, $note !== '' ? $note : null);
        $report['rows']++;
        $report['children'][$name]++;
    }
    return $report;
}

rust_head('Import');
?>

<h1>Import CSV</h1>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo rust_h($error); ?></p>
<?php endif; ?>

<?php if ($report !== null): ?>
  <div class="rust-panel">
    <p><strong>Naimportováno <?php echo (int)$report['rows']; ?> měření.</strong></p>
    <ul>
      <?php foreach ($report['children'] as $name => $count): ?>
        <li><?php echo rust_h($name); ?>: <?php echo (int)$count; ?> měření</li>
      <?php endforeach; ?>
    </ul>
    <?php if ($report['skipped']): ?>
      <p class="rust-poznamka">
        Přeskočeno <?php echo (int)$report['skipped']; ?> řádků bez výšky i hmotnosti.
      </p>
    <?php endif; ?>
    <?php foreach ($report['errors'] as $message): ?>
      <p class="rust-chyba"><?php echo rust_h($message); ?></p>
    <?php endforeach; ?>
    <p><a href="index.php">Zpět na seznam dětí</a></p>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="rust-formular">
  <?php echo rust_csrf_field(); ?>
  <label>Soubor CSV
    <input type="file" name="soubor" accept=".csv,text/csv" required>
  </label>
  <button type="submit">Importovat</button>
</form>

<div class="rust-panel">
  <h2>Formát</h2>
  <p>První řádek je hlavička, oddělovač je čárka:</p>
  <pre>dite,pohlavi,narozeni,otec_cm,matka_cm,datum,vyska_cm,hmotnost_kg
"Novak Jan",m,2018-03-14,180,165,2018-05-20,58,4.2</pre>
  <p class="rust-poznamka">
    Povinné jsou <code>dite</code>, <code>pohlavi</code> (m/z),
    <code>narozeni</code> a <code>datum</code>, obojí ve tvaru RRRR-MM-DD.
    Výška i hmotnost mohou být prázdné &ndash; prázdná buňka znamená
    &bdquo;neměřeno&ldquo;, ne nulu. Opakovaný import stejného data zápis
    přepíše, neduplikuje.
  </p>
  <h2>Z RůstCZ</h2>
  <p>Převod staré databáze na toto CSV:</p>
  <pre>php tools/import_rustcz.php meda.rcz export-child1.txt export-child2.txt &gt; rust.csv</pre>
</div>

<p class="rust-odkazy"><a href="index.php">Zpět</a></p>

<?php rust_foot(); ?>
