/**
 * Race maps (v56), built on Leaflet + OpenStreetMap.
 *  - EvoraRaceMap.base(el): a map with the official route and the checkpoints (runner tracker uses it too).
 *  - Route Management (#raceMap): draw the route, pick a checkpoint position, and follow runners live.
 */
(function () {
    'use strict';
    if (!window.L) {
        return;
    }
    var TYPE_COLORS = {
        start: '#198754', finish: '#dc3545', checkpoint: '#6c757d', water_station: '#0dcaf0',
        medical_station: '#ffc107', emergency_point: '#dc3545', turnaround_point: '#0d6efd'
    };
    var VERDICTS = {
        pending: ['Running', 'bg-primary'], verified: ['Verified', 'bg-success'],
        possible_shortcut: ['Possible shortcut', 'bg-warning text-dark'], insufficient: ['Not enough GPS', 'bg-secondary']
    };

    function readJson(el, name, fallback) {
        try { return JSON.parse(el.getAttribute(name) || '') || fallback; } catch (e) { return fallback; }
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
    }
    function lengthOf(latlngs) {
        var m = 0;
        for (var i = 1; i < latlngs.length; i++) { m += L.latLng(latlngs[i - 1]).distanceTo(latlngs[i]); }
        return m;
    }

    function base(el) {
        var map = L.map(el, { scrollWheelZoom: false });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        var route = L.polyline(readJson(el, 'data-route', []), { color: '#0d6efd', weight: 5, opacity: 0.75 }).addTo(map);
        var cpLayer = L.layerGroup().addTo(map);
        var checkpoints = readJson(el, 'data-checkpoints', []);
        checkpoints.forEach(function (c) {
            var color = TYPE_COLORS[c.type] || '#6c757d';
            L.circle([c.lat, c.lng], { radius: c.radius || 60, color: color, weight: 1, fillOpacity: 0.12 }).addTo(cpLayer);
            L.circleMarker([c.lat, c.lng], { radius: 7, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 })
                .bindTooltip(esc(c.label)).addTo(cpLayer);
        });
        function fit() {
            var bounds = L.latLngBounds([]);
            if (route.getLatLngs().length) { bounds.extend(route.getBounds()); }
            checkpoints.forEach(function (c) { bounds.extend([c.lat, c.lng]); });
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [24, 24], maxZoom: 17 });
            } else {
                map.setView([12.8797, 121.774], 6); // the Philippines
            }
        }
        fit();

        // v67: scrolling the page never zooms the map (it used to after one click and lost the route);
        // Ctrl/Cmd + scroll or the +/- buttons zoom, and a short hint says so.
        var hint = L.DomUtil.create('div', 'race-map-hint', el);
        hint.textContent = 'Use Ctrl + scroll to zoom the map';
        var hintTimer = null;
        el.addEventListener('wheel', function (e) {
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                map.setZoom(map.getZoom() + (e.deltaY < 0 ? 1 : -1));
                return;
            }
            el.classList.add('show-hint');
            clearTimeout(hintTimer);
            hintTimer = setTimeout(function () { el.classList.remove('show-hint'); }, 1300);
        }, { passive: false });

        // "Show the whole route" button under +/-.
        var FitControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var bar = L.DomUtil.create('div', 'leaflet-bar race-map-fit');
                var a = L.DomUtil.create('a', '', bar);
                a.href = '#';
                a.title = 'Show the whole route';
                a.setAttribute('role', 'button');
                a.setAttribute('aria-label', 'Show the whole route');
                a.innerHTML = '<i class="bi bi-arrows-angle-contract" aria-hidden="true"></i>';
                L.DomEvent.disableClickPropagation(bar);
                L.DomEvent.on(a, 'click', function (e) { L.DomEvent.preventDefault(e); fit(); });
                return bar;
            }
        });
        map.addControl(new FitControl());
        return { map: map, route: route, checkpoints: cpLayer, fit: fit };
    }

    window.EvoraRaceMap = { base: base, lengthOf: lengthOf, verdicts: VERDICTS, esc: esc };

    // ------------------------------------------------------------- Route Management (organizer)
    var el = document.getElementById('raceMap');
    if (!el) {
        return;
    }
    var root = el.closest('[data-race-map-root]') || document;
    var q = function (name) { return root.querySelector('[data-rm="' + name + '"]'); };
    var m = base(el);
    var map = m.map;
    var points = m.route.getLatLngs().map(function (p) { return [p.lat, p.lng]; });
    var mode = null; // 'draw' | 'pick' | null
    var vertexLayer = L.layerGroup().addTo(map);

    function redraw() {
        m.route.setLatLngs(points);
        vertexLayer.clearLayers();
        if (points.length) {
            L.circleMarker(points[0], { radius: 6, color: '#198754', fillOpacity: 1 }).bindTooltip('Route start').addTo(vertexLayer);
            L.circleMarker(points[points.length - 1], { radius: 6, color: '#dc3545', fillOpacity: 1 }).bindTooltip('Route end').addTo(vertexLayer);
        }
        if (q('distance')) { q('distance').textContent = (lengthOf(points) / 1000).toFixed(2) + ' km'; }
        if (q('points')) { q('points').value = JSON.stringify(points.map(function (p) { return [+p[0].toFixed(6), +p[1].toFixed(6)]; })); }
        if (q('save')) { q('save').disabled = el.getAttribute('data-saved') === q('points').value; }
    }
    function setMode(next) {
        mode = mode === next ? null : next;
        el.classList.toggle('race-map-drawing', mode !== null);
        if (q('draw')) {
            q('draw').classList.toggle('active', mode === 'draw');
            q('draw').innerHTML = mode === 'draw' ? '<i class="bi bi-check2"></i> Done drawing' : '<i class="bi bi-pencil"></i> Draw route';
        }
        if (q('hint')) {
            q('hint').textContent = mode === 'draw' ? 'Tap the map along the course, in running order. Zoom in for curves.'
                : mode === 'pick' ? 'Tap the map where this checkpoint is.'
                : mode === 'add' ? 'Click the map to place a ' + (TYPE_NAMES[addType] || 'checkpoint') + '. Click the button again or press Esc to stop.' : '';
        }
        root.querySelectorAll('[data-rm="add"]').forEach(function (b) { b.classList.toggle('active', mode === 'add' && b.getAttribute('data-type') === addType); });
    }
    if (q('points')) {
        redraw();
        el.setAttribute('data-saved', q('points').value);
        redraw();
    }

    map.on('click', function (ev) {
        if (mode === 'draw') {
            points.push([ev.latlng.lat, ev.latlng.lng]);
            redraw();
        } else if (mode === 'pick') {
            var lat = document.querySelector('[data-rm="cp-lat"]');
            var lng = document.querySelector('[data-rm="cp-lng"]');
            if (lat && lng) {
                lat.value = ev.latlng.lat.toFixed(6);
                lng.value = ev.latlng.lng.toFixed(6);
                lat.dispatchEvent(new Event('input', { bubbles: true }));
            }
            pickMarker(ev.latlng);
            setMode('pick');
        } else if (mode === 'add') {
            addAt(ev.latlng);
        }
    });
    var picked = null;
    function pickMarker(latlng) {
        if (picked) { map.removeLayer(picked); }
        picked = L.circleMarker(latlng, { radius: 9, color: '#fd7e14', weight: 3, fillOpacity: 0.3 }).bindTooltip('New position').addTo(map);
    }

    root.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-rm]');
        if (!btn) { return; }
        var act = btn.getAttribute('data-rm');
        if (act === 'draw') {
            setMode('draw');
        } else if (act === 'undo') {
            points.pop();
            redraw();
        } else if (act === 'clear') {
            if (points.length && window.confirm('Remove every point of the route? (Nothing changes until you tap Save.)')) {
                points = [];
                redraw();
            }
        } else if (act === 'locate') {
            map.locate({ setView: true, maxZoom: 16 });
        } else if (act === 'tab-route' || act === 'tab-live') {
            showTab(act === 'tab-live');
        } else if (act === 'show-track') {
            showTrack(+btn.getAttribute('data-track'), btn.getAttribute('data-name'));
        }
    });
    // The checkpoint form sits outside the map card.
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('[data-rm="pick"]')) { return; }
        ev.preventDefault();
        setMode('pick');
        if (mode === 'pick') { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
    var cpLat = document.querySelector('[data-rm="cp-lat"]');
    if (cpLat && cpLat.value) {
        pickMarker([+cpLat.value, +document.querySelector('[data-rm="cp-lng"]').value]);
    }

    // ------------------------------------------------------------- quick checkpoint setup on the map (v69)
    // Pick a type, click the map: the checkpoint is saved at once, named, put in running order and given
    // its km. Drag a marker to move it; click it to rename or remove it. One click places Start/Finish at
    // the ends of the route, and another fills in water stations every few km.
    var editorUrl = el.getAttribute('data-editor-url');
    var TYPE_ICONS = { start: 'bi-flag-fill', finish: 'bi-flag-fill', checkpoint: 'bi-stopwatch', water_station: 'bi-droplet-fill',
        medical_station: 'bi-plus-lg', emergency_point: 'bi-exclamation-triangle-fill', turnaround_point: 'bi-arrow-repeat' };
    var TYPE_NAMES = { start: 'Start', finish: 'Finish', checkpoint: 'Checkpoint', water_station: 'Water Station',
        medical_station: 'Medical Station', emergency_point: 'Emergency Point', turnaround_point: 'Turn-around Point' };
    var addType = null;
    var changes = 0;

    function cpIcon(c) {
        var color = c.active ? (TYPE_COLORS[c.type] || '#6c757d') : '#adb5bd';
        return L.divIcon({ className: 'race-cp', iconSize: [28, 28], iconAnchor: [14, 14],
            html: '<span style="background:' + color + '"><i class="bi ' + (TYPE_ICONS[c.type] || 'bi-geo-alt') + '" aria-hidden="true"></i></span>' });
    }
    function popupHtml(c) {
        return '<div class="race-cp-popup" data-cp="' + c.id + '">'
            + '<div class="small text-muted mb-1">' + c.seq + '. ' + esc(TYPE_NAMES[c.type] || c.type) + (c.km != null ? ' · ' + c.km.toFixed(2) + ' km' : '') + (c.active ? '' : ' · inactive') + '</div>'
            + '<input class="form-control form-control-sm mb-2" value="' + esc(c.label) + '" maxlength="100" aria-label="Checkpoint name">'
            + '<div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-evora-primary" data-cp-act="rename">Save name</button>'
            + '<button type="button" class="btn btn-sm btn-outline-danger" data-cp-act="delete">Remove</button></div>'
            + '<div class="small text-muted mt-2">Drag the marker to move it.</div></div>';
    }
    function renderEditable(list) {
        m.checkpoints.clearLayers();
        list.forEach(function (c) {
            if (c.lat == null) { return; }
            L.circle([c.lat, c.lng], { radius: c.radius || 60, color: TYPE_COLORS[c.type] || '#6c757d', weight: 1, fillOpacity: 0.1, interactive: false }).addTo(m.checkpoints);
            var mk = L.marker([c.lat, c.lng], { icon: cpIcon(c), draggable: true, riseOnHover: true, keyboard: true, title: c.label }).addTo(m.checkpoints);
            mk.bindTooltip(c.seq + '. ' + esc(c.label) + (c.km != null ? ' · ' + c.km.toFixed(2) + ' km' : ''));
            mk.bindPopup(function () { return popupHtml(c); }, { minWidth: 220 });
            mk.on('dragend', function () {
                var p = mk.getLatLng();
                send({ map_action: 'move', checkpoint_id: c.id, lat: p.lat.toFixed(6), lng: p.lng.toFixed(6) });
            });
        });
    }
    function status(text, kind) {
        var s = q('quick-status');
        if (!s) { return; }
        s.className = 'small ms-auto ' + (kind === 'error' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-muted');
        s.innerHTML = esc(text) + (changes && kind === 'ok' ? ' · <a href="#" data-rm="reload-list">Refresh the list below</a>' : '');
    }
    function send(fields) {
        var body = new URLSearchParams();
        body.set('csrf_token', el.getAttribute('data-csrf'));
        body.set('event_id', el.getAttribute('data-event-id'));
        Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        status('Saving…', '');
        return fetch(editorUrl, { method: 'POST', credentials: 'same-origin', body: body, headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Could not save. Reload the page.' }; }); })
            .then(function (d) {
                if (d.checkpoints) { renderEditable(d.checkpoints); }
                if (!d.ok) { status(d.message || 'Could not save.', 'error'); return; }
                changes++;
                status(d.message, 'ok');
            })
            .catch(function () { status('No connection. Try again.', 'error'); });
    }
    function addAt(latlng) {
        send({ map_action: 'add', type: addType, lat: latlng.lat.toFixed(6), lng: latlng.lng.toFixed(6) });
        if (addType === 'start' || addType === 'finish') { setMode('add'); } // only one of each: stop after placing it
    }

    if (editorUrl) {
        renderEditable(readJson(el, 'data-cp-list', []));
        root.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-rm]');
            if (!b) { return; }
            var act = b.getAttribute('data-rm');
            if (act === 'add') {
                var t = b.getAttribute('data-type');
                if (mode === 'add' && addType === t) { setMode('add'); return; }
                addType = t;
                mode = null;
                setMode('add');
            } else if (act === 'auto-sf') {
                send({ map_action: 'auto_start_finish' });
            } else if (act === 'water-every') {
                send({ map_action: 'water_every', km: q('water-km').value });
            } else if (act === 'reload-list') {
                ev.preventDefault();
                location.reload();
            }
        });
        el.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-cp-act]');
            if (!b) { return; }
            var box = b.closest('[data-cp]');
            var id = +box.getAttribute('data-cp');
            if (b.getAttribute('data-cp-act') === 'rename') {
                send({ map_action: 'rename', checkpoint_id: id, label: box.querySelector('input').value });
                map.closePopup();
            } else if (window.confirm('Remove this checkpoint? Runner scans recorded at it are removed too.')) {
                send({ map_action: 'delete', checkpoint_id: id });
                map.closePopup();
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && mode) { setMode(mode); } });
    }

    // ------------------------------------------------------------- live runners (v68: Strava-like)
    // Every runner has a coloured trail that grows with each refresh (only new points are fetched),
    // a leaderboard with pace, time and checkpoints, and an alert as soon as someone leaves the route.
    var liveUrl = el.getAttribute('data-live-url');
    var runnerLayer = L.layerGroup().addTo(map);
    var trailLayer = L.layerGroup().addTo(map);
    var trackLayer = L.layerGroup().addTo(map);
    var COLORS = ['#0d6efd', '#6f42c1', '#20c997', '#d63384', '#0dcaf0', '#6610f2', '#198754', '#b45309'];
    var trails = {};          // track id -> L.polyline
    var cursor = 0;           // last race_track_points id already drawn
    var offRouteSeen = {};    // track id -> true while it is off the route (to alert only once)
    var focused = null;
    var timer = null;
    var fitted = false;

    function colorOf(id) { return COLORS[id % COLORS.length]; }

    function showTab(live) {
        root.querySelectorAll('[data-rm-pane]').forEach(function (p) {
            p.classList.toggle('d-none', (p.getAttribute('data-rm-pane') === 'live') !== live);
        });
        if (q('tab-route')) { q('tab-route').classList.toggle('active', !live); }
        if (q('tab-live')) { q('tab-live').classList.toggle('active', live); }
        if (mode === 'draw') { setMode('draw'); }
        clearInterval(timer);
        if (live) {
            poll();
            timer = setInterval(function () { if (!document.hidden) { poll(); } }, 10000);
        } else {
            runnerLayer.clearLayers();
            trailLayer.clearLayers();
            trackLayer.clearLayers();
            trails = {};
            cursor = 0;
            fitted = false;
        }
        setTimeout(function () { map.invalidateSize(); }, 50);
    }

    function ago(s) {
        if (s == null) { return 'no GPS yet'; }
        if (s < 60) { return s + 's ago'; }
        if (s < 3600) { return Math.floor(s / 60) + ' min ago'; }
        return Math.floor(s / 3600) + ' h ago';
    }
    function clock(s) {
        if (s == null) { return '—'; }
        var h = Math.floor(s / 3600), mnt = Math.floor(s / 60) % 60, sec = s % 60;
        return (h ? h + ':' + String(mnt).padStart(2, '0') : mnt) + ':' + String(sec).padStart(2, '0');
    }
    function pace(r) {
        if (!r.elapsed_s || r.distance_km < 0.05) { return '—'; }
        var spk = Math.round(r.elapsed_s / r.distance_km);
        return Math.floor(spk / 60) + ':' + String(spk % 60).padStart(2, '0') + ' /km';
    }

    function poll() {
        fetch(liveUrl + '&cursor=' + cursor, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(render)
            .catch(function () { if (q('updated')) { q('updated').textContent = 'Could not refresh. Retrying…'; } });
    }

    function drawTrails(newPoints) {
        Object.keys(newPoints || {}).forEach(function (id) {
            var pts = newPoints[id];
            if (!trails[id]) {
                trails[id] = L.polyline([], { color: colorOf(+id), weight: 3, opacity: 0.8 }).addTo(trailLayer);
            }
            pts.forEach(function (p) { trails[id].addLatLng(p); });
        });
    }

    function render(data) {
        drawTrails(data.trails);
        cursor = data.cursor || cursor;
        runnerLayer.clearLayers();
        var limit = data.off_route_alert_m || 100;
        var total = data.checkpoints_total || 0;
        var b = L.latLngBounds([]);
        var counts = { running: 0, off: 0, finished: 0, flagged: 0 };
        var alerts = [];

        // Finished runners by time, then runners still out there by distance.
        var list = data.runners.slice().sort(function (a, z) {
            if (a.status !== z.status) { return a.status === 'finished' ? -1 : 1; }
            return a.status === 'finished' ? (a.elapsed_s || 0) - (z.elapsed_s || 0) : z.distance_km - a.distance_km;
        });
        var rows = list.map(function (r, i) {
            var active = r.status === 'active';
            var stale = active && (r.seconds_ago == null || r.seconds_ago > 120);
            var off = active && !stale && r.off_route_m != null && r.off_route_m >= limit;
            if (active) { counts.running++; } else { counts.finished++; }
            if (off) {
                counts.off++;
                alerts.push(r);
                if (!offRouteSeen[r.id] && window.evoraHaptic) { window.evoraHaptic([60, 40, 60]); }
                offRouteSeen[r.id] = true;
            } else {
                delete offRouteSeen[r.id];
            }
            if (r.verdict === 'possible_shortcut') { counts.flagged++; }

            var badge;
            if (off) { badge = '<span class="badge bg-danger">Off route · ' + r.off_route_m + ' m</span>'; }
            else if (stale) { badge = '<span class="badge bg-secondary">No signal</span>'; }
            else if (active) { badge = '<span class="badge bg-primary">Running</span>'; }
            else { var v = VERDICTS[r.verdict] || VERDICTS.pending; badge = '<span class="badge ' + v[1] + '">' + v[0] + '</span>'; }
            if (r.source === 'gpx') { badge += ' <span class="badge text-bg-light border" title="Uploaded GPX file (Strava / watch)">GPX</span>'; }

            if (r.lat != null) {
                var color = off ? '#dc3545' : (active ? (stale ? '#adb5bd' : colorOf(r.id)) : (r.verdict === 'possible_shortcut' ? '#fd7e14' : '#198754'));
                L.marker([r.lat, r.lng], {
                    zIndexOffset: off ? 1000 : 0,
                    icon: L.divIcon({ className: 'race-runner' + (off ? ' is-off-route' : ''), html: '<span style="background:' + color + '">' + esc(r.bib || '•') + '</span>', iconSize: [30, 30], iconAnchor: [15, 15] })
                }).bindTooltip(esc(r.name) + ' · ' + r.distance_km.toFixed(2) + ' km · ' + pace(r) + ' · ' + ago(r.seconds_ago)).addTo(runnerLayer);
                b.extend([r.lat, r.lng]);
            }
            if (trails[r.id]) {
                trails[r.id].setStyle({ color: off ? '#dc3545' : colorOf(r.id), weight: focused === r.id ? 6 : 3, opacity: focused && focused !== r.id ? 0.35 : 0.85 });
            }

            var cps = total ? r.checkpoints_passed + '/' + total + (r.last_checkpoint ? '<div class="small text-muted">' + esc(r.last_checkpoint) + '</div>' : '') : '—';
            return '<tr' + (off ? ' class="table-danger"' : '') + '>'
                + '<td class="fw-semibold">' + (i + 1) + '</td>'
                + '<td class="text-nowrap"><span class="race-dot" style="background:' + colorOf(r.id) + '"></span>' + esc(r.bib || '—') + '</td>'
                + '<td>' + esc(r.name) + (r.category ? '<div class="small text-muted">' + esc(r.category) + '</div>' : '') + '</td>'
                + '<td class="text-nowrap">' + r.distance_km.toFixed(2) + ' km</td>'
                + '<td class="text-nowrap">' + pace(r) + '</td>'
                + '<td class="text-nowrap">' + clock(r.elapsed_s) + '<div class="small text-muted">' + (active ? ago(r.seconds_ago) : 'finished') + '</div></td>'
                + '<td>' + cps + '</td>'
                + '<td>' + badge + (!active && r.detail ? '<div class="small text-muted mt-1">' + esc(r.detail) + '</div>' : '') + '</td>'
                + '<td><button type="button" class="btn btn-sm ' + (focused === r.id ? 'btn-primary' : 'btn-outline-secondary') + '" data-rm="show-track" data-track="' + r.id + '" data-name="' + esc(r.name) + '"' + (r.points ? '' : ' disabled') + '>' + (focused === r.id ? 'Focused' : 'Focus') + '</button></td></tr>';
        });

        var body = q('live-body');
        if (body) {
            body.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="9" class="text-center text-muted small py-4">No runner has started tracking yet. Runners open the tracker from their registration on race day, or upload a GPX file after the race.</td></tr>';
        }
        var box = q('alerts');
        if (box) {
            box.innerHTML = alerts.map(function (r) {
                return '<div class="alert alert-danger py-2 px-3 mb-2 small d-flex align-items-center gap-2" role="alert"><i class="bi bi-exclamation-triangle-fill"></i>'
                    + '<span><strong>' + esc(r.bib ? 'Bib ' + r.bib + ' · ' : '') + esc(r.name) + '</strong> is ' + r.off_route_m + ' m off the route'
                    + (r.last_checkpoint ? ' (last checkpoint: ' + esc(r.last_checkpoint) + ')' : '') + '.</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-rm="show-track" data-track="' + r.id + '" data-name="' + esc(r.name) + '">Show</button></div>';
            }).join('');
        }
        if (q('summary')) {
            q('summary').innerHTML = counts.running + ' running · <span class="' + (counts.off ? 'text-danger' : '') + '">' + counts.off + ' off route</span> · '
                + counts.finished + ' finished · ' + counts.flagged + ' flagged';
        }
        if (q('updated')) { q('updated').textContent = 'Updated ' + data.updated; }
        if (!fitted && b.isValid()) {
            map.fitBounds(b.extend(m.route.getBounds().isValid() ? m.route.getBounds() : b), { padding: [30, 30], maxZoom: 17 });
            fitted = true;
        }
    }

    // "Focus": highlight one runner's trail and zoom to it; click again to show everyone.
    function showTrack(id, name) {
        if (focused === id) {
            focused = null;
            m.fit();
            poll();
            return;
        }
        focused = id;
        var line = trails[id];
        if (line && line.getLatLngs().length) {
            line.bringToFront();
            map.fitBounds(line.getBounds(), { padding: [30, 30], maxZoom: 17 });
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            poll();
            return;
        }
        fetch(liveUrl + '&track=' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                trackLayer.clearLayers();
                if (!d.path || !d.path.length) { return; }
                var full = L.polyline(d.path, { color: '#fd7e14', weight: 5 }).bindTooltip(esc(name) + "'s path").addTo(trackLayer);
                map.fitBounds(full.getBounds(), { padding: [30, 30], maxZoom: 17 });
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
    }

    if (location.hash === '#live') { showTab(true); }
})();
