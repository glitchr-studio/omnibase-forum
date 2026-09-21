/*
 * A topic's sense of place (topic/show.html.twig), from the outline the
 * controller computes (TopicController::outline()):
 *
 *   - the timeline, Discourse's scroller: a track standing for the whole
 *     topic, a handle following the reading, click / drag / arrow keys to go
 *     anywhere in it;
 *   - the stream, Discourse's way with a long topic: the server still cuts
 *     it in pages (for a reader without this script, and for the search
 *     engines), but here it reads as one. The pages before and after the one
 *     opened are fetched as the reader nears them and added in place - above
 *     without moving what is being read - and a jump to a message pages away
 *     fetches its page instead of reloading the document. The address and the
 *     "Page n/N" follow the reading; the page-number pagers step aside.
 *
 * Which message answers which is read in the thread itself - the "en réponse
 * à" line, the branch's indent, the ">>n" links - and is not drawn beside it.
 */
(function () {
    'use strict';

    /*
     * Started for the topic on the page, and started over for the next one.
     *
     * A site that swaps its pages in place (transparent.js on Chapaland)
     * keeps this script loaded from one page of a topic to the next, and
     * from one topic to another. Started once, it kept the first page's
     * posts, page number and handlers: on page 3, reached from page 1 by the
     * pager, a click on a page-3 message went through the page-1 start,
     * which found it on "another page" and reloaded the document. So the
     * whole thing is a start() with a destroy(): the document watches for
     * the timeline's root to change and starts again on the new one, taking
     * down the old one's listeners on the document, the window and the
     * media query, its timers and its observer - and the nav
     * itself when the narrow screen had parked it in <body>, out of the
     * content the site swapped.
     *
     * Such a site also runs this script again when a page brings its
     * <script> back, and two copies each started their own timeline on the
     * same nav. The first copy stays the only one: a later run only
     * asks it to look again.
     */
    if (window.ForumThread) {
        window.ForumThread.boot();
        return;
    }
    var live = null;

    function boot() {
        var nav = document.querySelector('[data-forum-timeline]');
        if (live) {
            if (live.alive() && nav === live.nav) return;
            live.destroy();
            live = null;
            nav = document.querySelector('[data-forum-timeline]');
        }
        if (nav) live = start(nav);
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
    window.ForumThread = { boot: soon };
    if (document.body) watch(); else document.addEventListener('DOMContentLoaded', watch, { once: true });

    function start(nav) {
    // The nav's own outline (topic/show.html.twig writes it inside the nav):
    // while a site swaps one page for the next, two can be in the document.
    var source = nav.querySelector('#forum-outline');
    if (!source) return null;

    var data;
    try { data = JSON.parse(source.textContent); } catch (e) { return null; }
    var posts = data.posts || [];
    var total = posts.length;
    if (!total) return null;

    // Every listener that outlives the nav's own DOM, for destroy().
    var bound = [];
    function on(target, type, fn, options) {
        target.addEventListener(type, fn, options);
        bound.push([target, type, fn, options]);
    }
    // Where the nav stands in the content: the narrow screen parks the nav
    // in <body> (settle()), and this marks its place - and, once the site
    // has swapped the content away, that the page is gone.
    var home = document.createComment('forum-timeline');
    nav.parentNode.insertBefore(home, nav);

    var labels = data.labels || {};

    /* ── the stream: the pages of the topic loaded around the one opened ── */
    var list = document.querySelector('[data-forum-stream]');
    var pages = list ? Math.max(1, parseInt(list.getAttribute('data-pages'), 10) || 1) : 1;
    var loaded = { min: data.page, max: data.page };
    var article = list ? list.closest('.forum-topic') : null;
    var pageCount = document.querySelector('[data-forum-pagecount]');
    var aborter = window.AbortController ? new AbortController() : null;
    if (article && pages > 1) article.classList.add('is-streaming');
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var byId = {};
    posts.forEach(function (p, i) { p.index = i; byId[p.id] = p; });

    function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
    function format(pattern, values) {
        return String(pattern || '').replace(/\{(\w+)\}/g, function (m, k) { return k in values ? values[k] : m; });
    }
    var dates = new Intl.DateTimeFormat(document.documentElement.lang || 'fr', { day: 'numeric', month: 'short', year: 'numeric' });
    function dayOf(p) { return p && p.at ? dates.format(new Date(p.at)) : ''; }

    var current = -1;
    nav.hidden = false;

    /* ── going somewhere ───────────────────────────────────────────────── */
    function hrefOf(p) { return data.url + (p.page > 1 ? '?page=' + p.page : '') + '#post-' + p.id; }
    function elementOf(p) { return p.page >= loaded.min && p.page <= loaded.max ? document.getElementById('post-' + p.id) : null; }

    // The whole topic is not on screen: a post is scrolled to when it is on
    // the page being read, and only loaded when it is on another one.
    // `instant` and prefers-reduced-motion both mean "no animation", and it is
    // said outright rather than left to 'auto': a site whose stylesheet sets
    // scroll-behavior: smooth would animate that one too.
    function scrollToPost(el, instant) {
        el.scrollIntoView({ behavior: instant || motion.matches ? 'instant' : 'smooth', block: 'start' });
    }

    // The address follows the reading without a navigation, and carries the
    // page - a bare '#post-12' would lose it. The site's transition script
    // (transparent.js) keeps its own copy of the address in history.state and
    // replays that on Back, so it is kept in step rather than fought.
    function setAddress(href) {
        if (!history.replaceState) return;
        var state = history.state;
        if (state && typeof state === 'object' && 'href' in state) {
            state = Object.assign({}, state, { href: location.origin + href });
        }
        try { history.replaceState(state, '', href); } catch (e) { /* a sandboxed frame: the scroll still happened */ }
    }

    function jump(i, instant) {
        var p = posts[clamp(Math.round(i), 0, total - 1)];
        reach(p).then(function (el) {
            if (!el) return;
            scrollToPost(el, instant);
            setAddress(hrefOf(p));
            setPosition(p.index);
        });
    }

    // The message's element, its page fetched first when it is not loaded: the
    // page next to the loaded ones is added to them, a page further away takes
    // their place. Without the stream (no list, or the fetch failing), a real
    // load of the page, which keeps its ?page.
    var pending = {};
    function fetchPage(n) {
        if (pending[n]) return pending[n];
        var request = fetch(data.url + (n > 1 ? '?page=' + n : ''), {
            credentials: 'same-origin',
            headers: { 'X-Forum-Stream': '1', 'Accept': 'text/html' },
            signal: aborter ? aborter.signal : undefined
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        }).then(function (html) {
            var t = document.createElement('template');
            t.innerHTML = html;
            return Array.prototype.filter.call(t.content.children, function (el) { return el.matches('li.forum-post'); });
        });
        pending[n] = request;
        var clear = function () { delete pending[n]; };
        request.then(clear, clear);
        return request;
    }

    var statusAfter = null, statusBefore = null;
    function status(where, state) {
        if (!list) return;
        var el = where === 'before' ? statusBefore : statusAfter;
        if (!el) {
            el = document.createElement('p');
            el.className = 'forum-stream-status';
            el.setAttribute('role', 'status');
            el.hidden = true;
            list.parentNode.insertBefore(el, where === 'before' ? list : list.nextSibling);
            if (where === 'before') statusBefore = el; else statusAfter = el;
        }
        el.hidden = !state;
        el.classList.toggle('is-failed', state === 'failed');
        el.textContent = state === 'failed' ? (labels.failed || '') : state ? (labels.loading || '…') : '';
    }

    function loadPage(n, where) {
        if (!list || n < 1 || n > pages) return Promise.resolve(false);
        if (n >= loaded.min && n <= loaded.max) return Promise.resolve(true);
        if (where === 'after' && n !== loaded.max + 1) where = 'replace';
        if (where === 'before' && n !== loaded.min - 1) where = 'replace';
        status(where === 'before' ? 'before' : 'after', 'loading');
        return fetchPage(n).then(function (items) {
            status('before', null);
            status('after', null);
            if (!list.isConnected || !items.length) return false;
            if (n >= loaded.min && n <= loaded.max) return true; // loaded meanwhile
            if (where === 'after' && n === loaded.max + 1) {
                items.forEach(function (li) { list.appendChild(li); });
                loaded.max = n;
            } else if (where === 'before' && n === loaded.min - 1) {
                // What is being read stays where it is on screen: the browser's
                // own scroll anchoring does it in some browsers and not in
                // others, so the difference is measured, not assumed.
                var anchor = readingElement() || list.firstElementChild;
                var before = anchor ? anchor.getBoundingClientRect().top : 0;
                var first = list.firstElementChild;
                items.forEach(function (li) { list.insertBefore(li, first); });
                var shift = anchor ? anchor.getBoundingClientRect().top - before : 0;
                if (Math.abs(shift) > 0.5) window.scrollBy({ top: shift, behavior: 'instant' });
                loaded.min = n;
            } else {
                while (list.firstChild) list.removeChild(list.firstChild);
                items.forEach(function (li) { list.appendChild(li); });
                loaded.min = loaded.max = n;
                seen.clear();
            }
            collect();
            watchEdges();
            schedule();
            return true;
        }, function (e) {
            status('before', null);
            status('after', e && e.name === 'AbortError' ? null : 'failed');
            return false;
        });
    }

    function reach(p) {
        var el = elementOf(p);
        if (el) return Promise.resolve(el);
        if (!list) { window.location.href = hrefOf(p); return Promise.resolve(null); }
        var where = p.page === loaded.max + 1 ? 'after' : p.page === loaded.min - 1 ? 'before' : 'replace';
        return loadPage(p.page, where).then(function (ok) {
            var found = elementOf(p);
            if (!ok || !found) { window.location.href = hrefOf(p); return null; }
            return found;
        });
    }

    /* ── where the reader is ───────────────────────────────────────────── */
    // The loaded messages, in order, gathered again whenever a page comes in.
    var onPage = [];
    var seen = new Set();
    var io = null;
    function collect() {
        onPage = posts.map(function (p) { return { post: p, el: elementOf(p) }; }).filter(function (x) { return x.el; });
        if (io) onPage.forEach(function (x) { io.observe(x.el); });
    }
    collect();
    // The message the reader is on, for keeping it in place when a page is added above.
    function readingElement() {
        var line = Math.min(96, window.innerHeight * 0.2), best = null;
        onPage.forEach(function (x) {
            var r = x.el.getBoundingClientRect();
            if (r.bottom > line && (!best || r.top < best.r.top)) best = { el: x.el, r: r };
        });
        return best ? best.el : null;
    }

    // The post crossing a line near the top of the window, and how far into it.
    function measure() {
        var line = Math.min(96, window.innerHeight * 0.2), best = null;
        function scan(list) {
            list.forEach(function (x) {
                var r = x.el.getBoundingClientRect();
                if (r.top <= line && (!best || r.top > best.r.top)) best = { x: x, r: r };
            });
        }
        scan(onPage.filter(function (x) { return seen.has(x.el); }));
        if (!best) scan(onPage);
        if (!best) return onPage.length ? onPage[0].post.index : 0;

        var pos = best.x.post.index + clamp((line - best.r.top) / Math.max(1, best.r.height), 0, 0.99);
        var doc = document.documentElement;
        if (window.innerHeight + window.scrollY >= doc.scrollHeight - 4) {
            pos = Math.max(pos, onPage[onPage.length - 1].post.index);
        }
        return Math.min(pos, total - 1);
    }

    var track = nav.querySelector('[data-timeline-track]');
    var handle = nav.querySelector('[data-timeline-handle]');
    var fill = nav.querySelector('[data-timeline-fill]');
    var label = nav.querySelector('[data-timeline-label]');
    var dateLabel = nav.querySelector('[data-timeline-date]');
    var pill = nav.querySelector('[data-timeline-pill]');
    var pillLabel = nav.querySelector('[data-timeline-pill-label]');

    nav.querySelector('[data-timeline-start]').textContent = dayOf(posts[0]);
    nav.querySelector('[data-timeline-end]').textContent = dayOf(posts[total - 1]);

    function fractionOf(pos) { return total > 1 ? clamp(pos / (total - 1), 0, 1) : 0; }

    // Draws the handle at a (fractional) position; returns the post index.
    function place(pos) {
        var i = clamp(Math.floor(pos + 1e-6), 0, total - 1), p = posts[i];
        var room = Math.max(0, track.clientHeight - handle.offsetHeight);
        var y = fractionOf(pos) * room;
        handle.style.transform = 'translateY(' + y.toFixed(1) + 'px)';
        fill.style.height = (y + handle.offsetHeight / 2).toFixed(1) + 'px';
        var text = format(labels.position, { current: i + 1, total: total });
        label.textContent = text;
        dateLabel.textContent = dayOf(p);
        if (pillLabel) pillLabel.textContent = text;
        handle.setAttribute('aria-valuenow', i + 1);
        handle.setAttribute('aria-valuetext', text + ', ' + dayOf(p));
        return i;
    }

    // The reader's own scrolling has begun: until then the address is the one
    // they came with (its #post is where arrive() takes them), after it the
    // address follows the reading.
    var moved = false;
    function setPosition(pos) {
        var i = place(pos);
        if (i !== current) {
            current = i;
            var p = posts[i];
            if (pageCount && pageCount.getAttribute('data-label')) {
                pageCount.textContent = format(pageCount.getAttribute('data-label'), { page: p.page, total: pages });
            }
            if (moved && !dragging && list && pages > 1) setAddress(i ? hrefOf(p) : data.url);
        }
    }

    // One tick per post while they fit; branch posts stand out.
    if (total <= 160) {
        var ticks = nav.querySelector('[data-timeline-ticks]');
        posts.forEach(function (p, i) {
            var t = document.createElement('span');
            t.className = 'forum-timeline-tick' + (p.branch ? ' is-branch' : '') + (p.deleted ? ' is-deleted' : '');
            t.style.top = 'calc((100% - var(--timeline-handle)) * ' + fractionOf(i).toFixed(4) + ' + var(--timeline-handle) / 2)';
            ticks.appendChild(t);
        });
    }

    var dragging = false, target = 0, queued = false;
    function schedule() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; if (!dragging) setPosition(measure()); });
    }
    var edges = null;
    function watchEdges() {
        if (!edges || !list) return;
        edges.disconnect();
        if (list.firstElementChild) edges.observe(list.firstElementChild);
        if (list.lastElementChild) edges.observe(list.lastElementChild);
    }
    if ('IntersectionObserver' in window) {
        io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) seen.add(e.target); else seen.delete(e.target); });
            schedule();
        });
        onPage.forEach(function (x) { io.observe(x.el); });

        // The first and last loaded messages coming near the window call in
        // the page before or after, a screen or two ahead of the reader.
        if (list && pages > 1) {
            edges = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (!e.isIntersecting || dragging) return;
                    if (e.target === list.lastElementChild && loaded.max < pages) loadPage(loaded.max + 1, 'after');
                    else if (e.target === list.firstElementChild && loaded.min > 1 && moved) loadPage(loaded.min - 1, 'before');
                });
            }, { rootMargin: '1200px 0px' });
            watchEdges();
        }
    }
    on(window, 'scroll', schedule, { passive: true });
    on(window, 'resize', schedule);

    /* ── moving the handle: pointer and keys ───────────────────────────── */
    // Where a press on the track lands.
    function positionAt(e) {
        var r = track.getBoundingClientRect(), h = handle.offsetHeight;
        return clamp((e.clientY - r.top - h / 2) / Math.max(1, r.height - h), 0, 1) * (total - 1);
    }
    // Once the drag is on, the pointer's own travel moves the handle, not where
    // the pointer is over the track: the track is sticky and the page scrolls
    // under it as the reading follows, so it slides away beneath a pointer held
    // perfectly still - and reading the position off it again chased that
    // movement, sending the handle to one end of the topic on its own.
    var from = null;
    function positionFrom(e) {
        var room = Math.max(1, track.clientHeight - handle.offsetHeight);
        return clamp(from.pos + (e.clientY - from.y) / room * (total - 1), 0, total - 1);
    }
    function preview(pos) {
        target = Math.round(pos);
        setPosition(target);
        if (elementOf(posts[target])) jump(target, true); // this page follows live
    }
    track.addEventListener('pointerdown', function (e) {
        if (e.button) return;
        from = { y: e.clientY, pos: positionAt(e) }; // before dragging, which positionFrom needs
        dragging = true;
        track.setPointerCapture(e.pointerId);
        handle.focus({ preventScroll: true });
        preview(from.pos);
        e.preventDefault();
    });
    track.addEventListener('pointermove', function (e) { if (dragging) preview(positionFrom(e)); });
    function release() {
        if (!dragging) return;
        dragging = false;
        jump(target, true);
    }
    track.addEventListener('pointerup', release);
    track.addEventListener('pointercancel', release);

    var keyTimer = null;
    handle.addEventListener('keydown', function (e) {
        var steps = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1, PageDown: 10, PageUp: -10 };
        var to = e.key === 'Home' ? 0 : e.key === 'End' ? total - 1 : e.key in steps ? clamp(current + steps[e.key], 0, total - 1) : null;
        if (to === null) return;
        e.preventDefault();
        setPosition(to);
        clearTimeout(keyTimer);
        // On this page, go at once; another page waits for the keys to settle.
        if (elementOf(posts[to])) jump(to, true);
        else keyTimer = setTimeout(function () { jump(to); }, 600);
    });

    nav.querySelector('[data-timeline-top]').addEventListener('click', function () { jump(0); });
    nav.querySelector('[data-timeline-bottom]').addEventListener('click', function () { jump(total - 1); });

    /* ── the narrow screen's pill ──────────────────────────────────────── */
    // A fixed pill is only fixed to the window if no ancestor is transformed
    // (a site's sticky header may `translate` its <main>), so on a narrow
    // screen the nav waits in <body>, and goes back to its column when wide.
    var narrow = window.matchMedia('(max-width: 900px)');
    function settle() {
        if (narrow.matches && nav.parentNode !== document.body) document.body.appendChild(nav);
        else if (!narrow.matches && nav.parentNode === document.body) home.parentNode.insertBefore(nav, home);
        schedule();
    }
    if (narrow.addEventListener) on(narrow, 'change', settle); else narrow.addListener(settle);
    settle();

    function open(yes) {
        nav.classList.toggle('is-open', yes);
        pill.setAttribute('aria-expanded', yes ? 'true' : 'false');
        if (yes) schedule();
    }
    pill.addEventListener('click', function () { open(!nav.classList.contains('is-open')); });
    on(document, 'keydown', function (e) { if (e.key === 'Escape' && nav.classList.contains('is-open')) { open(false); pill.focus(); } });
    on(document, 'pointerdown', function (e) { if (!nav.contains(e.target)) open(false); });

    setPosition(measure());

    /* ── the topic's own links ─────────────────────────────────────────── */
    /*
     * Every link into this topic - a post's number, its permalink, "en réponse
     * à #n", "n réponses" - goes to a message, not to a
     * page: when that message is already on screen the reader should travel to
     * it, not watch the whole page load again.
     *
     * The click is taken in the CAPTURE phase, on the document. The site's
     * transition script (transparent.js) listens for clicks on the document
     * too, and answers a link whose address carries ?page= with a full page
     * load - even when it points at the page already being read, because it
     * compares the link's ?page against an address it has itself rewritten
     * without one (see the address repair below). Reached first, and with the
     * default prevented, it stands down on its own: __main__ begins with
     * `if (e.defaultPrevented) return;`.
     */
    var topicPath = new URL(data.url, document.baseURI).pathname;

    // The post a link points at, or null for anything else (the quote and
    // branch tools go to #reply, the pager to another page: not ours).
    function postOfHref(href) {
        if (!href) return null;
        var url;
        try { url = new URL(href, document.baseURI); } catch (e) { return null; }
        if (url.pathname !== topicPath) return null;
        var m = /^#post-(\d+)$/.exec(url.hash);
        return m && byId[m[1]] ? byId[m[1]] : null;
    }

    var marked = null, markTimer = null;

    // Where the reader was sent. A post is not focusable of itself, so it is
    // made focusable for as long as it is the one being pointed at - that is
    // what carries a screen reader and the Tab key to the message, the way a
    // real fragment navigation would.
    function point(el) {
        if (marked) { marked.classList.remove('is-target'); }
        clearTimeout(markTimer);
        marked = el;
        el.setAttribute('tabindex', '-1');
        el.focus({ preventScroll: true });
        // The stylesheet rings .is-target as it rings :target (forum.css), which
        // only a real fragment navigation sets - not the replaceState above.
        // No ring of its own here: it used to add one whenever the stylesheet's
        // was not a box-shadow, and once the ring became an outline the post
        // wore two for these two seconds.
        el.classList.add('is-target');
        markTimer = setTimeout(function () {
            el.classList.remove('is-target');
            marked = null;
        }, 2200);
    }

    function follow(p, instant) {
        reach(p).then(function (el) {
            if (!el) return;
            scrollToPost(el, instant);
            point(el);
            setAddress(hrefOf(p));
            setPosition(p.index);
        });
    }

    on(document, 'click', function (e) {
        // Already answered, or the reader asking for a new tab/window: leave
        // the browser and the other handlers to it.
        if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a) return;
        // getAttribute, not .href: the address as the page wrote it, page and all.
        var p = postOfHref(a.getAttribute('href'));
        if (!p) return;
        e.preventDefault();
        follow(p);
    }, true);

    /*
     * Two repairs to the address, both of the same cause: transparent.js
     * rewrites it from the path alone when the page opens
     * (location.origin + location.pathname + location.hash), so a topic opened
     * at ?page=3 is shown as page 1's address while page 3 is on screen. A
     * reload - and base-bundle's own USER/INFO cookie reloads the first page
     * of a visit - then really does land on page 1.
     */
    if (data.page > 1) {
        var here = new URL(location.href);
        if (here.searchParams.get('page') !== String(data.page)) {
            here.searchParams.set('page', data.page);
            setAddress(here.pathname + here.search + here.hash);
        }
    }

    // Landing on a message: a link followed from another page, a reload, an
    // address someone shared. The browser's own jump happens before the page
    // has settled (and not at all when it was the hash that changed), so the
    // post is put in view here, without an animation - it is where the reader
    // arrives, not somewhere they travelled to.
    ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach(function (name) {
        on(window, name, function () {
            if (moved) return;
            moved = true;
            // The page above the one opened waited for the reader to move.
            if (list && loaded.min > 1 && list.firstElementChild) {
                var r = list.firstElementChild.getBoundingClientRect();
                if (r.top > -1200) loadPage(loaded.min - 1, 'before');
            }
        }, { passive: true, once: true });
    });
    function arrive() {
        var p = postOfHref(location.hash);
        if (!p || moved) return;
        var el = elementOf(p);
        if (!el) return;
        scrollToPost(el, true);
        point(el);
        setPosition(p.index);
    }
    arrive();
    // Once more when the images and fonts have settled, since they move posts.
    on(window, 'load', arrive);

    return {
        nav: nav,
        alive: function () { return home.isConnected; },
        destroy: function () {
            bound.forEach(function (b) { b[0].removeEventListener(b[1], b[2], b[3]); });
            bound = [];
            if (io) io.disconnect();
            if (edges) edges.disconnect();
            if (aborter) aborter.abort();
            if (article) article.classList.remove('is-streaming');
            clearTimeout(markTimer);
            clearTimeout(keyTimer);
            // Parked in <body> for the narrow screen: not in the content the
            // site swapped out, so it goes by itself.
            if (nav.parentNode === document.body) document.body.removeChild(nav);
        }
    };
    }
})();
