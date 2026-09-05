# Extracting the reference data

All of this lives in `tools/build_reference_data.php`. This document explains
*how* it works and *why* each piece is shaped the way it is - the parts a
future maintainer could not reconstruct just by reading the source, because
the reasoning lives in what didn't work first.

Every source ends up as the same shape: a table of `age, L, M, S` rows per
metric per sex (the [LMS
method](https://en.wikipedia.org/wiki/Box%E2%80%93Cox_distribution#LMS_method_for_normalisation);
see `docs/architecture.md` for why the whole application standardises on it).
Getting there is a different fight for each source, because none of them
publish LMS parameters in a form meant for a program to read.

## CDC: the easy one, with two traps

The CDC ships plain CSV files of LMS parameters - the only source here that
does. Two things still go wrong silently if you are not looking for them:

- **`lenageinf.csv` carries a UTF-8 BOM; the other three CDC files do not.**
  Without stripping it, the first column parses as the literal header
  `"\xEF\xBB\xBFsex"` rather than `"sex"`, every row fails the column-presence
  check, the entire infant-length table is dropped, and CDC height quietly
  starts at 2 years instead of birth. Nothing errors - the build just produces
  a shorter table, which is exactly the kind of mistake that survives until
  someone measures a newborn.
- **Two CDC weight-for-age files, one for infants and one from 2 years on,
  overlap between 2 and 3 years.** The infant rows win on overlap, per a
  comment CDC themselves publish alongside the data - the birth-to-36-month
  survey is the more direct measurement for that age band.

Both are caught by `EXPECTED_AGE_SPAN`: every reference's build is checked
against an expected age range at both ends, deliberately, because a wrong or
truncated table is invisible at the end that still looks normal and obvious
only at the end that doesn't. A dropped infant table still ends at 20 years;
it just starts at 2 instead of 0.

## WHO: the same filename means two different files

WHO's expandable tables are `.xlsx`, fetched and parsed as HTML tables (WHO's
`.xlsx` files are literally SpreadsheetML wrapped in a `.xlsx` extension, so
an HTML table parser reads them fine). The trap is caching: **the weight-for-age
5-10-year file and the height-for-age 5-19-year file share the exact same
basename**, `hfa-boys-z-who-2007-exp.xlsx` - only a query string
(`?sfvrsn=...`) tells them apart. A cache keyed on basename alone would fetch
one, cache it, and silently serve it back for the other indicator on the next
run. `fetch_cached()` keys on a hash of the *full URL* instead, precisely
because of this file.

Two more WHO-specific details:

- **Non-breaking spaces, not regular ones, separate the numbers in these
  tables** - both the literal UTF-8 bytes (`\xC2\xA0`) and the HTML entity
  form show up depending on the file. Neither PCRE's `\s` nor PHP's `trim()`
  treats U+00A0 as whitespace, so an unhandled non-breaking space leaves an
  age cell reading `"\xC2\xA07"`, `is_numeric()` rejects it, and the row
  silently disappears. Both forms are replaced before anything else touches
  the text.
- **WHO does not publish weight-for-age past age 10** - weight alone stops
  being able to separate height from body mass through puberty, which is
  their own stated reason. The application must show no weight curve above
  that age rather than inventing one; see `rust_reference_span()` in
  `src/rust.inc`.

## Poland: two papers, two licences, HTML tables

Kułaga et al.'s two papers (ages 3-6 and 7-18) publish their LMS parameters
directly, as HTML tables inside the PMC article pages themselves - the same
non-breaking-space and Unicode-minus-sign handling as WHO's tables applies
here too, because PMC's page rendering has the same quirks. The two papers
carry different licences (CC BY for the preschool paper, CC BY-NC for the
school-age one) - see `DATA-LICENCES.md` for what that means for anyone using
the built data, which is a large part of *why* this project ships a builder
rather than the tables themselves.

## Czech CAV: text trapped in CID-keyed PDF fonts

This is the one that took the most work, because SZÚ's PDF does not contain
extractable text in any straightforward sense.

The PDF uses **CID-keyed subset fonts**: each glyph is addressed by an
arbitrary glyph ID the font's own internal table assigns, not by a Unicode
code point or ASCII byte. A naive "extract the text" approach - even most PDF
text-extraction libraries, if applied without checking - would read
gibberish. For the specific font this PDF embeds, the mapping turns out to be
a flat, constant shift: `unicode = glyph_id + 29`. Concretely, space (`0x0003`)
maps to `0x20`, the digit `'4'` (`0x0017`) maps to `0x34`, and `'A'`
(`0x0024`) maps to `0x41`. That covers everything the percentile tables
actually contain - digits, the comma decimal separator, and unaccented ASCII
- which is deliberately all this build ever relies on; accented Czech letters
elsewhere in the PDF come out wrong under this shift and are never used for
anything.

Recovering *table structure* is a second problem on top of the character
mapping. PDF content streams position every run of text with an explicit
transform matrix (`Tm`) rather than emitting rows and columns, so the build
extracts each run's `x`/`y` position along with its (decoded) text, sorts by
`y` then `x` to reassemble reading order, and joins runs on the same `y` into
one line. `"Tab. 4.3."`, an English caption, and `"Boys"`/`"Girls"` together
pin down which page holds which metric/sex table (section 4.2 nearby holds
mean/SD tables under the same English captions, which is why the caption
alone is not enough).

**A canary row guards all of this.** `CAV_CANARY` is one specific published
row - the height percentiles for boys at age 0 - checked byte-for-byte against
what the parser actually extracts, before anything else runs. If SZÚ ever
changes the PDF's font encoding or table layout, this is what fails loudly at
the first step, rather than the build quietly producing plausible-looking
nonsense from a broken glyph mapping.

SZÚ publishes seven percentiles (3rd/10th/25th/50th/75th/90th/97th) per age,
not LMS parameters. L and S are least-squares fitted to those seven points
with M fixed at the published median (the 50th percentile *is* M in the LMS
model, so it needs no fitting); the worst residual across every row of a
build is logged and the build fails outright above 0.15 - three times the
0.05 noise floor SZÚ's own one-decimal-place rounding imposes.

## Czech breastfed infants: digitised from a drawing, then proven against a table

SZÚ has never published numeric tables for the breastfed-infant reference -
only charts. The curves here are read back off the drawing itself, which
would ordinarily be too fragile to trust. It is only defensible because of
one fact: **every one of these charts also plots the seven CAV percentile
curves alongside the three breastfed ones**, and the CAV numbers are known
authoritatively from the tables above. Digitising the CAV curves off the same
chart and comparing them to the published CAV table proves the axis
calibration before a single breastfed value is trusted - and the build treats
a mismatch as fatal, not a warning.

Mechanically:

- Axis tick **labels** come from positioned text runs, the same technique as
  the CAV PDF, but this producer places runs with a plain identity matrix
  (`1 0 0 1 x y Tm`) rather than CID-encoded glyphs, so no glyph-shift mapping
  is needed here.
- Axis **scale** comes from the numeric tick labels (evenly spaced), but the
  **origin** comes from the outermost grid lines instead of the labels
  themselves - a text label is positioned by its baseline, which sits a few
  units off the tick it names, while a grid line is drawn exactly on the
  value it represents.
- The percentile curves are stroked Bézier paths, flattened into 12-segment
  polylines per curve - far finer than the 0.05-unit precision the source
  tables themselves carry, so flattening error is not the limiting factor.

Across all four charts (height and weight, boys and girls), the worst
disagreement between the digitised CAV curves and SZÚ's own published CAV
table was 0.074 cm/kg - within the rounding of the published table itself.
The build fails if that check ever exceeds 0.15.

## What this buys, and what it costs

Every one of these extraction paths is genuinely fragile: a font substitution
in a PDF export tool, a WHO file reorganisation, a changed PMC template. That
is exactly why the canary check, the age-span check, and the CAV
cross-calibration all exist as **fatal** checks rather than warnings - a
silently wrong reference table would be worse than a build that refuses to
run. If `tools/build_reference_data.php` ever starts failing, that is the
build doing its job: something upstream changed, and the parser needs to
change with it before anyone trusts what it produces.
