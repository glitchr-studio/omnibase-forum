/*
 * The index's group toggles: the triangle in a group's title band folds its
 * boards away, the way the 2004 BBS did (index_body_cat_close / _open). What
 * is folded is remembered in the browser, per group, and nothing else.
 */
(function () {
    'use strict';

    var KEY = 'forum.folded';

    function load() {
        try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; }
    }
    function save(folded) {
        try { localStorage.setItem(KEY, JSON.stringify(folded)); } catch (e) { /* private window: forget it */ }
    }

    function init() {
        var folded = load();
        document.querySelectorAll('.forum-group-toggle[aria-controls]').forEach(function (button) {
            var list = document.getElementById(button.getAttribute('aria-controls'));
            if (!list) return;
            var id = button.dataset.group;

            function set(open) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                list.hidden = !open;
                button.closest('.forum-group').classList.toggle('is-folded', !open);
            }

            set(!folded[id]);
            button.addEventListener('click', function () {
                var open = button.getAttribute('aria-expanded') !== 'true';
                set(open);
                if (open) delete folded[id]; else folded[id] = 1;
                save(folded);
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
