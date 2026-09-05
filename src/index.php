<?php
/*
 * Growth tracker - list of children.
 *
 * Also the place a new child is added, since with a handful of children a
 * separate management page would be a page you visit twice.
 */
require_once __DIR__ . '/shell.inc';

$error = '';
$reference = rust_selected_reference();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['akce']) && $_POST['akce'] === 'nove_dite') {
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
        $id = rust_child_upsert($name, $sex, $born, $father, $mother, $breastfed);
        header('Location: dite.php?id=' . (int)$id);
        exit;
    }
}

$children = rust_children();

rust_head('');
?>

<h1>Růst dětí</h1>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo rust_h($error); ?></p>
<?php endif; ?>

<?php if (!$children): ?>
  <p class="rust-uvod">
    Zatím tu není žádné dítě. Přidejte první níže, nebo naimportujte data
    z RůstCZ přes <a href="import.php">import CSV</a>.
  </p>
<?php else: ?>
  <ul class="rust-seznam">
    <?php foreach ($children as $child): ?>
      <?php
        $measurements = rust_measurements($child['id']);
        $last = null;
        for ($i = count($measurements) - 1; $i >= 0; $i--) {
            if ($measurements[$i]['vyska_cm'] !== null || $measurements[$i]['hmotnost_kg'] !== null) {
                $last = $measurements[$i];
                break;
            }
        }
        $age = rust_decimal_age($child['datum_narozeni'], date('Y-m-d'));
      ?>
      <li>
        <a href="dite.php?id=<?php echo (int)$child['id']; ?>">
          <strong><?php echo rust_h($child['jmeno']); ?></strong>
          <span class="rust-vek"><?php echo rust_h(rust_age_cz($age)); ?></span>
        </a>
        <?php if ($last): ?>
          <span class="rust-posledni">
            <?php echo rust_h(rust_date_cz($last['datum'])); ?>:
            <?php if ($last['vyska_cm'] !== null): ?>
              <?php echo rust_h(rust_num($last['vyska_cm'])); ?> cm<?php endif; ?>
            <?php if ($last['vyska_cm'] !== null && $last['hmotnost_kg'] !== null): ?>,<?php endif; ?>
            <?php if ($last['hmotnost_kg'] !== null): ?>
              <?php echo rust_h(rust_num($last['hmotnost_kg'], 2)); ?> kg<?php endif; ?>
            <span class="rust-pocet">(<?php echo count($measurements); ?> měření)</span>
          </span>
        <?php else: ?>
          <span class="rust-posledni">zatím bez měření</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<details class="rust-panel"<?php echo $children ? '' : ' open'; ?>>
  <summary>Přidat dítě</summary>
  <form method="post" class="rust-formular">
    <input type="hidden" name="akce" value="nove_dite">
    <label>Jméno
      <input type="text" name="jmeno" required maxlength="60">
    </label>
    <label>Pohlaví
      <select name="pohlavi">
        <option value="m">chlapec</option>
        <option value="z">dívka</option>
      </select>
    </label>
    <label>Datum narození
      <input type="date" name="narozeni" required max="<?php echo date('Y-m-d'); ?>">
    </label>
    <label>Výška otce (cm)
      <input type="text" inputmode="decimal" name="otec" placeholder="nepovinné">
    </label>
    <label>Výška matky (cm)
      <input type="text" inputmode="decimal" name="matka" placeholder="nepovinné">
    </label>
    <label class="rust-zaskrtnuti">
      <input type="checkbox" name="kojeno" value="1">
      Kojené dítě
    </label>
    <button type="submit">Přidat</button>
  </form>
  <p class="rust-poznamka">
    Výšky rodičů slouží k výpočtu cílové (genetické) výšky. Bez nich vše
    ostatní funguje.
  </p>
</details>

<p class="rust-odkazy">
  <a href="import.php">Import CSV</a>
</p>

<?php rust_foot(); ?>
