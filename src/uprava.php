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
    $name = trim((string)$_POST['jmeno']);
    $sex = ($_POST['pohlavi'] === 'z') ? 'z' : 'm';
    $born = trim((string)$_POST['narozeni']);
    $father = rust_input_number($_POST['otec']);
    $mother = rust_input_number($_POST['matka']);
    $breastfed = !empty($_POST['kojeno']);

    if ($name === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $born)) {
        $error = 'Vyplňte jméno a datum narození.';
    } elseif (strtotime($born) > time()) {
        $error = 'Datum narození nemůže být v budoucnosti.';
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

rust_head('Úprava – ' . $child['jmeno'], $childId);
?>

<h1>Upravit dítě</h1>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo rust_h($error); ?></p>
<?php endif; ?>

<form method="post" class="rust-formular">
  <input type="hidden" name="id" value="<?php echo (int)$childId; ?>">
  <label>Jméno
    <input type="text" name="jmeno" required maxlength="60"
           value="<?php echo rust_h($child['jmeno']); ?>">
  </label>
  <label>Pohlaví
    <select name="pohlavi">
      <option value="m"<?php echo $child['pohlavi'] === 'm' ? ' selected' : ''; ?>>chlapec</option>
      <option value="z"<?php echo $child['pohlavi'] === 'z' ? ' selected' : ''; ?>>dívka</option>
    </select>
  </label>
  <label>Datum narození
    <input type="date" name="narozeni" required max="<?php echo date('Y-m-d'); ?>"
           value="<?php echo rust_h($child['datum_narozeni']); ?>">
  </label>
  <label>Výška otce (cm)
    <input type="text" inputmode="decimal" name="otec"
           value="<?php echo rust_h(rust_num($child['vyska_otce_cm'])); ?>">
  </label>
  <label>Výška matky (cm)
    <input type="text" inputmode="decimal" name="matka"
           value="<?php echo rust_h(rust_num($child['vyska_matky_cm'])); ?>">
  </label>
  <label class="rust-zaskrtnuti">
    <input type="checkbox" name="kojeno" value="1"<?php echo !empty($child['kojeno']) ? ' checked' : ''; ?>>
    Kojené dítě
  </label>
  <p class="rust-poznamka">
    Zpřístupní referenci SZÚ pro kojené děti (0–1 rok). Kojenci přibývají
    jinak &ndash; rychleji do zhruba tří měsíců, pomaleji potom.
  </p>
  <button type="submit">Uložit</button>
</form>

<p class="rust-odkazy">
  <a href="dite.php?id=<?php echo (int)$childId; ?>">Zpět</a>
</p>

<?php rust_foot(); ?>
