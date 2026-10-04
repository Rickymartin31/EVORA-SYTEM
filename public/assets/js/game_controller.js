// EVORA Live Game Controller — polls game_state_action.php and wires up the buttons.
document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('gcRoot');
    if (!root) return;

    const matchId = root.dataset.matchId;
    const csrf = root.dataset.csrf;
    const actionUrl = root.dataset.actionUrl;

    const scoreA = document.getElementById('gcScoreA');
    const scoreB = document.getElementById('gcScoreB');
    const foulsA = document.getElementById('gcFoulsA');
    const foulsB = document.getElementById('gcFoulsB');
    const timeoutsA = document.getElementById('gcTimeoutsA');
    const timeoutsB = document.getElementById('gcTimeoutsB');
    const periodEl = document.getElementById('gcPeriod');
    const clockEl = document.getElementById('gcClock');

    // v34.2: EVORA confirmation dialog (SweetAlert2 via app.js); native confirm() only if it is unavailable.
    const ask = function (opts) {
        if (window.EvoraConfirm) return window.EvoraConfirm(opts).then(function (r) { return !!r.isConfirmed; });
        return Promise.resolve(window.confirm(opts.title + ' ' + opts.text));
    };

    let remainingSeconds = 0;
    let clockRunning = false;
    let tickTimer = null;

    function formatClock(totalSeconds) {
        const s = Math.max(0, Math.floor(totalSeconds));
        const mm = String(Math.floor(s / 60)).padStart(2, '0');
        const ss = String(s % 60).padStart(2, '0');
        return mm + ':' + ss;
    }

    function renderClock() {
        clockEl.textContent = formatClock(remainingSeconds);
    }

    function startLocalTick() {
        if (tickTimer) return;
        tickTimer = setInterval(function () {
            if (clockRunning && remainingSeconds > 0) {
                remainingSeconds--;
                renderClock();
            }
        }, 1000);
    }

    function applyState(data) {
        scoreA.textContent = data.team_a_score;
        scoreB.textContent = data.team_b_score;
        foulsA.textContent = data.fouls_a;
        foulsB.textContent = data.fouls_b;
        timeoutsA.textContent = data.timeouts_a;
        timeoutsB.textContent = data.timeouts_b;
        periodEl.textContent = data.period + ' / ' + data.periods_count;
        remainingSeconds = data.remaining_seconds;
        clockRunning = !!data.clock_running;
        renderClock();
    }

    function sendAction(action, extra) {
        const body = new URLSearchParams(Object.assign({
            csrf_token: csrf, match_id: matchId, action: action,
        }, extra || {}));
        return fetch(actionUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.error) applyState(data);
                return data;
            });
    }

    document.querySelectorAll('.gc-points').forEach(function (btn) {
        btn.addEventListener('click', function () {
            sendAction('add_points', { team: btn.dataset.team, points: btn.dataset.points });
        });
    });
    document.querySelectorAll('.gc-foul').forEach(function (btn) {
        btn.addEventListener('click', function () { sendAction('add_foul', { team: btn.dataset.team }); });
    });
    document.querySelectorAll('.gc-timeout').forEach(function (btn) {
        btn.addEventListener('click', function () { sendAction('use_timeout', { team: btn.dataset.team }); });
    });
    document.getElementById('gcStart').addEventListener('click', function () { sendAction('start_clock'); });
    document.getElementById('gcPause').addEventListener('click', function () { sendAction('pause_clock'); });
    document.getElementById('gcReset').addEventListener('click', function () {
        ask({ title: 'Reset the clock?', text: 'The clock for this period goes back to the full period time. Scores, fouls and timeouts are not changed.', button: 'Reset Clock', variant: 'danger' })
            .then(function (ok) { if (ok) sendAction('reset_clock'); });
    });
    document.getElementById('gcNextPeriod').addEventListener('click', function () {
        ask({ title: 'Move to the next period?', text: 'This starts the next period and resets the clock.', button: 'Next Period', variant: 'primary' })
            .then(function (ok) { if (ok) sendAction('next_period'); });
    });
    document.getElementById('gcEndGame').addEventListener('click', function () {
        ask({ title: 'End the game?', text: 'This records the current score as the final score and marks the match completed.', button: 'End Game', variant: 'success' })
            .then(function (ok) {
                if (!ok) return;
                sendAction('end_game').then(function () {
                    window.location.href = root.dataset.matchesUrl;
                });
            });
    });

    // Initial sync + periodic poll to stay in sync (e.g. two tabs open).
    sendAction('refresh');
    startLocalTick();
    setInterval(function () { sendAction('refresh'); }, 4000);
});
