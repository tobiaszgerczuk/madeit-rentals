(function () {
    'use strict';

    var DAY = 86400000;

    function parse(iso) {
        var p = iso.split('-');
        return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    }

    function fmt(date) {
        return date.toISOString().slice(0, 10);
    }

    function add(iso, days) {
        return fmt(new Date(parse(iso).getTime() + days * DAY));
    }

    function init(root) {
        var cfg;
        try {
            cfg = JSON.parse(root.getAttribute('data-madeit-rn'));
        } catch (e) {
            return;
        }
        var form = root.closest('form');
        var button = form ? form.querySelector('.single_add_to_cart_button') : null;
        var qtyInput = form ? form.querySelector('input.qty, input[name="quantity"]') : null;
        var calendar = root.querySelector('[data-rn-calendar]');
        var hint = root.querySelector('[data-rn-hint]');
        var summary = root.querySelector('[data-rn-summary]');
        var errorBox = root.querySelector('[data-rn-error]');
        var startField = root.querySelector('[data-rn-start]');
        var endField = root.querySelector('[data-rn-end]');
        var lang = document.documentElement.lang || 'en-US';
        var monthFormat = new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric', timeZone: 'UTC' });
        var dateFormat = new Intl.DateTimeFormat(lang, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
        var state = { start: '', end: '', month: null, avail: null, token: 0 };

        function quantity() {
            var value = qtyInput ? parseInt(qtyInput.value, 10) : 1;
            return value > 0 ? value : 1;
        }

        function nightFree(iso) {
            var a = state.avail;
            if (!a || iso < a.first || iso > a.last) {
                return false;
            }
            var left = Object.prototype.hasOwnProperty.call(a.remaining, iso) ? a.remaining[iso] : a.units;
            return left >= quantity();
        }

        function endValid(iso) {
            if (!state.start || iso <= state.start) {
                return false;
            }
            for (var d = state.start; d < iso; d = add(d, 1)) {
                if (!nightFree(d)) {
                    return false;
                }
            }
            return iso <= add(state.avail.last, 1);
        }

        function setButton(enabled) {
            if (button) {
                button.disabled = !enabled;
                button.classList.toggle('disabled', !enabled);
            }
        }

        function setError(messages) {
            errorBox.hidden = !messages.length;
            errorBox.textContent = messages.join(' ');
        }

        function setHint() {
            if (state.start && state.end) {
                hint.textContent = dateFormat.format(parse(state.start)) + ' → ' + dateFormat.format(parse(state.end));
            } else {
                hint.textContent = state.start ? cfg.hintEnd : cfg.hintStart;
            }
        }

        function render() {
            if (!state.avail) {
                calendar.textContent = '';
                return;
            }
            var first = new Date(Date.UTC(state.month.getUTCFullYear(), state.month.getUTCMonth(), 1));
            var offset = (first.getUTCDay() + 6) % 7;
            var daysInMonth = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
            var html = '<div class="madeit-rn__cal"><div class="madeit-rn__head">';
            html += '<button type="button" class="madeit-rn__nav" data-rn-nav="-1" aria-label="' + cfg.i18n.prevMonth + '">‹</button>';
            html += '<strong>' + monthFormat.format(first) + '</strong>';
            html += '<button type="button" class="madeit-rn__nav" data-rn-nav="1" aria-label="' + cfg.i18n.nextMonth + '">›</button></div><div class="madeit-rn__grid">';
            var weekday = new Intl.DateTimeFormat(lang, { weekday: 'short', timeZone: 'UTC' });
            for (var w = 0; w < 7; w++) {
                html += '<span class="madeit-rn__dow">' + weekday.format(new Date(Date.UTC(2024, 0, 1 + w))) + '</span>';
            }
            for (var i = 0; i < offset; i++) {
                html += '<span></span>';
            }
            for (var day = 1; day <= daysInMonth; day++) {
                var iso = fmt(new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), day)));
                var selectable;
                if (state.start && !state.end) {
                    selectable = nightFree(iso) || endValid(iso);
                } else {
                    selectable = nightFree(iso);
                }
                var classes = 'madeit-rn__day';
                if (!selectable) {
                    classes += ' is-disabled';
                }
                if (iso === state.start) {
                    classes += ' is-start';
                } else if (iso === state.end) {
                    classes += ' is-end';
                } else if (state.start && state.end && iso > state.start && iso < state.end) {
                    classes += ' is-between';
                }
                html += '<button type="button" class="' + classes + '" data-rn-date="' + iso + '"' + (selectable ? '' : ' disabled') + '>' + day + '</button>';
            }
            calendar.innerHTML = html + '</div></div>';
        }

        function reset(keepStart) {
            state.end = '';
            if (!keepStart) {
                state.start = '';
            }
            startField.value = state.start;
            endField.value = '';
            summary.innerHTML = '';
            setError([]);
            setButton(false);
            setHint();
            render();
        }

        function quote() {
            var token = ++state.token;
            var url = new URL(cfg.quote, window.location.href);
            url.searchParams.set('product', cfg.product);
            url.searchParams.set('start', state.start);
            url.searchParams.set('end', state.end);
            url.searchParams.set('qty', quantity());
            setButton(false);
            fetch(url.toString(), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (token !== state.token) {
                        return;
                    }
                    setError(data.errors || []);
                    summary.innerHTML = data.valid ? data.summary : '';
                    startField.value = data.valid ? state.start : '';
                    endField.value = data.valid ? state.end : '';
                    setButton(!!data.valid);
                })
                .catch(function () {
                    if (token === state.token) {
                        setError([cfg.i18n.quoteFailed]);
                    }
                });
        }

        function pick(iso) {
            if (!state.start || state.end || iso <= state.start) {
                if (!nightFree(iso)) {
                    return;
                }
                state.start = iso;
                reset(true);
                return;
            }
            if (!endValid(iso)) {
                return;
            }
            state.end = iso;
            setHint();
            render();
            quote();
        }

        calendar.addEventListener('click', function (event) {
            var target = event.target.closest('button');
            if (!target) {
                return;
            }
            event.preventDefault();
            if (target.hasAttribute('data-rn-nav')) {
                state.month = new Date(Date.UTC(state.month.getUTCFullYear(), state.month.getUTCMonth() + parseInt(target.getAttribute('data-rn-nav'), 10), 1));
                render();
            } else if (target.hasAttribute('data-rn-date')) {
                pick(target.getAttribute('data-rn-date'));
            }
        });

        if (qtyInput) {
            qtyInput.addEventListener('change', function () {
                if (state.start && state.end) {
                    quote();
                }
                render();
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!startField.value || !endField.value) {
                    event.preventDefault();
                    setError([cfg.i18n.pickDates]);
                }
            });
        }

        setButton(false);
        var url = new URL(cfg.availability, window.location.href);
        url.searchParams.set('product', cfg.product);
        fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                state.avail = data;
                state.month = parse(data.first);
                render();
            })
            .catch(function () {
                setError([cfg.i18n.loadFailed]);
            });
    }

    function boot() {
        document.querySelectorAll('[data-madeit-rn]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
