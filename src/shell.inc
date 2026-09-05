<?php

/**
 * Page shell for the growth tracker.
 *
 * Self-contained on purpose: no shared site chrome, no analytics tag. This is
 * a private area holding children's health measurements - reporting page
 * views of it to an analytics provider would be wrong, and site chrome is not
 * what you want in something installed to a phone's home screen.
 */

require_once __DIR__ . '/data.inc';

const RUST_CSS_VERSION = '20260904-12';

function rust_head($title, $childId = null)
{
    $full = $title === '' ? 'Růst' : ($title . ' – Růst');
    ?>
<!DOCTYPE html>
<html lang="<?php echo rust_h(rust_locale()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<!-- A private health record has no business in a search index. -->
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#3c6e8f">
<title><?php echo rust_h($full); ?></title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="stylesheet" href="rust.css?v=<?php echo RUST_CSS_VERSION; ?>">
</head>
<body>
<header class="rust-hlavicka">
  <a class="rust-logo" href="index.php">Růst</a>
  <?php
    $children = rust_children();
    if (count($children) > 0) {
        echo '<nav class="rust-deti">';
        foreach ($children as $child) {
            $current = ($childId !== null && (int)$child['id'] === (int)$childId);
            printf(
                '<a class="rust-dite-odkaz%s" href="dite.php?id=%d%s">%s</a>',
                $current ? ' rust-aktivni' : '',
                (int)$child['id'],
                isset($_GET['ref']) ? '&amp;ref=' . rust_h(rust_selected_reference()) : '',
                rust_h($child['jmeno'])
            );
        }
        echo '</nav>';
    }
    rust_language_switcher();
  ?>
</header>
<main class="rust-obsah">
    <?php
}

/**
 * A plain-link language switcher, in the same no-JavaScript-required spirit
 * as rust_reference_picker(): every state is a real, bookmarkable URL.
 * Switching sets the rust_lang cookie (see i18n.inc) so it sticks for the
 * rest of the visit without repeating ?lang= on every link.
 */
function rust_language_switcher()
{
    $params = $_GET;
    echo '<nav class="rust-jazyk">';
    foreach (RUST_LOCALES as $locale) {
        $params['lang'] = $locale;
        printf(
            '<a class="rust-volba-jazyka%s" href="?%s">%s</a>',
            $locale === rust_locale() ? ' rust-aktivni' : '',
            rust_h(http_build_query($params)),
            rust_h(strtoupper($locale))
        );
    }
    echo '</nav>';
}

/**
 * Page footer.
 *
 * $referenceId names the reference currently on screen so its source line can
 * be printed. That is not decoration: the Polish tables are Creative Commons
 * and attribution is a condition of the licence, so the credit has to appear
 * wherever the data does.
 */
function rust_foot($referenceId = null)
{
    ?>
</main>
<footer class="rust-paticka">
  <?php
    $reference = ($referenceId === null) ? null : rust_reference($referenceId);
    if ($reference && isset($reference['source'])):
  ?>
    <p><?php echo th('footer_source_label'); ?> <?php echo rust_h($reference['source']); ?></p>
  <?php endif; ?>
  <p><?php echo th('footer_credits'); ?></p>
</footer>
<script src="rust.js?v=<?php echo RUST_CSS_VERSION; ?>" defer></script>
</body>
</html>
    <?php
}

/**
 * The reference picker.
 *
 * Renders as plain links carrying the current query string, so it works without
 * JavaScript and every state of the page is a real URL that can be bookmarked
 * or reloaded.
 */
function rust_reference_picker($current, $params = array(), $ids = null)
{
    if ($ids === null) {
        $ids = rust_reference_ids();
    }
    echo '<div class="rust-prepinac">';
    foreach ($ids as $id) {
        $reference = rust_reference($id);
        if (!$reference) {
            continue;
        }
        $query = array_merge($params, array('ref' => $id));
        printf(
            '<a class="rust-volba%s" href="?%s" title="%s">%s</a>',
            $id === $current ? ' rust-aktivni' : '',
            rust_h(http_build_query($query)),
            rust_h($reference['note']),
            rust_h($reference['label'])
        );
    }
    echo '</div>';
}
