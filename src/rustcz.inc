<?php

/**
 * Reading a RustCZ backup, as a library.
 *
 * RustCZ's backup writes two files side by side, and between them they hold
 * everything this application needs. Their names are the program's, not
 * anybody's: PEDA is the people, MEDA the measurements.
 *
 *   peda.rcz   one 397-byte record per child: names, sex, date of birth, the
 *              parents' heights, and a 16-byte GUID
 *   meda.rcz   one 68-byte record per measurement: the same GUID, a date, a
 *              height and a weight
 *
 * The GUID is the whole point. It says which measurements belong to which
 * child, exactly, which is why this can be an upload form rather than the
 * guessing game the project expected. tools/import_rustcz.php had to pair the
 * two by counting how many measurement dates lined up with the dates printed
 * in each child's separate text export, score the result, and ask a person to
 * judge it. None of that is needed once peda.rcz is read: the answer is in
 * the file.
 *
 * How the layout was established: both files were read against the text
 * exports RustCZ prints for the same children, field by field - name, sex,
 * date of birth, birth length and weight, both parents' years, heights and
 * weights - and every one of them matches. The measurement counts recorded
 * per child (rel 379) sum to exactly the number of records in meda.rcz, and
 * each child's GUID appears there and nowhere else.
 *
 * On the legality of reading it, which is the question that stalled this: a
 * file format is not itself copyrightable - what a licence protects is the
 * expression in a program, not the layout of the bytes it writes. This layout
 * was inferred from files rather than by decompiling anything, and even that
 * is expressly permitted for interoperability by the EU Software Directive
 * (2009/24/EC, Article 6) and its Czech implementation (Act No. 121/2000 Sb.,
 * § 66). The data in somebody's own backup is their own. What would be a
 * different question entirely is RustCZ's reference curves, and none of them
 * are here.
 *
 * Everything below treats the file as hostile. It arrives from an upload
 * form, so a length byte says whatever it likes, a float can be NaN, and a
 * date can be the year 30000. Nothing is trusted; a record that cannot be
 * read is reported and skipped rather than guessed at.
 */

require_once __DIR__ . '/i18n.inc';

/** One measurement: 68 bytes, no header, so filesize/68 is the record count. */
const GROWTH_RCZ_MEASUREMENT_SIZE = 68;

/** One child: 397 bytes, likewise a flat array with no header. */
const GROWTH_RCZ_CHILD_SIZE = 397;

/* Delphi counts days from 1899-12-30; Unix from 1970-01-01, 25569 days later. */
const GROWTH_RCZ_EPOCH_OFFSET_DAYS = 25569;

/* Field offsets within a child record. Everything between the parents' block
   and the measurement count is uninitialised memory - heap pointers, mostly,
   which differ between two records written seconds apart - and is read by
   nothing here. */
const GROWTH_RCZ_CHILD_SURNAME = 1;         /* ShortString, capacity 30 */
const GROWTH_RCZ_CHILD_GIVEN_NAME = 32;     /* ShortString, capacity 20 */
const GROWTH_RCZ_CHILD_SEX = 53;            /* uint8: 0 boy, 1 girl */
const GROWTH_RCZ_CHILD_BIRTH_DATE = 54;     /* float64 TDateTime */
const GROWTH_RCZ_CHILD_BIRTH_LENGTH = 62;   /* uint16, tenths of a cm */
const GROWTH_RCZ_CHILD_BIRTH_WEIGHT = 64;   /* float32, kg */
const GROWTH_RCZ_CHILD_FATHER = 68;         /* uint16 year, uint16 cm, float32 kg */
const GROWTH_RCZ_CHILD_MOTHER = 76;         /* the same three, again */
const GROWTH_RCZ_CHILD_COUNT = 379;         /* uint16: how many measurements */
const GROWTH_RCZ_CHILD_GUID = 381;          /* 16 bytes, keying meda.rcz */

/* Field offsets within a measurement record. Note the mixed widths: the date
   is a double and the two values are singles. Reading the values as doubles
   yields plausible-looking numbers rather than an obvious error, which is the
   trap this format sets. */
const GROWTH_RCZ_M_GUID = 0;
const GROWTH_RCZ_M_DATE = 16;               /* float64 TDateTime */
const GROWTH_RCZ_M_HEIGHT = 24;             /* float32 cm, 0.0 when not measured */
const GROWTH_RCZ_M_WEIGHT = 28;             /* float32 kg, 0.0 when not measured */

/**
 * Windows-1250 to UTF-8, without depending on iconv or mbstring.
 *
 * Both are usually present and neither is guaranteed on the shared hosting
 * this application is written for, and a name that silently loses its
 * diacritics is a poor greeting for somebody importing their own children.
 * The single-byte half is ASCII; this is the other half.
 */
function growth_rustcz_win1250(string $bytes): string
{
    static $map = array(
        0x80 => "€", 0x82 => "‚", 0x84 => "„", 0x85 => "…", 0x86 => "†", 0x87 => "‡",
        0x89 => "‰", 0x8A => "Š", 0x8B => "‹", 0x8C => "Ś", 0x8D => "Ť", 0x8E => "Ž",
        0x8F => "Ź", 0x91 => "‘", 0x92 => "’", 0x93 => "“", 0x94 => "”", 0x95 => "•",
        0x96 => "–", 0x97 => "—", 0x99 => "™", 0x9A => "š", 0x9B => "›", 0x9C => "ś",
        0x9D => "ť", 0x9E => "ž", 0x9F => "ź", 0xA0 => " ", 0xA1 => "ˇ", 0xA2 => "˘",
        0xA3 => "Ł", 0xA4 => "¤", 0xA5 => "Ą", 0xA6 => "¦", 0xA7 => "§", 0xA8 => "¨",
        0xA9 => "©", 0xAA => "Ş", 0xAB => "«", 0xAC => "¬", 0xAD => "­", 0xAE => "®",
        0xAF => "Ż", 0xB0 => "°", 0xB1 => "±", 0xB2 => "˛", 0xB3 => "ł", 0xB4 => "´",
        0xB5 => "µ", 0xB6 => "¶", 0xB7 => "·", 0xB8 => "¸", 0xB9 => "ą", 0xBA => "ş",
        0xBB => "»", 0xBC => "Ľ", 0xBD => "˝", 0xBE => "ľ", 0xBF => "ż", 0xC0 => "Ŕ",
        0xC1 => "Á", 0xC2 => "Â", 0xC3 => "Ă", 0xC4 => "Ä", 0xC5 => "Ĺ", 0xC6 => "Ć",
        0xC7 => "Ç", 0xC8 => "Č", 0xC9 => "É", 0xCA => "Ę", 0xCB => "Ë", 0xCC => "Ě",
        0xCD => "Í", 0xCE => "Î", 0xCF => "Ď", 0xD0 => "Đ", 0xD1 => "Ń", 0xD2 => "Ň",
        0xD3 => "Ó", 0xD4 => "Ô", 0xD5 => "Ő", 0xD6 => "Ö", 0xD7 => "×", 0xD8 => "Ř",
        0xD9 => "Ů", 0xDA => "Ú", 0xDB => "Ű", 0xDC => "Ü", 0xDD => "Ý", 0xDE => "Ţ",
        0xDF => "ß", 0xE0 => "ŕ", 0xE1 => "á", 0xE2 => "â", 0xE3 => "ă", 0xE4 => "ä",
        0xE5 => "ĺ", 0xE6 => "ć", 0xE7 => "ç", 0xE8 => "č", 0xE9 => "é", 0xEA => "ę",
        0xEB => "ë", 0xEC => "ě", 0xED => "í", 0xEE => "î", 0xEF => "ď", 0xF0 => "đ",
        0xF1 => "ń", 0xF2 => "ň", 0xF3 => "ó", 0xF4 => "ô", 0xF5 => "ő", 0xF6 => "ö",
        0xF7 => "÷", 0xF8 => "ř", 0xF9 => "ů", 0xFA => "ú", 0xFB => "ű", 0xFC => "ü",
        0xFD => "ý", 0xFE => "ţ", 0xFF => "˙",
    );
    $out = '';
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
        $byte = ord($bytes[$i]);
        if ($byte < 0x80) {
            $out .= $bytes[$i];
        } elseif (isset($map[$byte])) {
            $out .= $map[$byte];
        }
        /* An unassigned byte is dropped rather than turned into a replacement
           character: it cannot be part of a name, and U+FFFD in a name field
           only looks like the application's own fault. */
    }
    return $out;
}

/**
 * A Delphi ShortString - one length byte, then that many bytes of text - read
 * from a fixed-capacity field, decoded and cleaned.
 *
 * The length byte is not believed past the end of its own field: whatever
 * follows a name in the record is uninitialised memory, and a corrupt or
 * hostile length would otherwise read it out and put it on screen.
 */
function growth_rustcz_string(string $record, int $offset, int $capacity): string
{
    $length = ord($record[$offset]);
    if ($length > $capacity) {
        /* Clamping to the capacity would be the obvious thing and is the
           wrong one: it hands back a field's worth of uninitialised memory
           dressed as a name. A length byte that overruns its own field means
           the field cannot be read, so nothing is what it should return. */
        return '';
    }
    $text = growth_rustcz_win1250(substr($record, $offset + 1, $length));
    /* Control characters have no business in a name and are how a binary
       field ends up looking like a broken page. */
    $text = preg_replace('~[\x00-\x1F\x7F]~u', '', $text);
    return trim((string)$text);
}

/**
 * A Delphi TDateTime as YYYY-MM-DD, or null when it is not a date anybody
 * could have been born or measured on.
 *
 * The range is deliberately narrow. Uninitialised memory read as a float64
 * is overwhelmingly likely to land outside 1900-2100 - it is usually a
 * pointer, which is an enormous number, or zero - so this is most of what
 * separates a record from the noise around it.
 */
function growth_rustcz_date(string $record, int $offset)
{
    $serial = unpack('e', substr($record, $offset, 8))[1];
    if (!is_finite($serial) || $serial < 1.0 || $serial > 80000.0) {
        return null;
    }
    $timestamp = ($serial - GROWTH_RCZ_EPOCH_OFFSET_DAYS) * 86400;
    $date = gmdate('Y-m-d', (int)round($timestamp));
    /* Belt and braces: gmdate() on a bounded serial cannot produce anything
       else, and a parser is the wrong place to find that out. */
    return preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) ? $date : null;
}

/** A float32 that has to be a real measurement, or null. */
function growth_rustcz_number(string $record, int $offset, float $min, float $max)
{
    $value = unpack('g', substr($record, $offset, 4))[1];
    if (!is_finite($value) || $value < $min || $value > $max) {
        return null;
    }
    return $value;
}

/** A uint16 within a plausible range, or null. */
function growth_rustcz_int(string $record, int $offset, int $min, int $max)
{
    $value = unpack('v', substr($record, $offset, 2))[1];
    return ($value >= $min && $value <= $max) ? $value : null;
}

/**
 * Reads peda.rcz into a list of children, each keyed by its GUID.
 *
 * Returns ['children' => [...], 'errors' => [...]]. A record that cannot be
 * read is reported by its position and left out; one unreadable child in a
 * file of six should not cost somebody the other five.
 */
function growth_rustcz_children(string $bytes): array
{
    $result = array('children' => array(), 'errors' => array());

    if (strlen($bytes) === 0 || strlen($bytes) % GROWTH_RCZ_CHILD_SIZE !== 0) {
        $result['errors'][] = t('rcz_error_not_child_file', array(
            'bytes' => strlen($bytes), 'size' => GROWTH_RCZ_CHILD_SIZE));
        return $result;
    }

    $count = intdiv(strlen($bytes), GROWTH_RCZ_CHILD_SIZE);
    for ($i = 0; $i < $count; $i++) {
        $record = substr($bytes, $i * GROWTH_RCZ_CHILD_SIZE, GROWTH_RCZ_CHILD_SIZE);

        $born = growth_rustcz_date($record, GROWTH_RCZ_CHILD_BIRTH_DATE);
        $sexByte = ord($record[GROWTH_RCZ_CHILD_SEX]);
        $surname = growth_rustcz_string($record, GROWTH_RCZ_CHILD_SURNAME, 30);
        $given = growth_rustcz_string($record, GROWTH_RCZ_CHILD_GIVEN_NAME, 20);

        /* A date of birth and a sex are what this application cannot do
           without: they choose the reference curves and the age every point
           is plotted at. A name it could invent; those it cannot. */
        if ($born === null || ($sexByte !== 0 && $sexByte !== 1)) {
            $result['errors'][] = t('rcz_error_bad_child_record', array('n' => $i + 1));
            continue;
        }

        $name = trim($given . ' ' . $surname);
        if ($name === '') {
            $name = t('rcz_unnamed_child', array('n' => $i + 1));
        }

        $result['children'][] = array(
            'guid' => bin2hex(substr($record, GROWTH_RCZ_CHILD_GUID, 16)),
            'name' => $name,
            'sex' => ($sexByte === 1) ? 'f' : 'm',
            'birth_date' => $born,
            /* A height of zero is RustCZ for "not filled in", and a parent
               shorter than a metre or taller than two and a half is a field
               that was never a height. Either way it becomes NULL, which is
               what the adult-height projection needs to see to leave it out
               rather than average a nonsense in. */
            'father_cm' => growth_rustcz_int($record, GROWTH_RCZ_CHILD_FATHER + 2, 100, 250),
            'mother_cm' => growth_rustcz_int($record, GROWTH_RCZ_CHILD_MOTHER + 2, 100, 250),
            /* Stated by the file rather than counted from the other one, so
               the two can be compared - see growth_rustcz_pair(). */
            'stated_measurements' => unpack('v', substr($record, GROWTH_RCZ_CHILD_COUNT, 2))[1],
            'birth_length_cm' => (function ($cm) {
                return ($cm === null) ? null : $cm / 10.0;
            })(growth_rustcz_int($record, GROWTH_RCZ_CHILD_BIRTH_LENGTH, 200, 700)),
            'birth_weight_kg' => (function ($kg) {
                return ($kg === null) ? null : round($kg, 2);
            })(growth_rustcz_number($record, GROWTH_RCZ_CHILD_BIRTH_WEIGHT, 0.3, 8.0)),
        );
    }
    return $result;
}

/**
 * Reads meda.rcz into measurements grouped by child GUID, each group sorted
 * by date.
 */
function growth_rustcz_measurements(string $bytes): array
{
    $result = array('groups' => array(), 'errors' => array(), 'skipped' => 0);

    if (strlen($bytes) === 0 || strlen($bytes) % GROWTH_RCZ_MEASUREMENT_SIZE !== 0) {
        $result['errors'][] = t('rcz_error_not_measurement_file', array(
            'bytes' => strlen($bytes), 'size' => GROWTH_RCZ_MEASUREMENT_SIZE));
        return $result;
    }

    $count = intdiv(strlen($bytes), GROWTH_RCZ_MEASUREMENT_SIZE);
    for ($i = 0; $i < $count; $i++) {
        $record = substr($bytes, $i * GROWTH_RCZ_MEASUREMENT_SIZE, GROWTH_RCZ_MEASUREMENT_SIZE);
        $date = growth_rustcz_date($record, GROWTH_RCZ_M_DATE);
        if ($date === null) {
            $result['skipped']++;
            continue;
        }
        $guid = bin2hex(substr($record, GROWTH_RCZ_M_GUID, 16));
        /* 0.0 means "not measured" and must stay empty all the way into the
           database; a stored zero would be read back as a real measurement of
           0 cm and would wreck every chart built on it. */
        $height = growth_rustcz_number($record, GROWTH_RCZ_M_HEIGHT, 20.0, 260.0);
        $weight = growth_rustcz_number($record, GROWTH_RCZ_M_WEIGHT, 0.3, 400.0);
        $result['groups'][$guid][] = array(
            'date' => $date,
            /* Rounded to the precision the value was actually taken at, and
               to the precision the MySQL columns hold - decimal(4,1) and
               decimal(5,2). These are float32 in the file, so 122.2 cm comes
               out of it as 122.19999694824, and storing that would put the
               noise in the JSON and SQLite backends while MySQL rounded it
               away: three backends disagreeing about the same import. */
            'height_cm' => ($height === null) ? null : round($height, 1),
            'weight_kg' => ($weight === null) ? null : round($weight, 2),
        );
    }
    foreach ($result['groups'] as $guid => $rows) {
        usort($rows, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        $result['groups'][$guid] = $rows;
    }
    return $result;
}

/**
 * Puts the two files together into what the confirmation screen shows and
 * the import then writes.
 *
 * Nothing is guessed. Each child takes the measurements carrying its own
 * GUID; a group in meda.rcz that no child claims is reported rather than
 * quietly dropped, because it is the one sign that the two files did not come
 * from the same backup.
 */
function growth_rustcz_pair(array $children, array $groups): array
{
    $paired = array();
    $claimed = array();

    foreach ($children as $child) {
        $guid = $child['guid'];
        $rows = isset($groups[$guid]) ? $groups[$guid] : array();
        $claimed[$guid] = true;
        $child['measurements'] = $rows;
        $child['first_date'] = $rows ? $rows[0]['date'] : null;
        $child['last_date'] = $rows ? $rows[count($rows) - 1]['date'] : null;
        $paired[] = $child;
    }

    $orphans = 0;
    foreach ($groups as $guid => $rows) {
        if (!isset($claimed[$guid])) {
            $orphans += count($rows);
        }
    }
    return array('children' => $paired, 'orphan_measurements' => $orphans);
}
