# How the growth maths fits together

This is about `src/growth.inc` - the pure-maths core - not the application
around it. See `docs/reference-data.md` for how the reference tables
themselves are produced, and the code comments in `src/storage.inc`,
`src/i18n.inc` and `src/units.inc` for how storage, language and units are
each kept to one place.

## The LMS model is the one representation everything else is built on

Every growth reference here - Czech, WHO, CDC, Polish - ends up as the same
three numbers per age per sex: **L**, **M** and **S**, the parameters of
Cole's [LMS
method](https://en.wikipedia.org/wiki/Box%E2%80%93Cox_distribution#LMS_method_for_normalisation).
M is the median at that age; S is roughly the coefficient of variation; L is
a Box-Cox power that lets the distribution be skewed (weight, especially in
infancy, is not symmetric - a LOT of babies are lighter than the median, a
few are a LOT heavier).

Given L, M and S, a measured value converts to a z-score:

```
z = ((value / M)^L - 1) / (L * S)          when L != 0
z = ln(value / M) / S                       when L is ~0 (log-normal case)
```

and the inverse - "what value sits at z SD" - is the same formula solved for
`value`. Both directions are implemented once, in `rust_zscore()` and
`rust_value_at_z()`, and everything above them - percentiles, the charts,
smoothing, prediction - goes through these two functions rather than
touching the LMS formula directly.

**Why converge every source onto this one representation** rather than
keeping each reference in whatever form it was published: it means there is
exactly one code path for "where does this measurement sit", regardless of
which of the five references is selected. WHO and CDC already publish LMS
directly. SZÚ publishes seven percentiles instead (see
`docs/reference-data.md`), so those get least-squares fitted to L and S at
build time, once, rather than at every page load - the fitting is exactly as
expensive as it looks, and the result is committed to a generated file that
changes only when the source data does.

A percentile is then just the standard normal CDF applied to z
(`rust_percentile()`), computed via `erf()` through Abramowitz & Stegun's
series approximation rather than PHP's own (which lives only in the optional
`ext/stats`, not something a build step can assume a shared host has).

## Smoothing happens in SDS space, not in centimetres

A single measurement carries real noise that is not growth: half a
centimetre of reading error between observers is routine, and a person
measures measurably taller in the morning than the evening because the spine
compresses over the day. `rust_smooth_series()` (in `src/growth.inc`) exists to
show the trend through that noise, and two decisions about *where* to smooth
matter more than the smoothing algorithm itself.

**It smooths the z-score series, not the raw height/weight series.** A
child's SDS is close to constant over time - that is what "holding a growth
channel" means clinically - so in SDS space the true signal is nearly flat
and the noise sits on top of it, which is a far easier thing to separate out
than a steeply rising raw curve. The fitted SDS curve is converted back
through the reference's own LMS at each age before being drawn, so the
*shape* of growth - the actual curve, steep in infancy, flatter later - comes
from the reference table, not from the smoother. Smoothing centimetres
directly would instead blend together heights that legitimately differ
because the child grew between the two visits.

**It is a local linear regression, not a moving average.** A moving average
assumes the points inside its window are repeated measurements of one true
value; over any window wide enough to reduce noise usefully, a growing
child's measurements are not that - they are trending throughout the window.
A local *line* follows that trend instead of blurring across it, which
incidentally is also why it behaves reasonably at the two ends of the
series, where a moving average has nowhere to average from and flattens out.
Concretely this is a LOESS-style fit: for each age, take the nearest `k`
points (bandwidth adapts by *count* of neighbours rather than by a fixed time
window, because visit frequency is wildly uneven - many measurements in the
first year, perhaps one a year after that - and a fixed-width time window
would smooth infancy to mush while barely touching the sparse years that
follow), weight them by distance (tricube) and, after an initial pass, by how
badly each point fit last time (bisquare) so that one mis-recorded outlier
does not drag the whole local fit toward it.

The residual between each raw point and the fitted curve - converted back to
the metric's own unit - is what `rust_num($fit['scatter'], ...)` reports as
"typical spread", and a point whose residual clears a noise floor (itself
based on realistic measurement precision - half a centimetre for height, a
finer threshold for weight) gets flagged as worth double-checking rather than
silently trusted or silently dropped.

## Growth velocity is measured over the window closest to a year

`rust_velocities()` computes centimetres gained, but not simply between
consecutive measurements. For each visit it searches backward for the
**earlier measurement whose age gap is closest to one year**, with a 0.7-year
floor below which it will not compute a velocity at all.

The reason is that velocity amplifies measurement error rather than
absorbing it. It is the *difference* of two independently noisy values,
divided by the time between them - so the same half-centimetre of reading
error that barely matters for a single height reading turns into a large
apparent speed-up or slow-down when the two visits are close together. Half
a centimetre of imprecision over four months of separation comes out as an
extra 1.5 cm/year of apparent velocity; the same imprecision over a full year
is only a quarter as distorting. Measuring over the window nearest a year -
rather than, say, always using the immediately preceding visit - is what
keeps the reported number meaningful rather than dominated by whichever two
visits happened to land close together.

The chart's "median pace" reference line is the same one-year secant applied
to the reference table's own M column - how much a child exactly on the 50th
percentile would gain over the same window - so the two numbers are directly
comparable. It is deliberately not a velocity *percentile*: nobody publishes
usable velocity percentiles for this age range under a licence this project
can use (WHO's velocity standards stop at 24 months; SZÚ publishes none), and
inventing one from the attained-height tables would look authoritative while
meaning nothing.

## Predicting adult height assumes the channel holds

`rust_channel_projection()` takes the child's most recent height
measurements (up to four), converts each to a z-score against the *selected*
reference, and projects the mean of those z-scores forward to age 18 through
that same reference's LMS at 18. The band around the prediction comes from
the spread of those recent z-scores, floored at 0.25 SD - even a child whose
last four visits agree unusually closely is not thereby perfectly
predictable.

This assumes what a paediatrician would call "staying in the growth channel"
- exactly the assumption that a pubertal growth spurt violates, which is why
the app states that caveat next to the number rather than only in this
document.

**A reference that ends before adulthood must refuse to answer, not
extrapolate.** This function used to clamp to the last age the reference
table covers instead of refusing outright, which is a bug that shipped once:
against the breastfed-infant reference (which ends at one year), "predicted
height at the end of the table" silently became "predicted adult height",
producing a confident-looking 70 cm. The fix is the explicit range check at
the top of the function, and `tests/math_test.php` pins the corrected
behaviour so it cannot regress unnoticed a second time.
