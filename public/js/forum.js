/*
 * The forum's few behaviours, framework-free:
 *   - like / follow buttons calling base-bundle's /api/thread/{slug}/... endpoints;
 *   - a confirm() on the destructive moderation buttons;
 *   - a tiny Markdown toolbar on every [data-forum-editor] textarea;
 *   - "Sauter vers", the board jump at the foot of a topic.
 *
 * A site that swaps pages in place (transparent.js on Chapaland) brings new
 * buttons and editors without a new document, and runs this script again when
 * a page brings its <script> back. So: one copy only - a later run asks the
 * first to look again; the document-wide listeners are added once; and each
 * button or editor is marked when it is taken care of, so none is served
 * twice (a like toggled twice undid itself) and none that arrives later - a
 * page swapped in, the messages a topic's stream adds - is left out.
 */
(function () {
    'use strict';

    if (window.ForumBehaviours) {
        window.ForumBehaviours.scan();
        return;
    }

    var BOUND = 'forumBound';

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

    var tools = [
        ['fa-bold', 'Gras', '**', '**'],
        ['fa-italic', 'Italique', '_', '_'],
        ['fa-strikethrough', 'Barré', '~~', '~~'],
        ['fa-quote-left', 'Citation', '> ', ''],
        ['fa-code', 'Code', '`', '`'],
        ['fa-link', 'Lien', '[', '](https://)'],
        ['fa-list-ul', 'Liste', '- ', '']
    ];

    function editor(area) {
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
    }

    // Everything not yet taken care of, each once.
    function each(selector, fn) {
        document.querySelectorAll(selector).forEach(function (el) {
            if (BOUND in el.dataset) return;
            el.dataset[BOUND] = '';
            fn(el);
        });
    }
    function scan() {
        each('[data-forum-like]', function (b) {
            toggle(b, 'data-like', 'data-unlike', [b.dataset.labelOff || "J'aime", b.dataset.labelOn || 'Aimé']);
        });
        each('[data-forum-follow]', function (b) {
            toggle(b, 'data-follow', 'data-unfollow', [b.dataset.labelOff || 'Suivre', b.dataset.labelOn || 'Suivi']);
        });
        each('textarea[data-forum-editor]', editor);
    }

    // On the document, once: the buttons a topic's stream adds later and a page
    // swapped in carry these too.
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-forum-moderate] button[data-confirm]') : null;
        if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
    });

    // "Sauter vers", at the foot of a topic (topic/show.html.twig): the
    // chosen board's address is the option's value.
    document.addEventListener('submit', function (e) {
        var form = e.target && e.target.closest ? e.target.closest('[data-forum-jump]') : null;
        if (!form) return;
        var select = form.querySelector('select');
        if (!select || !select.value) return;
        e.preventDefault();
        window.location.href = select.value;
    }, true);

    var queued = false;
    function soon() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; scan(); });
    }
    function watch() {
        scan();
        new MutationObserver(soon).observe(document.body, { childList: true, subtree: true });
    }
    window.ForumBehaviours = { scan: soon };
    if (document.body) watch(); else document.addEventListener('DOMContentLoaded', watch, { once: true });
})();
