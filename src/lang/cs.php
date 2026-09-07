<?php

/**
 * Czech strings. Not the authoritative set - see lang/en.php for that and for
 * how a missing key here falls back. This is the application's original
 * language, so it should stay complete, but t() tolerates it lagging behind
 * during development.
 */

return array(

    /* --------------------------------------------------------- navigation */
    'nav_back' => 'Zpět',
    'nav_back_to_children' => 'Zpět na seznam dětí',
    'nav_all_children' => 'Všechny děti',
    'nav_edit_child' => 'Upravit dítě',
    'nav_delete_child' => 'Smazat dítě',
    'nav_export_csv' => 'Export CSV',
    'nav_export_all_csv' => 'Export všeho do CSV',
    'nav_import_csv' => 'Import CSV',
    'nav_trash' => 'Koš',

    /* --------------------------------------------------------------- home */
    'brand_tagline' => 'růst dětí',
    'page_title_home' => 'Růst dětí',
    'flash_moved_to_trash' => '{name} přesunuto do <a href="trash.php">koše</a>, odkud jde obnovit.',
    'intro_no_children' => 'Zatím tu není žádné dítě. Přidejte první níže, nebo načtěte existující měření přes <a href="import.php">import CSV</a>.',
    'no_measurements_yet' => 'zatím bez měření',
    'count_measurements' => '({n} měření)',
    'add_child_summary' => 'Přidat dítě',
    'note_parent_heights' => 'Výšky rodičů slouží k výpočtu cílové (genetické) výšky. Bez nich vše ostatní funguje.',

    /* ----------------------------------------------------------- edit/add */
    'page_title_edit' => 'Úprava',
    'heading_edit_child' => 'Upravit dítě',
    'label_name' => 'Jméno',
    'label_sex' => 'Pohlaví',
    'sex_boy' => 'chlapec',
    'sex_girl' => 'dívka',
    'label_birth_date' => 'Datum narození',
    'label_father_height' => 'Výška otce',
    'label_mother_height' => 'Výška matky',
    'placeholder_optional' => 'nepovinné',
    'placeholder_optional_height_imperial' => 'nepovinné, např. 5\'10"',
    'label_breastfed' => 'Kojené dítě',
    'note_breastfed_reference' => 'Zpřístupní referenci SZÚ pro kojené děti (0–1 rok). Kojenci přibývají jinak – rychleji do zhruba tří měsíců, pomaleji potom.',
    'button_add' => 'Přidat',
    'button_save' => 'Uložit',
    'button_cancel' => 'Zrušit',
    'error_name_and_birth_required' => 'Vyplňte jméno a datum narození.',
    'error_birth_in_future' => 'Datum narození nemůže být v budoucnosti.',

    /* -------------------------------------------------------------- child */
    'page_title_not_found' => 'Nenalezeno',
    'heading_child_not_found' => 'Dítě nenalezeno',
    'subtitle_born_m' => 'narozen',
    'subtitle_born_f' => 'narozena',
    'subtitle_now' => 'nyní',
    'link_show_measured_range' => 'Zobrazit jen doposud naměřený věk',
    'link_show_full_range' => 'Zobrazit celý rozsah do dospělosti',
    'link_hide_smoothing' => 'Zobrazit jen naměřené hodnoty',
    'link_show_smoothing' => 'Vyrovnat kolísání měření',

    'nodata_metric_not_in_reference' => 'Reference {reference} tento údaj neobsahuje.',
    'nodata_no_measurements_in_span' => 'Reference {reference} pokrývá {index} {min}–{max} {unit} a odtud tu nejsou žádná měření. Zvolte jinou referenci.',
    'index_word_age' => 'věk',
    'index_word_height' => 'výšku',
    'unit_years_word' => 'let',

    'note_scatter' => 'Typický rozptyl měření je {scatter} – tolik se jednotlivá měření liší od vyrovnané křivky.',
    'note_scatter_suspect' => 'Zvýrazněná měření ({n}) se liší natolik, že stojí za kontrolu zápisu.',

    'bmi_explainer_summary' => 'Co znamená BMI u dětí?',
    'bmi_explainer_p1' => 'BMI dává hmotnost do poměru k výšce (kg/m²), takže samo o sobě nezvýhodňuje vysoké ani malé děti.',
    'bmi_explainer_p2' => 'U dětí neplatí pevné hranice jako u dospělých – čísla 25 a 30 sem nepatří. Hodnotí se percentilem k věku a pohlaví: podle SZÚ je pásmo 90.–97. percentilu nadváha a nad 97. percentilem obezita. Jiné reference mají hranice jinde (CDC 85. a 95.), takže stejné dítě může podle zvolené reference vyjít různě.',
    'bmi_explainer_p3' => 'Do pěti let dává česká praxe přednost grafu hmotnosti k výšce před BMI, teprve u starších dětí se hodnotí BMI. Oba grafy jsou tu výš.',
    'bmi_explainer_p4' => 'Že BMI v předškolním věku klesá, je normální a není důvod k obavám: medián u chlapců stoupne asi na 17,2 kolem osmi měsíců, pak klesá až na 15,4 ve zhruba šesti letech a teprve potom zase roste, do osmnácti na 21,7. U dívek je ten pokles o něco dřív.',

    'note_weight_ceiling' => '{reference} publikuje hmotnost k věku jen do {age} – v pubertě už samotná hmotnost neodliší výšku od tělesné hmoty.',
    'note_hidden_measurements' => 'Reference {reference} pokrývá {index} {min}–{max} {unit}, {n} měření mimo tento rozsah graf nezobrazuje.',

    'heading_velocity' => 'Rychlost růstu',
    'velocity_explainer_p1' => 'Kolik centimetrů dítě přirostlo za rok. Tempo mediánu je, o kolik za stejné období vyroste dítě na 50. percentilu – není to percentil rychlosti, ale růst mediánového dítěte. Percentily rychlosti tu záměrně nejsou: nelze je odvodit z tabulek dosažené výšky a pro tento věk je nikdo nepublikuje v použitelné podobě (WHO je má jen do dvou let, SZÚ vůbec).',
    'velocity_explainer_p2' => 'Rychlost je ze všech údajů nejcitlivější na nepřesnost měření – počítá se z rozdílu dvou hodnot, takže se jejich chyby sčítají. Proto se měří za období kolem roku a proto se vyplatí číst spíš vyrovnaný průběh než jednotlivé body.',

    'heading_sds' => 'Vývoj SD v čase',
    'sds_explainer_p1' => 'Tenhle graf odpovídá na otázku, kterou růstové grafy samy neukážou: drží se dítě svého pásma? Vodorovná čára znamená, že roste stále stejně vzhledem k vrstevníkům – ať už nahoře, nebo dole. Stoupající nebo klesající čára znamená, že pásmo opouští, a právě to je signál, který stojí za pozornost lékaře. Šedý pruh je rozmezí −2 až +2 SD, kam patří zhruba 95 % dětí.',

    'heading_prediction' => 'Předpověď dospělé výšky',
    'heading_measured_prediction' => 'Podle naměřených hodnot',
    'range_cm' => 'rozmezí {low}–{high} {unit}',
    'note_projection' => 'Předpokládá, že dítě zůstane ve svém růstovém pásmu ({z} SD), spočteno z posledních {n} měření výšky. V grafu je vyznačeno modrou značkou u osmnácti let. <strong>V pubertě to neplatí</strong> – růstový výšvih běžně posune dítě mezi pásmy.',
    'nodata_reference_too_short' => 'Reference {reference} končí ve věku {age}, takže z ní dospělou výšku odhadnout nelze. Přepněte na referenci, která sahá do dospělosti.',
    'nodata_need_two_measurements' => 'Potřebuje aspoň dvě měření výšky.',
    'heading_target_height' => 'Cílová (genetická) výška',
    'note_target_height' => 'Jen z výšek rodičů ({father} a {mother} {unit}), bez ohledu na naměřené hodnoty. Pásmo je široké zhruba {band} {unit}, takže jde spíš o kontrolu než o předpověď. V grafu hnědě.',
    'nodata_need_parent_heights' => 'Zadejte výšky rodičů v {link_open}úpravě dítěte{link_close}.',
    'note_bone_age' => 'Přesnější metody (Bayley–Pinneau, Tanner–Whitehouse) vycházejí z kostního věku, který se určuje z rentgenu ruky, a záměrně tu nejsou.',

    'summary_percentile_sd' => 'Co znamená percentil a SD?',
    'percentile_sd_p1' => 'Percentil říká, kolik procent dětí stejného věku a pohlaví je menších. 25. percentil znamená, že čtvrtina dětí je menší a tři čtvrtiny větší. Padesátý percentil je medián – přesný střed populace.',
    'percentile_sd_p2' => 'SD (směrodatná odchylka, také SDS nebo z-skóre) měří totéž, ale jinou stupnicí: o kolik odchylek je dítě nad nebo pod průměrem. 0 SD je přesný průměr, záporné číslo znamená pod průměrem. Zhruba dvě třetiny dětí se vejdou mezi −1 a +1 SD a 95 % mezi −2 a +2 SD.',
    'percentile_sd_p3' => 'Převod je pevný: −2 SD je zhruba 2. percentil, −1 SD asi 16., 0 SD přesně 50., +1 SD asi 84. a +2 SD zhruba 98.',
    'percentile_sd_p4' => 'Proč jsou tu obojí: u dětí blízko průměru se percentil čte snáz, ale na okrajích se percentily mačkají k sobě – mezi 1. a 0,1. percentilem je víc než celá směrodatná odchylka růstu. Právě proto lékaři u velmi malých a velmi velkých dětí sledují SD, ne percentil. Změna SD v čase je přitom důležitější než jeho hodnota: dítě, které stabilně roste na −2 SD, je nejspíš prostě malé, zatímco dítě, které se během roku posune z −0,5 na −1,5 SD, opouští své pásmo, a to stojí za pozornost lékaře.',

    'heading_measurements' => 'Měření',
    'label_date' => 'Datum',
    'label_height_cm' => 'Výška',
    'placeholder_example_height' => 'např. 122,5',
    'placeholder_example_height_imperial' => 'např. 4\'10"',
    'label_weight_kg' => 'Hmotnost',
    'placeholder_example_weight' => 'např. 20,5',
    'placeholder_example_weight_imperial' => 'např. 45',
    'note_measurement_save' => 'Stačí vyplnit jen jeden z údajů. Uložení stejného data přepíše dřívější zápis.',
    'th_date' => 'Datum',
    'th_age' => 'Věk',
    'th_height' => 'Výška',
    'th_weight' => 'Hmotnost',
    'th_percentile_short' => 'Perc.',
    'th_velocity' => 'Rychlost růstu',
    'velocity_since' => 'od {date}, za {span}',
    'unit_year_short' => 'rok',
    'note_velocity_table' => 'Rychlost růstu je přepočtena na rok a měřena zhruba za poslední rok, ne od předchozího měření – najeďte na hodnotu a uvidíte přesné období. Krátký odstup by chybu měření zvětšil spolu s růstem: {small} {unit} nepřesnosti za čtyři měsíce vyjde jako {large} {velocity_unit} navíc, takže by i rovnoměrně rostoucí dítě zdánlivě zrychlovalo a zpomalovalo.',
    'button_delete' => 'Smazat',

    /* ------------------------------------------------- edit a measurement */
    'page_title_edit_measurement' => 'Upravit měření',
    'heading_edit_measurement' => 'Upravit měření',
    'note_edit_measurement' => 'Úprava měření dítěte {name}. Změna data přesune tento záznam, nepřidá další.',
    'nav_edit_measurement' => 'Upravit',
    'nav_delete_measurement' => 'Smazat toto měření',
    'label_note' => 'Poznámka',
    'placeholder_note' => 'po nemoci, u pediatra…',
    'th_note' => 'Poznámka',
    'error_measurement_collision' => 'K tomuto datu už měření existuje. Upravte je, nebo zvolte jiné datum.',
    'error_measurement_invalid' => 'Zadejte datum ze života dítěte a alespoň jednu z hodnot výška nebo hmotnost.',

    /* -------------------------------------------------------- delete/trash */
    'page_title_delete_measurement' => 'Smazat měření',
    'heading_delete_measurement' => 'Smazat měření?',
    'confirm_delete_measurement' => 'Opravdu smazat měření {name} z {date}?',
    'note_recoverable_from_trash' => 'Zůstane dostupné v <a href="trash.php">koši</a>, odkud jde obnovit.',

    'page_title_delete_child' => 'Smazat dítě',
    'heading_delete_child' => 'Smazat {name}?',
    'confirm_delete_child' => 'Tohle přesune {name} i všech {n} jeho/jejích měření najednou do koše.',
    'note_delete_child_confirm' => 'Jde to vzít zpět z <a href="trash.php">koše</a> – ale aby se to nestalo omylem, napište jméno dítěte přesně tak, jak je uvedeno výše.',
    'error_name_mismatch' => 'Jméno nesouhlasí, dítě nebylo smazáno.',
    'label_confirm_name' => 'Jméno pro potvrzení',
    'button_move_to_trash' => 'Přesunout do koše',

    'trash_intro' => 'Nic odtud nemizí samo – položky tu zůstávají, dokud je neobnovíte.',
    'trash_children_heading' => 'Smazané děti',
    'trash_measurements_heading' => 'Smazaná měření',
    'trash_none' => 'Žádné.',
    'trash_deleted_at' => '(smazáno {when})',
    'button_restore' => 'Obnovit',

    /* -------------------------------------------------------------- import */
    'page_title_import' => 'Import',
    'heading_import' => 'Import CSV',
    'import_error_upload_failed' => 'Nepodařilo se nahrát soubor.',
    'import_error_too_large' => 'Soubor je příliš velký (limit {mb} MB).',
    'import_error_cannot_open' => 'Soubor nelze otevřít.',
    'import_error_not_text' => 'Soubor nevypadá jako text (CSV) - je to opravdu ta správná příloha?',
    'import_error_empty_file' => 'Prázdný soubor.',
    'import_error_missing_column' => 'Chybí sloupec "{column}".',
    'import_error_too_many_rows' => 'Soubor má víc než {max} řádků, zbytek nebyl zpracován.',
    'import_error_column_count' => 'Řádek {line}: nesedí počet sloupců.',
    'import_error_invalid_birth' => 'Řádek {line}: neplatné datum narození.',
    'import_error_invalid_sex' => 'Řádek {line}: pohlaví musí být m nebo f.',
    'import_error_invalid_flag' => 'Řádek {line}: {column} musí být 1 nebo 0 (ano/ne).',
    'import_error_child_conflict' => 'Řádek {line}: {column} u dítěte {child} nesouhlasí s prvním řádkem pro to dítě; použil se první řádek.',
    'import_summary' => 'Naimportováno {n} měření.',
    'import_skipped' => 'Přeskočeno {n} řádků bez výšky i hmotnosti.',
    'label_csv_file' => 'Soubor CSV',
    'button_import' => 'Importovat',
    'import_format_heading' => 'Formát',
    'import_format_intro' => 'První řádek je hlavička, oddělovač je čárka:',
    'import_format_note' => 'Povinné jsou <code>child</code>, <code>sex</code> (m nebo f), <code>birth_date</code> a <code>date</code>, obě data ve tvaru RRRR-MM-DD. Volitelné: <code>height_cm</code>, <code>weight_kg</code>, <code>note</code>, <code>father_cm</code>, <code>mother_cm</code>, <code>breastfed</code> (1 nebo 0). Výška i hmotnost mohou být prázdné &ndash; prázdná buňka znamená &bdquo;neměřeno&ldquo;, ne nulu. Jiná hodnota pohlaví než m nebo f se odmítne s číslem řádku, neuhodne se. Údaje o dítěti se berou z prvního řádku, kde se dítě objeví; pozdější řádek, který tvrdí něco jiného, se ohlásí s číslem řádku a platí ten první. Opakovaný import stejného data zápis přepíše, neduplikuje.',
    'import_from_rustcz_heading' => 'Z RůstCZ',
    'import_from_rustcz_intro' => 'Tohle je jediná věc, která tu ještě potřebuje příkazovou řádku, a může za to formát RůstCZ: databáze <code>.rcz</code> obsahuje jen datovaná měření &ndash; žádná jména, data narození ani pohlaví &ndash; ty jsou v samostatném textovém exportu, jednom pro každé dítě. Převod znamená spárovat obojí, tedy odhadnout, která měření patří kterému dítěti, a takový odhad si zaslouží kontrolu, ne slepé spuštění. Nejdřív převeďte, prohlédněte si CSV, a pak ho nahrajte výše.',

    /* ------------------------------------------------------------------ demo */
    'demo_banner' => 'Ukázková instance – vymyšlená data, po restartu Codespace zmizí. Nic, co sem zadáte, se nikam trvale neukládá.',
    'demo_export_disabled' => 'V této ukázce je export vypnutý – není tu nic, co by stálo za odnesení.',

    /* ---------------------------------------------------------------- footer */
    'footer_source_label' => 'Zdroj dat:',
    'footer_credits_label' => 'Referenční data:',
    'footer_disclaimer' => 'Nenahrazuje lékaře.',

    /* --------------------------------------------------------------- charts */
    'button_fullscreen' => 'Zobrazit graf na celou obrazovku',
    'chart_title_growth' => 'Růstový graf: {label}',
    'nodata_reference_missing' => 'Tato reference pro daný údaj neexistuje.',
    'nodata_no_reference_for_age' => 'Pro tento věk nejsou k dispozici referenční data.',
    'chart_label_projection' => 'Předpověď podle naměřených hodnot: {mid} {unit} v 18 letech · rozmezí {low}–{high} {unit} · pásmo {z} SD · z posledních {n} měření',
    'chart_label_target' => 'Cílová (genetická) výška: {mid} {unit} · rozmezí {low}–{high} {unit} · jen z výšek rodičů',
    'tooltip_percentile' => '{n}. percentil',
    'tooltip_age' => 'věk {age}',
    'tooltip_height' => 'výška {height} {unit}',
    'tooltip_suspect' => 'odchylka {deviation} {unit} od vyrovnané křivky – zkontrolujte zápis',
    'tooltip_median_pace' => 'tempo mediánu {pace} {unit}',
    'legend_measured' => 'naměřené hodnoty',
    'legend_measured_velocity' => 'naměřená rychlost',
    'legend_smoothed' => 'vyrovnaný průběh',
    'legend_projection' => 'předpověď z měření',
    'legend_target' => 'cílová výška',
    'legend_median_pace' => 'tempo mediánu',

    /* ------------------------------------------------------------- metrics */
    'metric_height' => 'Tělesná výška',
    'metric_weight' => 'Hmotnost',
    'metric_bmi' => 'BMI',
    'metric_wfh' => 'Hmotnost k výšce',
    'index_age' => 'věk (roky)',
    'index_height' => 'tělesná výška ({unit})',

    /* -------------------------------------------------------- number/plural */
    'unit_year' => 'rok',
    'unit_years' => 'let',
    'unit_month' => 'měsíc',
    'unit_months' => 'měsíců',
    'median_diff' => '{sign}{value} {unit} oproti mediánu',
    'median_diff_exact' => 'přesně medián',
    'percentile_ordinal_suffix' => '.',               /* Czech: 30,1. is an ordinal */
    'percentile_extrapolated_title' => 'Mimo rozsah publikovaný SZÚ (3.–97. percentil), hodnota je extrapolovaná',

    /* ------------------------------------------------------- reference data */
    'ref_cav_label' => 'ČR – CAV (SZÚ)',
    'ref_cav_note' => 'Výška podle CAV 2001, hmotnost podle CAV 1991. Česká národní reference.',
    'ref_cav_source' => 'Státní zdravotní ústav, 6. celostátní antropologický výzkum (CAV 2001 / 1991).',

    'ref_breastfed_label' => 'ČR – kojené děti',
    'ref_breastfed_note' => 'Reference pro kojené děti, 0–1 rok. Kojenci přibývají jinak než nekojení.',
    'ref_breastfed_source' => 'SZÚ / 3. LF UK (4/2008), referenční grafy kojených dětí, odečteno z publikovaných grafů.',

    'ref_pol_label' => 'Polsko',
    'ref_pol_note' => 'Polská národní reference, 3–18 let. Sousední populace, bližší než WHO či CDC.',
    'ref_pol_source' => 'Kułaga Z. et al., Polish 2010 (7–18 let, CC BY-NC) a Polish 2012 (3–6 let, CC BY), Eur J Pediatr.',

    'ref_who_label' => 'WHO',
    'ref_who_note' => 'Mezinárodní standardy WHO. Hmotnost publikuje WHO jen do 10 let.',
    'ref_who_source' => 'WHO Child Growth Standards (2006) a Growth Reference 5–19 (2007).',

    'ref_cdc_label' => 'USA – CDC 2000',
    'ref_cdc_note' => 'Americká reference CDC 2000, výška i hmotnost od narození do 20 let.',
    'ref_cdc_source' => 'CDC, National Center for Health Statistics, growth charts 2000.',

);
