<?php

/**
 * Live race tracking (v56) for fun runs.
 *
 * The organizer draws the official route on a map and places the checkpoints on it. On race day a
 * runner opens the EVORA tracker on their phone; the browser sends GPS positions in small batches
 * (kept on the phone while there is no signal). The organizer's live map shows every runner, and
 * each finished track is checked against the route:
 *   verified           every mapped checkpoint passed, at least 85% of the route covered, distance ≥ 85%
 *   possible_shortcut  something is missing; the reason is stored for the organizer to review
 *   insufficient       not enough GPS data, or no route on the map to compare with
 * GPS can be off by 10–30 m, so a shortcut is only ever flagged for review, never auto-disqualified.
 */
class RaceTrackingService
{
    public const MAX_ROUTE_POINTS = 2000;
    public const MAX_BATCH = 500;
    /** GPS readings worse than this are stored but not used for distance. */
    public const MAX_ACCURACY_M = 50;
    /** Faster than this between two readings = a GPS jump, not running (12 m/s = 43 km/h). */
    public const MAX_SPEED_MS = 12.0;
    public const ROUTE_COVERAGE_MIN = 0.85;
    public const DISTANCE_RATIO_MIN = 0.85;
    /** A route sample counts as covered when a track point is this close. */
    public const COVER_RADIUS_M = 75;

    // ------------------------------------------------------------------ geometry

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Length of a polyline [[lat,lng],...] in metres. */
    public static function pathLength(array $points): float
    {
        $sum = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $sum += self::haversine($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        return $sum;
    }

    /** Points every ~$step metres along a polyline (for the coverage check). */
    public static function resample(array $points, float $step = 50.0): array
    {
        if (count($points) < 2) {
            return $points;
        }
        $out = [$points[0]];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            [$aLat, $aLng] = $points[$i - 1];
            [$bLat, $bLng] = $points[$i];
            $len = self::haversine($aLat, $aLng, $bLat, $bLng);
            $parts = max(1, (int) ceil($len / $step));
            for ($k = 1; $k <= $parts; $k++) {
                $t = $k / $parts;
                $out[] = [$aLat + ($bLat - $aLat) * $t, $aLng + ($bLng - $aLng) * $t];
            }
        }

        return $out;
    }

    private static function validLatLng($lat, $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && !((float) $lat == 0.0 && (float) $lng == 0.0);
    }

    // ------------------------------------------------------------------ route (organizer)

    /** The official route: ['points' => [[lat,lng],...], 'distance_m' => int] or null. */
    public static function route(PDO $db, int $eventId): ?array
    {
        $stmt = $db->prepare('SELECT points_json, distance_m FROM event_route_paths WHERE event_id = :e');
        $stmt->execute(['e' => $eventId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $points = json_decode((string) $row['points_json'], true);

        return is_array($points) && count($points) >= 2 ? ['points' => $points, 'distance_m' => (int) $row['distance_m']] : null;
    }

    /** Mapped, active checkpoints in running order. */
    public static function mappedCheckpoints(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare('SELECT id, sequence_no, label, checkpoint_type, lat, lng, radius_m FROM event_checkpoints
                              WHERE event_id = :e AND status = "active" AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY sequence_no, id');
        $stmt->execute(['e' => $eventId]);

        return array_map(fn ($c) => $c + ['lat_f' => (float) $c['lat'], 'lng_f' => (float) $c['lng']], $stmt->fetchAll());
    }

    /** Saves the route drawn on the map. $json = '[[lat,lng],...]'; '[]' clears it. Returns the length in metres. */
    public static function saveRoute(PDO $db, int $eventId, string $json, int $userId): int
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            throw new DomainException('The route could not be read. Please draw it again.');
        }
        if (!$raw) {
            $db->prepare('DELETE FROM event_route_paths WHERE event_id = :e')->execute(['e' => $eventId]);
            return 0;
        }
        if (count($raw) < 2 || count($raw) > self::MAX_ROUTE_POINTS) {
            throw new DomainException('A route needs at least 2 points (and at most ' . self::MAX_ROUTE_POINTS . ').');
        }
        $points = [];
        foreach ($raw as $p) {
            if (!is_array($p) || count($p) < 2 || !self::validLatLng($p[0], $p[1])) {
                throw new DomainException('The route has a point outside the map. Please draw it again.');
            }
            $points[] = [round((float) $p[0], 6), round((float) $p[1], 6)];
        }
        $distance = (int) round(self::pathLength($points));
        $db->prepare('INSERT INTO event_route_paths (event_id, points_json, distance_m, updated_by) VALUES (:e, :p, :d, :u)
                      ON DUPLICATE KEY UPDATE points_json = VALUES(points_json), distance_m = VALUES(distance_m), updated_by = VALUES(updated_by)')
            ->execute(['e' => $eventId, 'p' => json_encode($points), 'd' => $distance, 'u' => $userId]);

        return $distance;
    }

    // ------------------------------------------------------------------ tracking (runner)

    /**
     * The runner's registration for a fun run that can be tracked now (event day, or the day after for a
     * late finish), or a reason why not: ['reg' => row|null, 'why' => string|null].
     */
    public static function trackable(PDO $db, int $registrationId, int $userId): array
    {
        $stmt = $db->prepare('SELECT r.id, r.status, r.bib_number, r.distance_category, e.id AS event_id, e.name AS event_name, e.event_date, e.event_time, e.status AS event_status, c.slug
            FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id JOIN event_categories c ON c.id = e.category_id
            WHERE r.id = :r AND p.user_id = :u');
        $stmt->execute(['r' => $registrationId, 'u' => $userId]);
        $reg = $stmt->fetch();
        if (!$reg) {
            return ['reg' => null, 'why' => 'Registration not found.'];
        }
        if ($reg['slug'] !== 'fun-run') {
            return ['reg' => $reg, 'why' => 'Live tracking is only for fun runs.'];
        }
        if ($reg['status'] !== 'approved') {
            return ['reg' => $reg, 'why' => 'Your registration must be approved before you can be tracked.'];
        }
        if (in_array($reg['event_status'], ['cancelled', 'removed', 'archived', 'draft', 'pending_approval'], true)) {
            return ['reg' => $reg, 'why' => 'This event is not running.'];
        }
        $today = date('Y-m-d');
        if ($today < $reg['event_date']) {
            return ['reg' => $reg, 'why' => 'The tracker opens on race day (' . date('M d, Y', strtotime($reg['event_date'])) . ').'];
        }
        if ($today > date('Y-m-d', strtotime($reg['event_date'] . ' +1 day'))) {
            return ['reg' => $reg, 'why' => 'This race is over, so tracking is closed.'];
        }

        return ['reg' => $reg, 'why' => null];
    }

    public static function trackFor(PDO $db, int $registrationId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM race_tracks WHERE registration_id = :r');
        $stmt->execute(['r' => $registrationId]);

        return $stmt->fetch() ?: null;
    }

    /** Starts (or resumes) the runner's track. Returns the track row. */
    public static function start(PDO $db, array $reg): array
    {
        $track = self::trackFor($db, (int) $reg['id']);
        if ($track && $track['status'] === 'finished') {
            throw new DomainException('You already finished this race. Your track was saved.');
        }
        if (!$track) {
            $db->prepare('INSERT INTO race_tracks (registration_id, event_id) VALUES (:r, :e)')->execute(['r' => $reg['id'], 'e' => $reg['event_id']]);
            $track = self::trackFor($db, (int) $reg['id']);
            Logger::log(null, 'race_tracking_started', 'Registration #' . $reg['id'] . ' started live tracking for event #' . $reg['event_id']);
        }

        return $track;
    }

    /**
     * Stores a batch of positions [{lat,lng,acc,t(ms)}] (duplicates from a resend are ignored),
     * then updates the distance and the last position. Returns the updated track.
     */
    public static function push(PDO $db, array $track, array $batch): array
    {
        if ($track['status'] !== 'active') {
            throw new DomainException('This track is already finished.');
        }
        $batch = array_slice($batch, 0, self::MAX_BATCH);
        $ins = $db->prepare('INSERT IGNORE INTO race_track_points (track_id, lat, lng, accuracy_m, recorded_at) VALUES (:t, :lat, :lng, :acc, :at)');
        $now = time();
        foreach ($batch as $p) {
            if (!is_array($p) || !self::validLatLng($p['lat'] ?? null, $p['lng'] ?? null) || !is_numeric($p['t'] ?? null)) {
                continue;
            }
            $ts = (int) floor(((float) $p['t']) / 1000);
            if ($ts > $now + 300 || $ts < $now - 2 * 86400) {
                continue; // clock far off
            }
            $ins->execute(['t' => $track['id'], 'lat' => round((float) $p['lat'], 6), 'lng' => round((float) $p['lng'], 6),
                'acc' => isset($p['acc']) && is_numeric($p['acc']) ? min(65535, max(0, (int) round((float) $p['acc']))) : null, 'at' => date('Y-m-d H:i:s', $ts)]);
        }
        self::refresh($db, (int) $track['id']);

        return self::trackFor($db, (int) $track['registration_id']);
    }

    /** Ends the track and runs the route check. */
    public static function finish(PDO $db, array $track): array
    {
        if ($track['status'] === 'active') {
            $db->prepare('UPDATE race_tracks SET status = "finished", finished_at = NOW() WHERE id = :id AND status = "active"')->execute(['id' => $track['id']]);
            Logger::log(null, 'race_tracking_finished', 'Race track #' . $track['id'] . ' finished');
        }
        self::check($db, (int) $track['id']);

        return self::trackFor($db, (int) $track['registration_id']);
    }

    /** Usable points of a track: accurate enough, in time order, without GPS jumps. */
    public static function cleanPoints(PDO $db, int $trackId): array
    {
        $stmt = $db->prepare('SELECT lat, lng, accuracy_m, UNIX_TIMESTAMP(recorded_at) AS ts FROM race_track_points WHERE track_id = :t ORDER BY recorded_at');
        $stmt->execute(['t' => $trackId]);
        $clean = [];
        foreach ($stmt->fetchAll() as $p) {
            if ($p['accuracy_m'] !== null && (int) $p['accuracy_m'] > self::MAX_ACCURACY_M) {
                continue;
            }
            $pt = [(float) $p['lat'], (float) $p['lng'], (int) $p['ts']];
            if ($clean) {
                $prev = end($clean);
                $dt = max(1, $pt[2] - $prev[2]);
                if (self::haversine($prev[0], $prev[1], $pt[0], $pt[1]) / $dt > self::MAX_SPEED_MS) {
                    continue;
                }
            }
            $clean[] = $pt;
        }

        return $clean;
    }

    /** Recomputes distance, point count and last position of a track. */
    public static function refresh(PDO $db, int $trackId): void
    {
        $clean = self::cleanPoints($db, $trackId);
        $last = $db->prepare('SELECT lat, lng, accuracy_m, recorded_at FROM race_track_points WHERE track_id = :t ORDER BY recorded_at DESC LIMIT 1');
        $last->execute(['t' => $trackId]);
        $l = $last->fetch();
        $count = $db->prepare('SELECT COUNT(*) FROM race_track_points WHERE track_id = :t');
        $count->execute(['t' => $trackId]);
        $db->prepare('UPDATE race_tracks SET distance_m = :d, point_count = :c, last_lat = :lat, last_lng = :lng, last_accuracy_m = :acc, last_point_at = :at WHERE id = :id')
            ->execute(['d' => (int) round(self::pathLength($clean)), 'c' => (int) $count->fetchColumn(), 'lat' => $l['lat'] ?? null, 'lng' => $l['lng'] ?? null,
                'acc' => $l['accuracy_m'] ?? null, 'at' => $l['recorded_at'] ?? null, 'id' => $trackId]);
    }

    // ------------------------------------------------------------------ anti-shortcut check

    /** Compares a track with the official route and checkpoints; stores and returns the verdict. */
    public static function check(PDO $db, int $trackId): array
    {
        $track = $db->prepare('SELECT * FROM race_tracks WHERE id = :id');
        $track->execute(['id' => $trackId]);
        $track = $track->fetch();
        $route = self::route($db, (int) $track['event_id']);
        $checkpoints = self::mappedCheckpoints($db, (int) $track['event_id']);
        $points = self::cleanPoints($db, $trackId);
        $reasons = [];
        $verdict = 'verified';

        if (!$route && !$checkpoints) {
            $verdict = 'insufficient';
            $reasons[] = 'The organizer has not set the route or checkpoints on the map yet.';
        } elseif (count($points) < 10) {
            $verdict = 'insufficient';
            $reasons[] = 'Too little GPS data (' . count($points) . ' usable positions).';
        } else {
            foreach ($checkpoints as $c) {
                $passed = false;
                foreach ($points as $p) {
                    if (self::haversine($c['lat_f'], $c['lng_f'], $p[0], $p[1]) <= (int) $c['radius_m'] + 30) {
                        $passed = true;
                        break;
                    }
                }
                if (!$passed) {
                    $reasons[] = 'Did not pass ' . $c['label'] . '.';
                }
            }
            if ($route) {
                $samples = self::resample($route['points'], 50.0);
                $covered = 0;
                foreach ($samples as $s) {
                    foreach ($points as $p) {
                        if (self::haversine($s[0], $s[1], $p[0], $p[1]) <= self::COVER_RADIUS_M) {
                            $covered++;
                            break;
                        }
                    }
                }
                $coverage = $samples ? $covered / count($samples) : 0;
                if ($coverage < self::ROUTE_COVERAGE_MIN) {
                    $reasons[] = 'Covered only ' . round($coverage * 100) . '% of the route.';
                }
                $ran = self::pathLength(array_map(fn ($p) => [$p[0], $p[1]], $points));
                if ($route['distance_m'] > 0 && $ran / $route['distance_m'] < self::DISTANCE_RATIO_MIN) {
                    $reasons[] = 'Ran ' . number_format($ran / 1000, 2) . ' km of the ' . number_format($route['distance_m'] / 1000, 2) . ' km route.';
                }
            }
            if ($reasons) {
                $verdict = 'possible_shortcut';
            }
        }
        $detail = $reasons ? implode(' ', $reasons) : 'Passed every mapped checkpoint and followed the route.';
        $db->prepare('UPDATE race_tracks SET verdict = :v, verdict_detail = :d, checked_at = NOW() WHERE id = :id')
            ->execute(['v' => $verdict, 'd' => mb_substr($detail, 0, 500), 'id' => $trackId]);

        return ['verdict' => $verdict, 'detail' => $detail];
    }

    // ------------------------------------------------------------------ organizer live view

    /** Every tracked runner of an event, for the live map and the list. */
    public static function liveRunners(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare('SELECT t.id, t.status, t.started_at, t.finished_at, t.last_lat, t.last_lng, t.last_accuracy_m, t.last_point_at, t.point_count, t.distance_m,
                   t.verdict, t.verdict_detail, r.bib_number, r.distance_category, u.full_name, TIMESTAMPDIFF(SECOND, t.last_point_at, NOW()) AS seconds_ago
            FROM race_tracks t JOIN registrations r ON r.id = t.registration_id JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
            WHERE t.event_id = :e ORDER BY t.status, u.full_name');
        $stmt->execute(['e' => $eventId]);

        return $stmt->fetchAll();
    }

    /** One runner's full track as [[lat,lng],...] (only for a track of this event). */
    public static function trackPath(PDO $db, int $eventId, int $trackId): array
    {
        $stmt = $db->prepare('SELECT p.lat, p.lng FROM race_track_points p JOIN race_tracks t ON t.id = p.track_id WHERE t.id = :t AND t.event_id = :e ORDER BY p.recorded_at');
        $stmt->execute(['t' => $trackId, 'e' => $eventId]);

        return array_map(fn ($r) => [(float) $r['lat'], (float) $r['lng']], $stmt->fetchAll());
    }
}
