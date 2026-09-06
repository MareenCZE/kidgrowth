/*
 * Progressive enhancement for the growth tracker.
 *
 * Everything works without this file: pages are server-rendered, the charts are
 * inline SVG, and every mark on them - measurement dots and the two adult-height
 * predictions - carries a <title> that browsers show as a native tooltip.
 * The rule throughout: feature detect, and bail out silently rather than
 * breaking what already works.
 */
(function () {
    'use strict';

    /* The service worker only makes the app open faster from the home screen,
       so a browser without one loses nothing that matters. */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () {
                /* Registration fails on plain http and in private windows.
                   That is not an error worth showing anyone. */
            });
        });
    }

    /* Comma or dot, whichever the phone keyboard offered. The server accepts
       both; this just avoids the browser rejecting a comma in a number field
       before the form is ever submitted. */
    var fields = document.querySelectorAll('input[inputmode="decimal"]');
    Array.prototype.forEach.call(fields, function (field) {
        field.addEventListener('blur', function () {
            field.value = field.value.replace(/\s+/g, '').replace('.', ',');
        });
    });

    /* ------------------------------------------- edit and delete dialogs */

    /* The row's edit and delete controls are ordinary links to ordinary
       pages, and stay that way if any of this is missing: the dialogs are
       server-rendered forms posting the same fields to the same endpoint, so
       nothing here is the only way to reach a behaviour. */
    var editDialog = document.querySelector('[data-growth-dialog="edit"]');
    var deleteDialog = document.querySelector('[data-growth-dialog="delete"]');
    var dialogsUsable = editDialog && deleteDialog
        && typeof editDialog.showModal === 'function';

    if (dialogsUsable) {
        var closeButtons = document.querySelectorAll('[data-growth-dialog-close]');
        Array.prototype.forEach.call(closeButtons, function (button) {
            button.addEventListener('click', function () {
                var dialog = button.closest ? button.closest('dialog') : null;
                if (dialog) {
                    dialog.close();
                }
            });
        });

        /* One listener on the table rather than one per row: the rows are
           rewritten on every save, and this way nothing has to be re-bound. */
        document.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('a.growth-edit, a.growth-delete') : null;
            if (!link) {
                return;
            }
            var row = link.closest('tr');
            if (!row || !row.getAttribute('data-measurement')) {
                return;
            }

            var editing = link.classList.contains('growth-edit');
            var dialog = editing ? editDialog : deleteDialog;
            var form = dialog.querySelector('form');
            form.elements.id.value = row.getAttribute('data-measurement');

            if (editing) {
                form.elements.date.value = row.getAttribute('data-date') || '';
                form.elements.height.value = row.getAttribute('data-height') || '';
                form.elements.weight.value = row.getAttribute('data-weight') || '';
                form.elements.note.value = row.getAttribute('data-note') || '';
            } else {
                var line = dialog.querySelector('[data-growth-confirm]');
                /* The sentence was translated server-side with {date} left in
                   it, so the row's own date can be dropped into it here. */
                line.textContent = line.getAttribute('data-growth-confirm')
                    .replace('{date}', row.cells[0].textContent.trim());
            }

            event.preventDefault();
            dialog.showModal();
            var first = dialog.querySelector('input:not([type="hidden"]), button');
            if (first) {
                first.focus();
            }
        });
    }


    /* ------------------------------------------------ charts: readout + full screen */

    var charts = document.querySelectorAll('[data-growth-chart]');
    if (!charts.length || !document.querySelector) {
        return;
    }

    Array.prototype.forEach.call(charts, function (figure) {
        var readout = figure.querySelector('.growth-chart-value');
        var button = figure.querySelector('.growth-chart-full');
        /* Measurement dots and the two adult-height predictions alike: anything
           carrying a <title> is worth reading out. */
        var marks = figure.querySelectorAll('.growth-marker');

        /* --- the readout ------------------------------------------------ */

        /* The <title> is the single source of truth. It is what a browser shows
           natively with JavaScript off, so reusing it here means the tooltip and
           the readout can never disagree. */
        function describe(mark) {
            var title = mark.querySelector('title');
            return title ? title.textContent : '';
        }

        function show(mark) {
            if (readout) {
                readout.textContent = describe(mark);
            }
            Array.prototype.forEach.call(marks, function (other) {
                other.classList.toggle('growth-selected', other === mark);
            });
        }

        function clear() {
            if (readout) {
                readout.textContent = '';
            }
            Array.prototype.forEach.call(marks, function (other) {
                other.classList.remove('growth-selected');
            });
        }

        Array.prototype.forEach.call(marks, function (mark) {
            /* pointerenter covers mouse and pen; a phone has no hover at all,
               so a tap has to work too, and click fires there. */
            mark.addEventListener('pointerenter', function () { show(mark); });
            mark.addEventListener('focus', function () { show(mark); });
            mark.addEventListener('click', function (event) {
                event.stopPropagation();
                show(mark);
            });
        });

        /* Leaving the chart clears the readout, but only for pointers - a
           touch user needs the value to stay put after they lift their finger. */
        figure.addEventListener('pointerleave', function (event) {
            if (event.pointerType !== 'touch') {
                clear();
            }
        });

        /* --- full screen ------------------------------------------------ */

        if (!button) {
            return;
        }
        var canNative = !!(figure.requestFullscreen || figure.webkitRequestFullscreen);

        /* The button stays hidden until we know it can do something. iOS
           Safari has no Fullscreen API on non-video elements, so there we fall
           back to a CSS class that fills the viewport - which is all the user
           actually wanted, a bigger chart. */
        button.hidden = false;

        button.addEventListener('click', function () {
            var isNative = document.fullscreenElement === figure
                || document.webkitFullscreenElement === figure;

            if (canNative && !isNative && !figure.classList.contains('growth-fullscreen')) {
                var request = figure.requestFullscreen || figure.webkitRequestFullscreen;
                var result = request.call(figure);
                if (result && typeof result.catch === 'function') {
                    result.catch(function () {
                        figure.classList.add('growth-fullscreen');
                    });
                }
                return;
            }
            if (isNative) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                return;
            }
            figure.classList.toggle('growth-fullscreen');
        });
    });

    /* Escape leaves the CSS fallback, matching what it does for real full
       screen - otherwise a phone user has no obvious way back. */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var open = document.querySelector('.growth-fullscreen');
        if (open) {
            open.classList.remove('growth-fullscreen');
        }
    });
}());
