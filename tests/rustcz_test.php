<?php

/**
 * Reading a RustCZ backup.
 *
 * Every fixture here is built from the documented layout rather than taken
 * from a real file, and not only for tidiness: a real .rcz holds a real
 * child's name, date of birth and measurements, and there is no version of
 * committing one to a public repository that is acceptable.
 *
 * Building the fixtures from the layout has a second use. If the layout in
 * rustcz.inc is ever wrong, these tests are wrong in exactly the same way and
 * would pass regardless - so what they actually guard is not the layout but
 * the behaviour around it: what happens to a truncated file, a hostile length
 * byte, a date out of any plausible range, a measurement belonging to nobody.
 * The layout itself was established against real files and their text
 * exports, field by field, and that check cannot live here.
 */

require_once __DIR__ . '/../src/rustcz.inc';

/** A GUID as 16 raw bytes, from a readable seed. */
function growth_test_guid($seed)
{
    return substr(hash('sha256', (string)$seed, true), 0, 16);
}

/** One 397-byte child record, built the way RustCZ writes one. */
function growth_test_child_record($given, $surname, $sex, $born, $fatherCm, $motherCm, $count, $guid)
{
    /* The gaps are filled with 0xAA rather than zeros: the real file carries
       uninitialised memory there, and a parser that happens to work only
       because the padding is zero would pass a test on zeros and fail on a
       real backup. */
    $r = str_repeat("\xAA", GROWTH_RCZ_CHILD_SIZE);
    $put = function ($offset, $bytes) use (&$r) {
        $r = substr_replace($r, $bytes, $offset, strlen($bytes));
    };
    $put(0, "\x00");
    $put(GROWTH_RCZ_CHILD_SURNAME, chr(strlen($surname)) . $surname);
    $put(GROWTH_RCZ_CHILD_GIVEN_NAME, chr(strlen($given)) . $given);
    $put(GROWTH_RCZ_CHILD_SEX, chr($sex === 'f' ? 1 : 0));
    $put(GROWTH_RCZ_CHILD_BIRTH_DATE, pack('e', growth_test_delphi_days($born)));
    $put(GROWTH_RCZ_CHILD_BIRTH_LENGTH, pack('v', 500));
    $put(GROWTH_RCZ_CHILD_BIRTH_WEIGHT, pack('g', 3.4));
    $put(GROWTH_RCZ_CHILD_FATHER, pack('v', 1980) . pack('v', $fatherCm) . pack('g', 80.0));
    $put(GROWTH_RCZ_CHILD_MOTHER, pack('v', 1982) . pack('v', $motherCm) . pack('g', 65.0));
    $put(GROWTH_RCZ_CHILD_COUNT, pack('v', $count));
    $put(GROWTH_RCZ_CHILD_GUID, $guid);
    return $r;
}

/** One 68-byte measurement record. */
function growth_test_measurement_record($guid, $date, $heightCm, $weightKg)
{
    $r = str_repeat("\x00", GROWTH_RCZ_MEASUREMENT_SIZE);
    $r = substr_replace($r, $guid, GROWTH_RCZ_M_GUID, 16);
    $r = substr_replace($r, pack('e', growth_test_delphi_days($date)), GROWTH_RCZ_M_DATE, 8);
    $r = substr_replace($r, pack('g', $heightCm), GROWTH_RCZ_M_HEIGHT, 4);
    $r = substr_replace($r, pack('g', $weightKg), GROWTH_RCZ_M_WEIGHT, 4);
    return $r;
}

function growth_test_delphi_days($date)
{
    return (float)(strtotime($date . ' UTC') / 86400) + GROWTH_RCZ_EPOCH_OFFSET_DAYS;
}

function test_rustcz_reads_a_child_record()
{
    $guid = growth_test_guid('a');
    $bytes = growth_test_child_record('Marie', 'Novotna', 'f', '2019-04-11', 181, 167, 3, $guid);
    $read = growth_rustcz_children($bytes);

    assert_equals(0, count($read['errors']), 'no errors');
    assert_equals(1, count($read['children']), 'one child');
    $child = $read['children'][0];
    assert_equals('Marie Novotna', $child['name'], 'given name then surname');
    assert_equals('f', $child['sex'], 'sex');
    assert_equals('2019-04-11', $child['birth_date'], 'date of birth');
    assert_equals(181, $child['father_cm'], "father's height");
    assert_equals(167, $child['mother_cm'], "mother's height");
    assert_equals(3, $child['stated_measurements'], 'the count the file states');
    assert_equals(bin2hex($guid), $child['guid'], 'the GUID');
}

function test_rustcz_decodes_czech_names_without_iconv()
{
    /* Windows-1250: e1 is a-acute, f8 r-caron, 9a s-caron. Shared hosting
       cannot be relied on for iconv or mbstring, and a name that arrives
       stripped of its diacritics is a poor greeting. */
    $bytes = growth_test_child_record("R\xf9\x9eena", "K\xf8\xed\x9eov\xe1", 'f',
        '2019-04-11', 180, 165, 0, growth_test_guid('b'));
    $child = growth_rustcz_children($bytes)['children'][0];
    assert_equals('Růžena Křížová', $child['name'], 'diacritics survive');
}

function test_rustcz_refuses_a_file_that_is_not_a_whole_number_of_records()
{
    $bytes = substr(growth_test_child_record('A', 'B', 'm', '2019-04-11', 180, 165, 0,
        growth_test_guid('c')), 0, 300);
    $read = growth_rustcz_children($bytes);
    assert_equals(0, count($read['children']), 'nothing read');
    assert_true(count($read['errors']) > 0, 'and it says why');

    $read = growth_rustcz_measurements(str_repeat("\x00", 100));
    assert_equals(0, count($read['groups']), 'the same for measurements');
    assert_true(count($read['errors']) > 0, 'and it says why');
}

function test_rustcz_does_not_believe_a_hostile_length_byte()
{
    /* The file arrives from an upload form. A length byte claiming 200
       characters in a 30-byte field would otherwise read the record's
       uninitialised memory out and put it on screen. */
    $bytes = growth_test_child_record('Ann', 'Lee', 'm', '2019-04-11', 180, 165, 0,
        growth_test_guid('d'));
    $bytes[GROWTH_RCZ_CHILD_SURNAME] = chr(200);
    $child = growth_rustcz_children($bytes)['children'][0];
    assert_true(strlen($child['name']) < 60, 'the name stayed a name');
    assert_true(strpos($child['name'], "\xAA") === false, 'no padding leaked into it');
}

function test_rustcz_skips_a_child_it_cannot_identify()
{
    /* A date of birth and a sex choose the reference curves and the age every
       point is plotted at. A record without them is not importable, and
       inventing either would produce a chart that looks entirely plausible
       and is wrong. */
    $good = growth_test_child_record('Real', 'Child', 'm', '2019-04-11', 180, 165, 0,
        growth_test_guid('e'));
    $bad = growth_test_child_record('Broken', 'Child', 'm', '2019-04-11', 180, 165, 0,
        growth_test_guid('f'));
    $bad = substr_replace($bad, pack('e', 9.9e18), GROWTH_RCZ_CHILD_BIRTH_DATE, 8);

    $read = growth_rustcz_children($good . $bad);
    assert_equals(1, count($read['children']), 'the readable child came through');
    assert_equals('Real Child', $read['children'][0]['name'], 'and it is the right one');
    assert_equals(1, count($read['errors']), 'the other is reported, not silently dropped');
}

function test_rustcz_reads_measurements_and_groups_them_by_child()
{
    $one = growth_test_guid('one');
    $two = growth_test_guid('two');
    $bytes = growth_test_measurement_record($one, '2020-03-02', 90.0, 13.5)
           . growth_test_measurement_record($two, '2020-03-03', 120.0, 24.0)
           . growth_test_measurement_record($one, '2020-01-01', 88.0, 13.0);

    $read = growth_rustcz_measurements($bytes);
    assert_equals(2, count($read['groups']), 'two children');
    $rows = $read['groups'][bin2hex($one)];
    assert_equals(2, count($rows), 'two measurements for the first');
    assert_equals('2020-01-01', $rows[0]['date'], 'sorted by date, not by file order');
    assert_close(88.0, $rows[0]['height_cm'], 1e-4, 'height');
}

function test_rustcz_treats_zero_as_not_measured()
{
    /* A stored zero would be read back as a real measurement of 0 cm and
       would wreck every chart and trend built on it. */
    $guid = growth_test_guid('z');
    $read = growth_rustcz_measurements(growth_test_measurement_record($guid, '2020-03-02', 0.0, 13.5));
    $row = $read['groups'][bin2hex($guid)][0];
    assert_null($row['height_cm'], 'no height');
    assert_close(13.5, $row['weight_kg'], 1e-4, 'but the weight is real');
}

function test_rustcz_pairs_by_guid_without_guessing()
{
    $guid = growth_test_guid('paired');
    $children = growth_rustcz_children(
        growth_test_child_record('Eva', 'Nova', 'f', '2019-04-11', 180, 165, 2, $guid))['children'];
    $groups = growth_rustcz_measurements(
        growth_test_measurement_record($guid, '2020-03-02', 90.0, 13.5)
        . growth_test_measurement_record($guid, '2021-03-02', 99.0, 15.5))['groups'];

    $paired = growth_rustcz_pair($children, $groups);
    assert_equals(2, count($paired['children'][0]['measurements']), 'both measurements landed');
    assert_equals('2020-03-02', $paired['children'][0]['first_date'], 'first date');
    assert_equals('2021-03-02', $paired['children'][0]['last_date'], 'last date');
    assert_equals(0, $paired['orphan_measurements'], 'nothing left over');
}

function test_rustcz_reports_measurements_belonging_to_nobody()
{
    /* The one sign that the two files came from different backups, and the
       only thing here that a person needs to be told rather than shown. */
    $children = growth_rustcz_children(
        growth_test_child_record('Eva', 'Nova', 'f', '2019-04-11', 180, 165, 0,
            growth_test_guid('mine')))['children'];
    $groups = growth_rustcz_measurements(
        growth_test_measurement_record(growth_test_guid('somebody else'), '2020-03-02', 90.0, 13.5))['groups'];

    $paired = growth_rustcz_pair($children, $groups);
    assert_equals(0, count($paired['children'][0]['measurements']), 'the child got none');
    assert_equals(1, $paired['orphan_measurements'], 'and the stray one is reported');
}
