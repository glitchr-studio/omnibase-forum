/*
 * A topic's sense of place (topic/show.html.twig), from the outline the
 * controller computes (TopicController::outline()):
 *
 *   - the timeline, Discourse's scroller: a track standing for the whole
 *     topic, a handle following the reading, click / drag / arrow keys to go
 *     anywhere - on this page by scrolling, on another page by loading it;
 *   - the "Arbre à messages": the same topic drawn as a tree. The trunk is
 *     the conversation going on; each "Répondre à ce message" grows a branch
 *     from the message answered. Height is time, oldest at the root, so the
 *     tree reads like the timeline beside it.
 *
 * The tree lays itself out deterministically - a branch takes the nearest
 * lane free for its whole life on its side, trunk forks alternate right and
 * left - then lives: nodes sway on damped springs (verlet), and a branch
 * node dragged with the pointer bends its branch towards it (FABRIK inverse
 * kinematics), then springs back. prefers-reduced-motion keeps it still.
 */
(function () {
    'use strict';

    var nav = document.querySelector('[data-forum-timeline]');
    var source = document.getElementById('forum-outline');
    if (!nav || !source) return;

    var data;
    try { data = JSON.parse(source.textContent); } catch (e) { return; }
    var posts = data.posts || [];
    var total = posts.length;
    if (!total) return;

    var labels = data.labels || {};
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var byId = {};
    posts.forEach(function (p, i) { p.index = i; byId[p.id] = p; });

    function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
    function format(pattern, values) {
        return String(pattern || '').replace(/\{(\w+)\}/g, function (m, k) { return k in values ? values[k] : m; });
    }
    var dates = new Intl.DateTimeFormat(document.documentElement.lang || 'fr', { day: 'numeric', month: 'short', year: 'numeric' });
    function dayOf(p) { return p && p.at ? dates.format(new Date(p.at)) : ''; }

    var tree = null;
    var current = -1;
    nav.hidden = false;

    /* ── going somewhere ───────────────────────────────────────────────── */
    function hrefOf(p) { return data.url + (p.page > 1 ? '?page=' + p.page : '') + '#post-' + p.id; }
    function elementOf(p) { return p.page === data.page ? document.getElementById('post-' + p.id) : null; }

    function jump(i, instant) {
        var p = posts[clamp(Math.round(i), 0, total - 1)];
        var el = elementOf(p);
        if (!el) { window.location.href = hrefOf(p); return; }
        el.scrollIntoView({ behavior: instant || motion.matches ? 'auto' : 'smooth', block: 'start' });
        if (history.replaceState) history.replaceState(null, '', '#post-' + p.id);
    }

    /* ── where the reader is ───────────────────────────────────────────── */
    var onPage = posts.map(function (p) { return { post: p, el: elementOf(p) }; }).filter(function (x) { return x.el; });
    var seen = new Set();

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

    function setPosition(pos) {
        var i = place(pos);
        if (i !== current) {
            current = i;
            if (tree) tree.highlight(i, false);
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
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) seen.add(e.target); else seen.delete(e.target); });
            schedule();
        });
        onPage.forEach(function (x) { io.observe(x.el); });
    }
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);

    /* ── moving the handle: pointer and keys ───────────────────────────── */
    function positionAt(e) {
        var r = track.getBoundingClientRect(), h = handle.offsetHeight;
        return clamp((e.clientY - r.top - h / 2) / Math.max(1, r.height - h), 0, 1) * (total - 1);
    }
    function preview(pos) {
        target = Math.round(pos);
        setPosition(target);
        if (elementOf(posts[target])) jump(target, true); // this page follows live
    }
    track.addEventListener('pointerdown', function (e) {
        if (e.button) return;
        dragging = true;
        track.setPointerCapture(e.pointerId);
        handle.focus({ preventScroll: true });
        preview(positionAt(e));
        e.preventDefault();
    });
    track.addEventListener('pointermove', function (e) { if (dragging) preview(positionAt(e)); });
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
    var home = document.createComment('forum-timeline');
    nav.parentNode.insertBefore(home, nav);
    var narrow = window.matchMedia('(max-width: 900px)');
    function settle() {
        if (narrow.matches && nav.parentNode !== document.body) document.body.appendChild(nav);
        else if (!narrow.matches && nav.parentNode === document.body) home.parentNode.insertBefore(nav, home);
        schedule();
    }
    if (narrow.addEventListener) narrow.addEventListener('change', settle); else narrow.addListener(settle);
    settle();

    function open(yes) {
        nav.classList.toggle('is-open', yes);
        pill.setAttribute('aria-expanded', yes ? 'true' : 'false');
        if (yes) schedule();
    }
    pill.addEventListener('click', function () { open(!nav.classList.contains('is-open')); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && nav.classList.contains('is-open')) { open(false); pill.focus(); } });
    document.addEventListener('pointerdown', function (e) { if (!nav.contains(e.target)) open(false); });

    /* ── timeline / tree ───────────────────────────────────────────────── */
    var scroller = nav.querySelector('[data-timeline-scroller]');
    var treeBox = nav.querySelector('[data-forum-tree]');
    var viewButtons = nav.querySelectorAll('[data-timeline-view]');

    function show(view) {
        var isTree = view === 'tree';
        viewButtons.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-timeline-view') === view ? 'true' : 'false'); });
        scroller.hidden = isTree;
        treeBox.hidden = !isTree;
        nav.classList.toggle('is-tree', isTree);
        if (isTree) {
            if (!tree) tree = createTree(treeBox.querySelector('[data-forum-tree-host]'));
            tree.start();
            tree.highlight(current, true);
        } else {
            if (tree) tree.stop();
            schedule();
        }
        try { localStorage.setItem('forum.thread.view', view); } catch (e) {}
    }
    viewButtons.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-timeline-view')); }); });

    setPosition(measure());
    var saved = null;
    try { saved = localStorage.getItem('forum.thread.view'); } catch (e) {}
    if (saved === 'tree') show('tree');

    /* ── the message tree ──────────────────────────────────────────────── */
    function createTree(host) {
        var NS = 'http://www.w3.org/2000/svg';
        var STEP = total <= 24 ? 22 : clamp(520 / total, 9, 22);
        var LANE = 18, PAD = 16, R = STEP >= 14 ? 5 : 3.5;

        function svgEl(name, attrs) {
            var el = document.createElementNS(NS, name);
            for (var k in attrs) el.setAttribute(k, attrs[k]);
            return el;
        }

        // Branches and their lanes: the nearest lane on the branch's side that
        // is free from the post it grew on to its last post.
        var branches = {}, used = {}, forks = 0;
        posts.forEach(function (p) { (branches[p.branch] = branches[p.branch] || { nodes: [] }).nodes.push(p); });
        function free(lane, a, z) { return !(used[lane] || []).some(function (r) { return a <= r[1] + 1 && z >= r[0] - 1; }); }
        function claim(lane, a, z) { (used[lane] = used[lane] || []).push([a, z]); }
        branches[posts[0].branch].lane = 0;
        claim(0, 0, total - 1);
        posts.forEach(function (p) {
            if (p.branch !== p.id || !byId[p.parent]) return;
            var from = byId[p.parent], b = branches[p.id], lane0 = branches[from.branch].lane;
            var side = lane0 ? Math.sign(lane0) : (forks++ % 2 ? -1 : 1);
            var a = from.index, z = b.nodes[b.nodes.length - 1].index, lane = lane0 + side;
            while (!free(lane, a, z)) lane += side;
            b.lane = lane;
            claim(lane, a, z);
        });

        var lanes = Object.keys(branches).map(function (k) { return branches[k].lane || 0; });
        var minLane = Math.min.apply(null, lanes), maxLane = Math.max.apply(null, lanes);
        var width = (maxLane - minLane) * LANE + PAD * 2, height = (total - 1) * STEP + PAD * 2;

        // Leonardo's rule, roughly: a limb is as thick as what it carries.
        var weight = posts.map(function () { return 1; });
        for (var i = total - 1; i > 0; i--) if (byId[posts[i].parent]) weight[byId[posts[i].parent].index] += weight[i];

        var svg = svgEl('svg', { viewBox: '0 0 ' + width + ' ' + height, width: width, height: height, class: 'forum-tree-svg' });
        var bark = svgEl('g', { class: 'forum-tree-bark' }), leaves = svgEl('g', { class: 'forum-tree-leaves' });
        svg.appendChild(bark);
        svg.appendChild(leaves);

        var nodes = posts.map(function (p, i) {
            var n = {
                p: p, i: i,
                x: PAD + ((branches[p.branch].lane || 0) - minLane) * LANE,
                y: PAD + (total - 1 - i) * STEP,
                parent: byId[p.parent] ? byId[p.parent].index : -1,
                w: Math.min(STEP * 0.55, 1.2 + Math.sqrt(weight[i]) * 0.9),
                seed: (p.id * 7919 % 1000) / 159,
                lx: 0, ly: 0, px: 0, py: 0, dx: 0, dy: 0, held: false, current: false
            };
            if (n.parent >= 0) bark.appendChild(n.edge = svgEl('path', {}));
            bark.appendChild(n.knot = svgEl('circle', { r: 0 }));

            var name = format(labels.node, { number: p.number, author: p.author || labels.nobody, date: dayOf(p) }) + (p.deleted ? ' (' + labels.deleted + ')' : '');
            n.link = svgEl('a', { href: hrefOf(p), class: 'forum-tree-node' + (p.branch ? '' : ' is-trunk') + (p.deleted ? ' is-deleted' : ''), 'data-index': i, 'aria-label': name });
            var title = svgEl('title', {});
            title.textContent = name;
            n.link.appendChild(title);
            var hue = p.authorId === null ? null : Math.round((p.authorId * 137.508) % 360);
            n.dot = svgEl('circle', { r: 0, style: '--leaf:' + (hue === null ? '#9aa9b5' : 'hsl(' + hue + ', 68%, 52%)') });
            n.link.appendChild(n.dot);
            leaves.appendChild(n.link);
            return n;
        });
        host.appendChild(svg);

        // A limb from m to n: a cubic curve (straight up a lane, a sweep out of
        // the parent otherwise), filled between two offset edges so it tapers.
        function limb(m, n, grow) {
            var mx = m.x + m.dx, my = m.y + m.dy, nx = n.x + n.dx, ny = n.y + n.dy, dy = ny - my;
            var c = m.p.branch === n.p.branch || (branches[m.p.branch].lane === branches[n.p.branch].lane)
                ? [mx, my, mx, my + dy / 3, nx, ny - dy / 3, nx, ny]
                : [mx, my, mx + (nx - mx) * 0.6, my + dy * 0.12, nx, my + dy * 0.55, nx, ny];
            var w0 = Math.min(m.w, n.w * 1.35), w1 = n.w, left = [], right = [];
            for (var k = 0; k <= 8; k++) {
                var t = (k / 8) * grow, u = 1 - t;
                var x = u * u * u * c[0] + 3 * u * u * t * c[2] + 3 * u * t * t * c[4] + t * t * t * c[6];
                var y = u * u * u * c[1] + 3 * u * u * t * c[3] + 3 * u * t * t * c[5] + t * t * t * c[7];
                var tx = 3 * u * u * (c[2] - c[0]) + 6 * u * t * (c[4] - c[2]) + 3 * t * t * (c[6] - c[4]);
                var ty = 3 * u * u * (c[3] - c[1]) + 6 * u * t * (c[5] - c[3]) + 3 * t * t * (c[7] - c[5]);
                var len = Math.hypot(tx, ty) || 1, half = (w0 + (w1 - w0) * k / 8) / 2;
                left.push((x - ty / len * half).toFixed(1) + ',' + (y + tx / len * half).toFixed(1));
                right.unshift((x + ty / len * half).toFixed(1) + ',' + (y - tx / len * half).toFixed(1));
            }
            return 'M' + left.join('L') + 'L' + right.join('L') + 'Z';
        }

        var grown = motion.matches ? 1 : 0, startedAt = 0, running = false, raf = 0, drag = null;

        function draw() {
            var shown = (1 - Math.pow(1 - grown, 3)) * total;
            nodes.forEach(function (n) {
                var g = clamp(shown - n.i, 0, 1), x = (n.x + n.dx).toFixed(1), y = (n.y + n.dy).toFixed(1);
                n.dot.setAttribute('cx', x);
                n.dot.setAttribute('cy', y);
                n.dot.setAttribute('r', (R * (n.current ? 1.45 : 1) * g).toFixed(2));
                n.knot.setAttribute('cx', x);
                n.knot.setAttribute('cy', y);
                n.knot.setAttribute('r', (n.w / 2 * g).toFixed(2));
                if (n.edge) n.edge.setAttribute('d', g > 0 ? limb(nodes[n.parent], n, g) : '');
            });
        }

        /* FABRIK: the chain from a branch's anchor to the held node reaches for
           the pointer, every link keeping its length, the anchor staying put. */
        function fabrik(pts, lens, goal) {
            var last = pts.length - 1, base = { x: pts[0].x, y: pts[0].y };
            function toward(from, to, len) {
                var dx = to.x - from.x, dy = to.y - from.y, d = Math.hypot(dx, dy) || 1;
                return { x: from.x + dx / d * len, y: from.y + dy / d * len };
            }
            for (var it = 0; it < 10; it++) {
                pts[last] = { x: goal.x, y: goal.y };
                for (var j = last - 1; j >= 0; j--) pts[j] = toward(pts[j + 1], pts[j], lens[j]);
                pts[0] = base;
                for (j = 0; j < last; j++) pts[j + 1] = toward(pts[j], pts[j + 1], lens[j]);
                if (Math.hypot(pts[last].x - goal.x, pts[last].y - goal.y) < 0.5) break;
            }
            return pts;
        }

        // Turns the solved chain into the nodes' own offsets (what their parent
        // does not already carry), so whatever grows on them follows.
        function bend() {
            var chain = drag.chain;
            var pts = fabrik(chain.map(function (k) { return { x: nodes[k].x + nodes[k].dx, y: nodes[k].y + nodes[k].dy }; }), drag.lens, drag.goal);
            for (var j = 1; j < chain.length; j++) {
                var n = nodes[chain[j]], m = nodes[chain[j - 1]];
                n.lx = n.px = pts[j].x - n.x - (pts[j - 1].x - m.x);
                n.ly = n.py = pts[j].y - n.y - (pts[j - 1].y - m.y);
                n.held = true;
            }
        }

        // Verlet springs, driven by a little wind; a branch carries its
        // parent's movement, the trunk stands on its own.
        function frame(now) {
            raf = 0;
            var still = motion.matches || total > 400, moving = false, time = now / 1000;
            if (grown < 1) grown = clamp((now - startedAt) / 1600, 0, 1);
            if (drag && drag.goal) bend();
            nodes.forEach(function (n) {
                if (!n.held) {
                    var amp = still ? 0 : n.p.branch ? 0.35 + 0.25 * n.p.level : 1.4 * n.i / total;
                    var wind = amp * (Math.sin(time * 1.1 + n.y * 0.02 + n.seed) + 0.4 * Math.sin(time * 2.3 + n.seed));
                    var vx = (n.lx - n.px) * 0.9, vy = (n.ly - n.py) * 0.9;
                    n.px = n.lx;
                    n.py = n.ly;
                    n.lx += vx + 0.03 * (wind - n.lx);
                    n.ly += vy + 0.03 * (wind * 0.2 - n.ly);
                    if (Math.abs(vx) + Math.abs(vy) + Math.abs(n.lx) + Math.abs(n.ly) > 0.02) moving = true;
                }
                var m = n.parent >= 0 && n.p.branch ? nodes[n.parent] : null;
                n.dx = (m ? m.dx : 0) + n.lx;
                n.dy = (m ? m.dy : 0) + n.ly;
            });
            draw();
            if (running && (!still || moving || grown < 1 || drag)) raf = requestAnimationFrame(frame);
        }
        function kick() { if (running && !raf) raf = requestAnimationFrame(frame); }

        /* Pointer: a press without movement follows the link; a press dragged
           on a branch node bends that branch. */
        function svgPoint(e) {
            var ctm = svg.getScreenCTM();
            if (!ctm) return { x: 0, y: 0 };
            var pt = new DOMPoint(e.clientX, e.clientY).matrixTransform(ctm.inverse());
            return { x: pt.x, y: pt.y };
        }
        var press = null, swallowClick = false;
        svg.addEventListener('pointerdown', function (e) {
            var a = e.target.closest && e.target.closest('.forum-tree-node');
            if (a && !e.button) press = { i: +a.getAttribute('data-index'), x: e.clientX, y: e.clientY, id: e.pointerId };
        });
        svg.addEventListener('pointermove', function (e) {
            if (!press) return;
            if (!drag) {
                var n = nodes[press.i];
                if (Math.hypot(e.clientX - press.x, e.clientY - press.y) < 5) return;
                if (!n.p.branch) { press = null; return; }
                var chain = [press.i];
                while (nodes[chain[0]].p.branch === n.p.branch && nodes[chain[0]].parent >= 0) chain.unshift(nodes[chain[0]].parent);
                drag = { chain: chain, lens: [], goal: null };
                for (var j = 0; j < chain.length - 1; j++) {
                    drag.lens.push(Math.hypot(nodes[chain[j + 1]].x - nodes[chain[j]].x, nodes[chain[j + 1]].y - nodes[chain[j]].y));
                }
                svg.setPointerCapture(press.id);
                svg.classList.add('is-bending');
            }
            drag.goal = svgPoint(e);
            e.preventDefault();
            kick();
        });
        function letGo() {
            if (drag) {
                drag.chain.forEach(function (k) {
                    var n = nodes[k];
                    n.held = false;
                    if (motion.matches) n.lx = n.ly = n.px = n.py = 0;
                });
                drag = null;
                swallowClick = true;
                setTimeout(function () { swallowClick = false; }, 0);
                svg.classList.remove('is-bending');
                kick();
            }
            press = null;
        }
        svg.addEventListener('pointerup', letGo);
        svg.addEventListener('pointercancel', letGo);
        svg.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('.forum-tree-node');
            if (!a && !swallowClick) return;
            e.preventDefault();
            if (a && !swallowClick) jump(+a.getAttribute('data-index'));
        });

        return {
            start: function () {
                if (!startedAt) startedAt = performance.now();
                running = true;
                kick();
            },
            stop: function () {
                running = false;
                if (raf) cancelAnimationFrame(raf);
                raf = 0;
            },
            highlight: function (index, reveal) {
                nodes.forEach(function (n) {
                    n.current = n.i === index;
                    n.link.classList.toggle('is-current', n.current);
                    if (n.current) n.link.setAttribute('aria-current', 'location'); else n.link.removeAttribute('aria-current');
                });
                var n = nodes[index];
                if (n && svg.clientHeight) {
                    var y = n.y / height * svg.clientHeight;
                    if (reveal || y < host.scrollTop + 16 || y > host.scrollTop + host.clientHeight - 16) {
                        host.scrollTop = y - host.clientHeight / 2;
                    }
                }
                if (!raf) draw();
            }
        };
    }
})();
