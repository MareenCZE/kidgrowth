# Data licences

This repository's code is MIT (see `LICENSE`). **The growth-reference data it
reads is not** - each source sets its own terms, and one of them is
non-commercial. This file names them so nobody finds out the hard way.

None of the tables below are committed to this repository except CDC's, which
is public domain. Every other one is downloaded and generated on your own
machine by `php tools/build_reference_data.php` when you install the
application - see the README. That is a deliberate choice, not an oversight:
it keeps the repository itself unencumbered, and it means the licence terms
below apply to files that exist only on your installation, under your own
name, not to anything this project redistributes.

| Reference | Source | Licence | Attribution required | Commercial use |
|---|---|---|---|---|
| CDC 2000 (`cdc.php`, **shipped**) | US Centers for Disease Control and Prevention | US federal government work - public domain | No, but credited anyway | Yes |
| SZÚ / CAV (`cav.php`) | Statní zdravotní ústav, 6th Nationwide Anthropological Survey | Not stated by the publisher | Yes, as a courtesy | Unclear - not addressed by the publisher |
| SZÚ breastfed-infant curves (`koj.php`) | Statní zdravotní ústav / 3rd Faculty of Medicine, Charles University | Not stated by the publisher; also digitised from published charts, not a numeric source | Yes, as a courtesy | Unclear - not addressed by the publisher |
| Poland, preschool (`pol.php`, ages 3-6) | Kułaga et al., *European Journal of Pediatrics*, PMC3663205 | CC BY | Yes, required by the licence | Yes |
| Poland, school-age (`pol.php`, ages 7-18) | Kułaga et al., *European Journal of Pediatrics*, PMC3078309 | CC BY-NC | Yes, required by the licence | **No** |
| WHO Child Growth Standards / Growth Reference (`who.php`) | World Health Organization | CC BY-NC-SA 3.0 IGO (publications); WHO's data policy states no separate open licence for the underlying data, only non-commercial, not-for-profit use under WHO's control | Yes, required by the licence | **No** |

## What this means in practice

If you build and use the full set of references locally, **the installation
as a whole cannot be used commercially** - the Polish school-age tables (CC
BY-NC) and WHO's terms both carry a non-commercial condition, and that
condition does not go away just because the rest of the code is MIT. A
paediatrician's practice charging for a service built on this tool, or a
hosted product offered for money, would need to either drop those two
references or seek separate permission from Kułaga et al. and WHO
respectively.

Using only `cdc.php` (which ships) or a locally-built `cav.php` /
`koj.php` (whose licence is merely unstated, not restrictive) avoids this
entirely.

## Why SZÚ's tables are not shipped despite having no stated licence

"No licence stated" is not the same as "public domain" or "permission
granted". In the EU and Czechia, the *sui generis* database right can protect
a dataset representing substantial investment even where no copyright
attaches to the individual facts in it - and a national anthropological survey
is exactly the kind of dataset that right exists to cover. Publishing the
tables for parents and clinicians to consult is not the same act as a third
party redistributing them inside a public software repository. A request for
clarification was sent to SZÚ (`docs/szu-dotaz-licence.md`, if this repository
carries that document); absent a reply, the build-at-install approach is what
lets this project exist without resolving that question first.

## Attribution strings

Printed by the application's own footer wherever the corresponding reference
is on screen (see `growth_foot()` in `src/shell.inc`), and reproduced here for
anyone reusing the data outside the application:

- **CDC**: "CDC, National Center for Health Statistics, growth charts 2000."
- **Poland**: Kułaga Z, et al. Polish 2010/2012 growth references, *European
  Journal of Pediatrics*.
- **WHO**: World Health Organization Child Growth Standards / Growth
  Reference.
- **SZÚ**: Statní zdravotní ústav, 6. celostátní antropologický výzkum dětí a
  mládeže.
