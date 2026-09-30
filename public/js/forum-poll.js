/*
 * The poll, both ends, framework-free:
 *
 *   - WRITING one (topic/new.html.twig): the "one answer per line" textarea
 *     becomes a row per answer, each with the radio - or, when several answers
 *     are allowed, the box - a voter will see, so the poll is written the way it
 *     will be read. Above them, "one answer" or "several, up to N". The rows and
 *     the choice are written back into the form's own fields (pollOptions, one
 *     line per answer; pollMax), so the server reads exactly what it read
 *     before, and without this script the textarea still works.
 *   - VOTING on one that allows several (topic/_poll.html.twig): once as many
 *     boxes are ticked as the poll allows, the others are shut, and the vote
 *     button waits for at least one.
 *
 * Like forum.js, it may run again when a page is swapped in: each form is
 * marked once it is taken care of.
 */
(function () {
    'use strict';

    var BOUND = 'forumPollBound';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') node.textContent = attrs[k];
            else node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(c); });
        return node;
    }

    function builder(host) {
        if (host.dataset[BOUND]) return;
        var form = host.closest('form');
        var area = form && form.querySelector('textarea[data-forum-poll-options]');
        var maxInput = form && form.querySelector('input[data-forum-poll-max]');
        if (!area || !maxInput) return;
        host.dataset[BOUND] = '1';

        var t = JSON.parse(host.dataset.labels || '{}');
        var limit = parseInt(host.dataset.max, 10) || 10;
        var name = 'poll-mode-' + Math.random().toString(36).slice(2);

        // The two fields the form sends stay in it, out of sight.
        [area, maxInput].forEach(function (field) {
            var row = field.closest('.form-group, .mb-3, div');
            (row && row !== form ? row : field).hidden = true;
        });

        var one = el('input', { type: 'radio', name: name, value: 'one' });
        var many = el('input', { type: 'radio', name: name, value: 'many' });
        var upTo = el('input', { type: 'number', min: '2', class: 'forum-poll-upto', 'aria-label': t.upTo || '' });
        var modes = el('div', { class: 'forum-poll-modes', role: 'radiogroup', 'aria-label': t.max || '' }, [
            el('label', {}, [one, el('span', { text: t.one })]),
            el('label', {}, [many, el('span', { text: t.many }), el('span', { class: 'forum-poll-upto-text', text: t.upTo }), upTo])
        ]);
        var list = el('ol', { class: 'forum-poll-rows' });
        var add = el('button', { type: 'button', class: 'forum-poll-add', 'data-enter-ignore': '' }, [
            el('i', { class: 'fa-solid fa-plus', 'aria-hidden': 'true' }), el('span', { text: ' ' + (t.add || '+') })
        ]);
        host.appendChild(modes);
        host.appendChild(list);
        host.appendChild(add);

        function rows() { return Array.prototype.slice.call(list.querySelectorAll('input[type=text]')); }
        function filled() { return rows().map(function (i) { return i.value.trim(); }).filter(Boolean); }

        function sync() {
            area.value = filled().join('\n');
            var multiple = many.checked;
            var count = Math.max(2, rows().length);
            upTo.max = String(count);
            upTo.disabled = !multiple;
            var n = Math.min(Math.max(parseInt(upTo.value, 10) || 2, 2), count);
            if (multiple) upTo.value = String(n);
            maxInput.value = multiple ? String(n) : '1';
            host.classList.toggle('is-multiple', multiple);
            add.disabled = rows().length >= limit;
            rows().forEach(function (input, i) {
                input.placeholder = (t.answer || '#{n}').replace('{n}', i + 1);
                input.closest('li').querySelector('.forum-poll-remove').disabled = rows().length <= 2;
            });
        }

        function row(value, focus) {
            var input = el('input', { type: 'text', maxlength: '120', autocomplete: 'off' });
            input.value = value || '';
            var remove = el('button', { type: 'button', class: 'forum-poll-remove', 'data-enter-ignore': '', title: t.remove || '', 'aria-label': t.remove || '' }, [
                el('i', { class: 'fa-solid fa-xmark', 'aria-hidden': 'true' })
            ]);
            var li = el('li', {}, [el('span', { class: 'forum-poll-mark', 'aria-hidden': 'true' }), input, remove]);
            list.appendChild(li);
            input.addEventListener('input', sync);
            // Enter is the next answer, not the whole form sent half written - and nobody
            // else's. base-bundle's form.js binds its own Enter on every field once the page
            // has loaded, on the field itself, and clicks the button nearest to it: this
            // row's "remove", which took the answer just typed with it. This listener is on
            // the field first, so it stops the others (stopImmediatePropagation); the rows'
            // buttons also carry data-enter-ignore, base-bundle's own opt-out.
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                e.stopImmediatePropagation();
                var next = li.nextElementSibling && li.nextElementSibling.querySelector('input');
                if (next) next.focus();
                else if (rows().length < limit) { row('', true); sync(); }
            });
            remove.addEventListener('click', function () {
                if (rows().length <= 2) return;
                var prev = li.previousElementSibling || li.nextElementSibling;
                li.remove();
                sync();
                if (prev) prev.querySelector('input').focus();
            });
            if (focus) input.focus();
        }

        // What the form already holds (sent back with an error), else two empty answers.
        var lines = area.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
        (lines.length ? lines : ['', '']).forEach(function (l) { row(l); });
        if (rows().length < 2) row('');
        var max = parseInt(maxInput.value, 10) || 1;
        (max > 1 ? many : one).checked = true;
        upTo.value = String(Math.max(2, max));

        [one, many].forEach(function (r) { r.addEventListener('change', function () { sync(); if (many.checked) upTo.focus(); }); });
        upTo.addEventListener('input', sync);
        upTo.addEventListener('change', sync);
        add.addEventListener('click', function () { if (rows().length < limit) { row('', true); sync(); } });
        sync();

        // A board that takes no poll (Category::$polls) hides the whole section: its choice says so.
        var section = host.closest('.forum-form-poll');
        var board = form.querySelector('select[name$="[category]"]');
        function followBoard() {
            var option = board && board.selectedOptions[0];
            if (section) section.hidden = !!option && option.getAttribute('data-polls') === '0';
        }
        if (board) {
            board.addEventListener('change', followBoard);
            // select2 (when the site draws the board list with it) says so through jQuery only.
            if (window.jQuery) window.jQuery(board).on('change', followBoard);
            followBoard();
        }
    }

    function ballot(foot) {
        var form = foot.closest('form');
        if (!form || form.dataset[BOUND]) return;
        form.dataset[BOUND] = '1';
        var max = parseInt(foot.dataset.forumPollLimit, 10) || 1;
        var boxes = Array.prototype.slice.call(form.querySelectorAll('input[data-forum-poll-pick]'));
        var submit = foot.querySelector('button[type=submit]');
        function update() {
            var ticked = boxes.filter(function (b) { return b.checked; }).length;
            boxes.forEach(function (b) {
                b.disabled = !b.checked && ticked >= max;
                b.closest('label').classList.toggle('is-shut', b.disabled);
            });
            if (submit) submit.disabled = ticked === 0;
        }
        boxes.forEach(function (b) { b.addEventListener('change', update); });
        update();
    }

    function scan() {
        document.querySelectorAll('[data-forum-poll-builder]').forEach(builder);
        document.querySelectorAll('[data-forum-poll-limit]').forEach(ballot);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
    else scan();
})();
