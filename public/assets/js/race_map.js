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
        var bounds = L.latLngBounds([]);
        if (route.getLatLngs().length) { bounds.extend(route.getBounds()); }
        checkpoints.forEach(function (c) { bounds.extend([c.lat, c.lng]); });
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 17 });
        } else {
            map.setView([12.8797, 121.774], 6); // the Philippines
        }
        el.addEventListener('click', function () { map.scrollWheelZoom.enable(); }, { once: true });
        return { map: map, route: route, checkpoints: cpLayer };
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
                : mode === 'pick' ? 'Tap the map where this checkpoint is.' : '';
        }
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

    // ------------------------------------------------------------- live runners
    var liveUrl = el.getAttribute('data-live-url');
    var runnerLayer = L.layerGroup().addTo(map);
    var trackLayer = L.layerGroup().addTo(map);
    var timer = null;
    var fitted = false;

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
            trackLayer.clearLayers();
        }
        setTimeout(function () { map.invalidateSize(); }, 50);
    }

    function ago(s) {
        if (s == null) { return 'no GPS yet'; }
        if (s < 60) { return s + 's ago'; }
        if (s < 3600) { return Math.floor(s / 60) + ' min ago'; }
        return Math.floor(s / 3600) + ' h ago';
    }

    function poll() {
        fetch(liveUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(render)
            .catch(function () { if (q('updated')) { q('updated').textContent = 'Could not refresh. Retrying…'; } });
    }

    function render(data) {
        runnerLayer.clearLayers();
        var body = q('live-body');
        var rows = [];
        var b = L.latLngBounds([]);
        var counts = { running: 0, finished: 0, flagged: 0 };
        data.runners.forEach(function (r) {
            var v = r.status === 'active' ? VERDICTS.pending : (VERDICTS[r.verdict] || VERDICTS.pending);
            if (r.status === 'active') { counts.running++; } else { counts.finished++; }
            if (r.verdict === 'possible_shortcut') { counts.flagged++; }
            var stale = r.status === 'active' && (r.seconds_ago == null || r.seconds_ago > 120);
            if (r.lat != null) {
                var color = r.status === 'finished' ? (r.verdict === 'possible_shortcut' ? '#fd7e14' : '#198754') : (stale ? '#adb5bd' : '#0d6efd');
                L.marker([r.lat, r.lng], {
                    icon: L.divIcon({ className: 'race-runner', html: '<span style="background:' + color + '">' + esc(r.bib || '•') + '</span>', iconSize: [30, 30], iconAnchor: [15, 15] })
                }).bindTooltip(esc(r.name) + ' · ' + r.distance_km.toFixed(2) + ' km · ' + ago(r.seconds_ago)).addTo(runnerLayer);
                b.extend([r.lat, r.lng]);
            }
            rows.push('<tr><td>' + esc(r.bib || '—') + '</td><td>' + esc(r.name) + (r.category ? '<div class="small text-muted">' + esc(r.category) + '</div>' : '') + '</td>'
                + '<td>' + r.distance_km.toFixed(2) + ' km</td>'
                + '<td class="small">' + (stale ? '<span class="text-danger">' : '<span>') + ago(r.seconds_ago) + '</span></td>'
                + '<td><span class="badge ' + v[1] + '">' + v[0] + '</span>' + (r.status === 'finished' && r.detail ? '<div class="small text-muted mt-1">' + esc(r.detail) + '</div>' : '') + '</td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-secondary" data-rm="show-track" data-track="' + r.id + '" data-name="' + esc(r.name) + '"' + (r.points ? '' : ' disabled') + '>Path</button></td></tr>');
        });
        if (body) {
            body.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="6" class="text-center text-muted small py-4">No runner has started tracking yet. Runners open the tracker from their registration on race day.</td></tr>';
        }
        if (q('summary')) { q('summary').textContent = counts.running + ' running · ' + counts.finished + ' finished · ' + counts.flagged + ' flagged'; }
        if (q('updated')) { q('updated').textContent = 'Updated ' + data.updated; }
        if (!fitted && b.isValid()) {
            map.fitBounds(b.extend(m.route.getBounds().isValid() ? m.route.getBounds() : b), { padding: [30, 30], maxZoom: 17 });
            fitted = true;
        }
    }

    function showTrack(id, name) {
        fetch(liveUrl + '&track=' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                trackLayer.clearLayers();
                if (!d.path || !d.path.length) { return; }
                var line = L.polyline(d.path, { color: '#fd7e14', weight: 4, dashArray: '6 6' }).bindTooltip(esc(name) + "'s path").addTo(trackLayer);
                map.fitBounds(line.getBounds(), { padding: [30, 30], maxZoom: 17 });
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
    }

    if (location.hash === '#live') { showTab(true); }
})();
