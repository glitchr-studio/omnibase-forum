/*
 * The index's group toggles: the triangle in a group's title band folds its
 * boards away, the way the 2004 BBS did (index_body_cat_close / _open). What
 * is folded is remembered in the browser, per group, and nothing else.
 *
 * A site that swaps pages in place (transparent.js on Chapaland) brings the
 * index back without a new document, and runs this script again with it: one
 * copy only, and each toggle marked once it is wired - wired twice, a click
 * folded the group and unfolded it again.
 */
(function () {
    'use strict';

    if (window.ForumIndex) {
        window.ForumIndex.scan();
        return;
    }

    var KEY = 'forum.folded';

    function load() {
        try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; }
    }
    function save(folded) {
        try { localStorage.setItem(KEY, JSON.stringify(folded)); } catch (e) { /* private window: forget it */ }
    }

    function scan() {
        document.querySelectorAll('.forum-group-toggle[aria-controls]').forEach(function (button) {
            if ('forumBound' in button.dataset) return;
            var list = document.getElementById(button.getAttribute('aria-controls'));
            if (!list) return;
            button.dataset.forumBound = '';
            var id = button.dataset.group;

            function set(open) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                list.hidden = !open;
                button.closest('.forum-group').classList.toggle('is-folded', !open);
            }

            set(!load()[id]);
            button.addEventListener('click', function () {
                var open = button.getAttribute('aria-expanded') !== 'true';
                var folded = load();
                set(open);
                if (open) delete folded[id]; else folded[id] = 1;
                save(folded);
            });
        });
    }

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
    window.ForumIndex = { scan: soon };
    if (document.body) watch(); else document.addEventListener('DOMContentLoaded', watch, { once: true });
})();
