<?php
/*
 * Growth tracker - one child: charts, predictions and the measurement table.
 */
require_once __DIR__ . '/shell.inc';
require_once __DIR__ . '/graf.inc';

$childId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$child = rust_child($childId);
if (!$child) {
    header('HTTP/1.1 404 Not Found');
    rust_head('Nenalezeno');
    echo '<h1>Dítě nenalezeno</h1><p><a href="index.php">Zpět na seznam</a></p>';
    rust_foot();
    exit;
}

$referenceId = rust_selected_reference();
$availableRefs = rust_reference_ids_for($child);
if (!in_array($referenceId, $availableRefs, true)) {
    $referenceId = reset($availableRefs) ?: 'cdc';
}
$reference = rust_reference($referenceId);
$fullRange = isset($_GET['rozsah']) && $_GET['rozsah'] === 'vse';
$smoothing = isset($_GET['vyhlazeni']) && $_GET['vyhlazeni'] === '1';

$sex = $child['pohlavi'];
$born = $child['datum_narozeni'];
$measurements = rust_measurements($childId);

/* Split into per-metric series once; the charts and the table both need them.
   BMI and weight-for-height need both measurements from the same visit, and
   weight-for-height is plotted against height rather than against age - so its
   index is the height, which is what makes it able to tell "small for their
   age" apart from "underweight". */
$series = array('height' => array(), 'weight' => array(), 'bmi' => array(), 'wfh' => array());
foreach ($measurements as $row) {
    $age = rust_decimal_age($born, $row['datum']);
    if ($row['vyska_cm'] !== null) {
        $series['height'][] = array('age' => $age, 'value' => $row['vyska_cm'], 'date' => $row['datum']);
    }
    if ($row['hmotnost_kg'] !== null) {
        $series['weight'][] = array('age' => $age, 'value' => $row['hmotnost_kg'], 'date' => $row['datum']);
    }
    if ($row['vyska_cm'] !== null && $row['hmotnost_kg'] !== null) {
        $bmi = rust_bmi($row['vyska_cm'], $row['hmotnost_kg']);
        if ($bmi !== null) {
            $series['bmi'][] = array('age' => $age, 'value' => $bmi, 'date' => $row['datum']);
        }
        $series['wfh'][] = array(
            'age' => (float)$row['vyska_cm'],   /* the index here is the height */
            'value' => $row['hmotnost_kg'],
            'date' => $row['datum'],
        );
    }
}
/* weight-for-height is drawn against height, so it has to be ordered by it */
usort($series['wfh'], function ($a, $b) {
    return $a['age'] <=> $b['age'];
});

$target = rust_target_height($child['vyska_otce_cm'], $child['vyska_matky_cm'], $sex);
$projection = rust_channel_projection($born, $sex, $measurements, $referenceId);
$velocities = rust_velocities($born, $measurements);
$currentAge = rust_decimal_age($born, date('Y-m-d'));

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
    foreach (rust_metrics() as $metricId => $metricMeta) {
        $fit = rust_smooth_series($series[$metricId],
            rust_reference_rows($referenceId, $metricId, $sex),
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

rust_head($child['jmeno'], $childId);
?>

<h1><?php echo rust_h($child['jmeno']); ?></h1>
<p class="rust-podtitul">
  narozen<?php echo $sex === 'z' ? 'a' : ''; ?>
  <?php echo rust_h(rust_date_cz($born)); ?>,
  nyní <?php echo rust_h(rust_age_cz($currentAge)); ?>
</p>

<?php rust_reference_picker($referenceId, $linkParams, $availableRefs); ?>

<?php
  $baseLink = array('id' => $childId, 'ref' => $referenceId);
  $rangeLink = $baseLink; if (!$fullRange) { $rangeLink['rozsah'] = 'vse'; }
  if ($smoothing) { $rangeLink['vyhlazeni'] = '1'; }
  $smoothLink = $baseLink; if ($fullRange) { $smoothLink['rozsah'] = 'vse'; }
  if (!$smoothing) { $smoothLink['vyhlazeni'] = '1'; }
?>
<p class="rust-rozsah">
  <a href="?<?php echo rust_h(http_build_query($rangeLink)); ?>"><?php
    echo $fullRange ? 'Zobrazit jen doposud naměřený věk' : 'Zobrazit celý rozsah do dospělosti';
  ?></a>
  &middot;
  <a href="?<?php echo rust_h(http_build_query($smoothLink)); ?>"><?php
    echo $smoothing ? 'Zobrazit jen naměřené hodnoty' : 'Vyrovnat kolísání měření';
  ?></a>
</p>

<?php
/* Draw each metric, or explain its absence. A reference not covering an age is
   normal rather than exceptional - WHO publishes no weight-for-age past 10 -
   and saying so plainly beats an empty box or, worse, an extrapolated curve. */
foreach (rust_metrics() as $metric => $meta):
    $span = rust_reference_span($referenceId, $metric, $sex);

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
    ?>
    <section class="rust-sekce">
      <h2><?php echo rust_h($meta['label']); ?></h2>
      <?php if (!$span): ?>
        <p class="rust-nodata">
          Reference <?php echo rust_h($reference['label']); ?> tento údaj neobsahuje.
        </p>
      <?php elseif (!$pointsInSpan && !$childCovered): ?>
        <p class="rust-nodata">
          Reference <?php echo rust_h($reference['label']); ?> pokrývá
          <?php echo rust_h($meta['index'] === 'age' ? 'věk' : 'výšku'); ?>
          <?php echo rust_h(rust_num($span[0])); ?>&ndash;<?php echo rust_h(rust_num($span[1])); ?>
          <?php echo rust_h($meta['index'] === 'age' ? 'let' : 'cm'); ?>
          a odtud tu nejsou žádná měření. Zvolte jinou referenci.
        </p>
      <?php else: ?>
        <?php
          echo rust_chart_svg($referenceId, $metric, $sex, $series[$metric], array(
              'full_range' => $fullRange,
              'target' => ($metric === 'height') ? $target : null,
              'projection' => ($metric === 'height') ? $projection : null,
              'smooth' => isset($smooth[$metric]) ? $smooth[$metric] : null,
          ));
        ?>
        <?php if (isset($smooth[$metric])): ?>
          <?php $fit = $smooth[$metric]; ?>
          <p class="rust-poznamka">
            Typický rozptyl měření je
            <strong>±<?php echo rust_h(rust_num($fit['scatter'], $meta['decimals'])); ?>
            <?php echo rust_h($meta['unit']); ?></strong>
            &ndash; tolik se jednotlivá měření liší od vyrovnané křivky.
            <?php if ($fit['suspect']): ?>
              Zvýrazněná měření
              (<?php echo count($fit['suspect']); ?>) se liší natolik, že stojí
              za kontrolu zápisu.
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($metric === 'bmi'): ?>
          <details class="rust-panel">
            <summary>Co znamená BMI u dětí?</summary>
            <p>
              BMI dává hmotnost do poměru k výšce (kg/m²), takže samo o sobě
              nezvýhodňuje vysoké ani malé děti.
            </p>
            <p>
              <strong>U dětí neplatí pevné hranice jako u dospělých</strong>
              &ndash; čísla 25 a 30 sem nepatří. Hodnotí se percentilem k věku
              a pohlaví: podle SZÚ je pásmo 90.&ndash;97. percentilu nadváha
              a nad 97. percentilem obezita. Jiné reference mají hranice jinde
              (CDC 85. a 95.), takže stejné dítě může podle zvolené reference
              vyjít různě.
            </p>
            <p>
              <strong>Do pěti let dává česká praxe přednost grafu hmotnosti
              k výšce</strong> před BMI, teprve u starších dětí se hodnotí BMI.
              Oba grafy jsou tu výš.
            </p>
            <p class="rust-poznamka">
              Že BMI v předškolním věku klesá, je normální a není důvod
              k obavám: medián u chlapců stoupne asi na 17,2 kolem osmi měsíců,
              pak klesá až na 15,4 ve zhruba šesti letech a teprve potom zase
              roste, do osmnácti na 21,7. U dívek je ten pokles o něco dřív.
            </p>
          </details>
        <?php endif; ?>
        <?php if ($metric === 'weight' && $span[1] < 17.5): ?>
          <p class="rust-poznamka">
            <?php echo rust_h($reference['label']); ?> publikuje hmotnost k věku
            jen do <?php echo rust_h(rust_years_cz($span[1])); ?> &ndash; v pubertě
            už samotná hmotnost neodliší výšku od tělesné hmoty.
          </p>
        <?php endif; ?>
        <?php
          /* No reference covers every age - Poland starts at 3, the breastfed
             curves stop at 1 - so say which measurements fell outside rather
             than letting them quietly vanish from the chart. */
          $hidden = count($series[$metric]) - $pointsInSpan;
        ?>
        <?php if ($hidden > 0): ?>
          <p class="rust-poznamka">
            <?php echo rust_h($reference['label']); ?> pokrývá
            <?php echo rust_h($meta['index'] === 'age' ? 'věk' : 'výšku'); ?>
            <?php echo rust_h(rust_num($span[0])); ?>&ndash;<?php echo rust_h(rust_num($span[1])); ?>
            <?php echo rust_h($meta['index'] === 'age' ? 'let' : 'cm'); ?>,
            <?php echo (int)$hidden; ?> měření mimo tento rozsah graf nezobrazuje.
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
    $rows = rust_reference_rows($referenceId, $metric, $sex);
    if (!$rows) {
        continue;
    }
    $points = array();
    foreach ($series[$metric] as $point) {
        $lms = rust_lms_at($rows, $point['age']);
        if (!$lms) {
            continue;
        }
        $z = rust_zscore($point['value'], $lms);
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
$sdsChart = $sdsSeries ? rust_sds_chart_svg($sdsSeries, array('smooth' => $smooth)) : '';
?>
<?php
$velocityChart = rust_velocity_chart_svg($referenceId, $sex, $velocities, array(
    'smooth' => isset($smooth['height']) ? $smooth['height'] : null,
    'full_range' => $fullRange,
));
?>
<?php if ($velocityChart !== ''): ?>
  <section class="rust-sekce">
    <h2>Rychlost růstu</h2>
    <?php echo $velocityChart; ?>
    <p class="rust-poznamka">
      Kolik centimetrů dítě přirostlo za rok. <strong>Tempo mediánu</strong> je,
      o kolik za stejné období vyroste dítě na 50.&nbsp;percentilu &ndash; není
      to percentil rychlosti, ale růst mediánového dítěte. Percentily rychlosti
      tu záměrně nejsou: nelze je odvodit z tabulek dosažené výšky a pro tento
      věk je nikdo nepublikuje v použitelné podobě (WHO je má jen do dvou let,
      SZÚ vůbec).
    </p>
    <p class="rust-poznamka">
      Rychlost je ze všech údajů nejcitlivější na nepřesnost měření &ndash;
      počítá se z rozdílu dvou hodnot, takže se jejich chyby sčítají. Proto se
      měří za období kolem roku a proto se vyplatí číst spíš vyrovnaný průběh
      než jednotlivé body.
    </p>
  </section>
<?php endif; ?>

<?php if ($sdsChart !== ''): ?>
  <section class="rust-sekce">
    <h2>Vývoj SD v čase</h2>
    <?php echo $sdsChart; ?>
    <p class="rust-poznamka">
      Tenhle graf odpovídá na otázku, kterou růstové grafy samy neukážou:
      <strong>drží se dítě svého pásma?</strong> Vodorovná čára znamená, že
      roste stále stejně vzhledem k vrstevníkům &ndash; ať už nahoře, nebo dole.
      Stoupající nebo klesající čára znamená, že pásmo opouští, a právě to je
      signál, který stojí za pozornost lékaře. Šedý pruh je rozmezí
      &minus;2 až +2&nbsp;SD, kam patří zhruba 95&nbsp;% dětí.
    </p>
  </section>
<?php endif; ?>

<section class="rust-sekce">
  <h2>Předpověď dospělé výšky</h2>
  <div class="rust-predpoved">
    <?php /* Measured first: it is built from this child's own growth, whereas
             the mid-parental target is a ~17 cm band that says the same thing
             for every child of the same two parents. */ ?>
    <div class="rust-karta rust-karta-hlavni">
      <h3>Podle naměřených hodnot</h3>
      <?php if ($projection): ?>
        <p class="rust-cislo"><?php echo rust_h(rust_num($projection['mid'])); ?> cm</p>
        <p class="rust-rozptyl">
          rozmezí <?php echo rust_h(rust_num($projection['low'])); ?>&ndash;<?php echo rust_h(rust_num($projection['high'])); ?> cm
        </p>
        <p class="rust-poznamka">
          Předpokládá, že dítě zůstane ve svém růstovém pásmu
          (<?php echo rust_h(rust_num($projection['z'], 2)); ?> SD), spočteno
          z posledních <?php echo (int)$projection['based_on']; ?> měření výšky.
          V grafu je vyznačeno modrou značkou u osmnácti let.
          <strong>V pubertě to neplatí</strong> &ndash; růstový výšvih běžně
          posune dítě mezi pásmy.
        </p>
      <?php else: ?>
        <?php
          /* Two quite different reasons for having nothing to show, and the
             difference matters: one is fixed by measuring again, the other by
             switching reference. */
          $heightSpan = rust_reference_span($referenceId, 'height', $sex);
          $reachesAdulthood = $heightSpan && $heightSpan[1] >= 17.5;
        ?>
        <?php if (!$reachesAdulthood): ?>
          <p class="rust-nodata">
            Reference <?php echo rust_h($reference['label']); ?> končí ve věku
            <?php echo rust_h(rust_years_cz($heightSpan[1])); ?>, takže z ní
            dospělou výšku odhadnout nelze. Přepněte na referenci, která sahá
            do dospělosti.
          </p>
        <?php else: ?>
          <p class="rust-nodata">Potřebuje aspoň dvě měření výšky.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="rust-karta">
      <h3>Cílová (genetická) výška</h3>
      <?php if ($target): ?>
        <p class="rust-cislo"><?php echo rust_h(rust_num($target['mid'])); ?> cm</p>
        <p class="rust-rozptyl">
          rozmezí <?php echo rust_h(rust_num($target['low'])); ?>&ndash;<?php echo rust_h(rust_num($target['high'])); ?> cm
        </p>
        <p class="rust-poznamka">
          Jen z výšek rodičů (<?php echo rust_h(rust_num($child['vyska_otce_cm'])); ?> a
          <?php echo rust_h(rust_num($child['vyska_matky_cm'])); ?> cm), bez ohledu
          na naměřené hodnoty. Pásmo je široké zhruba 17 cm, takže jde spíš
          o kontrolu než o předpověď. V grafu hnědě.
        </p>
      <?php else: ?>
        <p class="rust-nodata">
          Zadejte výšky rodičů v <a href="uprava.php?id=<?php echo (int)$childId; ?>">úpravě dítěte</a>.
        </p>
      <?php endif; ?>
    </div>
  </div>
  <p class="rust-poznamka">
    Přesnější metody (Bayley&ndash;Pinneau, Tanner&ndash;Whitehouse) vycházejí
    z kostního věku, který se určuje z rentgenu ruky, a záměrně tu nejsou.
  </p>
</section>

<details class="rust-panel">
  <summary>Co znamená percentil a SD?</summary>
  <p>
    <strong>Percentil</strong> říká, kolik procent dětí stejného věku a pohlaví
    je menších. 25. percentil znamená, že čtvrtina dětí je menší a tři čtvrtiny
    větší. Padesátý percentil je medián &ndash; přesný střed populace.
  </p>
  <p>
    <strong>SD</strong> (směrodatná odchylka, také SDS nebo z-skóre) měří totéž,
    ale jinou stupnicí: o kolik odchylek je dítě nad nebo pod průměrem.
    0&nbsp;SD je přesný průměr, záporné číslo znamená pod průměrem.
    Zhruba dvě třetiny dětí se vejdou mezi &minus;1 a +1&nbsp;SD a 95&nbsp;%
    mezi &minus;2 a +2&nbsp;SD.
  </p>
  <p>
    Převod je pevný: &minus;2&nbsp;SD je zhruba 2. percentil,
    &minus;1&nbsp;SD asi 16., 0&nbsp;SD přesně 50., +1&nbsp;SD asi 84.
    a +2&nbsp;SD zhruba 98.
  </p>
  <p class="rust-poznamka">
    Proč jsou tu obojí: u dětí blízko průměru se percentil čte snáz, ale na
    okrajích se percentily mačkají k sobě &ndash; mezi 1. a 0,1. percentilem je
    víc než celá směrodatná odchylka růstu. Právě proto lékaři u velmi malých
    a velmi velkých dětí sledují SD, ne percentil. <strong>Změna SD v čase je
    přitom důležitější než jeho hodnota</strong>: dítě, které stabilně roste na
    &minus;2&nbsp;SD, je nejspíš prostě malé, zatímco dítě, které se během roku
    posune z &minus;0,5 na &minus;1,5&nbsp;SD, opouští své pásmo, a to stojí za
    pozornost lékaře.
  </p>
</details>

<section class="rust-sekce">
  <h2>Měření</h2>

  <form method="post" action="zaznam.php" class="rust-formular rust-radek">
    <input type="hidden" name="dite_id" value="<?php echo (int)$childId; ?>">
    <input type="hidden" name="ref" value="<?php echo rust_h($referenceId); ?>">
    <label>Datum
      <input type="date" name="datum" value="<?php echo date('Y-m-d'); ?>" required
             max="<?php echo date('Y-m-d'); ?>">
    </label>
    <label>Výška (cm)
      <input type="text" inputmode="decimal" name="vyska" placeholder="např. 122,5">
    </label>
    <label>Hmotnost (kg)
      <input type="text" inputmode="decimal" name="hmotnost" placeholder="např. 20,5">
    </label>
    <button type="submit">Uložit</button>
  </form>
  <p class="rust-poznamka">
    Stačí vyplnit jen jeden z údajů. Uložení stejného data přepíše dřívější zápis.
  </p>

  <?php if ($measurements): ?>
    <?php
      /* velocity keyed by date, so the table can show it on the right row */
      $velocityByDate = array();
      foreach ($velocities as $velocity) {
          $velocityByDate[$velocity['date']] = $velocity;
      }
    ?>
    <div class="rust-tabulka-obal">
    <table class="rust-tabulka">
      <thead>
        <tr>
          <th>Datum</th><th>Věk</th>
          <th>Výška</th><th>Perc.</th><th>SD</th>
          <th>Hmotnost</th><th>Perc.</th><th>SD</th>
          <th>BMI</th><th>Perc.</th>
          <th>Rychlost růstu</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_reverse($measurements) as $row): ?>
        <?php
          $age = rust_decimal_age($born, $row['datum']);
          $h = rust_evaluate($born, $sex, $row['datum'], 'height', $row['vyska_cm'], $referenceId);
          $w = rust_evaluate($born, $sex, $row['datum'], 'weight', $row['hmotnost_kg'], $referenceId);
          $bmiValue = rust_bmi($row['vyska_cm'], $row['hmotnost_kg']);
          $b = rust_evaluate($born, $sex, $row['datum'], 'bmi', $bmiValue, $referenceId);
        ?>
        <tr>
          <td><?php echo rust_h(rust_date_cz($row['datum'])); ?></td>
          <td class="rust-slabe"><?php echo rust_h(rust_age_cz($age)); ?></td>

          <td><?php echo $row['vyska_cm'] === null ? '' : rust_h(rust_num($row['vyska_cm'])) . '&nbsp;cm'; ?></td>
          <td><?php echo $row['vyska_cm'] === null ? '' : rust_percentile_cz($h['percentile'], $h['z'], $referenceId, 'height'); ?></td>
          <td class="rust-slabe"><?php echo $h['z'] === null ? '' : rust_h(rust_num($h['z'], 2)); ?></td>

          <td><?php echo $row['hmotnost_kg'] === null ? '' : rust_h(rust_num($row['hmotnost_kg'], 2)) . '&nbsp;kg'; ?></td>
          <td><?php echo $row['hmotnost_kg'] === null ? '' : rust_percentile_cz($w['percentile'], $w['z'], $referenceId, 'weight'); ?></td>
          <td class="rust-slabe"><?php echo $w['z'] === null ? '' : rust_h(rust_num($w['z'], 2)); ?></td>

          <td><?php echo $bmiValue === null ? '' : rust_h(rust_num($bmiValue, 1)); ?></td>
          <td><?php echo $bmiValue === null ? '' : rust_percentile_cz($b['percentile'], $b['z'], $referenceId, 'bmi'); ?></td>

          <td class="rust-slabe">
            <?php if (isset($velocityByDate[$row['datum']])): $v = $velocityByDate[$row['datum']]; ?>
              <span title="od <?php echo rust_h(rust_date_cz($v['from_date'])); ?>, za <?php echo rust_h(rust_years_span_cz($v['years'])); ?>">
                <?php echo rust_h(rust_num($v['cm_per_year'])); ?>&nbsp;cm/rok
              </span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="zaznam.php" class="rust-smazat"
                  onsubmit="return confirm('Smazat měření z <?php echo rust_h(rust_date_cz($row['datum'])); ?>?');">
              <input type="hidden" name="akce" value="smazat">
              <input type="hidden" name="dite_id" value="<?php echo (int)$childId; ?>">
              <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
              <input type="hidden" name="ref" value="<?php echo rust_h($referenceId); ?>">
              <button type="submit" title="Smazat">&times;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="rust-poznamka">
      Rychlost růstu je přepočtena na rok a měřena zhruba za poslední rok, ne
      od předchozího měření &ndash; najeďte na hodnotu a uvidíte přesné období.
      Krátký odstup by chybu měření zvětšil spolu s růstem: půl centimetru
      nepřesnosti za čtyři měsíce vyjde jako 1,5&nbsp;cm/rok navíc, takže by
      i rovnoměrně rostoucí dítě zdánlivě zrychlovalo a zpomalovalo.
    </p>
  <?php endif; ?>
</section>

<p class="rust-odkazy">
  <a href="uprava.php?id=<?php echo (int)$childId; ?>">Upravit dítě</a>
  &middot;
  <a href="export.php?id=<?php echo (int)$childId; ?>">Export CSV</a>
  &middot;
  <a href="index.php">Všechny děti</a>
</p>

<?php rust_foot($referenceId); ?>
