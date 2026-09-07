<?php

/**
 * The reference build's download check.
 *
 * The failure this guards against is not a failed download - that was always
 * caught - but a successful one carrying the wrong thing: cdc.gov's bot
 * filter answering with a 403 page, a consent screen, a login redirect, all
 * of them valid HTML with a 200 status. Before this check they were cached
 * and parsed as if they were data, and the only symptom was a parse that
 * found nothing.
 *
 * src/reference_build.inc declares functions and constants only, so requiring
 * it here downloads nothing.
 */

require_once __DIR__ . '/../src/reference_build.inc';

function test_download_accepts_the_real_thing()
{
    assert_equals('', growth_download_complaint('https://szu.gov.cz/x.pdf', "%PDF-1.7\nstuff", 900000),
        'a PDF');
    assert_equals('', growth_download_complaint('https://cdn.who.int/x.xlsx', "PK\x03\x04\x14\x00", 90000),
        'a spreadsheet');
    assert_equals('', growth_download_complaint('https://www.cdc.gov/x.csv', "Sex,Agemos,L,M,S\n1,0,1,2,3\n", 50000),
        'a CSV table');
    assert_equals('', growth_download_complaint('https://pmc.ncbi.nlm.nih.gov/articles/PMC3663205/',
        "<!DOCTYPE html><html><body><table>", 90000), 'an article page, which is HTML by design');
}

function test_download_refuses_a_web_page_where_a_file_was_promised()
{
    $block = "<!DOCTYPE html><html><head><title>Access Denied</title>";
    foreach (array('https://szu.gov.cz/x.pdf',
                   'https://cdn.who.int/x.xlsx',
                   'https://www.cdc.gov/x.csv') as $url) {
        $complaint = growth_download_complaint($url, $block, 400000);
        assert_true($complaint !== '', "a block page is refused for $url");
        assert_true(strpos($complaint, 'web page') !== false, 'and says it was a web page');
    }
}

function test_download_refuses_something_truncated()
{
    $complaint = growth_download_complaint('https://szu.gov.cz/x.pdf', "%PDF-1.7", 900);
    assert_true($complaint !== '', 'a 900-byte PDF is not one of these PDFs');
    assert_true(strpos($complaint, 'too small') !== false, 'and says so');
}

function test_download_refuses_a_file_that_is_not_the_type_it_claims()
{
    /* Not HTML, just wrong - a redirect body, a stray text file, an .xlsx
       that is really a .xls. There is nothing helpful to say about it beyond
       that it is not what the URL promised. */
    $complaint = growth_download_complaint('https://cdn.who.int/x.xlsx', "\xD0\xCF\x11\xE0 old xls", 90000);
    assert_true($complaint !== '', 'refused');
    assert_true(strpos($complaint, 'spreadsheet') !== false, 'names what was expected');
}

function test_download_shape_is_derived_from_every_url_the_build_uses()
{
    /* The expectation comes from the URL rather than a table beside it, so
       that a source cannot be added without one. This holds that promise for
       the URLs actually in the file. */
    $source = (string)file_get_contents(__DIR__ . '/../src/reference_build.inc');
    preg_match_all('~https://[^\'"\s]+~', $source, $matches);
    assert_true(count($matches[0]) > 20, 'the build really does list its sources here');
    foreach ($matches[0] as $url) {
        if (strpos($url, 'github.com') !== false) {
            continue;   /* the user-agent contact URL, not a source */
        }
        list($label, $magic, $minBytes, $htmlIsFine) = growth_download_shape($url);
        assert_true($minBytes > 0, "a size floor for $url");
    }
}
