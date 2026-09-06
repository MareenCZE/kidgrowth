<?php

/**
 * Internationalization: locale selection, the t() lookup helper, and the
 * per-user unit preference (metric/imperial - see growth_units() and units.inc).
 *
 * Deliberately simple mechanics rather than importing gettext: a flat
 * array('key' => 'text') per language, and a lookup with a fallback to
 * English and then to the key itself, so a missing translation degrades to
 * readable English rather than a blank space.
 *
 * English is the default: the Czech reference data is this project's
 * differentiator, but Czech-only text would make it unusable for the
 * WHO/CDC audience the international references serve.
 */

const GROWTH_LOCALES = array('en', 'cs');
const GROWTH_DEFAULT_LOCALE = 'en';

/**
 * The active locale: an explicit ?lang= switch (persisted to a cookie for
 * the rest of the visit), else a previously-set cookie, else a guess from
 * the browser's Accept-Language header, else the default.
 */
function growth_locale()
{
    static $locale = null;
    if ($locale !== null) {
        return $locale;
    }

    if (isset($_GET['lang']) && in_array($_GET['lang'], GROWTH_LOCALES, true)) {
        $locale = $_GET['lang'];
        growth_set_preference_cookie('growth_lang', $locale);
        return $locale;
    }
    if (isset($_COOKIE['growth_lang']) && in_array($_COOKIE['growth_lang'], GROWTH_LOCALES, true)) {
        $locale = $_COOKIE['growth_lang'];
        return $locale;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
    if (preg_match('~(^|[, ])cs\b~', $accept)) {
        $locale = 'cs';
        return $locale;
    }
    $locale = GROWTH_DEFAULT_LOCALE;
    return $locale;
}

/** Shared by growth_locale() and growth_units() - a year-long, first-party preference cookie. */
function growth_set_preference_cookie($name, $value)
{
    if (headers_sent()) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie($name, $value, array(
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false, /* read by nothing server-side but PHP; not a secret, just a preference */
        'samesite' => 'Lax',
    ));
}

/** One language file, cached for the request - these are tiny, read-only arrays. */
function growth_lang_table($locale)
{
    static $cache = array();
    if (!isset($cache[$locale])) {
        $path = __DIR__ . '/lang/' . $locale . '.php';
        $cache[$locale] = is_file($path) ? require $path : array();
    }
    return $cache[$locale];
}

/**
 * Looks up $key in the active locale, then in English, then gives up and
 * returns the key itself - visibly wrong rather than silently blank, so a
 * missing key is easy to spot.
 *
 * $params does simple {name} substitution - this application has no need for
 * plural rules or gender inside a single string; the few places that do
 * (growth_format_years() and friends in data.inc) already handle that themselves
 * and are passed in as an already-formatted parameter.
 */
function t($key, $params = array())
{
    $locale = growth_locale();
    $table = growth_lang_table($locale);
    if (isset($table[$key])) {
        $text = $table[$key];
    } else {
        $en = growth_lang_table('en');
        $text = isset($en[$key]) ? $en[$key] : $key;
    }
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }
    return $text;
}

/** t(), HTML-escaped - the common case in a template. */
function th($key, $params = array())
{
    return growth_h(t($key, $params));
}
