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
    /** Live alert when the latest position is this far from the route (v68). */
    public const OFF_ROUTE_ALERT_M = 100;
    /** A finished track that went this far from the route is flagged for review (v68). */
    public const OFF_ROUTE_FLAG_M = 200;
    public const GPX_MAX_BYTES = 15 * 1024 * 1024;
    public const GPX_MAX_POINTS = 30000;
    /** GPX files (Strava, watches) can be uploaded until this many days after the race. */
    public const GPX_UPLOAD_DAYS = 3;

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

    /** Shortest distance in metres from a point to a polyline [[lat,lng],...] (local flat projection, fine for a race course). */
    public static function distanceToRoute(float $lat, float $lng, array $route): float
    {
        $n = count($route);
        if ($n === 0) {
            return INF;
        }
        $kx = 111320.0 * cos(deg2rad($lat));
        $ky = 110540.0;
        $best = INF;
        for ($i = 0; $i < $n; $i++) {
            $ax = ($route[$i][1] - $lng) * $kx;
            $ay = ($route[$i][0] - $lat) * $ky;
            if ($i === $n - 1) {
                $best = min($best, hypot($ax, $ay));
                break;
            }
            $bx = ($route[$i + 1][1] - $lng) * $kx;
            $by = ($route[$i + 1][0] - $lat) * $ky;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $best;
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

    // ------------------------------------------------------------------ quick checkpoint setup on the map (v69)

    public const CHECKPOINT_TYPES = ['start' => 'Start', 'finish' => 'Finish', 'checkpoint' => 'Checkpoint', 'water_station' => 'Water Station',
        'medical_station' => 'Medical Station', 'emergency_point' => 'Emergency Point', 'turnaround_point' => 'Turn-around Point'];

    /** [metres along the route to the closest point, metres away from the route] for a position. */
    public static function routeProgress(float $lat, float $lng, array $route): array
    {
        $n = count($route);
        if ($n < 2) {
            return [0.0, INF];
        }
        $kx = 111320.0 * cos(deg2rad($lat));
        $ky = 110540.0;
        $bestOff = INF;
        $bestAlong = 0.0;
        $walked = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $ax = ($route[$i][1] - $lng) * $kx;
            $ay = ($route[$i][0] - $lat) * $ky;
            $dx = ($route[$i + 1][1] - $route[$i][1]) * $kx;
            $dy = ($route[$i + 1][0] - $route[$i][0]) * $ky;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $off = hypot($ax + $t * $dx, $ay + $t * $dy);
            $segLen = self::haversine($route[$i][0], $route[$i][1], $route[$i + 1][0], $route[$i + 1][1]);
            if ($off < $bestOff - 0.5) { // the first pass wins on an out-and-back course
                $bestOff = $off;
                $bestAlong = $walked + $t * $segLen;
            }
            $walked += $segLen;
        }

        return [$bestAlong, $bestOff];
    }

    /** The position $metres along the route. */
    public static function pointAlong(array $route, float $metres): array
    {
        $walked = 0.0;
        for ($i = 0, $n = count($route); $i < $n - 1; $i++) {
            $seg = self::haversine($route[$i][0], $route[$i][1], $route[$i + 1][0], $route[$i + 1][1]);
            if ($walked + $seg >= $metres && $seg > 0) {
                $t = ($metres - $walked) / $seg;
                return [round($route[$i][0] + ($route[$i + 1][0] - $route[$i][0]) * $t, 6), round($route[$i][1] + ($route[$i + 1][1] - $route[$i][1]) * $t, 6)];
            }
            $walked += $seg;
        }

        return [round($route[count($route) - 1][0], 6), round($route[count($route) - 1][1], 6)];
    }

    /** Every checkpoint of an event for the map editor. */
    public static function checkpointsForMap(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare('SELECT id, sequence_no, label, checkpoint_type, distance_km, lat, lng, radius_m, status FROM event_checkpoints WHERE event_id = :e ORDER BY sequence_no, id');
        $stmt->execute(['e' => $eventId]);

        return array_map(fn ($c) => ['id' => (int) $c['id'], 'seq' => (int) $c['sequence_no'], 'label' => $c['label'], 'type' => $c['checkpoint_type'],
            'km' => $c['distance_km'] !== null ? (float) $c['distance_km'] : null, 'lat' => $c['lat'] !== null ? (float) $c['lat'] : null,
            'lng' => $c['lng'] !== null ? (float) $c['lng'] : null, 'radius' => (int) $c['radius_m'], 'active' => $c['status'] === 'active'], $stmt->fetchAll());
    }

    /**
     * Puts the checkpoints in running order: Start first, Finish last, the rest by their km. A mapped
     * checkpoint without a km gets it from its place on the route; a km already set is kept (on an
     * out-and-back course the same spot is passed twice, so only the km says which pass is meant).
     */
    public static function resequenceCheckpoints(PDO $db, int $eventId): void
    {
        $route = self::route($db, $eventId);
        $rows = self::checkpointsForMap($db, $eventId);
        foreach ($rows as &$c) {
            if ($c['km'] === null && $route && $c['lat'] !== null) {
                $c['km'] = round(($c['type'] === 'finish' ? (float) $route['distance_m'] : ($c['type'] === 'start' ? 0.0 : self::routeProgress($c['lat'], $c['lng'], $route['points'])[0])) / 1000, 2);
            }
        }
        unset($c);
        usort($rows, function ($a, $b) {
            $rank = fn ($c) => $c['type'] === 'start' ? 0 : ($c['type'] === 'finish' ? 3 : ($c['km'] === null ? 2 : 1));
            return [$rank($a), $a['km'] ?? 0, $a['seq'], $a['id']] <=> [$rank($b), $b['km'] ?? 0, $b['seq'], $b['id']];
        });
        $upd = $db->prepare('UPDATE event_checkpoints SET sequence_no = :s, distance_km = :km WHERE id = :id AND event_id = :e');
        foreach ($rows as $i => $c) {
            $upd->execute(['s' => $i + 1, 'km' => $c['km'], 'id' => $c['id'], 'e' => $eventId]);
        }
    }

    /** km of a spot on the route (Start 0, Finish the full length, else the first pass by that spot). */
    private static function kmAt(PDO $db, int $eventId, string $type, float $lat, float $lng): ?float
    {
        $route = self::route($db, $eventId);
        if (!$route) {
            return null;
        }
        if ($type === 'start') {
            return 0.0;
        }
        if ($type === 'finish') {
            return round($route['distance_m'] / 1000, 2);
        }

        return round(self::routeProgress($lat, $lng, $route['points'])[0] / 1000, 2);
    }
    /** "Water Station 3": the type name, numbered when there can be several. */
    private static function nextCheckpointLabel(PDO $db, int $eventId, string $type): string
    {
        $name = self::CHECKPOINT_TYPES[$type];
        if (in_array($type, ['start', 'finish'], true)) {
            return $name;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM event_checkpoints WHERE event_id = :e AND checkpoint_type = :t');
        $stmt->execute(['e' => $eventId, 't' => $type]);

        return $name . ' ' . ((int) $stmt->fetchColumn() + 1);
    }

    /** Adds a checkpoint where the organizer clicked on the map. Returns its id. */
    public static function addCheckpointAt(PDO $db, int $eventId, string $type, float $lat, float $lng, int $userId): int
    {
        if (!isset(self::CHECKPOINT_TYPES[$type])) {
            throw new DomainException('Unknown checkpoint type.');
        }
        if (!self::validLatLng($lat, $lng)) {
            throw new DomainException('That place is outside the map.');
        }
        if (in_array($type, ['start', 'finish'], true)) {
            $has = $db->prepare('SELECT id FROM event_checkpoints WHERE event_id = :e AND checkpoint_type = :t AND status = "active" LIMIT 1');
            $has->execute(['e' => $eventId, 't' => $type]);
            if ($id = $has->fetchColumn()) {
                self::moveCheckpoint($db, $eventId, (int) $id, $lat, $lng); // one start / one finish: move it instead
                return (int) $id;
            }
        }
        $db->prepare('INSERT INTO event_checkpoints (event_id, sequence_no, label, checkpoint_type, distance_km, lat, lng, radius_m) VALUES (:e, 999, :l, :t, :km, :lat, :lng, 60)')
            ->execute(['e' => $eventId, 'l' => self::nextCheckpointLabel($db, $eventId, $type), 't' => $type, 'km' => self::kmAt($db, $eventId, $type, $lat, $lng), 'lat' => round($lat, 6), 'lng' => round($lng, 6)]);
        $id = (int) $db->lastInsertId();
        self::resequenceCheckpoints($db, $eventId);
        Logger::log($userId, 'checkpoint_added', 'Added a ' . self::CHECKPOINT_TYPES[$type] . ' on the map for event #' . $eventId);

        return $id;
    }

    public static function moveCheckpoint(PDO $db, int $eventId, int $id, float $lat, float $lng): void
    {
        if (!self::validLatLng($lat, $lng)) {
            throw new DomainException('That place is outside the map.');
        }
        $type = $db->prepare('SELECT checkpoint_type FROM event_checkpoints WHERE id = :id AND event_id = :e');
        $type->execute(['id' => $id, 'e' => $eventId]);
        $db->prepare('UPDATE event_checkpoints SET lat = :lat, lng = :lng, distance_km = :km WHERE id = :id AND event_id = :e')
            ->execute(['lat' => round($lat, 6), 'lng' => round($lng, 6), 'km' => self::kmAt($db, $eventId, (string) $type->fetchColumn(), $lat, $lng), 'id' => $id, 'e' => $eventId]);
        self::resequenceCheckpoints($db, $eventId);
    }

    /** Start at the beginning of the drawn route, Finish at its end (existing ones are moved). */
    public static function autoStartFinish(PDO $db, int $eventId, int $userId): void
    {
        $route = self::route($db, $eventId);
        if (!$route) {
            throw new DomainException('Draw and save the route first.');
        }
        $first = $route['points'][0];
        $last = $route['points'][count($route['points']) - 1];
        self::addCheckpointAt($db, $eventId, 'start', (float) $first[0], (float) $first[1], $userId);
        self::addCheckpointAt($db, $eventId, 'finish', (float) $last[0], (float) $last[1], $userId);
    }

    /** Water stations every $km along the route, skipping places that already have one nearby. Returns how many were added. */
    public static function waterEvery(PDO $db, int $eventId, float $km, int $userId): int
    {
        $route = self::route($db, $eventId);
        if (!$route) {
            throw new DomainException('Draw and save the route first.');
        }
        if ($km < 0.5 || $km > 20) {
            throw new DomainException('Choose a spacing between 0.5 and 20 km.');
        }
        $existing = array_filter(self::checkpointsForMap($db, $eventId), fn ($c) => $c['type'] === 'water_station' && $c['lat'] !== null && $c['active']);
        $added = 0;
        for ($m = $km * 1000; $m < $route['distance_m'] - 200; $m += $km * 1000) {
            [$lat, $lng] = self::pointAlong($route['points'], $m);
            foreach ($existing as $w) {
                if (self::haversine($lat, $lng, $w['lat'], $w['lng']) < 150) {
                    continue 2;
                }
            }
            $db->prepare('INSERT INTO event_checkpoints (event_id, sequence_no, label, checkpoint_type, distance_km, lat, lng, radius_m) VALUES (:e, 999, :l, "water_station", :km, :lat, :lng, 60)')
                ->execute(['e' => $eventId, 'l' => self::nextCheckpointLabel($db, $eventId, 'water_station'), 'km' => round($m / 1000, 2), 'lat' => $lat, 'lng' => $lng]);
            $existing[] = ['lat' => $lat, 'lng' => $lng];
            $added++;
        }
        self::resequenceCheckpoints($db, $eventId);
        if ($added) {
            Logger::log($userId, 'checkpoint_added', 'Added ' . $added . ' water stations every ' . $km . ' km for event #' . $eventId);
        }

        return $added;
    }
    // ------------------------------------------------------------------ tracking (runner)

    /**
     * The runner's registration for a fun run that can be tracked now (event day, or the day after for a
     * late finish), or a reason why not: ['reg' => row|null, 'why' => string|null].
     */
    public static function trackable(PDO $db, int $registrationId, int $userId): array
    {
        $stmt = $db->prepare('SELECT r.id, r.status, r.bib_number, r.distance_category, e.id AS event_id, e.name AS event_name, e.event_date, e.event_time, e.status AS event_status, e.gps_tracking, c.slug
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
        if (empty($reg['gps_tracking'])) {
            return ['reg' => $reg, 'why' => 'This race does not use GPS live tracking.'];
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

        // v68: how far from the route (latest and worst), and which mapped checkpoints were passed.
        $eventStmt = $db->prepare('SELECT event_id FROM race_tracks WHERE id = :id');
        $eventStmt->execute(['id' => $trackId]);
        $eventId = (int) $eventStmt->fetchColumn();
        $route = self::route($db, $eventId);
        $off = null;
        $maxOff = null;
        if ($route && $clean) {
            $maxOff = 0.0;
            foreach ($clean as $p) {
                $maxOff = max($maxOff, self::distanceToRoute($p[0], $p[1], $route['points']));
            }
            $lastClean = end($clean);
            $off = self::distanceToRoute($lastClean[0], $lastClean[1], $route['points']);
        }
        $passed = 0;
        $lastLabel = null;
        // A finish line usually sits at the start: it only counts once most of the course is behind the runner.
        $cum = [0.0];
        for ($i = 1, $n = count($clean); $i < $n; $i++) {
            $cum[$i] = $cum[$i - 1] + self::haversine($clean[$i - 1][0], $clean[$i - 1][1], $clean[$i][0], $clean[$i][1]);
        }
        $finishFrom = $route ? 0.7 * (int) $route['distance_m'] : 0.0;
        foreach (self::mappedCheckpoints($db, $eventId) as $c) {
            foreach ($clean as $i => $p) {
                if ($c['checkpoint_type'] === 'finish' && $cum[$i] < $finishFrom) {
                    continue;
                }
                if (self::haversine($c['lat_f'], $c['lng_f'], $p[0], $p[1]) <= (int) $c['radius_m'] + 30) {
                    $passed++;
                    $lastLabel = $c['label'];
                    break;
                }
            }
        }
        $db->prepare('UPDATE race_tracks SET off_route_m = :o, max_off_route_m = :m, checkpoints_passed = :p, last_checkpoint = :l WHERE id = :id')
            ->execute(['o' => $off === null ? null : min(65535, (int) round($off)), 'm' => $maxOff === null ? null : min(65535, (int) round($maxOff)),
                'p' => min(255, $passed), 'l' => $lastLabel === null ? null : mb_substr($lastLabel, 0, 100), 'id' => $trackId]);
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
                if ((int) ($track['max_off_route_m'] ?? 0) > self::OFF_ROUTE_FLAG_M) {
                    $reasons[] = 'Went up to ' . (int) $track['max_off_route_m'] . ' m off the route.';
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
        $stmt = $db->prepare('SELECT t.id, t.status, t.source, t.started_at, t.finished_at, t.last_lat, t.last_lng, t.last_accuracy_m, t.last_point_at, t.point_count, t.distance_m,
                   t.off_route_m, t.max_off_route_m, t.checkpoints_passed, t.last_checkpoint,
                   TIMESTAMPDIFF(SECOND, t.started_at, COALESCE(t.finished_at, NOW())) AS elapsed_s,
                   t.verdict, t.verdict_detail, r.bib_number, r.distance_category, u.full_name, TIMESTAMPDIFF(SECOND, t.last_point_at, NOW()) AS seconds_ago
            FROM race_tracks t JOIN registrations r ON r.id = t.registration_id JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
            WHERE t.event_id = :e ORDER BY t.status, u.full_name');
        $stmt->execute(['e' => $eventId]);

        return $stmt->fetchAll();
    }

    /**
     * Trails of every runner of an event (v68) for the live map: only the points added after $cursor
     * (a race_track_points id), so each refresh stays small. The first load ($cursor = 0) is thinned to
     * at most 300 points per runner. Returns ['trails' => [trackId => [[lat,lng],...]], 'cursor' => int].
     */
    public static function trails(PDO $db, int $eventId, int $cursor): array
    {
        $stmt = $db->prepare('SELECT p.id, p.track_id, p.lat, p.lng FROM race_track_points p JOIN race_tracks t ON t.id = p.track_id
                              WHERE t.event_id = :e AND p.id > :c ORDER BY p.track_id, p.recorded_at');
        $stmt->execute(['e' => $eventId, 'c' => $cursor]);
        $byTrack = [];
        $max = $cursor;
        foreach ($stmt->fetchAll() as $r) {
            $byTrack[(int) $r['track_id']][] = [(float) $r['lat'], (float) $r['lng']];
            $max = max($max, (int) $r['id']);
        }
        if ($cursor === 0) {
            foreach ($byTrack as $id => $pts) {
                $n = count($pts);
                if ($n > 300) {
                    $step = $n / 300;
                    $thin = [];
                    for ($i = 0; $i < 299; $i++) {
                        $thin[] = $pts[(int) floor($i * $step)];
                    }
                    $thin[] = $pts[$n - 1];
                    $byTrack[$id] = $thin;
                }
            }
        }

        return ['trails' => (object) $byTrack, 'cursor' => $max];
    }

    // ------------------------------------------------------------------ GPX upload (Strava, sports watches)

    /** Why a GPX file cannot be uploaded for this registration now, or null when it can. */
    public static function gpxBlockedReason(array $reg): ?string
    {
        if ($reg['slug'] !== 'fun-run') {
            return 'GPX upload is only for fun runs.';
        }
        if (empty($reg['gps_tracking'])) {
            return 'This race does not use GPS live tracking.';
        }
        if ($reg['status'] !== 'approved') {
            return 'Your registration must be approved first.';
        }
        if (in_array($reg['event_status'], ['cancelled', 'removed', 'archived', 'draft', 'pending_approval'], true)) {
            return 'This event is not running.';
        }
        $today = date('Y-m-d');
        if ($today < $reg['event_date']) {
            return 'You can upload your run from race day (' . date('M d, Y', strtotime($reg['event_date'])) . ').';
        }
        if ($today > date('Y-m-d', strtotime($reg['event_date'] . ' +' . self::GPX_UPLOAD_DAYS . ' days'))) {
            return 'The upload period for this race has ended.';
        }

        return null;
    }

    /**
     * Reads a GPX file (Strava export, Garmin, Coros, ...) into [[lat, lng, unixTime], ...].
     * Refuses DTDs/entities (XXE) and files without times. Throws DomainException with a readable message.
     */
    public static function parseGpx(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new DomainException('This GPX file cannot be read.');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new DomainException('This is not a valid GPX file.');
        }
        $nodes = $doc->xpath('//*[local-name()="trkpt"]') ?: [];
        if (!$nodes) {
            throw new DomainException('The file has no recorded track. Export the activity itself (not a planned route) as GPX.');
        }
        $tz = new DateTimeZone(date_default_timezone_get());
        $points = [];
        foreach ($nodes as $n) {
            $lat = (string) ($n['lat'] ?? '');
            $lng = (string) ($n['lon'] ?? '');
            $time = $n->xpath('./*[local-name()="time"]');
            if (!$time || !self::validLatLng($lat, $lng)) {
                continue;
            }
            try {
                $ts = (new DateTimeImmutable((string) $time[0]))->setTimezone($tz)->getTimestamp();
            } catch (Exception $e) {
                continue;
            }
            $points[] = [round((float) $lat, 6), round((float) $lng, 6), $ts];
            if (count($points) > self::GPX_MAX_POINTS) {
                throw new DomainException('The track is too long (more than ' . self::GPX_MAX_POINTS . ' points).');
            }
        }
        if (count($points) < 10) {
            throw new DomainException('The file has too few timed GPS points. Export the activity with its time data.');
        }
        usort($points, fn ($a, $b) => $a[2] <=> $b[2]);

        return $points;
    }

    /**
     * Stores an uploaded GPX run as the runner's finished track and checks it like a live track.
     * $file is one $_FILES entry. Returns the track row.
     */
    public static function importGpx(PDO $db, array $reg, array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('Please choose your GPX file.');
        }
        if ((int) $file['size'] > self::GPX_MAX_BYTES) {
            throw new DomainException('The file is too large (15 MB at most).');
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'gpx') {
            throw new DomainException('Please upload a .gpx file.');
        }
        $points = self::parseGpx((string) file_get_contents($file['tmp_name']));
        $firstDay = date('Y-m-d', $points[0][2]);
        if ($firstDay < $reg['event_date'] || $firstDay > date('Y-m-d', strtotime($reg['event_date'] . ' +1 day'))) {
            throw new DomainException('This run was recorded on ' . date('M d, Y', $points[0][2]) . ', not on race day (' . date('M d, Y', strtotime($reg['event_date'])) . ').');
        }
        $existing = self::trackFor($db, (int) $reg['id']);
        if ($existing && (int) $existing['point_count'] >= 10) {
            throw new DomainException('You already have a recorded run for this race.');
        }

        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'gpx';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = 'gpx_' . (int) $reg['id'] . '_' . bin2hex(random_bytes(6)) . '.gpx';
        if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
            throw new DomainException('The file could not be saved. Please try again.');
        }

        $db->beginTransaction();
        try {
            if ($existing) {
                $db->prepare('DELETE FROM race_tracks WHERE id = :id')->execute(['id' => $existing['id']]); // an empty start, nothing recorded
            }
            $db->prepare('INSERT INTO race_tracks (registration_id, event_id, status, source, gpx_path, started_at, finished_at)
                          VALUES (:r, :e, "finished", "gpx", :g, :s, :f)')
                ->execute(['r' => $reg['id'], 'e' => $reg['event_id'], 'g' => 'gpx/' . $name,
                    's' => date('Y-m-d H:i:s', $points[0][2]), 'f' => date('Y-m-d H:i:s', $points[count($points) - 1][2])]);
            $trackId = (int) $db->lastInsertId();
            $ins = $db->prepare('INSERT IGNORE INTO race_track_points (track_id, lat, lng, accuracy_m, recorded_at) VALUES (:t, :lat, :lng, NULL, :at)');
            foreach ($points as [$lat, $lng, $ts]) {
                $ins->execute(['t' => $trackId, 'lat' => $lat, 'lng' => $lng, 'at' => date('Y-m-d H:i:s', $ts)]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($dir . DIRECTORY_SEPARATOR . $name);
            throw $e;
        }
        self::refresh($db, $trackId);
        self::check($db, $trackId);
        Logger::log($userId, 'race_gpx_uploaded', 'Uploaded a GPX run (' . count($points) . ' points) for registration #' . $reg['id']);

        return self::trackFor($db, (int) $reg['id']);
    }

    /** One runner's full track as [[lat,lng],...] (only for a track of this event). */
    public static function trackPath(PDO $db, int $eventId, int $trackId): array
    {
        $stmt = $db->prepare('SELECT p.lat, p.lng FROM race_track_points p JOIN race_tracks t ON t.id = p.track_id WHERE t.id = :t AND t.event_id = :e ORDER BY p.recorded_at');
        $stmt->execute(['t' => $trackId, 'e' => $eventId]);

        return array_map(fn ($r) => [(float) $r['lat'], (float) $r['lng']], $stmt->fetchAll());
    }
}
