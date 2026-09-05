<?php

/**
 * Renders a growth chart as inline SVG.
 *
 * Inline SVG rather than a JavaScript charting library because this project has
 * no bundler and no dependency mechanism to hang one on, and because a chart
 * that is already in the HTML works with JavaScript off, prints properly, and
 * costs no extra request. Colours come from rust.css so the chart follows the
 * page rather than hard-coding a palette here.
 */

require_once __DIR__ . '/rust.inc';

/* The percentile curves drawn behind the child's own line. P50 is emphasised;
   the outer pair bound the range SZU actually publishes, which is why the
   shading stops there rather than fading out arbitrarily. */
function rust_chart_percentiles()
{
    return array(3, 10, 25, 50, 75, 90, 97);
}

/**
 * Builds one chart.
 *
 * $points are the child's measurements as array('age' => float, 'value' =>
 * float, 'date' => 'Y-m-d'), already filtered to this metric and sorted by age.
 * $extras may carry a 'target' band (mid/low/high, drawn at age 18) and a
 * 'projection' band from rust_channel_projection().
 */
function rust_chart_svg($referenceId, $metric, $sex, $points, $options = array())
{
    $meta = rust_metric($metric);
    if (!$meta) {
        return '';
    }
    $rows = rust_reference_rows($referenceId, $metric, $sex);
    if (!$rows) {
        return '<p class="rust-nodata">' . th('nodata_reference_missing') . '</p>';
    }

    /* Unit conversion, once, here: everything below operates on whatever
       unit $rows and $points come out in, exactly as it always did for
       metric - a linear conversion changes no geometry, only the numbers
       printed on it. See units.inc's docblocks for why this is exact rather
       than approximate. */
    $indexKind = rust_metric_index_kind($metric);
    $valueKind = rust_metric_value_kind($metric);
    $rows = rust_display_chart_rows($rows, $indexKind, $valueKind);
    $points = rust_display_chart_points($points, $indexKind, $valueKind);
    if (rust_units() === 'imperial') {
        foreach (array('target', 'projection') as $band) {
            if (!empty($options[$band])) {
                foreach (array('mid', 'low', 'high') as $field) {
                    $options[$band][$field] = rust_display_chart_scalar($options[$band][$field], $valueKind);
                }
            }
        }
        if (!empty($options['smooth'])) {
            if (!empty($options['smooth']['curve'])) {
                $converted = array();
                foreach ($options['smooth']['curve'] as $pair) {
                    $converted[] = array(
                        ($indexKind === 'length') ? rust_display_length($pair[0]) : $pair[0],
                        rust_display_chart_scalar($pair[1], $valueKind),
                    );
                }
                $options['smooth']['curve'] = $converted;
            }
        }
    }

    $refMin = $rows[0][0];
    $refMax = $rows[count($rows) - 1][0];

    /* --- horizontal range ---------------------------------------------- */
    $maxPointAge = 0.0;
    foreach ($points as $point) {
        $maxPointAge = max($maxPointAge, $point['age']);
    }
    if (!empty($options['full_range']) || $meta['index'] !== 'age') {
        /* A height-indexed chart is left at its full span: "one centimetre of
           headroom" would be meaningless, and the useful range is the whole
           thing anyway. */
        $xMin = $refMin;
        $xMax = $refMax;
    } else {
        /* Fit the chart to the child plus a year of headroom. A five-year-old
           plotted across a full 0-18 axis is mostly empty space, and the recent
           measurements - the ones actually being looked at - get squeezed into a
           corner. */
        $xMin = $refMin;
        $xMax = min($refMax, max(1.0, ceil($maxPointAge) + 1.0));
    }
    if ($xMax <= $xMin) {
        $xMax = $xMin + 1.0;
    }

    /* --- sample the reference curves ------------------------------------ */
    $steps = 160;
    $curves = array();
    foreach (rust_chart_percentiles() as $percentile) {
        $z = rust_z_from_percentile($percentile);
        $curve = array();
        for ($i = 0; $i <= $steps; $i++) {
            $age = $xMin + ($xMax - $xMin) * $i / $steps;
            $lms = rust_lms_at($rows, $age);
            if (!$lms) {
                continue;
            }
            $value = rust_value_at_z($lms, $z);
            if ($value !== null) {
                $curve[] = array($age, $value);
            }
        }
        $curves[$percentile] = $curve;
    }
    if (empty($curves[50])) {
        return '<p class="rust-nodata">' . th('nodata_no_reference_for_age') . '</p>';
    }

    /* --- vertical range ------------------------------------------------- */
    $yMin = INF;
    $yMax = -INF;
    foreach ($curves as $curve) {
        foreach ($curve as $pair) {
            $yMin = min($yMin, $pair[1]);
            $yMax = max($yMax, $pair[1]);
        }
    }
    foreach ($points as $point) {
        if ($point['age'] >= $xMin && $point['age'] <= $xMax) {
            $yMin = min($yMin, $point['value']);
            $yMax = max($yMax, $point['value']);
        }
    }
    if ($meta['index'] === 'age' && $xMax >= 17.5) {
        foreach (array('target', 'projection') as $band) {
            if (!empty($options[$band])) {
                $yMin = min($yMin, $options[$band]['low']);
                $yMax = max($yMax, $options[$band]['high']);
            }
        }
    }
    if (!is_finite($yMin) || !is_finite($yMax)) {
        return '<p class="rust-nodata">' . th('nodata_no_reference_for_age') . '</p>';
    }
    $pad = max(($yMax - $yMin) * 0.06, 0.5);
    $yMin -= $pad;
    $yMax += $pad;

    /* --- geometry ------------------------------------------------------- */
    $width = 760;
    $height = 460;
    $left = 46;
    $right = 34;
    $top = 14;
    $bottom = 34;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;

    $sx = function ($age) use ($left, $plotW, $xMin, $xMax) {
        return $left + $plotW * (($age - $xMin) / ($xMax - $xMin));
    };
    $sy = function ($value) use ($top, $plotH, $yMin, $yMax) {
        return $top + $plotH * (1.0 - (($value - $yMin) / ($yMax - $yMin)));
    };

    $unit = rust_display_metric_unit($metric) ?: $meta['unit'];
    $decimals = ($valueKind === 'weight') ? rust_weight_decimals() : $meta['decimals'];
    $title = t('chart_title_growth', array('label' => $meta['label']));

    $svg = array();
    /* The figure wrapper is what goes full screen, so the readout and the
       button travel with the chart rather than being left behind on the page. */
    $svg[] = '<figure class="rust-graf-obal" data-rust-graf>';
    $svg[] = '<figcaption class="rust-graf-lista">'
        . '<output class="rust-graf-hodnota" aria-live="polite"></output>'
        . '<button type="button" class="rust-graf-full" hidden'
        . ' aria-label="' . th('button_fullscreen') . '">⛶</button>'
        . '</figcaption>';
    $svg[] = sprintf(
        '<svg class="rust-graf" viewBox="0 0 %d %d" role="img" preserveAspectRatio="xMidYMid meet" aria-label="%s">',
        $width, $height, rust_h($title)
    );

    /* --- gridlines and axes --------------------------------------------- */
    $xTicks = rust_nice_ticks($xMin, $xMax, 9);
    $yTicks = rust_nice_ticks($yMin, $yMax, 8);

    foreach ($xTicks as $tick) {
        $x = $sx($tick);
        $svg[] = sprintf('<line class="rust-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $x, $top, $x, $top + $plotH);
        $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
            $x, $top + $plotH + 20, rust_h(rust_tick_label($tick)));
    }
    foreach ($yTicks as $tick) {
        $y = $sy($tick);
        $svg[] = sprintf('<line class="rust-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $left, $y, $left + $plotW, $y);
        $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
            $left - 6, $y + 4, rust_h(rust_tick_label($tick)));
    }
    $svg[] = sprintf('<text class="rust-axis rust-axis-unit" x="%.1f" y="%.1f">%s</text>',
        4, $top + 4, rust_h($unit));
    $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
        $left + $plotW, $top + $plotH + 32, rust_h($meta['index_label']));

    /* --- the band between P3 and P97 ------------------------------------ */
    $band = rust_area_path($curves[3], $curves[97], $sx, $sy);
    if ($band !== '') {
        $svg[] = '<path class="rust-band" d="' . $band . '"/>';
    }

    /* --- percentile curves ---------------------------------------------- */
    foreach ($curves as $percentile => $curve) {
        $class = 'rust-p';
        if ($percentile === 50) {
            $class .= ' rust-p50';
        } elseif ($percentile === 3 || $percentile === 97) {
            $class .= ' rust-p-outer';
        }
        $path = rust_line_path($curve, $sx, $sy);
        if ($path === '') {
            continue;
        }
        $svg[] = sprintf('<path class="%s" d="%s"/>', $class, $path);

        $last = $curve[count($curve) - 1];
        $svg[] = sprintf(
            '<text class="rust-plabel" x="%.1f" y="%.1f">%d</text>',
            $sx($last[0]) + 3, $sy($last[1]) + 3, $percentile
        );
    }

    /* --- the two adult-height predictions -------------------------------
       Drawn side by side at 18 years so they can be compared at a glance:
       where a child tracks well below their mid-parental band, the two
       disagree, and that disagreement is the whole point of showing both. */
    if ($meta['index'] === 'age' && $xMax >= 17.5) {
        $x18 = $sx(min(18.0, $xMax));

        if (!empty($options['projection'])) {
            $projection = $options['projection'];
            $x = $x18 - 5;
            $label = t('chart_label_projection', array(
                'mid' => rust_num($projection['mid']),
                'low' => rust_num($projection['low']),
                'high' => rust_num($projection['high']),
                'z' => rust_num($projection['z'], 2),
                'n' => (int)$projection['based_on'],
                'unit' => $unit,
            ));
            $svg[] = sprintf(
                '<line class="rust-projection rust-ukazatel" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"'
                . ' tabindex="0" role="img"><title>%s</title></line>',
                $x, $sy($projection['high']), $x, $sy($projection['low']), rust_h($label)
            );
            $svg[] = sprintf(
                '<circle class="rust-projection-dot rust-ukazatel" cx="%.1f" cy="%.1f" r="3"'
                . ' tabindex="0" role="img"><title>%s</title></circle>',
                $x, $sy($projection['mid']), rust_h($label)
            );
            /* a dashed run from the last measurement to the predicted adult
               height, so the projection reads as a continuation of the child's
               own line rather than a mark floating at the edge */
            if ($points) {
                $last = $points[count($points) - 1];
                $svg[] = sprintf(
                    '<path class="rust-projection-line" d="M%.1f %.1f L%.1f %.1f"/>',
                    $sx($last['age']), $sy($last['value']), $x, $sy($projection['mid'])
                );
            }
        }

        if (!empty($options['target'])) {
            $target = $options['target'];
            $x = $x18 + 5;
            $label = t('chart_label_target', array(
                'mid' => rust_num($target['mid']),
                'low' => rust_num($target['low']),
                'high' => rust_num($target['high']),
                'unit' => $unit,
            ));
            $svg[] = sprintf(
                '<line class="rust-target rust-ukazatel" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"'
                . ' tabindex="0" role="img"><title>%s</title></line>',
                $x, $sy($target['high']), $x, $sy($target['low']), rust_h($label)
            );
            $svg[] = sprintf(
                '<circle class="rust-target-dot rust-ukazatel" cx="%.1f" cy="%.1f" r="3"'
                . ' tabindex="0" role="img"><title>%s</title></circle>',
                $x, $sy($target['mid']), rust_h($label)
            );
        }
    }

    /* --- the child ------------------------------------------------------ */
    $inRange = array();
    foreach ($points as $point) {
        if ($point['age'] >= $xMin && $point['age'] <= $xMax) {
            $inRange[] = $point;
        }
    }
    if ($inRange) {
        $smoothed = !empty($options['smooth']) ? $options['smooth'] : null;

        $childPath = rust_line_path(
            array_map(function ($p) { return array($p['age'], $p['value']); }, $inRange),
            $sx, $sy
        );
        if ($childPath !== '') {
            /* With smoothing on, the raw line stays but recedes: the point is to
               show the trend without hiding what was actually measured. */
            $class = $smoothed ? 'rust-child-line rust-child-line-tlum' : 'rust-child-line';
            $svg[] = sprintf('<path class="%s" d="%s"/>', $class, $childPath);
        }

        if ($smoothed) {
            $within = array();
            foreach ($smoothed['curve'] as $pair) {
                if ($pair[0] >= $xMin && $pair[0] <= $xMax) {
                    $within[] = $pair;
                }
            }
            $smoothPath = rust_line_path($within, $sx, $sy);
            if ($smoothPath !== '') {
                $svg[] = '<path class="rust-smooth-line" d="' . $smoothPath . '"/>';
            }
        }
        foreach ($inRange as $point) {
            /* Each dot carries its own numbers so the readout needs no second
               request and works on a phone, where there is no hover at all and
               a tap has to be enough. The <title> stays as the no-JavaScript
               fallback: browsers show it natively. */
            $lms = rust_lms_at($rows, $point['age']);
            $z = $lms ? rust_zscore($point['value'], $lms) : null;
            $percentile = ($z === null) ? null : rust_percentile($z);

            /* One label serves both purposes: browsers show a <title> natively
               as a tooltip when JavaScript is off, and the readout above the
               chart simply prints the same text. Keeping it in one place means
               the two can never drift apart, and the Czech number formatting
               stays on the PHP side where the rest of it lives. */
            $parts = array(
                rust_date_cz($point['date']),
                rust_num($point['value'], $decimals) . ' ' . $unit,
            );
            /* M is the median by definition, so the gap to it needs no extra
               lookup - and it is the part of the readout a parent can act on
               without first translating a percentile in their head. */
            if ($lms) {
                $diff = rust_median_diff_cz($point['value'], $lms['m'], $decimals, $unit);
                if ($diff !== '') {
                    $parts[] = $diff;
                }
            }
            if ($percentile !== null) {
                $parts[] = t('tooltip_percentile', array(
                    'n' => rust_num($percentile, ($percentile < 1 || $percentile > 99) ? 2 : 1),
                ));
            }
            if ($z !== null) {
                $parts[] = rust_num($z, 2) . ' SD';
            }
            /* what the point is plotted against: an age, or a height */
            $parts[] = ($meta['index'] === 'age')
                ? t('tooltip_age', array('age' => rust_age_cz($point['age'])))
                : t('tooltip_height', array('height' => rust_num($point['age'], 1), 'unit' => rust_length_unit()));

            /* A measurement the smoother could not reconcile with its
               neighbours. Flagged rather than dropped: only the person who
               recorded it can say whether it is a typo or a real event. */
            $class = 'rust-child-dot rust-ukazatel';
            if (isset($point['suspect'])) {
                $class .= ' rust-podezrely';
                /* rust_num() already carries its own "-" for a negative deviation;
                   "%+s" cannot add the matching "+" for a positive one (PHP's sign
                   flag has no effect on a string conversion), so it is prepended
                   by hand instead. */
                $parts[] = t('tooltip_suspect', array(
                    'deviation' => (($point['suspect'] >= 0) ? '+' : '')
                        . rust_num($point['suspect'], $decimals),
                    'unit' => $unit,
                ));
            }

            $svg[] = sprintf(
                '<circle class="%s" cx="%.1f" cy="%.1f" r="2.8"'
                . ' tabindex="0" role="img"><title>%s</title></circle>',
                $class, $sx($point['age']), $sy($point['value']),
                rust_h(implode(' · ', $parts))
            );
        }
    }

    $svg[] = '</svg>';

    /* The legend lives inside the <figure>, because the figure is what goes
       full screen - a legend outside it simply disappears at the moment the
       chart is biggest and most worth reading. */
    $legend = array();
    if ($inRange) {
        $legend[] = array('mereni', t('legend_measured'));
    }
    if (!empty($options['smooth'])) {
        $legend[] = array('vyrovnani', t('legend_smoothed'));
    }
    if ($meta['index'] === 'age' && $xMax >= 17.5) {
        if (!empty($options['projection'])) {
            $legend[] = array('predpoved', t('legend_projection'));
        }
        if (!empty($options['target'])) {
            $legend[] = array('cil', t('legend_target'));
        }
    }
    if (count($legend) > 1) {
        $items = array();
        foreach ($legend as $entry) {
            $items[] = sprintf('<span class="rust-legenda rust-legenda-%s">%s</span>',
                $entry[0], rust_h($entry[1]));
        }
        $svg[] = '<p class="rust-legenda-radek">' . implode(' ', $items) . '</p>';
    }

    $svg[] = '</figure>';
    return implode("\n", $svg);
}

/**
 * Renders the SDS trend: every metric's z-score on one chart against age.
 *
 * This is the chart that answers the question the individual growth charts
 * cannot. On a growth chart a child low down looks the same whether they have
 * always been there or have just dropped; here a flat line means the child is
 * holding their channel and a sloping one means they are crossing it, which is
 * the signal that actually matters clinically. Putting the metrics together
 * also shows whether height and weight are moving as a pair.
 *
 * $seriesByMetric maps a metric id to points of array('age', 'z', 'date',
 * 'value'). Only age-indexed metrics belong here.
 */
function rust_sds_chart_svg($seriesByMetric, $options = array())
{
    $metrics = rust_metrics();
    $colours = array('height' => 1, 'weight' => 2, 'bmi' => 3);

    $maxAge = 0.0;
    $any = false;
    foreach ($seriesByMetric as $points) {
        foreach ($points as $point) {
            $maxAge = max($maxAge, $point['age']);
            $any = true;
        }
    }
    if (!$any) {
        return '';
    }

    $xMin = 0.0;
    $xMax = max(1.0, ceil($maxAge) + 0.5);
    /* Fixed vertical range: the whole point is comparing the shape of these
       lines between visits, and an axis that rescaled itself would make a
       steady child look like a moving one. */
    $yMin = -3.5;
    $yMax = 3.5;

    $width = 760;
    $height = 320;
    $left = 46;
    $right = 34;
    $top = 14;
    $bottom = 34;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;

    $sx = function ($age) use ($left, $plotW, $xMin, $xMax) {
        return $left + $plotW * (($age - $xMin) / ($xMax - $xMin));
    };
    $sy = function ($z) use ($top, $plotH, $yMin, $yMax) {
        return $top + $plotH * (1.0 - (($z - $yMin) / ($yMax - $yMin)));
    };

    $svg = array();
    $svg[] = '<figure class="rust-graf-obal" data-rust-graf>';
    $svg[] = '<figcaption class="rust-graf-lista">'
        . '<output class="rust-graf-hodnota" aria-live="polite"></output>'
        . '<button type="button" class="rust-graf-full" hidden'
        . ' aria-label="' . th('button_fullscreen') . '">⛶</button>'
        . '</figcaption>';
    $svg[] = sprintf(
        '<svg class="rust-graf" viewBox="0 0 %d %d" role="img" preserveAspectRatio="xMidYMid meet" aria-label="%s">',
        $width, $height, th('heading_sds')
    );

    /* the range two thirds of children sit in, then the 95% range */
    $svg[] = sprintf('<rect class="rust-band" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>',
        $left, $sy(2.0), $plotW, $sy(-2.0) - $sy(2.0));

    foreach (array(-3, -2, -1, 0, 1, 2, 3) as $line) {
        $y = $sy($line);
        $class = ($line === 0) ? 'rust-grid rust-sds-nula' : 'rust-grid';
        $svg[] = sprintf('<line class="%s" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $class, $left, $y, $left + $plotW, $y);
        $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="end">%+d</text>',
            $left - 6, $y + 4, $line);
    }
    foreach (rust_nice_ticks($xMin, $xMax, 9) as $tick) {
        $x = $sx($tick);
        $svg[] = sprintf('<line class="rust-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $x, $top, $x, $top + $plotH);
        $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
            $x, $top + $plotH + 20, rust_h(rust_tick_label($tick)));
    }
    $svg[] = sprintf('<text class="rust-axis rust-axis-unit" x="%.1f" y="%.1f">SD</text>', 4, $top + 4);
    $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
        $left + $plotW, $top + $plotH + 32, th('index_age'));

    $smoothing = !empty($options['smooth']) ? $options['smooth'] : array();

    foreach ($seriesByMetric as $metricId => $points) {
        if (!$points || !isset($colours[$metricId])) {
            continue;
        }
        $meta = $metrics[$metricId];
        $n = $colours[$metricId];

        $path = rust_line_path(
            array_map(function ($p) { return array($p['age'], $p['z']); }, $points),
            $sx, $sy
        );
        if ($path !== '') {
            /* With smoothing on the raw line recedes here too, so the three
               smoothed lines are what the eye follows - which is the whole
               point of this chart: is each one flat, or sloping? */
            $class = 'rust-sds-line rust-sds-' . $n;
            if (isset($smoothing[$metricId])) {
                $class .= ' rust-sds-line-tlum';
            }
            $svg[] = sprintf('<path class="%s" d="%s"/>', $class, $path);
        }

        if (isset($smoothing[$metricId]['curve_z'])) {
            $smoothPath = rust_line_path($smoothing[$metricId]['curve_z'], $sx, $sy);
            if ($smoothPath !== '') {
                $svg[] = sprintf('<path class="rust-sds-smooth rust-sds-%d" d="%s"/>', $n, $smoothPath);
            }
        }
        $sdsKind = rust_metric_value_kind($metricId);
        $sdsUnit = rust_display_metric_unit($metricId) ?: $meta['unit'];
        $sdsDecimals = ($sdsKind === 'weight') ? rust_weight_decimals() : $meta['decimals'];
        foreach ($points as $point) {
            $displayValue = rust_display_chart_scalar($point['value'], $sdsKind);
            $bits = array(
                $meta['label'],
                rust_date_cz($point['date']),
                rust_num($displayValue, $sdsDecimals) . ' ' . $sdsUnit,
            );
            if (isset($point['median'])) {
                $diff = rust_median_diff_cz($displayValue, rust_display_chart_scalar($point['median'], $sdsKind),
                    $sdsDecimals, $sdsUnit);
                if ($diff !== '') { $bits[] = $diff; }
            }
            $bits[] = rust_num($point['z'], 2) . ' SD';
            $bits[] = t('tooltip_age', array('age' => rust_age_cz($point['age'])));
            $label = implode(' · ', $bits);
            $svg[] = sprintf(
                '<circle class="rust-sds-dot rust-sds-%d rust-ukazatel" cx="%.1f" cy="%.1f" r="2.5"'
                . ' tabindex="0" role="img"><title>%s</title></circle>',
                $n, $sx($point['age']), $sy($point['z']), rust_h($label)
            );
        }
    }

    $svg[] = '</svg>';

    /* inside the figure, so it survives going full screen */
    $legend = array();
    foreach ($seriesByMetric as $metricId => $points) {
        if (!$points || !isset($colours[$metricId])) {
            continue;
        }
        $legend[] = sprintf('<span class="rust-legenda rust-sds-%d">%s</span>',
            $colours[$metricId], rust_h($metrics[$metricId]['label']));
    }
    if ($legend) {
        $svg[] = '<p class="rust-legenda-radek">' . implode(' ', $legend) . '</p>';
    }
    $svg[] = '</figure>';

    return implode("\n", $svg);
}

/**
 * Renders the growth-velocity chart: centimetres per year against age.
 *
 * Deliberately without percentile bands, unlike every other chart here. Velocity
 * percentiles cannot be derived from the height-for-age tables - the
 * distribution of a year's growth is a separate measurement - and nobody
 * publishes them for these ages under a licence we can use: WHO's velocity
 * standards stop at 24 months, SZU publishes none at all, and the classic
 * Tanner-Whitehouse reference is neither open nor machine-readable. Inventing
 * bands from the attained-height tables would look authoritative and mean
 * nothing.
 *
 * What is honest, and is drawn instead, is the pace of the median curve: how
 * much a child on the 50th percentile gains over the same window. It is
 * computed as a one-year secant on M, matching how the child's own velocity is
 * measured, so the two are directly comparable. Note it is the growth of the
 * median child, not the median of children's growths - very close for height in
 * mid-childhood, but not the same quantity, which is why it is labelled as a
 * pace rather than as a percentile.
 */
function rust_velocity_chart_svg($referenceId, $sex, $velocities, $options = array())
{
    if (count($velocities) < 2) {
        return '';
    }
    $rows = rust_reference_rows($referenceId, 'height', $sex);
    if (!$rows) {
        return '';
    }

    /* Same one-time unit conversion as rust_chart_svg(): height's M is a
       length, so it converts, and its difference over a year (the median
       pace below) is then correctly expressed in the display unit too. */
    $rows = rust_display_chart_rows($rows, null, 'length');
    $velocities = array_map(function ($v) {
        $v['cm_per_year'] = rust_display_velocity($v['cm_per_year']);
        return $v;
    }, $velocities);
    if (!empty($options['smooth']['curve'])) {
        $options['smooth']['curve'] = array_map(function ($pair) {
            return array($pair[0], rust_display_length($pair[1]));
        }, $options['smooth']['curve']);
    }

    $refMin = $rows[0][0];
    $refMax = $rows[count($rows) - 1][0];

    /* the median child's gain over the preceding year, at age $age */
    $medianPace = function ($age) use ($rows, $refMin, $refMax) {
        if ($age - 1.0 < $refMin || $age > $refMax) {
            return null;
        }
        $now = rust_lms_at($rows, $age);
        $before = rust_lms_at($rows, $age - 1.0);
        if (!$now || !$before) {
            return null;
        }
        return $now['m'] - $before['m'];
    };

    $xMin = INF;
    $xMax = -INF;
    $yMin = INF;
    $yMax = -INF;
    foreach ($velocities as $v) {
        $xMin = min($xMin, $v['age']);
        $xMax = max($xMax, $v['age']);
        $yMin = min($yMin, $v['cm_per_year']);
        $yMax = max($yMax, $v['cm_per_year']);
    }
    $xMin = max($refMin + 1.0, floor($xMin * 2) / 2);
    if (!empty($options['full_range'])) {
        /* Out to the end of the reference. Worth doing: the median pace carries
           the pubertal spurt, which peaks around 14 for boys at some 7.5 cm a
           year and then falls away to almost nothing by 18 - so the chart shows
           not just how fast the child is growing now but how much growing is
           left, which is the question behind it. */
        $xMax = $refMax;
    } else {
        $xMax = min($refMax, ceil($xMax * 2) / 2);
    }
    if ($xMax <= $xMin) {
        return '';
    }

    /* sample the median pace across the range */
    $steps = 120;
    $paceCurve = array();
    for ($i = 0; $i <= $steps; $i++) {
        $age = $xMin + ($xMax - $xMin) * $i / $steps;
        $pace = $medianPace($age);
        if ($pace !== null) {
            $paceCurve[] = array($age, $pace);
            $yMin = min($yMin, $pace);
            $yMax = max($yMax, $pace);
        }
    }

    /* velocity of the smoothed height curve, when smoothing is on: the same
       one-year secant, but on a line the measurement noise has been taken out
       of - which is the only way this chart reads as anything but jitter */
    $smoothCurve = array();
    if (!empty($options['smooth']['curve'])) {
        $curve = $options['smooth']['curve'];
        $heightAt = function ($age) use ($curve) {
            if ($age < $curve[0][0] || $age > $curve[count($curve) - 1][0]) {
                return null;
            }
            for ($i = 1; $i < count($curve); $i++) {
                if ($curve[$i][0] >= $age) {
                    $a = $curve[$i - 1];
                    $b = $curve[$i];
                    $span = $b[0] - $a[0];
                    $t = $span > 0 ? ($age - $a[0]) / $span : 0.0;
                    return $a[1] + $t * ($b[1] - $a[1]);
                }
            }
            return null;
        };
        for ($i = 0; $i <= $steps; $i++) {
            $age = $xMin + ($xMax - $xMin) * $i / $steps;
            $now = $heightAt($age);
            $before = $heightAt($age - 1.0);
            if ($now !== null && $before !== null) {
                $smoothCurve[] = array($age, $now - $before);
                $yMin = min($yMin, $now - $before);
                $yMax = max($yMax, $now - $before);
            }
        }
    }

    if (!is_finite($yMin) || !is_finite($yMax)) {
        return '';
    }
    $pad = max(($yMax - $yMin) * 0.1, 0.5);
    $yMin = max(0.0, $yMin - $pad);
    $yMax += $pad;

    $width = 760;
    $height = 320;
    $left = 46;
    $right = 34;
    $top = 14;
    $bottom = 34;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;

    $sx = function ($age) use ($left, $plotW, $xMin, $xMax) {
        return $left + $plotW * (($age - $xMin) / ($xMax - $xMin));
    };
    $sy = function ($value) use ($top, $plotH, $yMin, $yMax) {
        return $top + $plotH * (1.0 - (($value - $yMin) / ($yMax - $yMin)));
    };

    $svg = array();
    $svg[] = '<figure class="rust-graf-obal" data-rust-graf>';
    $svg[] = '<figcaption class="rust-graf-lista">'
        . '<output class="rust-graf-hodnota" aria-live="polite"></output>'
        . '<button type="button" class="rust-graf-full" hidden'
        . ' aria-label="' . th('button_fullscreen') . '">⛶</button>'
        . '</figcaption>';
    $svg[] = sprintf(
        '<svg class="rust-graf" viewBox="0 0 %d %d" role="img" preserveAspectRatio="xMidYMid meet" aria-label="%s">',
        $width, $height, th('heading_velocity')
    );

    foreach (rust_nice_ticks($yMin, $yMax, 6) as $tick) {
        $y = $sy($tick);
        $svg[] = sprintf('<line class="rust-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $left, $y, $left + $plotW, $y);
        $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
            $left - 6, $y + 4, rust_h(rust_tick_label($tick)));
    }
    foreach (rust_nice_ticks($xMin, $xMax, 9) as $tick) {
        $x = $sx($tick);
        $svg[] = sprintf('<line class="rust-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $x, $top, $x, $top + $plotH);
        $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
            $x, $top + $plotH + 20, rust_h(rust_tick_label($tick)));
    }
    $svg[] = sprintf('<text class="rust-axis rust-axis-unit" x="%.1f" y="%.1f">%s</text>',
        4, $top + 4, rust_h(rust_velocity_unit()));
    $svg[] = sprintf('<text class="rust-axis" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
        $left + $plotW, $top + $plotH + 32, th('index_age'));

    $pacePath = rust_line_path($paceCurve, $sx, $sy);
    if ($pacePath !== '') {
        $svg[] = '<path class="rust-tempo-line" d="' . $pacePath . '"/>';
    }

    $points = array();
    foreach ($velocities as $v) {
        if ($v['age'] >= $xMin && $v['age'] <= $xMax) {
            $points[] = array($v['age'], $v['cm_per_year']);
        }
    }
    $childPath = rust_line_path($points, $sx, $sy);
    if ($childPath !== '') {
        $class = $smoothCurve ? 'rust-child-line rust-child-line-tlum' : 'rust-child-line';
        $svg[] = sprintf('<path class="%s" d="%s"/>', $class, $childPath);
    }

    if ($smoothCurve) {
        $smoothPath = rust_line_path($smoothCurve, $sx, $sy);
        if ($smoothPath !== '') {
            $svg[] = '<path class="rust-smooth-line" d="' . $smoothPath . '"/>';
        }
    }

    foreach ($velocities as $v) {
        if ($v['age'] < $xMin || $v['age'] > $xMax) {
            continue;
        }
        $pace = $medianPace($v['age']);
        $cmPerYear = rust_h(rust_velocity_unit());
        $parts = array(
            rust_date_cz($v['date']),
            rust_num($v['cm_per_year']) . ' ' . $cmPerYear,
            th('velocity_since', array(
                'date' => rust_date_cz($v['from_date']),
                'span' => rust_years_span_cz($v['years']),
            )),
        );
        if ($pace !== null) {
            $parts[] = t('tooltip_median_pace', array(
                'pace' => rust_num($pace),
                'unit' => $cmPerYear,
            ));
        }
        $svg[] = sprintf(
            '<circle class="rust-child-dot rust-ukazatel" cx="%.1f" cy="%.1f" r="2.8"'
            . ' tabindex="0" role="img"><title>%s</title></circle>',
            $sx($v['age']), $sy($v['cm_per_year']), rust_h(implode(' · ', $parts))
        );
    }

    $svg[] = '</svg>';

    $legend = array(array('mereni', t('legend_measured_velocity')));
    if ($smoothCurve) {
        $legend[] = array('vyrovnani', t('legend_smoothed'));
    }
    $legend[] = array('tempo', t('legend_median_pace'));
    $items = array();
    foreach ($legend as $entry) {
        $items[] = sprintf('<span class="rust-legenda rust-legenda-%s">%s</span>',
            $entry[0], rust_h($entry[1]));
    }
    $svg[] = '<p class="rust-legenda-radek">' . implode(' ', $items) . '</p>';
    $svg[] = '</figure>';

    return implode("\n", $svg);
}

/* ------------------------------------------------------------- path helpers */

function rust_line_path($curve, $sx, $sy)
{
    $parts = array();
    foreach ($curve as $i => $pair) {
        $parts[] = sprintf('%s%.1f %.1f', $i === 0 ? 'M' : 'L', $sx($pair[0]), $sy($pair[1]));
    }
    return implode(' ', $parts);
}

/** A closed path following $lower forward and $upper back, for the shaded band. */
function rust_area_path($lower, $upper, $sx, $sy)
{
    if (!$lower || !$upper) {
        return '';
    }
    $parts = array();
    foreach ($lower as $i => $pair) {
        $parts[] = sprintf('%s%.1f %.1f', $i === 0 ? 'M' : 'L', $sx($pair[0]), $sy($pair[1]));
    }
    for ($i = count($upper) - 1; $i >= 0; $i--) {
        $parts[] = sprintf('L%.1f %.1f', $sx($upper[$i][0]), $sy($upper[$i][1]));
    }
    $parts[] = 'Z';
    return implode(' ', $parts);
}

/** Round tick positions at a 1/2/5 x 10^n step, so labels read cleanly. */
function rust_nice_ticks($min, $max, $target)
{
    $span = $max - $min;
    if ($span <= 0) {
        return array($min);
    }
    $rough = $span / max(1, $target);
    $magnitude = pow(10, floor(log10($rough)));
    $normalised = $rough / $magnitude;
    if ($normalised < 1.5) {
        $step = 1 * $magnitude;
    } elseif ($normalised < 3) {
        $step = 2 * $magnitude;
    } elseif ($normalised < 7) {
        $step = 5 * $magnitude;
    } else {
        $step = 10 * $magnitude;
    }

    $ticks = array();
    $first = ceil($min / $step) * $step;
    for ($v = $first; $v <= $max + 1e-9; $v += $step) {
        $ticks[] = round($v, 6);
    }
    return $ticks;
}

function rust_tick_label($value)
{
    if (abs($value - round($value)) < 1e-9) {
        return (string)(int)round($value);
    }
    /* Same locale-aware decimal separator as everywhere else - see rust_num()
       in data.inc. */
    return rust_num($value, 1);
}
