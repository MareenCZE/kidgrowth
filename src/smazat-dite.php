<?php
/*
 * Confirmation step before deleting a child.
 *
 * This cascades to years of measurements at once, so it gets a stronger
 * confirmation than a single measurement does (smazat-mereni.php): retyping
 * the child's name, not just clicking past a warning. Still a soft delete -
 * see kos.php - but the point of asking for the name is to stop the click
 * that was never meant to happen, not to make it easy to undo after the fact.
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
    $confirmName = trim((string)(isset($_POST['potvrzeni']) ? $_POST['potvrzeni'] : ''));
    if ($confirmName !== $child['jmeno']) {
        $error = 'Jméno nesouhlasí, dítě nebylo smazáno.';
    } else {
        rust_child_delete($childId);
        header('Location: index.php?smazano=' . urlencode($child['jmeno']));
        exit;
    }
}

$measurementCount = count(rust_measurements($childId));

rust_head('Smazat dítě', $childId);
?>

<h1>Smazat <?php echo rust_h($child['jmeno']); ?>?</h1>
<p>
  Tohle přesune <strong><?php echo rust_h($child['jmeno']); ?></strong> i všech
  <strong><?php echo (int)$measurementCount; ?></strong> jeho/jejích měření
  najednou do koše.
</p>
<p class="rust-poznamka">
  Jde to vzít zpět z <a href="kos.php">koše</a> - ale aby se to nestalo omylem,
  napište jméno dítěte přesně tak, jak je uvedeno výše.
</p>

<?php if ($error !== ''): ?>
  <p class="rust-chyba"><?php echo rust_h($error); ?></p>
<?php endif; ?>

<form method="post" class="rust-formular">
  <?php echo rust_csrf_field(); ?>
  <input type="hidden" name="id" value="<?php echo (int)$childId; ?>">
  <label>Jméno pro potvrzení
    <input type="text" name="potvrzeni" required autocomplete="off">
  </label>
  <button type="submit">Přesunout do koše</button>
</form>

<p class="rust-odkazy">
  <a href="dite.php?id=<?php echo (int)$childId; ?>">Zpět</a>
</p>

<?php rust_foot(); ?>
