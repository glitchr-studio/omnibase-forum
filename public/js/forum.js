/*
 * The forum's few behaviours, framework-free:
 *   - like / follow buttons calling base-bundle's /api/thread/{slug}/... endpoints;
 *   - a confirm() on the destructive moderation buttons;
 *   - a tiny Markdown toolbar on every [data-forum-editor] textarea.
 */
(function () {
    'use strict';

    function toggle(button, onAttr, offAttr, labels) {
        button.addEventListener('click', function () {
            var active = button.getAttribute('aria-pressed') === 'true';
            var url = button.getAttribute(active ? offAttr : onAttr);
            button.disabled = true;
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (data) {
                    active = !active;
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                    button.classList.toggle('is-active', active);
                    var span = button.querySelector('span');
                    if (span) span.textContent = labels[active ? 1 : 0];
                    if (typeof data.likes !== 'undefined') {
                        var counter = document.querySelector('[data-forum-likes]');
                        if (counter) counter.textContent = data.likes;
                    }
                })
                .catch(function () {})
                .then(function () { button.disabled = false; });
        });
    }

    document.querySelectorAll('[data-forum-like]').forEach(function (b) {
        toggle(b, 'data-like', 'data-unlike', [b.dataset.labelOff || "J'aime", b.dataset.labelOn || 'Aimé']);
    });
    document.querySelectorAll('[data-forum-follow]').forEach(function (b) {
        toggle(b, 'data-follow', 'data-unfollow', [b.dataset.labelOff || 'Suivre', b.dataset.labelOn || 'Suivi']);
    });

    document.querySelectorAll('[data-forum-moderate] button[data-confirm]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            if (!window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
        });
    });

    var tools = [
        ['fa-bold', 'Gras', '**', '**'],
        ['fa-italic', 'Italique', '_', '_'],
        ['fa-strikethrough', 'Barré', '~~', '~~'],
        ['fa-quote-left', 'Citation', '> ', ''],
        ['fa-code', 'Code', '`', '`'],
        ['fa-link', 'Lien', '[', '](https://)'],
        ['fa-list-ul', 'Liste', '- ', '']
    ];

    document.querySelectorAll('textarea[data-forum-editor]').forEach(function (area) {
        var bar = document.createElement('div');
        bar.className = 'forum-editor-bar';
        tools.forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'forum-btn forum-btn-ghost forum-btn-sm';
            b.title = t[1];
            b.innerHTML = '<i class="fa-solid ' + t[0] + '"></i>';
            b.addEventListener('click', function () {
                var s = area.selectionStart, e = area.selectionEnd, v = area.value;
                var inner = v.substring(s, e) || t[1].toLowerCase();
                area.value = v.substring(0, s) + t[2] + inner + t[3] + v.substring(e);
                area.focus();
                area.setSelectionRange(s + t[2].length, s + t[2].length + inner.length);
            });
            bar.appendChild(b);
        });
        area.parentNode.insertBefore(bar, area);
    });
})();
