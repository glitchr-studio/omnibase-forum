/*
 * The BBS's topic lists read as one, the way Discourse's lists read: no pages
 * to click through. The feed (index_fil.html.twig, its <ol>) and the lists of
 * a board, a tag and a search (_topic_list.html.twig, its <tbody>): any
 * [data-forum-feed] whose rows carry data-topic and data-page.
 *
 * The server still cuts the discussions in pages (?page=N) - for a reader
 * without this script and for the search engines - and the page asked for is
 * the one drawn. From there:
 *
 *   - the next page is fetched as the last row comes near the window, and its
 *     rows added at the end (ForumController answers the stream header with
 *     the rows alone: _feed_rows.html.twig, _topic_rows.html.twig);
 *   - on a page opened past the first, the page before it is fetched as the
 *     first row comes near - once the reader has moved, so an address pointing
 *     into the list is honoured - and added above without moving what is being
 *     read: the difference is measured, the browsers' own scroll anchoring
 *     not being the same everywhere;
 *   - a discussion already in the list is not added twice: a list by latest
 *     activity moves while it is read, and a topic bumped to the top between
 *     two fetches would otherwise come back on the next page;
 *   - the address keeps the page of the row at the top of the window, so a
 *     reload or a link shared lands near where the reader was;
 *   - the pager steps aside.
 *
 * Started for the list on the page and started over for the next one: a site
 * that swaps pages in place (transparent.js on Chapaland) keeps this loaded.
 * Such a site also runs the script again when a page brings its <script> back:
 * the first copy stays the only one, a later run only asks it to look again -
 * two copies each streamed the same list.
 */
(function () {
    'use strict';

    if (window.ForumFeed) {
        window.ForumFeed.boot();
        return;
    }

    var STREAM_HEADER = 'X-Forum-Stream';
    var AHEAD = '900px 0px';

    var live = null;

    function boot() {
        var list = document.querySelector('[data-forum-feed]');
        if (live) {
            if (live.list === list && list && list.isConnected) return;
            live.destroy();
            live = null;
        }
        if (list) live = start(list);
    }

    var queued = false;
    function soon() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; boot(); });
    }
    function watch() {
        boot();
        new MutationObserver(soon).observe(document.body, { childList: true, subtree: true });
    }
    window.ForumFeed = { boot: soon };
    if (document.body) watch(); else document.addEventListener('DOMContentLoaded', watch, { once: true });

    function start(list) {
        var pages = Math.max(1, parseInt(list.getAttribute('data-pages'), 10) || 1);
        var first = Math.max(1, parseInt(list.getAttribute('data-page'), 10) || 1);
        if (pages < 2 || !('IntersectionObserver' in window) || !window.fetch) return { list: list, destroy: function () {} };

        var baseUrl = list.getAttribute('data-url') || location.pathname;
        var loaded = { min: first, max: first };
        // What holds the list and its pager, which steps aside.
        var section = list.closest('.forum-list-stream, .forum-feed') || list.parentNode;
        // Where a status line may go: beside the table, not inside it.
        var block = list.tagName === 'TBODY' ? (list.closest('table') || list) : list;
        var aborter = window.AbortController ? new AbortController() : null;
        var busy = { before: false, after: false };
        var failed = { before: false, after: false };
        var moved = false;
        var bound = [];
        function on(target, type, fn, options) {
            target.addEventListener(type, fn, options);
            bound.push([target, type, fn, options]);
        }

        section.classList.add('is-streaming');

        function urlOf(page) {
            var url = new URL(baseUrl, location.href);
            if (page > 1) url.searchParams.set('page', page); else url.searchParams.delete('page');
            return url.pathname + url.search;
        }

        /* ── the status lines: loading, or a failure the next scroll retries ── */
        var status = {};
        function say(where, state) {
            var el = status[where];
            if (!el) {
                el = status[where] = document.createElement('p');
                el.className = 'forum-stream-status';
                el.setAttribute('role', 'status');
                el.hidden = true;
                block.parentNode.insertBefore(el, where === 'before' ? block : block.nextSibling);
            }
            el.hidden = !state;
            el.classList.toggle('is-failed', state === 'failed');
            el.textContent = state === 'failed' ? list.getAttribute('data-failed') || '' : state ? list.getAttribute('data-loading') || '…' : '';
        }

        /* ── fetching a page ───────────────────────────────────────────── */
        function fetchRows(page) {
            return fetch(urlOf(page), {
                credentials: 'same-origin',
                headers: (function (h) { h[STREAM_HEADER] = '1'; return h; })({ Accept: 'text/html' }),
                signal: aborter ? aborter.signal : undefined
            }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            }).then(function (html) {
                var t = document.createElement('template');
                t.innerHTML = html;
                return Array.prototype.filter.call(t.content.children, function (el) { return el.matches('[data-topic]'); });
            });
        }

        function present(id) { return !!list.querySelector(':scope > [data-topic="' + CSS.escape(id) + '"]'); }

        // The row the reader is on: the first one still below the top of the window.
        function readingRow() {
            var rows = list.children, line = Math.min(80, window.innerHeight * 0.15);
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getBoundingClientRect().bottom > line) return rows[i];
            }
            return null;
        }

        function load(where) {
            var page = where === 'after' ? loaded.max + 1 : loaded.min - 1;
            if (page < 1 || page > pages || busy[where]) return;
            busy[where] = true;
            say(where, 'loading');
            fetchRows(page).then(function (rows) {
                busy[where] = false;
                failed[where] = false;
                say(where, null);
                if (!list.isConnected) return;
                rows = rows.filter(function (li) { return !present(li.getAttribute('data-topic')); });
                if (where === 'after') {
                    rows.forEach(function (li) { list.appendChild(li); });
                    loaded.max = page;
                } else {
                    var anchor = readingRow();
                    var before = anchor ? anchor.getBoundingClientRect().top : 0;
                    var head = list.firstElementChild;
                    rows.forEach(function (li) { list.insertBefore(li, head); });
                    var shift = anchor ? anchor.getBoundingClientRect().top - before : 0;
                    if (Math.abs(shift) > 0.5) window.scrollBy({ top: shift, behavior: 'instant' });
                    loaded.min = page;
                }
                watchEdges();
            }, function (e) {
                busy[where] = false;
                if (e && e.name === 'AbortError') { say(where, null); return; }
                failed[where] = true;
                say(where, 'failed');
            });
        }

        /* ── when to fetch: the first and last rows coming near ───────── */
        var edges = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) return;
                if (e.target === list.lastElementChild && loaded.max < pages) load('after');
                else if (e.target === list.firstElementChild && loaded.min > 1 && moved) load('before');
            });
        }, { rootMargin: AHEAD });
        function watchEdges() {
            edges.disconnect();
            if (list.firstElementChild) edges.observe(list.firstElementChild);
            if (list.lastElementChild && list.lastElementChild !== list.firstElementChild) edges.observe(list.lastElementChild);
        }
        watchEdges();

        // The page above waits for the reader to move; a failure is retried
        // at the next scroll that brings its end near again.
        function nudge() {
            if (!moved) moved = true;
            var head = list.firstElementChild, tail = list.lastElementChild;
            if (head && loaded.min > 1 && !busy.before && head.getBoundingClientRect().top > -900) load('before');
            if (tail && loaded.max < pages && !busy.after && failed.after && tail.getBoundingClientRect().bottom < window.innerHeight + 900) load('after');
            track();
        }
        // pointerdown: the scrollbar dragged is a move too.
        ['wheel', 'touchmove', 'keydown', 'pointerdown'].forEach(function (name) { on(window, name, nudge, { passive: true }); });

        /* ── the address follows the reading ──────────────────────────── */
        var shown = first, pending = false;
        function track() {
            if (pending) return;
            pending = true;
            requestAnimationFrame(function () {
                pending = false;
                if (!moved || !history.replaceState) return;
                var row = readingRow();
                var page = row ? parseInt(row.getAttribute('data-page'), 10) || shown : shown;
                if (page === shown) return;
                shown = page;
                var href = urlOf(page) + location.hash;
                // The site's transition script keeps its own copy of the
                // address in history.state and replays it on Back: kept in step.
                var state = history.state;
                if (state && typeof state === 'object' && 'href' in state) state = Object.assign({}, state, { href: location.origin + href });
                try { history.replaceState(state, '', href); } catch (e) { /* a sandboxed frame */ }
            });
        }
        on(window, 'scroll', track, { passive: true });

        return {
            list: list,
            destroy: function () {
                edges.disconnect();
                if (aborter) aborter.abort();
                bound.forEach(function (b) { b[0].removeEventListener(b[1], b[2], b[3]); });
                bound = [];
                section.classList.remove('is-streaming');
            }
        };
    }
})();
