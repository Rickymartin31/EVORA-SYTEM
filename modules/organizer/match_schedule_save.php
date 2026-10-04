<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_POST['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, s.sport_type, s.team_size FROM events e
     LEFT JOIN sports s ON s.id = e.sport_id WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event || empty($event['sport_id'])) {
    flash('error', 'Event not found or has no sport selected.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'organizer-events');

$sportId = (int) $event['sport_id'];
$usesTeams = $event['sport_type'] !== 'individual' || (int) $event['team_size'] !== 1;
$action = $_POST['schedule_action'] ?? '';

function venueLabel(array $v): string
{
    return $v['code'] !== null && $v['code'] !== '' ? $v['name'] . ' (' . $v['code'] . ')' : $v['name'];
}

function insertScheduledMatch(PDO $db, int $eventId, int $sportId, int $roundNo, ?int $teamA, ?int $teamB, ?int $partA, ?int $partB, ?string $scheduledAt, ?string $venue): int
{
    $stmt = $db->prepare(
        'INSERT INTO matches (event_id, sport_id, team_a_id, team_b_id, participant_a_id, participant_b_id, scheduled_at, venue, round_no)
         VALUES (:eid, :sid, :ta, :tb, :pa, :pb, :sched, :venue, :round)'
    );
    $stmt->execute([
        'eid' => $eventId, 'sid' => $sportId, 'ta' => $teamA, 'tb' => $teamB, 'pa' => $partA, 'pb' => $partB,
        'sched' => $scheduledAt, 'venue' => $venue, 'round' => $roundNo,
    ]);
    $matchId = (int) $db->lastInsertId();

    $posStmt = $db->prepare('SELECT COUNT(*) c FROM tournament_brackets WHERE event_id = :eid AND round_no = :round');
    $posStmt->execute(['eid' => $eventId, 'round' => $roundNo]);
    $position = (int) $posStmt->fetch()['c'] + 1;

    $bracketStmt = $db->prepare('INSERT INTO tournament_brackets (event_id, round_no, match_id, position) VALUES (:eid, :round, :mid, :pos)');
    $bracketStmt->execute(['eid' => $eventId, 'round' => $roundNo, 'mid' => $matchId, 'pos' => $position]);

    return $matchId;
}

if ($action === 'generate') {
    $existingCheck = $db->prepare('SELECT COUNT(*) c FROM matches WHERE event_id = :eid');
    $existingCheck->execute(['eid' => $eventId]);
    if ((int) $existingCheck->fetch()['c'] > 0) {
        flash('error', 'A schedule already exists for this event. Remove existing matches before regenerating.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    $format = in_array($_POST['format'] ?? '', ['single_elim', 'round_robin'], true) ? $_POST['format'] : 'single_elim';
    $tournamentDate = trim((string) ($_POST['tournament_date'] ?? ''));
    $startTime = trim((string) ($_POST['start_time'] ?? ''));
    $endTime = trim((string) ($_POST['end_time'] ?? ''));
    $matchDuration = (int) ($_POST['match_duration'] ?? 0);
    $breakDuration = max(0, (int) ($_POST['break_duration'] ?? 0));
    $venueIds = array_filter(array_map('intval', (array) ($_POST['venue_ids'] ?? [])));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tournamentDate) || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        flash('error', 'Please provide a valid tournament date, start time, and end time.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }
    if ($matchDuration < 1) {
        flash('error', 'Match duration must be at least 1 minute.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }
    if (empty($venueIds)) {
        flash('error', 'Select at least one venue.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    // Never trust posted venue ids: only venues belonging to this event AND currently available.
    $placeholders = implode(',', array_fill(0, count($venueIds), '?'));
    $venueStmt = $db->prepare("SELECT * FROM venues WHERE event_id = ? AND status = 'available' AND id IN ($placeholders) ORDER BY name");
    $venueStmt->execute(array_merge([$eventId], $venueIds));
    $venues = $venueStmt->fetchAll();

    if (empty($venues)) {
        flash('error', 'None of the selected venues are available. Maintenance or closed venues cannot be scheduled.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    // Build the time-slot x venue grid.
    try {
        $start = new DateTime($tournamentDate . ' ' . $startTime);
        $end = new DateTime($tournamentDate . ' ' . $endTime);
    } catch (Exception $e) {
        flash('error', 'Invalid date/time.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }
    if ($end <= $start) {
        flash('error', 'End time must be after start time.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    $slots = [];
    $cursor = clone $start;
    $stepMinutes = $matchDuration + $breakDuration;
    $maxSlots = 300; // safety valve against a runaway loop from a huge window / tiny duration
    while (true) {
        $slotEnd = (clone $cursor)->modify('+' . $matchDuration . ' minutes');
        if ($slotEnd > $end || count($slots) >= $maxSlots) {
            break;
        }
        $slots[] = clone $cursor;
        $cursor->modify('+' . $stepMinutes . ' minutes');
    }

    if (empty($slots)) {
        flash('error', 'No time slots fit in that window with the given match duration.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    $cells = [];
    foreach ($slots as $slot) {
        foreach ($venues as $v) {
            $cells[] = ['time' => $slot->format('Y-m-d H:i:s'), 'venue' => venueLabel($v)];
        }
    }

    // Build the pool of entrants (teams, or approved participants for individual sports).
    if ($usesTeams) {
        $poolStmt = $db->prepare('SELECT id FROM teams WHERE event_id = :eid ORDER BY created_at');
        $poolStmt->execute(['eid' => $eventId]);
    } else {
        $poolStmt = $db->prepare(
            'SELECT p.id FROM registrations r JOIN participants p ON p.id = r.participant_id
             WHERE r.event_id = :eid AND r.status = "approved" ORDER BY r.registered_at'
        );
        $poolStmt->execute(['eid' => $eventId]);
    }
    $pool = array_column($poolStmt->fetchAll(), 'id');

    if (count($pool) < 2) {
        flash('error', 'Need at least 2 ' . ($usesTeams ? 'teams' : 'approved participants') . ' to generate a schedule.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    // Build the list of pairings per round (before touching the DB at all).
    $roundsPairings = [];
    if ($format === 'single_elim') {
        $pairings = [];
        for ($i = 0; $i < count($pool); $i += 2) {
            $pairings[] = [$pool[$i], $pool[$i + 1] ?? null];
        }
        $roundsPairings[1] = $pairings;
    } else { // round_robin, circle method
        $entrants = $pool;
        if (count($entrants) % 2 !== 0) {
            $entrants[] = null; // bye slot
        }
        $n = count($entrants);
        $roundsCount = $n - 1;
        $fixed = $entrants[0];
        $rotating = array_slice($entrants, 1);
        for ($r = 1; $r <= $roundsCount; $r++) {
            $roundEntrants = array_merge([$fixed], $rotating);
            $pairings = [];
            for ($i = 0; $i < $n / 2; $i++) {
                $a = $roundEntrants[$i];
                $b = $roundEntrants[$n - 1 - $i];
                if ($a !== null && $b !== null) { // skip the bye pairing for this round
                    $pairings[] = [$a, $b];
                }
            }
            $roundsPairings[$r] = $pairings;
            array_unshift($rotating, array_pop($rotating));
        }
    }

    $totalRealMatches = 0;
    foreach ($roundsPairings as $pairings) {
        $totalRealMatches += count($pairings);
    }
    if ($totalRealMatches > count($cells)) {
        flash('error', 'Not enough time slots/venues: need ' . $totalRealMatches . ' match slot(s) but only ' . count($cells) . ' are available. Widen the time window, add venues, or shorten match duration.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    $db->beginTransaction();
    try {
        $cellIndex = 0;
        $teamBusyAt = []; // defensive double-check: [scheduled_at] => [entrantId => true]
        $created = 0;

        foreach ($roundsPairings as $roundNo => $pairings) {
            foreach ($pairings as [$entrantA, $entrantB]) {
                if ($entrantA === null && $entrantB === null) {
                    continue;
                }
                if ($entrantB === null) {
                    // Single-elim bye: auto-complete, no time/venue consumed.
                    $matchId = $usesTeams
                        ? insertScheduledMatch($db, $eventId, $sportId, $roundNo, $entrantA, null, null, null, null, null)
                        : insertScheduledMatch($db, $eventId, $sportId, $roundNo, null, null, $entrantA, null, null, null);
                    $db->prepare('UPDATE matches SET status = "completed" WHERE id = :id')->execute(['id' => $matchId]);
                    $scoreStmt = $db->prepare('INSERT INTO match_scores (match_id, team_a_score, team_b_score, winner_team_id, winner_participant_id) VALUES (:mid, 0, 0, :wt, :wp)');
                    $scoreStmt->execute(['mid' => $matchId, 'wt' => $usesTeams ? $entrantA : null, 'wp' => !$usesTeams ? $entrantA : null]);
                    $created++;
                    continue;
                }

                // Find the next cell whose time doesn't already double-book either entrant (should never trigger given the construction above, kept as defense in depth).
                while ($cellIndex < count($cells) && (
                    !empty($teamBusyAt[$cells[$cellIndex]['time']][$entrantA]) ||
                    !empty($teamBusyAt[$cells[$cellIndex]['time']][$entrantB])
                )) {
                    $cellIndex++;
                }
                if ($cellIndex >= count($cells)) {
                    throw new RuntimeException('Ran out of conflict-free time slots while assigning the schedule.');
                }
                $cell = $cells[$cellIndex];
                $cellIndex++;

                $matchId = $usesTeams
                    ? insertScheduledMatch($db, $eventId, $sportId, $roundNo, $entrantA, $entrantB, null, null, $cell['time'], $cell['venue'])
                    : insertScheduledMatch($db, $eventId, $sportId, $roundNo, null, null, $entrantA, $entrantB, $cell['time'], $cell['venue']);

                $teamBusyAt[$cell['time']][$entrantA] = true;
                $teamBusyAt[$cell['time']][$entrantB] = true;
                $created++;
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash('error', 'Schedule generation failed: ' . $e->getMessage());
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    Logger::log($userId, 'schedule_generated', 'Generated ' . $created . ' match(es) (' . $format . ') for event #' . $eventId);
    flash('success', $created . ' match(es) scheduled.');
} elseif ($action === 'reschedule') {
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $newVenueId = (int) ($_POST['venue_id'] ?? 0);
    $newDate = trim((string) ($_POST['reschedule_date'] ?? ''));
    $newTime = trim((string) ($_POST['reschedule_time'] ?? ''));

    $matchStmt = $db->prepare('SELECT * FROM matches WHERE id = :id AND event_id = :eid');
    $matchStmt->execute(['id' => $matchId, 'eid' => $eventId]);
    $match = $matchStmt->fetch();
    if (!$match) {
        flash('error', 'Match not found.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || !preg_match('/^\d{2}:\d{2}$/', $newTime)) {
        flash('error', 'Please provide a valid date and time.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }
    $newScheduledAt = $newDate . ' ' . $newTime . ':00';

    $venueStmt = $db->prepare("SELECT * FROM venues WHERE id = :id AND event_id = :eid AND status = 'available'");
    $venueStmt->execute(['id' => $newVenueId, 'eid' => $eventId]);
    $venue = $venueStmt->fetch();
    if (!$venue) {
        flash('error', 'Selected venue is not available for this event.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }
    $newVenueLabel = venueLabel($venue);

    // Conflict check: neither of this match's own entrants may already be booked
    // at the new time in a different match.
    $entrantIds = array_filter([$match['team_a_id'], $match['team_b_id'], $match['participant_a_id'], $match['participant_b_id']]);
    if (!empty($entrantIds)) {
        $inClause = implode(',', array_fill(0, count($entrantIds), '?'));
        $conflictStmt = $db->prepare(
            "SELECT COUNT(*) c FROM matches
             WHERE event_id = ? AND id != ? AND scheduled_at = ?
             AND (team_a_id IN ($inClause) OR team_b_id IN ($inClause) OR participant_a_id IN ($inClause) OR participant_b_id IN ($inClause))"
        );
        $params = array_merge([$eventId, $matchId, $newScheduledAt], $entrantIds, $entrantIds, $entrantIds, $entrantIds);
        $conflictStmt->execute($params);
        if ((int) $conflictStmt->fetch()['c'] > 0) {
            flash('error', 'One of this match\'s teams/participants already has another match at that time.');
            redirect('organizer-match-schedule', ['event_id' => $eventId]);
        }
    }

    // Venue double-booking check (compare against the venue label snapshot, since matches.venue is free text).
    $venueConflictStmt = $db->prepare(
        'SELECT COUNT(*) c FROM matches WHERE event_id = :eid AND id != :mid AND scheduled_at = :sched AND venue = :venue'
    );
    $venueConflictStmt->execute(['eid' => $eventId, 'mid' => $matchId, 'sched' => $newScheduledAt, 'venue' => $newVenueLabel]);
    if ((int) $venueConflictStmt->fetch()['c'] > 0) {
        flash('error', 'That venue is already booked at that time.');
        redirect('organizer-match-schedule', ['event_id' => $eventId]);
    }

    $upd = $db->prepare('UPDATE matches SET scheduled_at = :sched, venue = :venue WHERE id = :id AND event_id = :eid');
    $upd->execute(['sched' => $newScheduledAt, 'venue' => $newVenueLabel, 'id' => $matchId, 'eid' => $eventId]);

    Logger::log($userId, 'match_rescheduled', 'Match #' . $matchId . ' rescheduled for event #' . $eventId);
    flash('success', 'Match rescheduled.');
} else {
    flash('error', 'Invalid action.');
}

redirect('organizer-match-schedule', ['event_id' => $eventId]);
