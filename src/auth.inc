<?php

/**
 * Fail-closed authentication guard.
 *
 * This application has no login of its own - it relies entirely on the web
 * server (Basic Auth via .htaccess, normally) to set REMOTE_USER before PHP
 * ever runs. That is a reasonable design for a single trusted deployment, but
 * a public project must not leave it implicit: someone will eventually drop
 * this on a server without the .htaccess in place, and publish their
 * children's health records to the open internet. This refuses to render
 * anything at all when that looks like what has happened.
 */
function growth_require_auth()
{
    /* CLI callers - the test suite, tools/*.php - are not the thing this
       guards against: nobody reaches a CLI script's output over the web. */
    if (PHP_SAPI === 'cli') {
        return;
    }
    global $GROWTH_AUTH_HANDLED_EXTERNALLY;
    if (!empty($GROWTH_AUTH_HANDLED_EXTERNALLY)) {
        return;
    }
    if (!empty($_SERVER['REMOTE_USER'])) {
        return;
    }

    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Not configured</title></head>
<body style="font: 16px/1.5 sans-serif; max-width: 40em; margin: 3em auto; padding: 0 1em;">
<h1>This installation is not protected</h1>
<p>
  KidGrowth has no login of its own - it expects your web server to require
  authentication before PHP ever runs, and <code>REMOTE_USER</code> is empty,
  so nothing did. Without that, anyone who finds this URL can read and edit
  these health records.
</p>
<p>
  See <code>src/.htaccess</code> for a starting point (Apache Basic Auth) -
  edit its <code>AuthUserFile</code> path before using it. If you have your
  own authentication layer in front of this application (a reverse proxy, for
  example) and have verified it actually gates access, set
  <code>$GROWTH_AUTH_HANDLED_EXTERNALLY = true;</code> in
  <code>src/config.php</code> to skip this check.
</p>
</body>
</html>
<?php
    exit;
}
