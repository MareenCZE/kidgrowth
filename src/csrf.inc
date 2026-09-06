<?php

/**
 * CSRF protection: a per-session token, checked with hash_equals() on every
 * state-changing POST.
 *
 * Behind Basic Auth the risk this closes is real but easy to miss: a browser
 * holding cached credentials attaches them automatically to a cross-site form
 * POST, so a page on another site can submit to save-measurement.php or edit-child.php on
 * a visitor's behalf without ever seeing the password. A public project
 * cannot assume every deployment sits behind auth strong enough to make that
 * moot.
 */

function rust_session_start()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            /* Only mark the cookie Secure when the request itself is HTTPS -
               marking it Secure unconditionally would make the browser
               silently refuse to send it back over a plain-HTTP local/dev
               setup, breaking the session rather than protecting it. */
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ));
        session_start();
    }
}

function rust_csrf_token()
{
    rust_session_start();
    if (empty($_SESSION['rust_csrf'])) {
        $_SESSION['rust_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['rust_csrf'];
}

/** The hidden field every form that POSTs here needs. */
function rust_csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . rust_h(rust_csrf_token()) . '">';
}

/**
 * Verifies the token on a POST and stops the request with a 403 if it is
 * missing or wrong. Call this before reading anything else out of $_POST.
 */
function rust_csrf_check()
{
    rust_session_start();
    $token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    $expected = isset($_SESSION['rust_csrf']) ? $_SESSION['rust_csrf'] : '';
    if ($expected === '' || !hash_equals($expected, $token)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Neplatny nebo chybejici bezpecnostni token. Vratte se zpet a zkuste to znovu.\n";
        exit;
    }
}
