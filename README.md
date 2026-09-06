# KidGrowth

A self-hosted web app for tracking a child's height and weight against
national and international growth references, with percentiles, z-scores,
smoothing and adult-height prediction.

## Why this exists

KidGrowth replaces [RůstCZ](http://www.rustcz.cz/), a Windows desktop program for
plotting Czech children's growth against the national percentile tables -
still useful, no longer developed, and tied to a single machine. KidGrowth is a
small web app instead: it runs anywhere PHP does, works from a phone, and,
because it stores LMS parameters rather than a fixed set of curves, can plot
the same measurements against several national references side by side.

The Czech reference data (from SZÚ's national anthropological surveys) is
this project's actual differentiator - it exists nowhere else in a modern,
self-hostable tool - but KidGrowth also ships WHO, CDC (US) and Polish references,
so it is useful well outside the Czech Republic.

## What it does

- Five growth references: Czech (SZÚ/CAV), Czech breastfed-infant curves, WHO,
  US (CDC 2000), Poland.
- Height, weight, BMI and weight-for-height charts, each with percentile and
  z-score readouts.
- Optional smoothing to see the trend through measurement noise, and a
  growth-velocity chart.
- A predicted adult-height channel, projected from the reference the
  measurements are tracking.
- An optional note on any measurement - "after sickness", "at the
  paediatrician" - shown in the table and on the chart point itself, so the
  explanation for a dip sits where the dip is.
- Measurements can be corrected in place, including the date. Editing and
  deleting open in a dialog where the browser supports one, and fall back to
  their own pages where it does not.
- CSV import/export, including a converter for RůstCZ's own database format
  (`tools/import_rustcz.php`).
- Installable as a PWA (works from a phone's home screen).
- English and Czech, with locale-aware number, date and plural formatting
  (`src/i18n.inc`, `src/lang/`). English is the default; a link in the header
  switches and remembers the choice for the visit.
- Metric or imperial units (`src/units.inc`), switchable independently of
  language. Height accepts feet-and-inches (`4'3"`, `4 ft 3 in`) as well as a
  plain number of inches; everything is still stored in cm/kg regardless of
  which unit you type in or view.
- Soft delete: deleting a child or a measurement moves it to `trash.php` (the
  "Koš" / trash view) rather than removing it, and it can be restored from
  there. Deleting a child asks you to retype their name first - it takes
  every one of their measurements with it.

## Try it

The fastest way to see it working, no install and nobody else's data involved:

- **GitHub Codespaces.** **Code → Codespaces → Create codespace on main**.
  `.devcontainer/` seeds two synthetic children (`tools/seed_demo_data.php` -
  entirely invented measurement histories, never a real family's) and starts
  the app on port 8080. To open it, use the **Ports** panel beside the
  terminal: find port 8080 ("KidGrowth") and click the globe icon. The editor also
  offers a notification when the port comes up, though in the browser-based
  editor that is easy to miss.
  Each Codespace is its own throwaway instance; nothing you enter is shared
  with anyone else, and it disappears when the Codespace does. A banner in
  the header says so, and CSV export is turned off there - there is nothing
  in a demo worth taking with you.
- **`docker compose up`**, then open <http://localhost:8080>. This runs the
  real application, not the seeded demo, so - as the on-screen message will
  tell you the first time - it needs the same authentication step as any
  other install (see below) before it renders anything.

## Install

Requirements: **PHP 8.0+**, and a **writable directory** - nothing else. By
default, KidGrowth stores its data as a single JSON file (`storage/growth.json`);
SQLite and MySQL are also available, see below.

1. Clone the repository and point your web server's document root at `src/`.
   That's it for storage - the default JSON backend needs no further setup.
2. Build the reference data your installation will use:
   ```
   php tools/build_reference_data.php
   ```
   This downloads from SZÚ, WHO, PMC and the CDC and writes `src/data/*.php`
   locally. Only `src/data/cdc.php` (public domain) ships in the repository -
   see `DATA-LICENCES.md` for why the rest do not, and for what each source's
   licence actually permits. Run this again any time you want to refresh the
   data; nothing else in the application depends on network access. Until you
   run it, the app works with CDC's reference alone.
3. **Put Basic Auth (or an equivalent) in front of it.** KidGrowth has no
   authentication of its own - it relies entirely on your web server, and
   refuses to render anything at all if it cannot see that one is configured
   (`src/auth.inc`). A copy of `src/.htaccess` is provided as a starting point
   for Apache; edit the `AuthUserFile` path before using it, and point it
   somewhere outside your document root. Publishing this application without
   an authentication layer in front of it means publishing your children's
   health records to the open internet.

### Switching storage backend

Copy `src/config.sample.php` to `src/config.php` and set `$STORAGE_BACKEND`:

- **`'json'`** (default) - one file, `storage/growth.json`. No setup. Fine for
  a family's own data: a handful of writes a month, a dataset that stays
  comfortably under a megabyte for years.
- **`'sqlite'`** - one file, `storage/growth.sqlite`, created automatically.
  Needs the `pdo_sqlite` PHP extension. Preferred over MySQL where available,
  for the same "one file" simplicity with proper concurrent-write handling.
- **`'mysql'`** - for a deployment that already runs one. Load `db/schema.sql`
  first, then set `$DB_HOST` / `$DB_USER` / `$DB_PASS` / `$DB_NAME` in
  `config.php`.

Either way, keep `storage/` (or your database) reachable only by the
application - `storage/.htaccess` denies web access to it as a second layer,
but the real protection is that it sits outside your document root by
default.

### Demo mode

`$DEMO_MODE = true;` in `config.php` adds the "this is a demo" banner and
turns off CSV export (`src/export.php`). It changes nothing else - it is not
a different code path, just those two things - so a demo instance is exactly
what a real one looks like otherwise. This is what
`.devcontainer/postCreate.sh` sets for Codespaces; you should not set it for
a real deployment.

## Accuracy

KidGrowth's maths was validated against RůstCZ's own output across roughly 130
real measurements of two children spanning nine years: in the range RůstCZ
and SZÚ's published tables actually cover (the 3rd to 97th percentile),
results agree to within 0.10 standard deviations. Where the two programs
disagree outside that range, both are extrapolating past what SZÚ ever
published, from different starting assumptions, and neither can be said to be
more correct than the other - see `tools/check_rustcz_percentiles.php` for the
detail.

## The data

See `DATA-LICENCES.md` for the full table of sources, licences and required
attribution. In short: the code here is MIT, the reference data is not, and
combining every available reference means the installation as a whole
inherits a non-commercial restriction from two of the sources (Poland's
school-age tables and WHO).

`docs/reference-data.md` explains how each source's numbers actually get out
of a PDF, an Excel-shaped HTML page, or a chart with no numbers published at
all - CID-keyed PDF fonts, a WHO filename reused for two different tables,
non-breaking spaces that break `is_numeric()`, and the rest. `docs/architecture.md`
explains the maths itself: the LMS model every reference is converted to, why
smoothing happens in SDS space rather than centimetres, and why growth
velocity is measured over the window closest to a year rather than between
whichever two visits happened to occur.

## Accessibility

Text and every chart line were checked against WCAG's contrast requirements
(4.5:1 for text, 3:1 for a graphical element like a chart line) by computing
relative luminance directly rather than eyeballing it - see the comments next
to the colours in `src/growth.css` for the actual ratios. The SD-over-time
chart's three lines are additionally distinguished by dash pattern (solid /
dashed / dotted), not colour alone, since brown-vs-green is the classic
red-green colour-blindness confusion and the two metrics on that axis are
exactly that pair. Not done: running an automated tool (axe, Lighthouse) - if
you do, please open an issue with what it finds.

## Testing

```
php tests/run.php
```

Zero-dependency: no Composer, no PHPUnit, just `tests/*_test.php` files of
plain functions and a runner that calls the ones starting with `test_`. CI
(`.github/workflows/ci.yml`) runs this plus `php -l` on every file, across
PHP 8.1/8.3/8.4.

## Disclaimer

KidGrowth is a tracking tool, not a diagnostic one. It plots measurements against
published reference curves; it does not interpret them. Whether a child's
growth is a cause for concern is a question for a paediatrician, not a web
page.

## Licence

MIT for the code (see `LICENSE`); the reference data is separately licensed
(see `DATA-LICENCES.md`). Credit to SZÚ, WHO, the CDC, and Kułaga et al. for
the reference data itself, and to RůstCZ's author for the original idea and
for a text-export format detailed enough to validate against.
