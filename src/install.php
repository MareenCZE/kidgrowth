<?php

/**
 * Browser-driven setup.
 *
 * Most people who self-host a small PHP application have FTP and a browser and
 * no shell at all -- many shared hosts do not offer one at any price. The
 * command line install excluded all of them, and left them running with the
 * CDC reference alone and no sign that four others existed. This page is the
 * door for those installations; tools/build_reference_data.php is the same
 * work for people who do have a shell.
 *
 * ---------------------------------------------------------------------------
 * Why this page authenticates like every other one
 *
 * An installer that accepts database credentials and writes PHP is the classic
 * self-hosted hole. This one calls growth_require_auth() before it does
 * anything at all, exactly as index.php does, so it inherits the single
 * security rule the project has: the web server authenticates, and without
 * REMOTE_USER nothing renders.
 *
 * That makes putting a password in front of the application a prerequisite of
 * installing it rather than a step to get round to later -- which is the right
 * order anyway, since the alternative is a window during which a stranger can
 * point the application at a database of their choosing. It also means there
 * is no exception carved into that rule for the one page where an exception
 * would hurt most.
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/auth.inc';
require_once __DIR__ . '/csrf.inc';
require_once __DIR__ . '/reference_build.inc';
require_once __DIR__ . '/storage/mysql_schema.inc';

growth_require_auth();

const INSTALL_CONFIG = __DIR__ . '/config.php';
const INSTALL_DATA_DIR = __DIR__ . '/data';
const INSTALL_STORAGE_DIR = __DIR__ . '/../storage';

/* Downloads are kept outside the document root when there is an outside, and
   beside the data when there is not. Either way they survive between requests,
   which is what makes an interrupted install resumable rather than restartable. */
function install_cache_dir(): string
{
    $candidates = [
        INSTALL_STORAGE_DIR . '/reference-cache',
        INSTALL_DATA_DIR . '/.cache',
        sys_get_temp_dir() . '/growth-reference-cache',
    ];
    foreach ($candidates as $dir) {
        $parent = dirname($dir);
        if (is_dir($dir) || (is_dir($parent) && is_writable($parent) && @mkdir($dir, 0777, true))) {
            if (is_writable($dir)) {
                return $dir;
            }
        }
    }
    return sys_get_temp_dir() . '/growth-reference-cache';
}

/* ------------------------------------------------------------ environment */

function install_checks(): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP version',
        'ok' => version_compare(PHP_VERSION, '8.0', '>='),
        'detail' => PHP_VERSION,
        'why' => 'KidGrowth needs 8.0 or newer.',
    ];

    $checks[] = [
        'label' => 'data/ is writable',
        'ok' => is_dir(INSTALL_DATA_DIR) && is_writable(INSTALL_DATA_DIR),
        'detail' => is_dir(INSTALL_DATA_DIR) ? (is_writable(INSTALL_DATA_DIR) ? 'yes' : 'not writable') : 'missing',
        'why' => 'The reference tables are written here. Give it write permission for the '
               . 'web server user, or upload the built files by hand.',
    ];

    $checks[] = [
        'label' => 'this directory is writable',
        'ok' => is_writable(__DIR__),
        'detail' => is_writable(__DIR__) ? 'yes' : 'not writable',
        'why' => 'config.php is written here. Without this you can still install, but you '
               . 'will have to create config.php yourself from config.sample.php.',
        'soft' => true,
    ];

    $downloads = (bool)ini_get('allow_url_fopen') || function_exists('curl_init');
    $checks[] = [
        'label' => 'can download',
        'ok' => $downloads,
        'detail' => ini_get('allow_url_fopen') ? 'allow_url_fopen is on' : (function_exists('curl_init') ? 'ext/curl' : 'no'),
        'why' => 'The reference tables are fetched from SZÚ, WHO, the CDC and PMC. Without '
               . 'this, build them on another machine and upload data/*.php.',
    ];

    $checks[] = [
        'label' => 'ZipArchive',
        'ok' => class_exists('ZipArchive'),
        'detail' => class_exists('ZipArchive') ? 'present' : 'missing',
        'why' => "WHO publishes its tables as .xlsx, which is a zip file. Without this "
               . 'extension every reference except WHO still builds.',
        'soft' => true,
    ];

    $checks[] = [
        'label' => 'pdo_sqlite',
        'ok' => extension_loaded('pdo_sqlite'),
        'detail' => extension_loaded('pdo_sqlite') ? 'present' : 'missing',
        'why' => 'Only needed for the SQLite storage backend. JSON and MySQL do not use it.',
        'soft' => true,
    ];

    $checks[] = [
        'label' => 'storage/ is writable',
        'ok' => is_dir(INSTALL_STORAGE_DIR) && is_writable(INSTALL_STORAGE_DIR),
        'detail' => is_dir(INSTALL_STORAGE_DIR) ? (is_writable(INSTALL_STORAGE_DIR) ? 'yes' : 'not writable') : 'missing',
        'why' => 'Only needed for the JSON and SQLite backends, which keep their file there. '
               . 'MySQL does not use it.',
        'soft' => true,
    ];

    return $checks;
}

function install_installed_references(): array
{
    $found = [];
    foreach (array_keys(growth_reference_catalog()) as $id) {
        if (is_file(INSTALL_DATA_DIR . '/' . $id . '.php')) {
            $found[] = $id;
        }
    }
    return $found;
}

/* ------------------------------------------------------------- json actions */

function install_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action !== '') {
    /* Every action changes something on disk or opens a connection somewhere,
       so all of them are POST and all of them are token-checked.
       growth_csrf_check() answers a bad token itself, with a 403 and a plain
       text body rather than a return value, so it is called for its effect and
       not tested -- the fetch() wrapper on the page turns that reply into a
       readable message. */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        install_json(['ok' => false, 'error' => 'That action needs a POST.'], 405);
    }
    growth_csrf_check();
}

if ($action === 'fetch') {
    $ref = (string)($_POST['ref'] ?? '');
    $index = (int)($_POST['index'] ?? -1);
    $urls = growth_reference_urls($ref);
    if (!isset($urls[$index])) {
        install_json(['ok' => false, 'error' => "No file $index for $ref."], 400);
    }
    $url = $urls[$index];
    $cacheDir = install_cache_dir();
    @mkdir($cacheDir, 0777, true);

    $GLOBALS['GROWTH_BUILD_LOG'] = [];
    try {
        $path = fetch_cached($url, $cacheDir);
    } catch (Throwable $e) {
        install_json(['ok' => false, 'error' => $e->getMessage(), 'url' => $url], 500);
    }
    if (!is_file($path) || filesize($path) === 0) {
        install_json(['ok' => false, 'error' => 'Downloaded nothing.', 'url' => $url], 500);
    }
    install_json([
        'ok' => true,
        'url' => $url,
        'bytes' => filesize($path),
        'index' => $index,
        'total' => count($urls),
    ]);
}

if ($action === 'build') {
    $ref = (string)($_POST['ref'] ?? '');
    $catalog = growth_reference_catalog();
    if (!isset($catalog[$ref])) {
        install_json(['ok' => false, 'error' => "Unknown reference $ref."], 400);
    }
    $cacheDir = install_cache_dir();
    $outDir = INSTALL_DATA_DIR;

    /* Collecting rather than printing: growth_build_log() appends here instead
       of writing to a STDERR that does not exist in a web request. */
    $GLOBALS['GROWTH_BUILD_LOG'] = [];
    try {
        switch ($ref) {
            case 'cav':
                growth_build_cav($cacheDir, $outDir);
                break;
            case 'cdc':
                growth_build_cdc($cacheDir, $outDir);
                break;
            case 'who':
                growth_build_who($cacheDir, $outDir);
                break;
            case 'pol':
                growth_build_pol($cacheDir, $outDir);
                break;
            case 'breastfed':
                /* Digitised off charts that also carry the CAV curves, which is
                   what makes the digitisation checkable -- so CAV has to exist
                   first, and is rebuilt here if the page was reloaded between
                   the two steps. */
                $cav = growth_build_cav($cacheDir, $outDir);
                growth_build_breastfed($cacheDir, $outDir, $cav);
                break;
        }
    } catch (Throwable $e) {
        install_json([
            'ok' => false,
            'error' => $e->getMessage(),
            'log' => $GLOBALS['GROWTH_BUILD_LOG'],
        ], 500);
    }
    install_json(['ok' => true, 'log' => $GLOBALS['GROWTH_BUILD_LOG']]);
}

if ($action === 'testdb') {
    $host = (string)($_POST['host'] ?? 'localhost');
    $port = (int)($_POST['port'] ?? 3306);
    $user = (string)($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    $name = (string)($_POST['name'] ?? '');

    mysqli_report(MYSQLI_REPORT_OFF);
    $link = @mysqli_connect($host, $user, $pass, $name, $port);
    if (!$link) {
        install_json(['ok' => false, 'error' => 'MySQL said: ' . mysqli_connect_error()]);
    }

    $existing = [];
    foreach (growth_mysql_tables() as $table) {
        $res = @mysqli_query($link, "SHOW TABLES LIKE '" . mysqli_real_escape_string($link, $table) . "'");
        if ($res && mysqli_num_rows($res) > 0) {
            $existing[] = $table;
        }
    }

    if (!empty($_POST['create']) && count($existing) < count(growth_mysql_tables())) {
        foreach (growth_mysql_schema_statements() as $statement) {
            if (!@mysqli_query($link, $statement)) {
                install_json(['ok' => false, 'error' => 'Creating tables failed: ' . mysqli_error($link)]);
            }
        }
        $existing = growth_mysql_tables();
    }

    install_json([
        'ok' => true,
        'server' => mysqli_get_server_info($link),
        'tables' => $existing,
        'complete' => count($existing) === count(growth_mysql_tables()),
    ]);
}

if ($action === 'saveconfig') {
    $backend = (string)($_POST['backend'] ?? 'json');
    if (!in_array($backend, ['json', 'sqlite', 'mysql'], true)) {
        install_json(['ok' => false, 'error' => 'Unknown storage backend.'], 400);
    }
    if (is_file(INSTALL_CONFIG) && empty($_POST['overwrite'])) {
        install_json(['ok' => false, 'error' => 'config.php already exists. Tick the overwrite box to replace it.'], 409);
    }

    /* Saving a MySQL configuration creates the tables if they are not there.
       Leaving that to a separate button was a trap somebody fell into on the
       first real install: they connected, saved, and got a working
       configuration pointing at a database with no tables in it -- which then
       failed much later, at the import, as a blank page. There is no reason
       for "save" to be able to produce an installation that cannot work. */
    $tableNote = '';
    if ($backend === 'mysql') {
        mysqli_report(MYSQLI_REPORT_OFF);
        $link = @mysqli_connect(
            (string)($_POST['host'] ?? 'localhost'),
            (string)($_POST['user'] ?? ''),
            (string)($_POST['pass'] ?? ''),
            (string)($_POST['name'] ?? ''),
            (int)($_POST['port'] ?? 3306)
        );
        if (!$link) {
            install_json([
                'ok' => false,
                'error' => 'Not saved — the database would not accept those details. MySQL said: '
                         . mysqli_connect_error(),
            ]);
        }
        $missing = [];
        foreach (growth_mysql_tables() as $table) {
            $res = @mysqli_query($link, "SHOW TABLES LIKE '" . mysqli_real_escape_string($link, $table) . "'");
            if (!$res || mysqli_num_rows($res) === 0) {
                $missing[] = $table;
            }
        }
        if ($missing) {
            foreach (growth_mysql_schema_statements() as $statement) {
                if (!@mysqli_query($link, $statement)) {
                    install_json([
                        'ok' => false,
                        'error' => 'Not saved — the tables are missing and could not be created: '
                                 . mysqli_error($link),
                    ]);
                }
            }
            $tableNote = ' Created ' . implode(' and ', $missing) . '.';
        } else {
            $tableNote = ' The tables were already there.';
        }
    }

    $lines = [
        '<?php',
        '',
        '/* Written by install.php on ' . date('Y-m-d H:i') . '. Edit freely; see',
        '   config.sample.php for what each setting does. */',
        '',
        '$STORAGE_BACKEND = ' . var_export($backend, true) . ';',
    ];
    if ($backend === 'mysql') {
        $lines[] = '';
        $lines[] = '$DB_HOST = ' . var_export((string)($_POST['host'] ?? 'localhost'), true) . ';';
        $lines[] = '$DB_USER = ' . var_export((string)($_POST['user'] ?? ''), true) . ';';
        $lines[] = '$DB_PASS = ' . var_export((string)($_POST['pass'] ?? ''), true) . ';';
        $lines[] = '$DB_NAME = ' . var_export((string)($_POST['name'] ?? ''), true) . ';';
        $lines[] = '$DB_PORT = ' . (int)($_POST['port'] ?? 3306) . ';';
    }
    $body = implode("\n", $lines) . "\n";

    if (@file_put_contents(INSTALL_CONFIG, $body) === false) {
        install_json([
            'ok' => false,
            'error' => 'Could not write config.php. Create it by hand with this content:',
            'body' => $body,
        ], 500);
    }
    @chmod(INSTALL_CONFIG, 0640);
    install_json(['ok' => true, 'backend' => $backend, 'note' => $tableNote]);
}

/* ------------------------------------------------------------------ the page */

/* Before a single byte of HTML. growth_csrf_token() starts the session, and a
   session started after output has begun cannot send its cookie -- so the
   token would render into the page while the browser held no session to check
   it against, and every action would then be rejected as forged. It fails in
   exactly the way that looks like a token bug rather than an ordering one. */
$csrfToken = growth_csrf_token();

$checks = install_checks();
$catalog = growth_reference_catalog();
$installed = install_installed_references();
$hasConfig = is_file(INSTALL_CONFIG);
$blocking = array_filter($checks, function ($c) {
    return empty($c['soft']) && !$c['ok'];
});

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KidGrowth setup</title>
<style>
  :root {
    --ink: #16222b; --muted: #61727d; --rule: #d7dee2; --paper: #f4f6f7;
    --card: #ffffff; --accent: #3c6e8f; --ok: #3f7355; --bad: #9d4b44;
    --warn: #8a6a2b; --wash: #e7eff3;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --ink: #e4ebef; --muted: #93a5b0; --rule: #2c3a43; --paper: #131a1f;
      --card: #1a2329; --accent: #6fa8c9; --ok: #74a888; --bad: #cf8a83;
      --warn: #c9a469; --wash: #1d2c36;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 0 1rem 5rem; background: var(--paper); color: var(--ink);
    font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  .wrap { max-width: 46rem; margin: 0 auto; }
  header { padding: 2.5rem 0 1.5rem; border-bottom: 2px solid var(--ink); }
  h1 { margin: 0 0 .4rem; font-size: 1.9rem; letter-spacing: -.02em; }
  h2 { font-size: 1.15rem; margin: 0 0 .2rem; }
  .step { margin-top: 2.25rem; }
  .step > p.lede { color: var(--muted); margin: .2rem 0 1rem; }
  .card { background: var(--card); border: 1px solid var(--rule); border-radius: 4px; }
  table { width: 100%; border-collapse: collapse; font-size: .92rem; }
  td { padding: .55rem .8rem; border-bottom: 1px solid var(--rule); vertical-align: top; }
  tr:last-child td { border-bottom: 0; }
  td.mark { width: 1.6rem; font-weight: 700; }
  td.name { width: 12rem; }
  .ok { color: var(--ok); } .bad { color: var(--bad); } .warn { color: var(--warn); }
  .why { color: var(--muted); font-size: .85rem; display: block; margin-top: .15rem; }
  label.ref { display: block; padding: .7rem .8rem; border-bottom: 1px solid var(--rule); }
  label.ref:last-of-type { border-bottom: 0; }
  label.ref b { font-weight: 600; }
  label.ref .meta { color: var(--muted); font-size: .85rem; display: block; margin: .15rem 0 0 1.65rem; }
  input[type=checkbox], input[type=radio] { margin-right: .5rem; }
  fieldset { border: 0; margin: 0; padding: 0; }
  .field { display: flex; gap: .6rem; align-items: center; padding: .35rem .8rem; }
  .field label { width: 9rem; color: var(--muted); font-size: .88rem; }
  .field input { flex: 1; padding: .35rem .5rem; border: 1px solid var(--rule);
                 border-radius: 3px; background: var(--paper); color: var(--ink); font: inherit; }
  button {
    font: inherit; font-weight: 600; padding: .5rem 1rem; border-radius: 3px;
    border: 1px solid var(--accent); background: var(--accent); color: #fff; cursor: pointer;
  }
  button.secondary { background: transparent; color: var(--accent); }
  button[disabled] { opacity: .5; cursor: default; }
  .row { display: flex; gap: .6rem; align-items: center; margin-top: .9rem; flex-wrap: wrap; }
  pre.log {
    margin: .9rem 0 0; padding: .7rem .8rem; background: var(--wash); border-radius: 3px;
    font: 12px/1.5 ui-monospace, Menlo, monospace; max-height: 15rem; overflow: auto;
    white-space: pre-wrap; word-break: break-word;
  }
  .note { background: var(--wash); border-radius: 4px; padding: .9rem 1rem; margin-top: 1rem; font-size: .92rem; }
  .done { color: var(--ok); font-weight: 600; }
  code { font: 12.5px/1.5 ui-monospace, Menlo, monospace; background: var(--wash); padding: .05em .3em; border-radius: 2px; }
</style>
</head>
<body>
<div class="wrap">

<header>
  <h1>KidGrowth setup</h1>
  <p class="lede" style="color:var(--muted);margin:0">
    Everything here can be undone by editing <code>config.php</code> and
    re-running this page.
  </p>
</header>

<section class="step">
  <h2>1 · This server</h2>
  <p class="lede">What the application needs, and what it found.</p>
  <div class="card">
    <table>
      <?php foreach ($checks as $c): ?>
      <tr>
        <td class="mark <?php echo $c['ok'] ? 'ok' : (empty($c['soft']) ? 'bad' : 'warn'); ?>">
          <?php echo $c['ok'] ? '✓' : (empty($c['soft']) ? '✕' : '!'); ?>
        </td>
        <td class="name"><?php echo h($c['label']); ?></td>
        <td>
          <?php echo h($c['detail']); ?>
          <?php if (!$c['ok']): ?><span class="why"><?php echo h($c['why']); ?></span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php if ($blocking): ?>
  <div class="note">
    The rows marked <span class="bad">✕</span> have to be fixed before the application
    will run. The ones marked <span class="warn">!</span> only limit what you can choose below.
  </div>
  <?php endif; ?>
</section>

<section class="step">
  <h2>2 · Where the measurements are kept</h2>
  <p class="lede">
    <?php if ($hasConfig): ?>
      <code>config.php</code> already exists, so this is already answered. Changing it here
      will overwrite that file.
    <?php else: ?>
      A family's own data is a few hundred rows that grow by a handful a month, so the
      simplest option that works is the right one.
    <?php endif; ?>
  </p>
  <form id="storage-form" class="card">
    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
    <fieldset>
      <label class="ref">
        <input type="radio" name="backend" value="json" checked><b>A JSON file</b>
        <span class="meta">No database. One file in <code>storage/</code>. Fine for a family:
          a handful of writes a month, comfortably under a megabyte after years.</span>
      </label>
      <label class="ref">
        <input type="radio" name="backend" value="sqlite"
          <?php echo extension_loaded('pdo_sqlite') ? '' : 'disabled'; ?>><b>SQLite</b>
        <span class="meta">One file, proper concurrent writes.
          <?php echo extension_loaded('pdo_sqlite') ? 'Available here.' : 'Needs pdo_sqlite, which this server does not have.'; ?></span>
      </label>
      <label class="ref">
        <input type="radio" name="backend" value="mysql"><b>MySQL</b>
        <span class="meta">For a host that already runs one. Saving creates the tables if they
          are not there yet — you do not have to do it separately.</span>
      </label>
    </fieldset>
    <div id="mysql-fields" hidden>
      <div class="field"><label for="db-host">Host</label><input id="db-host" name="host" value="localhost"></div>
      <div class="field"><label for="db-port">Port</label><input id="db-port" name="port" value="3306"></div>
      <div class="field"><label for="db-name">Database</label><input id="db-name" name="name" placeholder="kidgrowth"></div>
      <div class="field"><label for="db-user">User</label><input id="db-user" name="user"></div>
      <div class="field"><label for="db-pass">Password</label><input id="db-pass" name="pass" type="password"></div>
      <div class="row" style="padding:0 .8rem .3rem">
        <button type="button" id="test-db" class="secondary">Test connection</button>
        <button type="button" id="create-tables" class="secondary">Create the tables</button>
      </div>
    </div>
    <div class="row" style="padding:0 .8rem .8rem">
      <button type="button" id="save-config">Save configuration</button>
      <?php if ($hasConfig): ?>
        <label style="font-size:.88rem;color:var(--muted)">
          <input type="checkbox" id="overwrite">overwrite the existing config.php
        </label>
      <?php endif; ?>
    </div>
  </form>
  <pre class="log" id="storage-log" hidden></pre>
</section>

<section class="step">
  <h2>3 · Which growth references to install</h2>
  <p class="lede">
    Each is downloaded from its original publisher, onto this server, now. Only the CDC
    tables ship with the application, because only they are public domain — the licence
    for each is below, since this is the moment it matters.
  </p>
  <form id="ref-form" class="card">
    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
    <?php foreach ($catalog as $id => $ref): ?>
    <label class="ref">
      <input type="checkbox" name="ref[]" value="<?php echo h($id); ?>"
             <?php echo in_array($id, $installed, true) ? '' : 'checked'; ?>>
      <b><?php echo h($ref['label']); ?></b>
      <?php if (in_array($id, $installed, true)): ?>
        <span class="done">· installed</span>
      <?php endif; ?>
      <span class="meta">
        <?php echo h($ref['ages']); ?> ·
        <?php echo count(growth_reference_urls($id)); ?> file<?php echo count(growth_reference_urls($id)) === 1 ? '' : 's'; ?>
        <?php if ($ref['requires']): ?> · needs <?php echo h(implode(', ', $ref['requires'])); ?><?php endif; ?>
        <br><?php echo h($ref['licence']); ?>
      </span>
    </label>
    <?php endforeach; ?>
    <div class="row" style="padding:0 .8rem .8rem">
      <button type="button" id="install-refs">Download and build</button>
      <span id="ref-status" style="color:var(--muted);font-size:.9rem"></span>
    </div>
  </form>
  <pre class="log" id="ref-log" hidden></pre>
</section>

<section class="step">
  <h2>4 · When you are done</h2>
  <div class="note">
    <p style="margin:0 0 .6rem">
      Open <a href="index.php">the application</a>. If it shows children and charts, the
      install is finished.
    </p>
    <p style="margin:0">
      Then <strong>delete <code>install.php</code></strong>. It is protected by the same
      password as the rest of the application, so leaving it is not an emergency — but it
      writes configuration files, and nothing that writes configuration files should stay
      on a server longer than it is useful.
    </p>
  </div>
</section>

</div>
<script>
/* Which references pull in which others. The page expands the chosen set
   through this before it queues anything, so a dependency's files are
   downloaded in the download phase like everything else. Without it, ticking
   only the breastfed curves would leave the build request to fetch CAV's
   890 kB PDF by itself -- the one request in the whole install that could
   plausibly hit a time limit. */
window.INSTALL_REQUIRES = <?php
    $requires = [];
    foreach ($catalog as $id => $ref) {
        $requires[$id] = $ref['requires'];
    }
    echo json_encode($requires);
?>;
(function () {
  var csrf = document.querySelector('#ref-form input[name=csrf], #ref-form input[type=hidden]');

  function post(action, data) {
    var body = new FormData();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    if (csrf) { body.append(csrf.name, csrf.value); }
    return fetch('install.php?action=' + action, { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () {
        return { ok: false, error: 'The server replied with something that was not JSON (HTTP ' + r.status + ').' };
      }); });
  }

  function logTo(el, line) {
    el.hidden = false;
    el.textContent += (el.textContent ? '\n' : '') + line;
    el.scrollTop = el.scrollHeight;
  }

  /* ---- storage ---- */
  var mysqlFields = document.getElementById('mysql-fields');
  var storageLog = document.getElementById('storage-log');

  document.querySelectorAll('input[name=backend]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      mysqlFields.hidden = document.querySelector('input[name=backend]:checked').value !== 'mysql';
    });
  });

  function dbFields(extra) {
    var d = {
      host: document.getElementById('db-host').value,
      port: document.getElementById('db-port').value,
      name: document.getElementById('db-name').value,
      user: document.getElementById('db-user').value,
      pass: document.getElementById('db-pass').value
    };
    Object.keys(extra || {}).forEach(function (k) { d[k] = extra[k]; });
    return d;
  }

  document.getElementById('test-db').addEventListener('click', function () {
    logTo(storageLog, 'Connecting…');
    post('testdb', dbFields()).then(function (r) {
      if (!r.ok) { logTo(storageLog, r.error); return; }
      logTo(storageLog, 'Connected to ' + r.server +
        (r.tables.length ? '. Tables present: ' + r.tables.join(', ') : '. The tables do not exist yet.'));
    });
  });

  document.getElementById('create-tables').addEventListener('click', function () {
    logTo(storageLog, 'Creating tables…');
    post('testdb', dbFields({ create: '1' })).then(function (r) {
      logTo(storageLog, r.ok
        ? (r.complete ? 'Tables ready: ' + r.tables.join(', ') : 'Something is still missing.')
        : r.error);
    });
  });

  document.getElementById('save-config').addEventListener('click', function () {
    var backend = document.querySelector('input[name=backend]:checked').value;
    var overwrite = document.getElementById('overwrite');
    var data = backend === 'mysql' ? dbFields() : {};
    data.backend = backend;
    if (overwrite && overwrite.checked) { data.overwrite = '1'; }
    logTo(storageLog, 'Writing config.php…');
    post('saveconfig', data).then(function (r) {
      if (r.ok) {
        logTo(storageLog, 'Saved. Storage backend is now ' + r.backend + '.' + (r.note || ''));
        return;
      }
      logTo(storageLog, r.error);
      if (r.body) { logTo(storageLog, '\n' + r.body); }
    });
  });

  /* ---- references ---- */
  var refLog = document.getElementById('ref-log');
  var refStatus = document.getElementById('ref-status');
  var installBtn = document.getElementById('install-refs');

  installBtn.addEventListener('click', function () {
    var chosen = Array.prototype.slice
      .call(document.querySelectorAll('#ref-form input[name="ref[]"]:checked'))
      .map(function (b) { return b.value; });
    if (!chosen.length) { refStatus.textContent = 'Nothing selected.'; return; }

    /* Dependencies first, and only once each. Asking for the breastfed curves
       alone quietly adds CAV, because they are digitised off charts that carry
       the CAV curves and there is nothing to calibrate against without it. */
    var expanded = [];
    chosen.forEach(function (ref) {
      (window.INSTALL_REQUIRES[ref] || []).forEach(function (dep) {
        if (expanded.indexOf(dep) === -1) { expanded.push(dep); }
      });
      if (expanded.indexOf(ref) === -1) { expanded.push(ref); }
    });
    if (expanded.length > chosen.length) {
      logTo(refLog, 'Also building ' +
        expanded.filter(function (r) { return chosen.indexOf(r) === -1; }).join(', ') +
        ', which the selection depends on.');
    }
    chosen = expanded;

    installBtn.disabled = true;
    refStatus.textContent = '';
    refLog.textContent = '';

    /* One file per request. Downloading is the whole cost of a build -- with
       the files already fetched, parsing all five references takes under six
       seconds -- so no single request here comes close to a time limit, and an
       interrupted install picks up where it stopped. */
    var queue = chosen.slice();

    function nextReference() {
      if (!queue.length) {
        installBtn.disabled = false;
        refStatus.textContent = 'Done.';
        logTo(refLog, '\nAll finished. Open the application to check.');
        return;
      }
      var ref = queue.shift();
      logTo(refLog, '── ' + ref);
      fetchFiles(ref, 0);
    }

    function fetchFiles(ref, index) {
      refStatus.textContent = ref + ': downloading…';
      post('fetch', { ref: ref, index: index }).then(function (r) {
        if (!r.ok) {
          logTo(refLog, '   failed: ' + r.error + (r.url ? '\n   ' + r.url : ''));
          installBtn.disabled = false;
          refStatus.textContent = 'Stopped. Fix the problem and press the button again — '
            + 'what already downloaded is kept.';
          return;
        }
        logTo(refLog, '   ' + (index + 1) + '/' + r.total + '  ' +
          r.url.split('/').pop().substring(0, 60) + '  (' + Math.round(r.bytes / 1024) + ' kB)');
        if (index + 1 < r.total) { fetchFiles(ref, index + 1); }
        else { buildReference(ref); }
      });
    }

    function buildReference(ref) {
      refStatus.textContent = ref + ': building…';
      post('build', { ref: ref }).then(function (r) {
        (r.log || []).forEach(function (line) { logTo(refLog, '   ' + line); });
        if (!r.ok) {
          logTo(refLog, '   failed: ' + r.error);
          installBtn.disabled = false;
          refStatus.textContent = 'Stopped.';
          return;
        }
        nextReference();
      });
    }

    nextReference();
  });
})();
</script>
</body>
</html>
