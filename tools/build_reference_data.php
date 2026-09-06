<?php

/**
 * Builds the growth-reference tables used by src/.
 *
 * Why this is a build step and not a runtime fetch: the reference data does not
 * change. The Czech CAV tables are the current national reference and the 7th
 * survey was never funded, so re-downloading and re-parsing a 900 KB PDF on
 * every page view would buy nothing. We extract once, commit the result, and
 * keep this script so the extraction stays reproducible and auditable.
 *
 * Usage:  php tools/build_reference_data.php [--cache-dir=DIR]
 *
 * Writes src/data/{cav,cdc,koj,pol,who}.php, each a PHP array of
 * LMS rows so that the runtime has exactly one kind of maths to do.
 */

const SZU_TABLES_PDF = 'https://szu.gov.cz/wp-content/uploads/2024/05/6.CAV_4_Telesne_rozmery-tabulky.pdf';

/* CDC ships one CSV per indicator, each carrying a sex column. Most are indexed
   by age in months; weight-for-stature is indexed by height in centimetres, so
   each entry names its index column and the divisor that turns it into the unit
   the application stores. */
const CDC_FILES = [
    'height' => [['https://www.cdc.gov/growthcharts/data/zscore/lenageinf.csv', 'agemos', 12.0],
                 ['https://www.cdc.gov/growthcharts/data/zscore/statage.csv', 'agemos', 12.0]],
    'weight' => [['https://www.cdc.gov/growthcharts/data/zscore/wtageinf.csv', 'agemos', 12.0],
                 ['https://www.cdc.gov/growthcharts/data/zscore/wtage.csv', 'agemos', 12.0]],
    'bmi'    => [['https://www.cdc.gov/growthcharts/data/zscore/bmiagerev.csv', 'agemos', 12.0]],
    'wfh'    => [['https://www.cdc.gov/growthcharts/data/zscore/wtleninf.csv', 'length', 1.0],
                 ['https://www.cdc.gov/growthcharts/data/zscore/wtstat.csv', 'height', 1.0]],
];

const WHO_FILES = [
    // metric => sex => [url, age-unit] ; WHO splits 0-5 from 5-19
    'height' => [
        'm' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/length-height-for-age/expandable-tables/lhfa-boys-zscore-expanded-tables.xlsx', 'day'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/height-for-age-(5-19-years)/hfa-boys-z-who-2007-exp.xlsx', 'month'],
        ],
        'f' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/length-height-for-age/expandable-tables/lhfa-girls-zscore-expanded-tables.xlsx', 'day'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/height-for-age-(5-19-years)/hfa-girls-z-who-2007-exp.xlsx', 'month'],
        ],
    ],
    /* The 5-10 y weight files really are named "hfa-*" in WHO's own weight-for-age
       directory - their naming, not a copy/paste slip here. The contents are
       weight (M = 18.5 kg at 61 months). Keep the full GUID-suffixed URLs that
       WHO's own page links to; the short forms 404. */
    'weight' => [
        'm' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/weight-for-age/expanded-tables/wfa-boys-zscore-expanded-tables.xlsx', 'day'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/weight-for-age-(5-10-years)/hfa-boys-z-who-2007-exp_0ff9c43c-8cc0-4c23-9fc6-81290675e08b.xlsx?sfvrsn=b3ca0d6f_4', 'month'],
        ],
        'f' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/weight-for-age/expanded-tables/wfa-girls-zscore-expanded-tables.xlsx', 'day'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/weight-for-age-(5-10-years)/hfa-girls-z-who-2007-exp_7ea58763-36a2-436d-bef0-7fcfbadd2820.xlsx?sfvrsn=6ede55a4_4', 'month'],
        ],
    ],
    'bmi' => [
        'm' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/body-mass-index-for-age/expanded-tables/bfa-boys-zscore-expanded-tables.xlsx', 'day'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/bmi-for-age-(5-19-years)/bmi-boys-z-who-2007-exp.xlsx?sfvrsn=a84bca93_2', 'month'],
        ],
        'f' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/body-mass-index-for-age/expanded-tables/bfa-girls-zscore-expanded-tables.xlsx', 'day'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/growth-reference-5-19-years/bmi-for-age-(5-19-years)/bmi-girls-z-who-2007-exp.xlsx?sfvrsn=79222875_2', 'month'],
        ],
    ],
    /* Weight-for-length below two years, weight-for-height above: WHO measures
       infants lying down and older children standing, and the two are not the
       same number. Both are indexed in centimetres. */
    'wfh' => [
        'm' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/weight-for-length-height/expanded-tables/wfl-boys-zscore-expanded-table.xlsx', 'cm'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/weight-for-length-height/expanded-tables/wfh-boys-zscore-expanded-tables.xlsx', 'cm'],
        ],
        'f' => [
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/weight-for-length-height/expanded-tables/wfl-girls-zscore-expanded-table.xlsx', 'cm'],
            ['https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/indicators/weight-for-length-height/expanded-tables/wfh-girls-zscore-expanded-tables.xlsx', 'cm'],
        ],
    ],
];

/* SZU's reference for breastfed infants, 0-1 year, published only as charts.
   Height is "tělesná délka" - infants are measured lying down. */
const BREASTFED_CHARTS = [
    'height' => [
        'm' => 'https://szu.gov.cz/wp-content/uploads/2022/12/TELESNA_DELKA_chlapci.pdf',
        'f' => 'https://szu.gov.cz/wp-content/uploads/2022/12/TELESNA_DELKA_divky.pdf',
    ],
    'weight' => [
        'm' => 'https://szu.gov.cz/wp-content/uploads/2024/04/hmotnost_chlapci.pdf',
        'f' => 'https://szu.gov.cz/wp-content/uploads/2024/04/hmotnost_divky.pdf',
    ],
];

/* Polish national reference, in two open-access papers that publish their LMS
   parameters in full. Licences differ between them and both require credit:
   the preschool paper is CC BY, the school-age one CC BY-NC. */
const POLISH_PRESCHOOL_URL = 'https://pmc.ncbi.nlm.nih.gov/articles/PMC3663205/';
const POLISH_SCHOOL_URL = 'https://pmc.ncbi.nlm.nih.gov/articles/PMC3078309/';

/* Age spans each reference is expected to cover, in years, as
   [[min_lo, min_hi], [max_lo, max_hi]]. Both ends are checked after the build,
   because a wrong or stale source is otherwise invisible at one end and obvious
   at the other: a weight table silently filled with height data still ends at
   19 y, and a CSV dropped by a stray BOM still ends at 20 y while quietly
   starting at 2. */
const EXPECTED_AGE_SPAN = [
    'cdc' => [
        'height' => [[0.0, 0.1], [19.5, 20.5]],
        'weight' => [[0.0, 0.1], [19.5, 20.5]],
        'bmi' => [[1.9, 2.1], [19.5, 20.5]],
        /* weight-for-height is indexed in centimetres, not years */
        'wfh' => [[44.0, 78.0], [110.0, 125.0]],
    ],
    'who' => [
        'height' => [[0.0, 0.1], [18.5, 19.5]],
        'weight' => [[0.0, 0.1], [9.5, 10.5]],
        'bmi' => [[0.0, 0.1], [18.5, 19.5]],
        'wfh' => [[44.0, 50.0], [115.0, 125.0]],
    ],
    'cav' => [
        'height' => [[0.0, 0.1], [17.5, 18.5]],
        'weight' => [[0.0, 0.1], [17.5, 18.5]],
        'bmi' => [[0.0, 0.1], [17.5, 18.5]],
        'wfh' => [[48.0, 52.0], [95.0, 185.0]],
    ],
    /* Poland starts at 3: the two papers together cover preschool and school
       age, and nothing below 3 was ever published in this form. */
    'pol' => [
        'height' => [[2.9, 3.1], [17.5, 18.5]],
        'weight' => [[2.9, 3.1], [17.5, 18.5]],
        'bmi' => [[2.9, 3.1], [17.5, 18.5]],
    ],
    /* The breastfed charts run birth to one year and no further. */
    'koj' => [
        'height' => [[0.0, 0.1], [0.95, 1.05]],
        'weight' => [[0.0, 0.1], [0.95, 1.05]],
        'wfh' => [[48.0, 56.0], [75.0, 90.0]],
    ],
];

/* The seven percentiles SZU publishes, as exact normal quantiles. The LMS fit
   is anchored to these. */
const PCT_Z = [
    -1.8807936081512509,
    -1.2815515655446004,
    -0.6744897501960817,
    0.0,
    0.6744897501960817,
    1.2815515655446004,
    1.8807936081512509,
];

/* A known-good row used to prove the PDF extraction still works: boys' height
   at age 0.0, straight from the published table. If the PDF is ever re-issued
   with a different font encoding this assertion fails loudly instead of
   silently producing plausible nonsense. */
const CAV_CANARY = ['age' => 0.0, 'p' => [45.9, 47.5, 49.0, 50.6, 52.1, 53.4, 54.6]];

$opts = getopt('', ['cache-dir::']);
$cacheDir = $opts['cache-dir'] ?? sys_get_temp_dir() . '/growth-reference-cache';
@mkdir($cacheDir, 0777, true);

$root = dirname(__DIR__);
$outDir = $root . '/src/data';
@mkdir($outDir, 0777, true);

/* ------------------------------------------------------------------ fetching */

function fetch_cached(string $url, string $cacheDir): string
{
    /* The URL hash is part of the cache key on purpose. WHO ships different
       tables under identical basenames - the 5-10 y weight file is also called
       hfa-boys-z-who-2007-exp.xlsx - so keying on basename alone silently
       serves one indicator's data for another. */
    $base = preg_replace('~[^A-Za-z0-9._-]~', '_', basename(parse_url($url, PHP_URL_PATH)));
    $path = $cacheDir . '/' . substr(sha1($url), 0, 8) . '-' . $base;
    if (is_file($path) && filesize($path) > 0) {
        return $path;
    }
    fwrite(STDERR, '    downloading ' . basename($url) . "\n");

    /* Deliberately no ext/curl: a build script that only runs a handful of
       times should not dictate which PHP extensions a machine needs. Plain
       streams cover it, and the curl binary is the fallback when a hardened
       php.ini has allow_url_fopen off. */
    $context = stream_context_create(['http' => [
        'timeout' => 180,
        'follow_location' => 1,
        'user_agent' => 'kidgrowth reference-data build script',
    ]]);
    $body = @file_get_contents($url, false, $context);

    if ($body === false || $body === '') {
        /* -f matters: without it curl happily returns the server's 404 page,
           which is a perfectly valid non-empty string and would be cached and
           parsed as if it were data. */
        $body = shell_exec('curl -fsSL --max-time 180 ' . escapeshellarg($url));
    }
    if ($body === false || $body === null || $body === '') {
        fwrite(STDERR, "FATAL: download failed: $url\n");
        exit(1);
    }
    file_put_contents($path, $body);
    return $path;
}

/* ------------------------------------------------------ Czech CAV: PDF text */

/**
 * Pulls text out of the SZU tables PDF.
 *
 * The PDF uses CID-keyed subset fonts, so a glyph id is not a character code.
 * For this producer the mapping is a flat shift, unicode = gid + 29: space
 * 0x0003 -> 0x20, '4' 0x0017 -> 0x34, 'A' 0x0024 -> 0x41. That covers digits,
 * the comma decimal separator and unaccented ASCII, which is all we match on.
 * Accented Czech letters come out wrong and we simply never rely on them.
 */
function pdf_pages_text(string $pdfPath): array
{
    $raw = file_get_contents($pdfPath);
    $streams = [];
    $offset = 0;
    while (($pos = strpos($raw, 'stream', $offset)) !== false) {
        $start = $pos + 6;
        if (isset($raw[$start]) && $raw[$start] === "\r") { $start++; }
        if (isset($raw[$start]) && $raw[$start] === "\n") { $start++; }
        $end = strpos($raw, 'endstream', $start);
        if ($end === false) { break; }
        $inflated = @gzuncompress(substr($raw, $start, $end - $start));
        if ($inflated !== false && str_contains($inflated, 'BT')) {
            $streams[] = $inflated;
        }
        $offset = $end + 9;
    }

    $pages = [];
    foreach ($streams as $content) {
        /* Every text run is positioned by a Tm matrix. Keeping x/y lets us
           reassemble rows in reading order, which matters because each table
           cell is emitted as its own run. */
        $items = [];
        $re = '~([-\d.]+)\s+([-\d.]+)\s+Tm\s*((?:\[[^\]]*\]\s*TJ)|(?:<[0-9A-Fa-f]*>\s*Tj))~';
        if (!preg_match_all($re, $content, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $hit) {
            $text = '';
            preg_match_all('~<([0-9A-Fa-f]*)>~', $hit[3], $hex);
            foreach ($hex[1] as $chunk) {
                for ($i = 0; $i + 4 <= strlen($chunk); $i += 4) {
                    $text .= chr((hexdec(substr($chunk, $i, 4)) + 29) & 0xFF);
                }
            }
            $items[] = ['x' => (float)$hit[1], 'y' => (float)$hit[2], 't' => $text];
        }
        usort($items, function ($a, $b) {
            if (abs($a['y'] - $b['y']) > 1.0) { return $b['y'] <=> $a['y']; }
            return $a['x'] <=> $b['x'];
        });
        $out = '';
        $lastY = null;
        foreach ($items as $item) {
            if ($lastY !== null) { $out .= abs($item['y'] - $lastY) > 1.0 ? "\n" : ' '; }
            $out .= $item['t'];
            $lastY = $item['y'];
        }
        $pages[] = $out;
    }
    return $pages;
}

/**
 * Finds the section 4.3 percentile table for one metric and sex.
 *
 * Matching is on ASCII fragments only: "Tab. 4.3." pins the section (4.2 holds
 * mean/SD tables with the same English captions), the English caption pins the
 * metric, and Boys/Girls pins the sex.
 */
function parse_percentile_table(array $pages, string $caption, string $sexMarker): array
{
    foreach ($pages as $text) {
        if (!str_contains($text, 'Tab. 4.3.')) { continue; }
        if (!str_contains($text, $caption)) { continue; }
        if (!str_contains($text, $sexMarker)) { continue; }

        $rows = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            /* An index value followed by exactly seven percentiles. The index is
               an age in years with a comma decimal for most tables, but a whole
               number of centimetres for weight-for-height, which is indexed by
               height rather than by age. */
            if (!preg_match('~^(\d+(?:,\d+)?)((?:\s+\d+,\d+){7})$~', $line, $m)) { continue; }
            $values = [];
            foreach (preg_split('~\s+~', trim($m[2])) as $v) {
                $values[] = (float)str_replace(',', '.', $v);
            }
            $rows[] = ['age' => (float)str_replace(',', '.', $m[1]), 'p' => $values];
        }
        if ($rows) { return $rows; }
    }
    fwrite(STDERR, "FATAL: section 4.3 table not found for '$caption' / '$sexMarker'.\n");
    exit(1);
}

/* --------------------------------------------------------------- LMS fitting */

/** The measurement at a given z. Inverse of the z-score formula. */
function lms_value(float $l, float $m, float $s, float $z): float
{
    if (abs($l) < 1e-9) {
        return $m * exp($s * $z);
    }
    $base = 1.0 + $l * $s * $z;
    if ($base <= 0.0) { return NAN; }
    return $m * pow($base, 1.0 / $l);
}

/** Fits the seven published percentiles, the usual case. */
function fit_lms(array $published): array
{
    $pairs = [];
    foreach (PCT_Z as $k => $z) {
        $pairs[] = [$z, $published[$k]];
    }
    return fit_lms_pairs($pairs, $published[3]);
}

/**
 * Fits L and S to a set of (z, value) pairs with M fixed at the median, which
 * is exact by definition. That leaves two free parameters, so three published
 * percentiles are already enough to determine them - which is what the
 * breastfed charts give us, against seven for the CAV tables.
 *
 * A grid scan with successive zoom is used rather than a gradient method: the
 * objective is cheap, only two parameters move, and unlike Newton-style solvers
 * this cannot diverge or land in a local minimum off the grid.
 */
function fit_lms_pairs(array $pairs, float $median): array
{
    $m = $median;
    $best = ['l' => 1.0, 's' => 0.1, 'err' => INF];

    $lLo = -8.0; $lHi = 5.0;
    $sLo = 0.0005; $sHi = 0.60;
    $steps = 48;

    for ($pass = 0; $pass < 9; $pass++) {
        $dl = ($lHi - $lLo) / $steps;
        $ds = ($sHi - $sLo) / $steps;
        for ($i = 0; $i <= $steps; $i++) {
            $l = $lLo + $i * $dl;
            for ($j = 0; $j <= $steps; $j++) {
                $s = $sLo + $j * $ds;
                if ($s <= 0.0) { continue; }
                $err = 0.0;
                $ok = true;
                foreach ($pairs as [$z, $target]) {
                    $v = lms_value($l, $m, $s, $z);
                    if (!is_finite($v)) { $ok = false; break; }
                    $err += ($v - $target) ** 2;
                }
                if ($ok && $err < $best['err']) {
                    $best = ['l' => $l, 's' => $s, 'err' => $err];
                }
            }
        }
        $lSpan = ($lHi - $lLo) / 5.0;
        $sSpan = ($sHi - $sLo) / 5.0;
        $lLo = $best['l'] - $lSpan;      $lHi = $best['l'] + $lSpan;
        $sLo = max(1e-5, $best['s'] - $sSpan); $sHi = $best['s'] + $sSpan;
    }

    $maxErr = 0.0;
    foreach ($pairs as [$z, $target]) {
        $maxErr = max($maxErr, abs(lms_value($best['l'], $m, $best['s'], $z) - $target));
    }
    return ['l' => $best['l'], 'm' => $m, 's' => $best['s'], 'maxerr' => $maxErr];
}

/* ----------------------------------------------------------- CDC / WHO input */

/**
 * CDC ships plain CSV with Sex,<index>,L,M,S columns. Sex 1 = male, 2 = female.
 *
 * $indexColumn is the name of the indexing variable - "agemos" for the
 * age-based tables, "height" or "length" for weight-for-stature - and $divisor
 * converts it to the unit stored (years, or centimetres unchanged).
 */
function parse_cdc_csv(string $path, string $indexColumn, float $divisor): array
{
    $rows = ['m' => [], 'f' => []];
    $fh = fopen($path, 'r');
    $header = null;
    while (($line = fgetcsv($fh)) !== false) {
        if ($header === null) {
            $header = array_map(fn($h) => strtolower(trim($h)), $line);
            /* lenageinf.csv carries a UTF-8 BOM and the others do not, so
               without this its first column is named "\xEF\xBB\xBFsex", every
               row fails the column check, and the whole infant length table is
               silently dropped - leaving CDC height starting at 2 years. */
            $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', $header[0]);
            continue;
        }
        $row = @array_combine($header, $line);
        if (!$row || !isset($row['sex'], $row[$indexColumn], $row['l'], $row['m'], $row['s'])) { continue; }
        if (!is_numeric($row[$indexColumn])) { continue; }
        $sex = ((int)$row['sex'] === 1) ? 'm' : 'f';
        $rows[$sex][] = [
            'age' => round(((float)$row[$indexColumn]) / $divisor, 6),
            'l' => (float)$row['l'],
            'm' => (float)$row['m'],
            's' => (float)$row['s'],
        ];
    }
    fclose($fh);
    return $rows;
}

/**
 * Reads a zip archive into [name => contents].
 *
 * Hand-rolled rather than using ext/zip for the same reason as the downloader:
 * this script should run on any PHP with zlib, which is always present. Only
 * the two storage methods that matter are handled - stored and deflated - which
 * is everything a spreadsheet writer produces.
 */
function zip_entries(string $path): array
{
    $raw = file_get_contents($path);
    $size = strlen($raw);

    /* find the end-of-central-directory record, scanning back from the tail */
    $eocd = -1;
    for ($i = $size - 22; $i >= 0; $i--) {
        if (substr($raw, $i, 4) === "PK\x05\x06") { $eocd = $i; break; }
    }
    if ($eocd < 0) {
        fwrite(STDERR, "FATAL: not a zip archive: $path\n");
        exit(1);
    }
    $count = unpack('v', substr($raw, $eocd + 10, 2))[1];
    $pos = unpack('V', substr($raw, $eocd + 16, 4))[1];

    $entries = [];
    for ($n = 0; $n < $count; $n++) {
        if (substr($raw, $pos, 4) !== "PK\x01\x02") { break; }
        $method = unpack('v', substr($raw, $pos + 10, 2))[1];
        $compressedSize = unpack('V', substr($raw, $pos + 20, 4))[1];
        $nameLen = unpack('v', substr($raw, $pos + 28, 2))[1];
        $extraLen = unpack('v', substr($raw, $pos + 30, 2))[1];
        $commentLen = unpack('v', substr($raw, $pos + 32, 2))[1];
        $localPos = unpack('V', substr($raw, $pos + 42, 4))[1];
        $name = substr($raw, $pos + 46, $nameLen);

        /* the local header repeats the name/extra lengths, and they can differ
           from the central directory's, so read them again from the local one */
        $localNameLen = unpack('v', substr($raw, $localPos + 26, 2))[1];
        $localExtraLen = unpack('v', substr($raw, $localPos + 28, 2))[1];
        $dataPos = $localPos + 30 + $localNameLen + $localExtraLen;
        $data = substr($raw, $dataPos, $compressedSize);

        $entries[$name] = ($method === 8) ? (@gzinflate($data) ?: '') : $data;
        $pos += 46 + $nameLen + $extraLen + $commentLen;
    }
    return $entries;
}

/**
 * Minimal xlsx reader: an xlsx is a zip of XML, and WHO's tables are a single
 * sheet of numbers with a header row. We only need the numeric grid, so we pull
 * the worksheet and the shared-string table and flatten to rows of cells.
 */
function parse_xlsx_rows(string $path): array
{
    $zip = zip_entries($path);
    $shared = [];
    $sharedXml = $zip['xl/sharedStrings.xml'] ?? false;
    if ($sharedXml !== false) {
        if (preg_match_all('~<si>(.*?)</si>~s', $sharedXml, $m)) {
            foreach ($m[1] as $si) {
                preg_match_all('~<t[^>]*>(.*?)</t>~s', $si, $t);
                $shared[] = html_entity_decode(implode('', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
    $sheetXml = false;
    foreach ($zip as $name => $content) {
        if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
            $sheetXml = $content;
            break;
        }
    }
    if ($sheetXml === false) {
        fwrite(STDERR, "FATAL: no worksheet in $path\n");
        exit(1);
    }

    $rows = [];
    preg_match_all('~<row[^>]*>(.*?)</row>~s', $sheetXml, $rowMatches);
    foreach ($rowMatches[1] as $rowXml) {
        $cells = [];
        preg_match_all('~<c([^>]*)>(.*?)</c>~s', $rowXml, $cellMatches, PREG_SET_ORDER);
        foreach ($cellMatches as $cell) {
            $isShared = str_contains($cell[1], 't="s"');
            if (!preg_match('~<v>(.*?)</v>~s', $cell[2], $v)) { $cells[] = ''; continue; }
            $cells[] = $isShared ? ($shared[(int)$v[1]] ?? '') : $v[1];
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** Locates the Day/Month, L, M, S columns in a WHO sheet and returns LMS rows. */
function parse_who_xlsx(string $path, string $ageUnit): array
{
    $rows = parse_xlsx_rows($path);
    $header = null;
    $idx = [];
    $out = [];
    foreach ($rows as $cells) {
        if ($header === null) {
            $lower = array_map(fn($c) => strtolower(trim((string)$c)), $cells);
            if (!in_array('l', $lower, true) || !in_array('m', $lower, true)) { continue; }
            $header = $lower;
            foreach ($lower as $i => $name) { $idx[$name] = $i; }
            continue;
        }
        $ageKey = null;
        foreach (['day', 'month', 'age', 'length', 'height'] as $candidate) {
            if (isset($idx[$candidate])) { $ageKey = $candidate; break; }
        }
        if ($ageKey === null) { continue; }
        $ageRaw = $cells[$idx[$ageKey]] ?? '';
        if (!is_numeric($ageRaw)) { continue; }
        if ($ageUnit === 'day') {
            $age = ((float)$ageRaw) / 365.25;
        } elseif ($ageUnit === 'cm') {
            /* weight-for-length and weight-for-height are indexed in
               centimetres, so the index is stored unchanged */
            $age = (float)$ageRaw;
        } else {
            $age = ((float)$ageRaw) / 12.0;
        }
        $out[] = [
            'age' => round($age, 6),
            'l' => (float)($cells[$idx['l']] ?? 0),
            'm' => (float)($cells[$idx['m']] ?? 0),
            's' => (float)($cells[$idx['s']] ?? 0),
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ writing */

/** Sorts by age and drops duplicate ages, keeping the first (younger source). */
function normalise_rows(array $rows): array
{
    usort($rows, fn($a, $b) => $a['age'] <=> $b['age']);
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $key = (string)round($row['age'], 5);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $out[] = [
            'age' => round($row['age'], 6),
            'l' => round($row['l'], 6),
            'm' => round($row['m'], 4),
            's' => round($row['s'], 6),
        ];
    }
    return $out;
}

/**
 * Serialises LMS rows to one compact string, "age,L,M,S" per row separated by
 * spaces.
 *
 * The obvious var_export() of nested arrays costs about a megabyte for WHO's
 * daily tables, which is a lot of parsing per request for what is really just a
 * grid of numbers. Splitting a string is far cheaper, and trailing zeros are
 * trimmed since these are all decimal fractions.
 */
function compact_rows(array $rows): string
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = trim_num($row['age']) . ',' . trim_num($row['l']) . ','
               . trim_num($row['m']) . ',' . trim_num($row['s']);
    }
    return implode(' ', $out);
}

function trim_num(float $v): string
{
    $s = rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

/**
 * Guards against a stale or mistyped source URL.
 *
 * A weight table that reaches 19 years has quietly been filled with height
 * data. That is exactly the bug this catches, and it is invisible in the
 * numbers themselves because heights and weights are both plausible positive
 * reals - only the age span gives it away.
 */
function check_age_range(string $reference, string $metric, array $rows): void
{
    if (!$rows) {
        fwrite(STDERR, "FATAL: $reference/$metric produced no rows.\n");
        exit(1);
    }
    [[$minLo, $minHi], [$maxLo, $maxHi]] = EXPECTED_AGE_SPAN[$reference][$metric];

    $minAge = $rows[0]['age'];
    $maxAge = $rows[count($rows) - 1]['age'];

    if ($maxAge < $maxLo || $maxAge > $maxHi) {
        fwrite(STDERR, sprintf(
            "FATAL: %s/%s ends at %.2f y, expected between %.1f and %.1f.\n"
            . "       A wrong source file was almost certainly downloaded.\n",
            $reference, $metric, $maxAge, $maxLo, $maxHi
        ));
        exit(1);
    }
    if ($minAge < $minLo || $minAge > $minHi) {
        fwrite(STDERR, sprintf(
            "FATAL: %s/%s starts at %.2f y, expected between %.1f and %.1f.\n"
            . "       Part of a source probably parsed to nothing - check its header row.\n",
            $reference, $metric, $minAge, $minLo, $minHi
        ));
        exit(1);
    }
}

/* --------------------------------------- Czech reference for breastfed infants */

/**
 * Extracts positioned text runs from a PDF content stream.
 *
 * These charts place every run with an explicit Tm matrix, so the axis tick
 * labels come with coordinates - which is what makes calibration possible at
 * all.
 */
function pdf_text_items(string $content): array
{
    $items = [];
    $re = '~1 0 0 1 ([\d.]+) ([\d.]+) Tm[\s\S]{0,40}?\[([^\]]*)\]\s*TJ~';
    preg_match_all($re, $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $hit) {
        preg_match_all('~\(((?:\\\\.|[^()\\\\])*)\)~', $hit[3], $parts);
        $text = implode('', $parts[1]);
        if (trim($text) !== '') {
            $items[] = ['x' => (float)$hit[1], 'y' => (float)$hit[2], 't' => $text];
        }
    }
    return $items;
}

/**
 * Extracts stroked paths, flattening cubic Beziers into polylines.
 *
 * The percentile curves in these charts are drawn as Beziers, so they have to
 * be flattened before anything can be read off them. Twelve segments per curve
 * is far finer than the 0.05 unit precision the published tables themselves
 * carry.
 */
function pdf_paths(string $content): array
{
    $tokens = preg_split('~\s+~', str_replace("\r", ' ', $content), -1, PREG_SPLIT_NO_EMPTY);
    $paths = [];
    $current = [];
    $colour = null;
    $x = 0.0;
    $y = 0.0;
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t === 'm') {
            if (count($current) > 1) { $paths[] = ['pts' => $current, 'col' => $colour]; }
            $x = (float)$tokens[$i - 2];
            $y = (float)$tokens[$i - 1];
            $current = [[$x, $y]];
        } elseif ($t === 'l') {
            $x = (float)$tokens[$i - 2];
            $y = (float)$tokens[$i - 1];
            $current[] = [$x, $y];
        } elseif ($t === 'c') {
            $x1 = (float)$tokens[$i - 6]; $y1 = (float)$tokens[$i - 5];
            $x2 = (float)$tokens[$i - 4]; $y2 = (float)$tokens[$i - 3];
            $x3 = (float)$tokens[$i - 2]; $y3 = (float)$tokens[$i - 1];
            for ($k = 1; $k <= 12; $k++) {
                $u = $k / 12.0;
                $v = 1.0 - $u;
                $current[] = [
                    $v * $v * $v * $x + 3 * $v * $v * $u * $x1 + 3 * $v * $u * $u * $x2 + $u * $u * $u * $x3,
                    $v * $v * $v * $y + 3 * $v * $v * $u * $y1 + 3 * $v * $u * $u * $y2 + $u * $u * $u * $y3,
                ];
            }
            $x = $x3; $y = $y3;
        } elseif ($t === 'RG') {
            $colour = [(float)$tokens[$i - 3], (float)$tokens[$i - 2], (float)$tokens[$i - 1]];
        } elseif (in_array($t, ['S', 's', 'f', 'F', 'B', 'n'], true)) {
            if (count($current) > 1) { $paths[] = ['pts' => $current, 'col' => $colour]; }
            $current = [];
        }
    }
    if (count($current) > 1) { $paths[] = ['pts' => $current, 'col' => $colour]; }
    return $paths;
}

/**
 * Digitises one SZU breastfed-infant chart.
 *
 * SZU never published these as numbers - only as charts - so the curves are
 * read back off the drawing. That is only defensible because every chart also
 * draws the seven CAV percentile curves alongside the breastfed ones, and CAV
 * we hold authoritatively. Digitising those and comparing them against the
 * published table proves the axis calibration before a single breastfed value
 * is trusted; the caller treats a mismatch as fatal.
 *
 * Axis scale comes from the numeric tick labels, which are evenly spaced. The
 * origin comes from the outermost grid lines instead, because a label is placed
 * by its text baseline and so sits a few units off the tick it belongs to,
 * while a grid line is drawn exactly on it.
 */
function digitise_breastfed_chart(string $path, array $cavByMonth): array
{
    $content = '';
    $raw = file_get_contents($path);
    $offset = 0;
    while (($pos = strpos($raw, 'stream', $offset)) !== false) {
        $start = $pos + 6;
        if (isset($raw[$start]) && $raw[$start] === "\r") { $start++; }
        if (isset($raw[$start]) && $raw[$start] === "\n") { $start++; }
        $end = strpos($raw, 'endstream', $start);
        if ($end === false) { break; }
        $inflated = @gzuncompress(substr($raw, $start, $end - $start));
        if ($inflated !== false) { $content .= $inflated . "\n"; }
        $offset = $end + 9;
    }

    /* --- axis labels ------------------------------------------------- */
    $numbers = [];
    foreach (pdf_text_items($content) as $item) {
        if (preg_match('~^\d+$~', $item['t'])) {
            $numbers[] = ['v' => (int)$item['t'], 'x' => $item['x'], 'y' => $item['y']];
        }
    }
    $byX = [];
    $byY = [];
    foreach ($numbers as $number) {
        $byX[(string)round($number['x'])][] = $number;
        $byY[(string)round($number['y'])][] = $number;
    }
    $collect = function ($groups) {
        $out = [];
        foreach ($groups as $group) {
            if (count($group) > 3) { $out = array_merge($out, $group); }
        }
        usort($out, fn($a, $b) => $a['v'] <=> $b['v']);
        return $out;
    };
    $yLabels = $collect($byX);   /* the value axis: labels share an x */
    $xLabels = $collect($byY);   /* the age axis: labels share a y */
    if (count($yLabels) < 4 || count($xLabels) < 4) {
        fwrite(STDERR, "FATAL: could not find axis labels in $path\n");
        exit(1);
    }

    /* --- grid lines --------------------------------------------------- */
    $paths = pdf_paths($content);
    $horizontal = [];
    $vertical = [];
    foreach ($paths as $p) {
        $xs = array_column($p['pts'], 0);
        $ys = array_column($p['pts'], 1);
        $dx = max($xs) - min($xs);
        $dy = max($ys) - min($ys);
        if ($dy < 0.6 && $dx > 50) { $horizontal[] = $ys[0]; }
        elseif ($dx < 0.6 && $dy > 50) { $vertical[] = $xs[0]; }
    }
    sort($horizontal);
    sort($vertical);
    if (!$horizontal || !$vertical) {
        fwrite(STDERR, "FATAL: could not find grid lines in $path\n");
        exit(1);
    }

    $vLo = $yLabels[0]['v'];
    $vHi = $yLabels[count($yLabels) - 1]['v'];
    $mLo = $xLabels[0]['v'];
    $mHi = $xLabels[count($xLabels) - 1]['v'];
    $y0 = $horizontal[0];
    $x0 = $vertical[0];
    $yScale = ($horizontal[count($horizontal) - 1] - $y0) / ($vHi - $vLo);
    $xScale = ($vertical[count($vertical) - 1] - $x0) / ($mHi - $mLo);

    /* --- the curves --------------------------------------------------- */
    $curves = [];
    foreach ($paths as $p) {
        $xs = array_column($p['pts'], 0);
        if (count($p['pts']) > 8 && (max($xs) - min($xs)) > 100) { $curves[] = $p['pts']; }
    }

    $valueAt = function (array $pts, float $months) use ($x0, $xScale, $y0, $yScale, $vLo) {
        $px = $x0 + $months * $xScale;
        usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
        if ($px < $pts[0][0] - 1 || $px > $pts[count($pts) - 1][0] + 1) { return null; }
        for ($i = 1; $i < count($pts); $i++) {
            if ($pts[$i][0] >= $px) {
                $a = $pts[$i - 1];
                $b = $pts[$i];
                $t = ($b[0] - $a[0]) != 0.0 ? ($px - $a[0]) / ($b[0] - $a[0]) : 0.0;
                return $vLo + (($a[1] + $t * ($b[1] - $a[1])) - $y0) / $yScale;
            }
        }
        return null;
    };

    /* Classify by content, not by colour: the four charts use different colours
       for the same thing. A curve that tracks a known CAV percentile is CAV.
     *
     * The comparison happens at the ages CAV itself publishes - 0, 0.2, 0.4,
     * 0.6, 0.8 and 1.0 years, i.e. months 0, 2.4, 4.8, 7.2, 9.6 and 12 - so no
     * interpolation of the reference enters the check. Comparing at whole
     * months instead would mean interpolating CAV across its own 2.4-month
     * steps, and through infancy those segments bend hard enough that the
     * chord sits several tenths below the curve, which is enough to make every
     * curve fail to match. */
    $months = array_keys($cavByMonth);
    $cavCurves = [];
    $breastfed = [];
    $worstCav = 0.0;

    foreach ($curves as $pts) {
        $mine = [];
        foreach ($months as $month) { $mine[] = $valueAt($pts, (float)$month); }
        if (in_array(null, $mine, true)) { continue; }

        $bestErr = INF;
        $bestIndex = null;
        for ($p = 0; $p < 7; $p++) {
            $err = 0.0;
            foreach ($months as $i => $month) {
                $err = max($err, abs($mine[$i] - $cavByMonth[$month][$p]));
            }
            if ($err < $bestErr) { $bestErr = $err; $bestIndex = $p; }
        }
        if ($bestErr < 0.25) {
            $cavCurves[$bestIndex] = $bestErr;
            $worstCav = max($worstCav, $bestErr);
        } else {
            $breastfed[] = $pts;
        }
    }

    if (count($cavCurves) !== 7) {
        fwrite(STDERR, sprintf(
            "FATAL: %s - matched %d of the 7 CAV curves, so the calibration is unproven.\n"
            . "       value axis %.1f..%.1f over labels %d..%d, age axis %.1f..%.1f over %d..%d\n"
            . "       curves considered: %d\n",
            basename($path), count($cavCurves),
            $y0, $horizontal[count($horizontal) - 1], $vLo, $vHi,
            $x0, $vertical[count($vertical) - 1], $mLo, $mHi,
            count($curves)
        ));
        foreach ($curves as $pts) {
            $v0 = $valueAt($pts, 0.0);
            $v12 = $valueAt($pts, 12.0);
            fwrite(STDERR, sprintf("         %4d pts: %s .. %s\n", count($pts),
                $v0 === null ? 'null' : sprintf('%.2f', $v0),
                $v12 === null ? 'null' : sprintf('%.2f', $v12)));
        }
        fwrite(STDERR, sprintf("       CAV expected at month 0: %s\n",
            implode(' ', array_map(fn($v) => sprintf('%.2f', $v), $cavByMonth[0]))));
        exit(1);
    }
    if (count($breastfed) !== 3) {
        fwrite(STDERR, sprintf(
            "FATAL: %s - found %d breastfed curves, expected 3 (P3, P50, P97).\n",
            basename($path), count($breastfed)
        ));
        exit(1);
    }

    /* order the three by value, giving P3, P50, P97 */
    usort($breastfed, function ($a, $b) use ($valueAt) {
        return $valueAt($a, 6.0) <=> $valueAt($b, 6.0);
    });

    $rows = [];
    for ($month = 0; $month <= 12; $month++) {
        $p3 = $valueAt($breastfed[0], (float)$month);
        $p50 = $valueAt($breastfed[1], (float)$month);
        $p97 = $valueAt($breastfed[2], (float)$month);
        if ($p3 === null || $p50 === null || $p97 === null) { continue; }
        $rows[] = ['month' => $month, 'p3' => $p3, 'p50' => $p50, 'p97' => $p97];
    }

    return ['rows' => $rows, 'cav_error' => $worstCav];
}

/* ------------------------------------------------- Polish national reference */

/**
 * Extracts every HTML table on a page as an array of rows of cell text.
 *
 * The two Polish papers publish their LMS parameters as ordinary HTML tables on
 * PubMed Central, so they are read straight from the article rather than
 * transcribed by hand - which for several hundred numbers is the difference
 * between reproducible and hopeful.
 */
function html_tables(string $path): array
{
    $html = file_get_contents($path);
    $out = [];
    preg_match_all('~<table[\s\S]*?</table>~i', $html, $tableMatches);
    foreach ($tableMatches[0] as $table) {
        $rows = [];
        preg_match_all('~<tr[\s\S]*?</tr>~i', $table, $rowMatches);
        foreach ($rowMatches[0] as $row) {
            $cells = [];
            preg_match_all('~<t[dh][^>]*>([\s\S]*?)</t[dh]>~i', $row, $cellMatches);
            foreach ($cellMatches[1] as $cell) {
                $text = preg_replace('~<[^>]+>~', ' ', $cell);
                /* Both the entity and the raw UTF-8 byte sequence for a
                   non-breaking space appear in these pages. The raw form
                   matters: PCRE's \s does not match U+00A0 and neither does
                   trim(), so an age cell arrives as "\xC2\xA07", is_numeric()
                   rejects it, and the entire table parses to nothing. */
                $text = str_replace(['&#x000a0;', '&nbsp;', "\xC2\xA0"], ' ', $text);
                /* the papers use a real minus sign for negative L values */
                $text = str_replace(['&#x02212;', '&minus;', "\xE2\x88\x92"], '-', $text);
                $cells[] = trim(preg_replace('~\s+~', ' ', $text));
            }
            $rows[] = $cells;
        }
        $out[] = $rows;
    }
    return $out;
}

/**
 * Polish 2012 preschool reference, ages 3-6 in half-year steps.
 *
 * One table per sex, with "Height (cm)" / "Weight (kg)" / "BMI" section rows
 * splitting it. Columns are age, L, S, four SD columns, then the centiles - the
 * median column doubles as M, which is why M is read from there rather than
 * from a column of its own.
 */
function parse_polish_preschool(string $path): array
{
    $tables = html_tables($path);
    /* section headings, matched on a prefix because the BMI one carries its
       units with stray spacing: "BMI (kg/m 2 )" */
    $wanted = ['Height (cm)' => 'height', 'Weight (kg)' => 'weight', 'BMI' => 'bmi'];
    $result = [];

    /* the boys' table comes before the girls' */
    $sexes = ['m', 'f'];
    $found = 0;
    foreach ($tables as $rows) {
        if (!$rows || ($rows[0][0] ?? '') !== 'Age (years)') {
            continue;
        }
        if (!in_array('L', $rows[0], true) || !in_array('P3', $rows[0], true)) {
            continue;
        }
        $sex = $sexes[$found] ?? null;
        if ($sex === null) {
            break;
        }
        $found++;

        $metric = null;
        foreach ($rows as $row) {
            if (count($row) === 1) {
                $metric = null;
                foreach ($wanted as $heading => $id) {
                    if (strpos($row[0], $heading) === 0) { $metric = $id; break; }
                }
                continue;
            }
            if ($metric === null || count($row) < 11 || !is_numeric($row[0])) {
                continue;
            }
            $result[$metric][$sex][] = [
                'age' => (float)$row[0],
                'l' => (float)$row[1],
                'm' => (float)$row[10],   /* the "P50: M (median)" column */
                's' => (float)$row[2],
            ];
        }
    }
    return $result;
}

/**
 * Polish 2010 school-age reference, ages 7-18 in whole years.
 *
 * Separate tables for height and weight, each holding a "Boys" section then a
 * "Girls" section. Columns are age, N, L, M, S, then the centiles.
 */
function parse_polish_school(string $path): array
{
    $tables = html_tables($path);
    $result = [];

    /* Height comes first, then weight, then BMI. The first two carry an N
       column before the LMS values and BMI does not, which shifts every column
       by one - so the layout is read from the header rather than assumed. */
    $metrics = ['height', 'weight', 'bmi'];
    $found = 0;

    foreach ($tables as $rows) {
        if (!$rows || ($rows[0][0] ?? '') !== 'Age (years)') {
            continue;
        }
        $hasCount = (($rows[0][1] ?? '') === 'Number');
        $offset = $hasCount ? 2 : 1;   /* index of the L column */

        $metric = $metrics[$found] ?? null;
        if ($metric === null) {
            break;
        }
        $found++;

        $sex = null;
        foreach ($rows as $row) {
            if (count($row) === 1) {
                if ($row[0] === 'Boys') { $sex = 'm'; }
                elseif ($row[0] === 'Girls') { $sex = 'f'; }
                continue;
            }
            if ($sex === null || count($row) < $offset + 3 || !is_numeric($row[0])) {
                continue;
            }
            $result[$metric][$sex][] = [
                'age' => (float)$row[0],
                'l' => (float)$row[$offset],
                'm' => (float)$row[$offset + 1],
                's' => (float)$row[$offset + 2],
            ];
        }
    }
    return $result;
}

function export_reference(
    string $path,
    string $id,
    string $comment,
    array $metrics
): void {
    /* Deliberately no label/note/source here: those are display text, which
       varies by locale, and this file does not know which locale it will be
       read under. src/growth.inc's growth_reference() looks them up from
       src/lang/{en,cs}.php (keys ref_{$id}_label/note/source) every time it
       loads this file instead. */
    $payload = ['id' => $id, 'metrics' => []];
    foreach ($metrics as $metric => $bySex) {
        foreach ($bySex as $sex => $rows) {
            check_age_range($id, $metric, $rows);
            $payload['metrics'][$metric][$sex] = compact_rows($rows);
        }
    }
    $body = "<?php\n\n/*\n" . $comment . "\n\n"
        . " Format: each metric/sex is one string of \"age,L,M,S\" rows separated by\n"
        . " spaces, with age in years. Parsed by growth_reference_rows() in growth.inc.\n*/\n\n"
        . 'return ' . var_export($payload, true) . ";\n";
    file_put_contents($path, $body);
    fwrite(STDERR, '  wrote ' . basename($path) . ' (' . number_format(filesize($path)) . " bytes)\n");
}

/* --------------------------------------------------------------------- main */

/* --- Czech CAV ---------------------------------------------------------- */

fwrite(STDERR, "Czech CAV reference (SZU)\n");
$pdf = fetch_cached(SZU_TABLES_PDF, $cacheDir);
$pages = pdf_pages_text($pdf);
fwrite(STDERR, '  pages with text: ' . count($pages) . "\n");

$cavSpec = [
    ['metric' => 'height', 'sex' => 'm', 'caption' => 'Height (cm)',      'marker' => 'Boys'],
    ['metric' => 'height', 'sex' => 'f', 'caption' => 'Height (cm)',      'marker' => 'Girls'],
    ['metric' => 'weight', 'sex' => 'm', 'caption' => 'Body weight (kg)', 'marker' => 'Boys'],
    ['metric' => 'weight', 'sex' => 'f', 'caption' => 'Body weight (kg)', 'marker' => 'Girls'],
    ['metric' => 'bmi',    'sex' => 'm', 'caption' => 'Body Mass Index',  'marker' => 'Boys'],
    ['metric' => 'bmi',    'sex' => 'f', 'caption' => 'Body Mass Index',  'marker' => 'Girls'],
    /* indexed by height in centimetres rather than by age */
    ['metric' => 'wfh',    'sex' => 'm', 'caption' => 'Weight-for-height (kg)', 'marker' => 'Boys'],
    ['metric' => 'wfh',    'sex' => 'f', 'caption' => 'Weight-for-height (kg)', 'marker' => 'Girls'],
];

$cav = [];
$worst = 0.0;
$worstWhere = '';
$canaryChecked = false;
$residuals = [];

foreach ($cavSpec as $spec) {
    $rows = parse_percentile_table($pages, $spec['caption'], $spec['marker']);

    /* prove the extraction against a value read by hand from the published PDF */
    if ($spec['metric'] === 'height' && $spec['sex'] === 'm') {
        $first = $rows[0];
        if (abs($first['age'] - CAV_CANARY['age']) > 1e-9 || $first['p'] !== CAV_CANARY['p']) {
            fwrite(STDERR, "FATAL: canary row mismatch. Expected age " . CAV_CANARY['age']
                . ' [' . implode(', ', CAV_CANARY['p']) . "]\n        got age {$first['age']} ["
                . implode(', ', $first['p']) . "]\n"
                . "        The PDF's font encoding or table layout has changed; fix the parser.\n");
            exit(1);
        }
        $canaryChecked = true;
    }

    $fitted = [];
    foreach ($rows as $row) {
        $fit = fit_lms($row['p']);
        $residuals[] = $fit['maxerr'];
        if ($fit['maxerr'] > $worst) {
            $worst = $fit['maxerr'];
            $worstWhere = "{$spec['metric']}/{$spec['sex']} at age {$row['age']}";
        }
        $fitted[] = [
            'age' => $row['age'],
            'l' => round($fit['l'], 6),
            'm' => round($fit['m'], 4),
            's' => round($fit['s'], 6),
        ];
    }
    $cav[$spec['metric']][$spec['sex']] = normalise_rows($fitted);
    fwrite(STDERR, sprintf("  %-6s %s: %2d age rows\n", $spec['metric'], $spec['sex'], count($fitted)));
}

if (!$canaryChecked) {
    fwrite(STDERR, "FATAL: canary row was never checked.\n");
    exit(1);
}
/* How good is good enough: SZU prints its percentile tables rounded to 0.1 cm
   and 0.1 kg, so the inputs already carry up to 0.05 of quantisation error. No
   smooth three-parameter curve can pass through seven independently rounded
   points more accurately than that noise floor, and a residual near it means
   the fit is limited by the published precision, not by the model. We flag only
   residuals beyond three times the rounding half-step, which would indicate a
   genuinely mis-shaped distribution or a parsing error. */
sort($residuals);
$mean = array_sum($residuals) / max(1, count($residuals));
$p95 = $residuals[(int)floor(0.95 * (count($residuals) - 1))];
fwrite(STDERR, sprintf(
    "  LMS fit residual: max %.4f (%s), p95 %.4f, mean %.4f over %d rows\n",
    $worst, $worstWhere, $p95, $mean, count($residuals)
));
fwrite(STDERR, sprintf("  (published tables are rounded to 0.1, so ~%.2f is the noise floor)\n", 0.05));
if ($worst > 0.15) {
    fwrite(STDERR, "WARNING: residual beyond 3x the source rounding - investigate before trusting these curves.\n");
}

export_reference(
    $outDir . '/cav.php',
    'cav',
    <<<TXT
 Czech national growth reference, derived from the 6th Nationwide
 Anthropological Survey published by Statni zdravotni ustav.

 Height  : CAV 2001.
 Weight  : CAV 1991. SZU deliberately kept the older weight tables, because
           refreshing them against a heavier population would push the
           overweight and obesity thresholds upward.

 Source  : {$_SERVER['SCRIPT_NAME']} parsing section 4.3 "Percentilove tabulky"
           of 6.CAV_4_Telesne_rozmery-tabulky.pdf. SZU publishes seven
           percentiles per age rather than LMS parameters, so L and S are
           least-squares fitted to those seven points with M fixed at the
           published median. Worst residual across every row of this build was
           {$worst} (cm for height, kg for weight), against a noise floor of
           0.05 imposed by SZU rounding its tables to one decimal place.

 GENERATED FILE - do not edit by hand. Re-run:
     php tools/build_reference_data.php
TXT,
    $cav
);

/* --- CDC ---------------------------------------------------------------- */

fwrite(STDERR, "CDC 2000 reference\n");
$cdc = [];
foreach (CDC_FILES as $metric => $sources) {
    $merged = ['m' => [], 'f' => []];
    foreach ($sources as [$url, $indexColumn, $divisor]) {
        $parsed = parse_cdc_csv(fetch_cached($url, $cacheDir), $indexColumn, $divisor);
        foreach (['m', 'f'] as $sex) {
            $merged[$sex] = array_merge($merged[$sex], $parsed[$sex]);
        }
    }
    foreach (['m', 'f'] as $sex) {
        $cdc[$metric][$sex] = normalise_rows($merged[$sex]);
        fwrite(STDERR, sprintf("  %-6s %s: %3d age rows\n", $metric, $sex, count($cdc[$metric][$sex])));
    }
}
export_reference(
    $outDir . '/cdc.php',
    'cdc',
    <<<TXT
 CDC 2000 growth charts for the United States, LMS parameters as published by
 the CDC. The birth-to-36-month and 2-to-20-year files are concatenated, with
 the infant rows winning where the two overlap.

 Source: cdc.gov/growthcharts/data/zscore/{lenageinf,statage,wtageinf,wtage}.csv

 GENERATED FILE - do not edit by hand. Re-run:
     php tools/build_reference_data.php
TXT,
    $cdc
);

/* --- WHO ---------------------------------------------------------------- */

fwrite(STDERR, "WHO reference\n");
$who = [];
foreach (WHO_FILES as $metric => $bySex) {
    foreach ($bySex as $sex => $sources) {
        $merged = [];
        foreach ($sources as [$url, $unit]) {
            $merged = array_merge($merged, parse_who_xlsx(fetch_cached($url, $cacheDir), $unit));
        }
        $who[$metric][$sex] = normalise_rows($merged);
        $rows = $who[$metric][$sex];
        fwrite(STDERR, sprintf(
            "  %-6s %s: %3d age rows (%.2f - %.2f y)\n",
            $metric, $sex, count($rows),
            $rows ? $rows[0]['age'] : 0,
            $rows ? $rows[count($rows) - 1]['age'] : 0
        ));
    }
}
export_reference(
    $outDir . '/who.php',
    'who',
    <<<TXT
 WHO growth standards and references, LMS parameters as published by WHO.
 Height blends the 0-5 y Child Growth Standards with the 5-19 y Growth
 Reference; weight blends 0-5 y with the 5-10 y reference.

 Note the weight ceiling: WHO deliberately does not publish weight-for-age
 beyond 10 years, because weight alone cannot separate height from mass through
 puberty. The application must present no weight curve above that age rather
 than extrapolating one.

 Source: cdn.who.int child-growth expandable tables (xlsx).

 GENERATED FILE - do not edit by hand. Re-run:
     php tools/build_reference_data.php
TXT,
    $who
);

/* --- Poland -------------------------------------------------------------- */

/* Included because it is one of very few national references outside WHO and
   CDC that is both openly licensed and publishes its LMS parameters in full,
   and because a neighbouring country with a similar population is a more
   meaningful comparison for a Czech child than the United States. The two
   papers cover 3-6 and 7-18 years; nothing below 3 was published this way. */

fwrite(STDERR, "Polish reference\n");
$preschool = parse_polish_preschool(fetch_cached(POLISH_PRESCHOOL_URL, $cacheDir));
$school = parse_polish_school(fetch_cached(POLISH_SCHOOL_URL, $cacheDir));

$pol = [];
foreach (['height', 'weight', 'bmi'] as $metric) {
    foreach (['m', 'f'] as $sex) {
        $merged = array_merge(
            $preschool[$metric][$sex] ?? [],
            $school[$metric][$sex] ?? []
        );
        $pol[$metric][$sex] = normalise_rows($merged);
        $rows = $pol[$metric][$sex];
        fwrite(STDERR, sprintf(
            "  %-6s %s: %2d age rows (%.1f - %.1f y)\n",
            $metric, $sex, count($rows),
            $rows ? $rows[0]['age'] : 0,
            $rows ? $rows[count($rows) - 1]['age'] : 0
        ));
    }
}

/* The two papers are separate studies, so the 6 -> 7 year join is the one place
   this reference could be discontinuous. Report the step at the seam rather
   than assume it is smooth. */
foreach (['height', 'weight', 'bmi'] as $metric) {
    foreach (['m', 'f'] as $sex) {
        $before = null;
        $after = null;
        foreach ($pol[$metric][$sex] as $row) {
            if ($row['age'] <= 6.0) { $before = $row; }
            if ($after === null && $row['age'] >= 7.0) { $after = $row; }
        }
        if ($before && $after) {
            fwrite(STDERR, sprintf(
                "  seam %s/%s: M %.1f at %.1f y -> %.1f at %.1f y (%+.1f over %.1f y)\n",
                $metric, $sex, $before['m'], $before['age'], $after['m'], $after['age'],
                $after['m'] - $before['m'], $after['age'] - $before['age']
            ));
        }
    }
}

export_reference(
    $outDir . '/pol.php',
    'pol',
    <<<TXT
 Polish national growth reference.

 Two open-access papers by Kulaga et al. in the European Journal of Pediatrics,
 both publishing their LMS parameters in full:

   3-6 y   Polish 2012 growth references for preschool children
           PMC3663205 - Creative Commons Attribution (CC BY)
   7-18 y  Polish 2010 growth references for school-aged children and
           adolescents
           PMC3078309 - Creative Commons Attribution-NonCommercial (CC BY-NC)

 Both licences require attribution, which is why every reference carries a
 "source" string that the page footer prints. The non-commercial clause on the
 school-age tables is why this belongs in a private family tool and must not be
 reused on anything commercial.

 Nothing below 3 years exists in this form, so the application shows no Polish
 curve for infants rather than extrapolating one.

 GENERATED FILE - do not edit by hand. Re-run:
     php tools/build_reference_data.php
TXT,
    $pol
);

/* --- Czech breastfed infants --------------------------------------------- */

/* Breastfed infants gain differently - faster to about three months, slower
   after - so judging them against a mixed-feeding reference invites
   unnecessary supplementation at 2-3 months and early solids before 6. SZU
   publishes a reference for them, but only as charts, so the curves are read
   back off the drawing. See digitise_breastfed_chart() for why that is
   defensible: every chart also carries the seven CAV curves, which we hold
   authoritatively, so the calibration proves itself. */

fwrite(STDERR, "Czech breastfed-infant reference\n");

/* The CAV values the charts are checked against, taken at CAV's own published
   ages so the check involves no interpolation of the reference at all. */
$cavByMonth = [];
foreach (['height', 'weight'] as $metric) {
    foreach (['m', 'f'] as $sex) {
        foreach ($cav[$metric][$sex] as $row) {
            if ($row['age'] > 1.0001) {
                continue;
            }
            $month = round($row['age'] * 12.0, 4);
            $values = [];
            foreach (PCT_Z as $z) {
                $values[] = lms_value($row['l'], $row['m'], $row['s'], $z);
            }
            $cavByMonth[$metric][$sex][(string)$month] = $values;
        }
    }
}

$koj = [];
$worstCalibration = 0.0;
foreach (BREASTFED_CHARTS as $metric => $bySex) {
    foreach ($bySex as $sex => $url) {
        $chart = digitise_breastfed_chart(
            fetch_cached($url, $cacheDir),
            $cavByMonth[$metric][$sex]
        );
        $worstCalibration = max($worstCalibration, $chart['cav_error']);

        $rows = [];
        $worstFit = 0.0;
        foreach ($chart['rows'] as $row) {
            /* three published percentiles, two free parameters - determined */
            $fit = fit_lms_pairs([
                [PCT_Z[0], $row['p3']],
                [PCT_Z[3], $row['p50']],
                [PCT_Z[6], $row['p97']],
            ], $row['p50']);
            $worstFit = max($worstFit, $fit['maxerr']);
            $rows[] = [
                'age' => round($row['month'] / 12.0, 6),
                'l' => round($fit['l'], 6),
                'm' => round($fit['m'], 4),
                's' => round($fit['s'], 6),
            ];
        }
        $koj[$metric][$sex] = normalise_rows($rows);
        fwrite(STDERR, sprintf(
            "  %-6s %s: %2d months, CAV calibration check %.3f, LMS fit %.3f\n",
            $metric, $sex, count($rows), $chart['cav_error'], $worstFit
        ));
    }
}
fwrite(STDERR, sprintf(
    "  worst CAV calibration error across all four charts: %.3f\n", $worstCalibration
));
if ($worstCalibration > 0.15) {
    fwrite(STDERR, "FATAL: the digitised CAV curves do not match the published table.\n"
        . "       The axis calibration is wrong; the breastfed values cannot be trusted.\n");
    exit(1);
}

export_reference(
    $outDir . '/koj.php',
    'koj',
    <<<TXT
 Czech reference for breastfed infants, birth to one year.

 SZU publishes this one only as charts - unlike the CAV tables there is no
 numeric version anywhere, and the Charles University thesis on the subject has
 none either. The curves here were therefore read back off the published
 drawings.

 That is only defensible because each chart also plots the seven CAV percentile
 curves alongside the three breastfed ones, and CAV we hold authoritatively.
 Digitising the CAV curves and comparing them with the published table proves
 the axis calibration; across all four charts the worst disagreement was
 {$worstCalibration}, which is the rounding of the published table itself. The
 build fails if that check ever exceeds 0.15.

 SZU draws only the 3rd, 50th and 97th percentiles for breastfed infants. M is
 the published median and L and S are fitted to the outer two, so the
 intermediate percentiles the application draws are implied by the LMS model
 rather than published. That is what LMS is for, but it is worth knowing they
 are not independent measurements.

 GENERATED FILE - do not edit by hand. Re-run:
     php tools/build_reference_data.php
TXT,
    $koj
);

fwrite(STDERR, "done.\n");
