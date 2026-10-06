/**
 * Runner's live tracker (v56). Start → the phone's GPS positions are queued on the phone (localStorage)
 * and sent every 10 s; nothing is lost without signal. Finish → the rest is sent and the track is checked.
 */
(function () {
    'use strict';
    var box = document.getElementById('raceTracker');
    if (!box) {
        return;
    }
    var cfg = {
        url: box.getAttribute('data-action-url'),
        csrf: box.getAttribute('data-csrf'),
        reg: box.getAttribute('data-registration'),
        status: box.getAttribute('data-status'),
        started: box.getAttribute('data-started')
    };
    var KEY = 'evora_race_track_' + cfg.reg;
    var q = function (n) { return box.querySelector('[data-rt="' + n + '"]'); };
    var startBtn = q('start');
    var finishBtn = q('finish');
    if (!startBtn) {
        drawMapOnly();
        return;
    }

    var state = load();
    var watchId = null;
    var wakeLock = null;
    var sending = false;
    var lastFix = null;
    var searching = false;
    var localMeters = 0;
    var startedAt = cfg.started ? new Date(cfg.started.replace(' ', 'T')).getTime() : null;

    function load() {
        try { return JSON.parse(localStorage.getItem(KEY)) || { queue: [] }; } catch (e) { return { queue: [] }; }
    }
    function save() {
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* storage full or blocked: points stay in memory */ }
    }
    function say(html, kind) {
        var m = q('message');
        m.className = 'small mb-3' + (kind === 'error' ? ' text-danger' : kind === 'ok' ? ' text-success' : '');
        m.innerHTML = html;
    }

    // ------------------------------------------------------------- map
    var tmap = null, myPath = null, me = null;
    function drawMapOnly() {
        var el = document.getElementById('raceTrackerMap');
        if (el && window.EvoraRaceMap) {
            tmap = window.EvoraRaceMap.base(el);
            myPath = L.polyline([], { color: '#fd7e14', weight: 4 }).addTo(tmap.map);
        }
    }
    drawMapOnly();
    function showMe(lat, lng, acc) {
        if (!tmap) { return; }
        myPath.addLatLng([lat, lng]);
        if (!me) {
            me = L.circleMarker([lat, lng], { radius: 8, color: '#fff', weight: 3, fillColor: '#0d6efd', fillOpacity: 1 }).addTo(tmap.map);
            tmap.map.setView([lat, lng], Math.max(tmap.map.getZoom(), 16));
        } else {
            me.setLatLng([lat, lng]);
        }
        if (!tmap.map.getBounds().pad(-0.2).contains([lat, lng])) { tmap.map.panTo([lat, lng]); }
    }

    // ------------------------------------------------------------- server
    function post(action, points) {
        var body = new URLSearchParams();
        body.set('csrf_token', cfg.csrf);
        body.set('race_track_action', action);
        body.set('registration_id', cfg.reg);
        if (points) { body.set('points', JSON.stringify(points)); }
        return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body, headers: { Accept: 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) {
                    if (!r.ok) { var err = new Error(d.error || 'Could not reach EVORA.'); err.status = r.status; throw err; }
                    return d;
                });
            });
    }

    function flush() {
        if (sending || !state.queue.length || !navigator.onLine) {
            return Promise.resolve();
        }
        sending = true;
        var batch = state.queue.slice(0, 300);
        return post('push', batch).then(function (d) {
            state.queue.splice(0, batch.length);
            save();
            q('distance').textContent = Math.max(d.distance_km, localMeters / 1000).toFixed(2);
            say('<i class="bi bi-broadcast"></i> Tracking. The organizer can see you on the map.', 'ok');
        }).catch(function (e) {
            say((e.status ? e.message : 'No signal. Positions are kept on this phone (' + state.queue.length + ' waiting).'), e.status ? 'error' : null);
        }).then(function () { sending = false; });
    }

    // ------------------------------------------------------------- GPS
    function onFix(pos) {
        var c = pos.coords;
        if (searching) {
            searching = false;
            say('<i class="bi bi-broadcast"></i> Tracking. GPS signal is back.', 'ok');
        }
        var t = pos.timestamp || Date.now();
        q('gps').textContent = '±' + Math.round(c.accuracy) + ' m';
        if (lastFix && t - lastFix.t < 3000) {
            return; // at most one position every 3 s
        }
        if (lastFix && c.accuracy <= 50) {
            var d = L.latLng(lastFix.lat, lastFix.lng).distanceTo([c.latitude, c.longitude]);
            if (d / Math.max(1, (t - lastFix.t) / 1000) < 12) { localMeters += d; }
        }
        lastFix = { lat: c.latitude, lng: c.longitude, t: t };
        state.queue.push({ lat: +c.latitude.toFixed(6), lng: +c.longitude.toFixed(6), acc: Math.round(c.accuracy), t: t });
        save();
        showMe(c.latitude, c.longitude, c.accuracy);
        q('distance').textContent = (localMeters / 1000).toFixed(2);
    }
    function onGpsError(err) {
        if (err.code === 1) {
            say('Location is blocked. Allow location for this site in your browser settings, then tap Start again.', 'error');
            stopWatch();
            showButtons(false);
        } else {
            searching = true;
            say('Looking for GPS signal… Stay in an open area.', null);
        }
    }
    function startWatch() {
        if (!window.isSecureContext || !navigator.geolocation) {
            say('This browser cannot share your location here (the site must use HTTPS).', 'error');
            return false;
        }
        if (watchId === null) {
            watchId = navigator.geolocation.watchPosition(onFix, onGpsError, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
        }
        keepAwake();
        return true;
    }
    function stopWatch() {
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
        if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
    }
    function keepAwake() {
        if ('wakeLock' in navigator && !wakeLock) {
            navigator.wakeLock.request('screen').then(function (l) {
                wakeLock = l;
                l.addEventListener('release', function () { wakeLock = null; });
            }).catch(function () {});
        }
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && watchId !== null) { keepAwake(); flush(); }
    });
    window.addEventListener('online', flush);

    // ------------------------------------------------------------- clock and buttons
    setInterval(function () {
        if (!startedAt || watchId === null) { return; }
        var s = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
        q('time').textContent = [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map(function (n) { return String(n).padStart(2, '0'); }).join(':');
    }, 1000);
    setInterval(flush, 10000);

    function showButtons(running) {
        startBtn.classList.toggle('d-none', running);
        finishBtn.classList.toggle('d-none', !running);
    }

    startBtn.addEventListener('click', function () {
        startBtn.disabled = true;
        post('start').then(function (d) {
            cfg.status = d.status;
            startedAt = startedAt || new Date(String(d.started_at).replace(' ', 'T')).getTime() || Date.now();
            if (startWatch()) {
                showButtons(true);
                say('<i class="bi bi-broadcast"></i> Tracking started. Keep this page open.', 'ok');
            }
        }).catch(function (e) {
            say(e.message, 'error');
        }).then(function () { startBtn.disabled = false; });
    });

    finishBtn.addEventListener('click', function () {
        if (!window.confirm('Did you cross the finish line? Tracking stops and your route is checked.')) {
            return;
        }
        finishBtn.disabled = true;
        stopWatch();
        say('Sending your last positions…', null);
        var sendAll = function () {
            if (state.queue.length > 300) { return flush().then(function () { if (sending) { throw new Error('Still sending. Try again.'); } return sendAll(); }); }
            var rest = state.queue.slice();
            return post('finish', rest).then(function () {
                state.queue = [];
                save();
                try { localStorage.removeItem(KEY); } catch (e) {}
                window.location.reload();
            });
        };
        sendAll().catch(function (e) {
            finishBtn.disabled = false;
            say((e.status ? e.message : 'No signal. Tap Finish again when you are online; your positions are kept.'), 'error');
        });
    });

    // A track already started (page reloaded, or phone restarted): continue it.
    if (cfg.status === 'active') {
        showButtons(true);
        startBtn.innerHTML = '<i class="bi bi-play-fill"></i> Resume';
        if (startWatch()) {
            say('<i class="bi bi-broadcast"></i> Tracking resumed.' + (state.queue.length ? ' Sending ' + state.queue.length + ' saved positions…' : ''), 'ok');
            flush();
        }
    } else if (box.getAttribute('data-blocked')) {
        startBtn.disabled = true;
    }
})();
