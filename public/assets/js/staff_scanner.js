/*
 * EVORA Staff QR scanner (camera + manual entry).
 *
 * Camera: html5-qrcode 2.3.8 (assets/vendor). Permission is asked by the browser
 * when the staff member presses "Start Camera" (or automatically when the browser
 * already granted it). Every outcome gets its own message: not requested yet,
 * blocked, unsupported/insecure page, no camera, camera busy, library missing.
 *
 * Validation and check-in stay on the server (staff-scan-verify): this script only
 * sends the scanned token + selected event; the server re-checks staff assignment,
 * QR signature, event, registration, payment and duplicates.
 */
(function () {
    'use strict';
    const root = document.getElementById('staffScanner');
    if (!root) return;

    const $ = (id) => document.getElementById(id);
    const csrfToken = root.dataset.csrf;
    const verifyUrl = root.dataset.verifyUrl;
    const eventSelect = $('eventSelect');
    const statusBox = $('cameraStatus');
    const resultBox = $('scanResult');
    const recentList = $('recentScans');
    const READER_ID = 'qr-reader';

    let scanner = null;          // Html5Qrcode instance
    let running = false;         // camera stream active
    let busy = false;            // a scan is being validated / shown
    let wantCamera = false;      // staff asked for the camera (used to restart after event change / tab return)
    let lastToken = '';
    let lastTokenAt = 0;

    // ------------------------------------------------------------------ helpers
    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v);
        });
        (children || []).forEach((c) => c && node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
        return node;
    }
    const icon = (name) => el('i', { class: 'bi ' + name, 'aria-hidden': 'true' });
    const button = (label, cls, onclick, iconName) => el('button', { type: 'button', class: 'btn btn-sm ' + cls, onclick }, [iconName ? icon(iconName + ' me-1') : null, label]);
    const selectedEventName = () => eventSelect.options[eventSelect.selectedIndex].text;

    /** kind: idle | info | success | warning | danger */
    function setStatus(kind, title, text, actions, extra) {
        statusBox.className = 'scanner-status is-' + kind;
        statusBox.replaceChildren(...[
            el('div', { class: 'scanner-status-title' }, [icon({ success: 'bi-camera-video', danger: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle', idle: 'bi-camera' }[kind] || 'bi-info-circle'), ' ', title]),
            text ? el('div', { class: 'scanner-status-text', text }) : null,
            extra || null,
            actions && actions.length ? el('div', { class: 'd-flex flex-wrap gap-2 mt-2' }, actions) : null,
        ].filter(Boolean));
        root.classList.toggle('camera-on', kind === 'success' && running);
        $('cameraToggle').hidden = !running;
    }

    function errorName(err) {
        if (err && err.name) return err.name;
        const m = String(err || '').match(/([A-Za-z]+Error)/);
        return m ? m[1] : 'UnknownError';
    }

    const useManual = () => button('Use Manual Entry', 'btn-outline-secondary', () => $('manualToken').focus(), 'bi-keyboard');
    const tryAgain = () => button('Try Again', 'btn-evora-primary', startCamera, 'bi-arrow-clockwise');

    function showCameraError(err) {
        const name = errorName(err);
        running = false;
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
            const steps = el('ol', { class: 'small mb-0 mt-2 ps-3' }, [
                el('li', { text: 'Click the camera (or lock) icon beside ' + location.host + ' in the address bar.' }),
                el('li', { text: 'Choose "Allow" for Camera.' }),
                el('li', { text: 'Click Try Again (or reload the page).' }),
            ]);
            setStatus('danger', 'Camera access was blocked by your browser.', 'EVORA needs camera permission to scan QR codes. Manual entry below still works.', [tryAgain(), useManual()], steps);
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
            setStatus('warning', 'No camera was detected on this device.', 'Connect a webcam or use a phone/tablet with a camera, then try again. Manual entry below still works.', [tryAgain(), useManual()]);
        } else if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
            setStatus('warning', 'The camera is being used by another app or tab.', 'Close the other app or browser tab that uses the camera (video call, another scanner tab), then try again.', [tryAgain(), useManual()]);
        } else {
            setStatus('danger', 'Camera could not be started.', 'Reason: ' + name + '. You can try again or use manual entry.', [tryAgain(), useManual()]);
        }
        console.warn('[EVORA scanner] camera error:', name, err); // one line, only on failure
    }

    // ------------------------------------------------------------------ camera
    function environmentProblem() {
        if (!window.isSecureContext) {
            return ['Camera needs a secure page.', 'Browsers only allow the camera on https:// pages or http://localhost. This page was opened at ' + location.origin + '. Open EVORA through localhost or HTTPS.'];
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return ['This browser does not support camera access.', 'Use a current version of Chrome, Edge, Firefox or Safari.'];
        }
        if (typeof window.Html5Qrcode === 'undefined') {
            return ['The QR scanner could not load.', 'Reload the page. If it keeps happening, the scanner file is missing from the server.'];
        }
        return null;
    }

    function scanConfig() {
        const cfg = {
            fps: 10,
            qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; },
            aspectRatio: 1.333,
        };
        return cfg;
    }

    async function startCamera() {
        const problem = environmentProblem();
        if (problem) { setStatus('danger', problem[0], problem[1], [useManual()]); return; }
        if (running) return;
        wantCamera = true;
        setStatus('info', 'Starting camera…', 'Allow camera access if your browser asks.');
        if (!scanner) {
            scanner = new Html5Qrcode(READER_ID, {
                verbose: false,
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                experimentalFeatures: { useBarCodeDetectorIfSupported: true },
            });
        }
        try {
            // Rear camera on phones/tablets; desktop webcams satisfy this as a preference.
            await scanner.start({ facingMode: 'environment' }, scanConfig(), onDecoded, () => {});
        } catch (first) {
            const name = errorName(first);
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError' || name === 'NotReadableError') {
                showCameraError(first);
                return;
            }
            try {
                // Fallback: the first camera the browser lists (front camera / USB webcam).
                const cams = await Html5Qrcode.getCameras();
                if (!cams || !cams.length) { showCameraError({ name: 'NotFoundError' }); return; }
                await scanner.start(cams[0].id, scanConfig(), onDecoded, () => {});
            } catch (second) {
                showCameraError(second);
                return;
            }
        }
        running = true;
        busy = false;
        setStatus('success', 'Camera ready — point it at the participant\'s QR code.', 'Scanning for: ' + selectedEventName());
    }

    async function stopCamera(keepWanted) {
        if (!keepWanted) wantCamera = false;
        if (scanner && running) {
            try { await scanner.stop(); } catch (e) { /* already stopped */ }
        }
        running = false;
        // Belt and braces: release any track the library may still hold.
        document.querySelectorAll('#' + READER_ID + ' video').forEach((v) => {
            if (v.srcObject) v.srcObject.getTracks().forEach((t) => t.stop());
        });
        if (!keepWanted) idleStatus();
    }

    function pauseScanning() {
        if (scanner && running) {
            try { scanner.pause(true); } catch (e) { /* not scanning */ }
            setStatus('info', 'Scanning paused.', 'Finish the result below, then choose Scan Next Participant.');
            $('cameraToggle').hidden = false;
        }
    }
    function resumeScanning() {
        busy = false;
        if (scanner && running) {
            try { scanner.resume(); } catch (e) { /* not paused */ }
            setStatus('success', 'Camera ready — point it at the participant\'s QR code.', 'Scanning for: ' + selectedEventName());
        }
    }

    function idleStatus() {
        setStatus('idle', 'Camera is off.', 'Camera permission is required to scan QR codes. Your browser will ask the first time.', [button('Start Camera', 'btn-evora-primary', startCamera, 'bi-camera-video')]);
    }

    function onDecoded(text) {
        const now = Date.now();
        if (busy) return;
        if (text === lastToken && now - lastTokenAt < 5000) return;   // same badge still in view
        lastToken = text;
        lastTokenAt = now;
        busy = true;
        pauseScanning();
        if (navigator.vibrate) navigator.vibrate(60);
        lookup(text, 'camera');
    }

    // ------------------------------------------------------------------ server
    // regId: manual check-in (participant found by name/bib) — sent instead of a QR token (v40).
    function post(token, stage, regId) {
        const body = new URLSearchParams({ csrf_token: csrfToken, token: token || '', event_id: eventSelect.value, action: 'attendance', stage });
        if (regId) body.set('registration_id', String(regId));
        return fetch(verifyUrl, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then((r) => r.json());
    }

    function lookup(token, source, regId) {
        renderLoading(regId ? 'Checking registration…' : 'Checking QR code…');
        post(token, 'lookup', regId).then((data) => renderResult(data, token, source, regId))
            .catch(() => renderResult({ valid: false, code: 'network', message: 'Network or server error. Check the connection and try again.' }, token, source, regId));
    }

    function confirmCheckIn(token, btn, regId) {
        btn.disabled = true;
        btn.replaceChildren(el('span', { class: 'spinner-border spinner-border-sm me-1', 'aria-hidden': 'true' }), 'Recording…');
        post(token, 'confirm', regId).then((data) => {
            renderResult(data, token, 'confirm', regId);
            refreshStats();
        }).catch(() => renderResult({ valid: false, code: 'network', message: 'Network or server error. The check-in was not confirmed — try again.' }, token, 'confirm', regId));
    }

    // ------------------------------------------------------------------ result card
    const TITLES = {
        ready: ['Participant verified', 'info', 'bi-person-check'],
        checked_in: ['Check-in successful', 'success', 'bi-check-circle-fill'],
        already_checked_in: ['Already checked in', 'warning', 'bi-exclamation-circle'],
        not_eligible: ['Cannot check in', 'danger', 'bi-x-circle'],
        wrong_event: ['Wrong event', 'danger', 'bi-calendar-x'],
        invalid_qr: ['Invalid QR code', 'danger', 'bi-qr-code'],
        invalidated: ['QR code no longer valid', 'danger', 'bi-slash-circle'],
        not_found: ['Registration not found', 'danger', 'bi-question-circle'],
        no_permission: ['Not allowed for this event', 'danger', 'bi-shield-x'],
        network: ['Connection problem', 'danger', 'bi-wifi-off'],
    };

    function renderLoading(text) {
        resultBox.hidden = false;
        resultBox.className = 'scan-result is-info';
        resultBox.replaceChildren(el('div', { class: 'd-flex align-items-center gap-2' }, [el('span', { class: 'spinner-border spinner-border-sm', 'aria-hidden': 'true' }), text]));
    }

    function nextButton(label) {
        return button(label || 'Scan Next Participant', 'btn-evora-primary', () => { clearResult(); resumeScanning(); $('manualToken').value = ''; }, 'bi-qr-code-scan');
    }

    function clearResult() {
        resultBox.hidden = true;
        resultBox.replaceChildren();
    }

    function renderResult(data, token, source, regId) {
        const code = data.code || (data.valid ? (data.can_confirm ? 'ready' : 'not_eligible') : 'invalid_qr');
        const [title, kind, iconName] = TITLES[code] || [data.valid ? 'Result' : 'Cannot check in', data.valid ? 'info' : 'danger', 'bi-info-circle'];
        const rows = [];
        if (data.participant_name) rows.push(['Participant', data.participant_name]);
        if (data.event_name) rows.push(['Event', data.event_name]);
        if (data.registration_status) rows.push(['Registration', data.registration_status.charAt(0).toUpperCase() + data.registration_status.slice(1)]);
        if (data.payment_status) rows.push(['Payment', data.payment_status === 'not_required' ? 'Free event' : data.payment_status.charAt(0).toUpperCase() + data.payment_status.slice(1)]);
        if (data.checked_in_at) rows.push(['Check-in', data.checked_in_at]);

        const actions = [];
        if (code === 'ready' && data.can_confirm) {
            const confirmBtn = button(regId ? 'Confirm Check-In (ID checked)' : 'Confirm Check-In', 'btn-success', () => confirmCheckIn(token, confirmBtn, regId), 'bi-check2-circle');
            actions.push(confirmBtn, button('Cancel', 'btn-outline-secondary', () => { clearResult(); resumeScanning(); }));
        } else {
            actions.push(nextButton(code === 'checked_in' ? 'Scan Next Participant' : 'Scan Again'));
            if ((code === 'checked_in' || code === 'already_checked_in') && data.can_undo && data.attendance_id) {
                actions.push(button('Undo Check-In', 'btn-outline-danger', () => undoCheckIn(data.attendance_id, data.participant_name), 'bi-arrow-counterclockwise'));
            }
        }

        resultBox.hidden = false;
        resultBox.className = 'scan-result is-' + kind;
        resultBox.replaceChildren(...[
            el('div', { class: 'scan-result-title' }, [icon(iconName), ' ', title]),
            el('p', { class: 'scan-result-message', text: data.message || '' }),
            rows.length ? el('dl', { class: 'scan-result-facts' }, rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })])) : null,
            data.certificate_issued ? el('p', { class: 'small mb-2', text: 'Certificate issued automatically.' }) : null,
            el('div', { class: 'd-flex flex-wrap gap-2' }, actions),
        ].filter(Boolean));
        if (code !== 'ready') logScan(data, code === 'checked_in');
        const first = resultBox.querySelector('button');
        if (first) first.focus({ preventScroll: true });
    }

    // ------------------------------------------------------------------ recent scans
    function logScan(data, success) {
        const code = data.code || (success ? 'checked_in' : 'invalid_qr');
        const [title, kind] = TITLES[code] || ['Scan', 'info'];
        const empty = $('recentEmpty');
        if (empty) empty.remove();
        recentList.prepend(el('li', { class: 'recent-scan' }, [
            el('span', { class: 'badge ' + ({ success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', info: 'bg-info' }[kind] || 'bg-secondary') }, [title]),
            el('div', { class: 'recent-scan-body' }, [
                el('div', { class: 'fw-semibold', text: data.participant_name || 'Unknown QR' }),
                el('div', { class: 'small text-muted', text: (data.event_name || selectedEventName()) + ' · ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }),
            ]),
        ]));
        while (recentList.children.length > 15) recentList.lastElementChild.remove();
    }

    // ------------------------------------------------------------------ v40: find without QR, live counter, undo
    const searchUrl = root.dataset.searchUrl;
    const statsUrl = root.dataset.statsUrl;
    const undoUrl = root.dataset.undoUrl;
    const undoMinutes = root.dataset.undoMinutes || '15';
    let findTimer = null;
    let missing = [];

    function getJson(url, params) {
        const u = new URL(url, location.href);
        Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
        return fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then((r) => r.json());
    }

    function lookupById(regId) {
        busy = true;
        pauseScanning();
        lookup('', 'manual', regId);
        resultBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function personButton(p, extra) {
        const meta = [p.bib ? 'Bib ' + p.bib : null, p.category, p.email].filter(Boolean).join(' · ');
        const b = el('button', { type: 'button', class: 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2' }, [
            el('span', {}, [el('span', { class: 'fw-semibold d-block', text: p.name }), meta ? el('span', { class: 'small text-muted', text: meta }) : null].filter(Boolean)),
            extra || null,
        ].filter(Boolean));
        b.addEventListener('click', () => lookupById(p.registration_id));
        return b;
    }

    function runFind() {
        const q = $('findInput').value.trim();
        const box = $('findResults');
        if (q.length < 2) { box.replaceChildren(); return; }
        getJson(searchUrl, { event_id: eventSelect.value, q }).then((data) => {
            if (!data.ok) { box.replaceChildren(el('div', { class: 'small text-danger', text: data.message || 'Search failed.' })); return; }
            if (!data.results.length) { box.replaceChildren(el('div', { class: 'small text-muted', text: 'No registration for this event matches "' + q + '".' })); return; }
            box.replaceChildren(...data.results.map((p) => personButton(p, el('span', {
                class: 'badge ' + (p.checked_in_at ? 'bg-success' : (p.status === 'approved' ? 'bg-light text-dark border' : 'bg-warning text-dark')),
                text: p.checked_in_at ? 'In ' + p.checked_in_at : (p.status === 'approved' ? 'Not checked in' : p.status),
            }))));
        }).catch(() => box.replaceChildren(el('div', { class: 'small text-danger', text: 'Network error — try again.' })));
    }

    function renderMissing() {
        const f = ($('missingFilter').value || '').trim().toLowerCase();
        const list = missing.filter((p) => !f || p.name.toLowerCase().includes(f) || (p.bib || '').toLowerCase() === f);
        $('missingList').replaceChildren(...(list.length ? list.slice(0, 200).map((p) => {
            const li = el('li', { class: 'py-1 border-bottom d-flex justify-content-between gap-2' }, [el('span', { text: p.name + (p.bib ? ' · Bib ' + p.bib : '') })]);
            const go = el('button', { type: 'button', class: 'btn btn-link btn-sm p-0', text: 'Check in' });
            go.addEventListener('click', () => lookupById(p.registration_id));
            li.append(go);
            return li;
        }) : [el('li', { class: 'text-muted', text: missing.length ? 'No match.' : 'Everyone expected is checked in.' })]));
    }

    function refreshStats() {
        if (!statsUrl) return;
        getJson(statsUrl, { event_id: eventSelect.value }).then((d) => {
            if (!d.ok) { $('liveOf').textContent = d.message || ''; return; }
            $('liveCount').textContent = d.checked_in;
            $('liveOf').textContent = '/ ' + d.expected + ' checked in · ' + d.remaining + ' to go';
            $('liveBar').style.width = d.percent + '%';
            $('liveBarWrap').setAttribute('aria-valuenow', String(d.percent));
            $('liveUpdated').textContent = 'Updated ' + d.updated_at;
            missing = d.not_arrived || [];
            $('missingCount').textContent = String(missing.length);
            renderMissing();
            const u = d.undoable || [];
            $('undoBox').hidden = !u.length;
            $('undoList').replaceChildren(...u.map((x) => {
                const btn = el('button', { type: 'button', class: 'btn btn-link btn-sm text-danger p-0', text: 'Undo' });
                btn.addEventListener('click', () => undoCheckIn(x.attendance_id, x.name));
                return el('li', { class: 'd-flex justify-content-between gap-2 py-1' }, [el('span', { text: x.name + ' · ' + x.time + ' (until ' + x.until + ')' }), btn]);
            }));
        }).catch(() => { $('liveUpdated').textContent = 'Offline — retrying'; });
    }

    function undoCheckIn(attendanceId, name) {
        const reason = window.prompt('Undo the check-in of ' + (name || 'this participant') + '?\n\nType the reason (for example: wrong person scanned). Allowed within ' + undoMinutes + ' minutes of your own check-in.');
        if (reason === null) return;
        const body = new URLSearchParams({ csrf_token: csrfToken, attendance_id: String(attendanceId), reason });
        fetch(undoUrl, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then((r) => r.json())
            .then((d) => {
                resultBox.hidden = false;
                resultBox.className = 'scan-result is-' + (d.ok ? 'warning' : 'danger');
                resultBox.replaceChildren(
                    el('div', { class: 'scan-result-title' }, [icon(d.ok ? 'bi-arrow-counterclockwise' : 'bi-x-circle'), ' ', d.ok ? 'Check-in undone' : 'Could not undo']),
                    el('p', { class: 'scan-result-message', text: d.message || '' }),
                    el('div', { class: 'd-flex flex-wrap gap-2' }, [nextButton()]),
                );
                refreshStats();
            })
            .catch(() => window.alert('Network error — the check-in was not undone.'));
    }

    $('findInput').addEventListener('input', () => { clearTimeout(findTimer); findTimer = setTimeout(runFind, 300); });
    $('missingFilter').addEventListener('input', renderMissing);
    refreshStats();
    setInterval(() => { if (!document.hidden) refreshStats(); }, 10000);

    // ------------------------------------------------------------------ wiring
    $('startCamera') && $('startCamera').addEventListener('click', startCamera);
    $('cameraToggle').addEventListener('click', () => stopCamera(false));

    $('manualForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const val = $('manualToken').value.trim();
        if (!val) { $('manualToken').focus(); return; }
        busy = true;
        pauseScanning();
        lookup(val, 'manual');
    });

    eventSelect.addEventListener('change', async () => {
        clearResult();
        lastToken = '';
        $('scanningEventName').textContent = selectedEventName();
        $('findInput').value = '';
        $('findResults').replaceChildren();
        refreshStats();
        if (running) {
            await stopCamera(true);
            await startCamera();          // reset and start again for the new event
        }
    });

    // Never leave the webcam on: stop when leaving the page, pause the device when the tab is hidden.
    window.addEventListener('pagehide', () => { stopCamera(true); });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && running) { stopCamera(true); }
        else if (!document.hidden && wantCamera && !running) { startCamera(); }
    });

    // First state: start right away only if the browser already allowed the camera for this site.
    const problem = environmentProblem();
    if (problem) {
        setStatus('danger', problem[0], problem[1], [useManual()]);
    } else if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then((p) => {
            if (p.state === 'granted') startCamera();
            else if (p.state === 'denied') showCameraError({ name: 'NotAllowedError' });
            else idleStatus();
        }, idleStatus);
    } else {
        idleStatus();
    }
})();
