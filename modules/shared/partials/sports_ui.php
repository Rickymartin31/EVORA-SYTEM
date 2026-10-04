<?php
// v34.2 presentation helpers for the specialized modules (sports, match center,
// race, seminar). Markup only: every value comes from data the page already
// loaded; nothing here queries the database or changes a record.

if (!function_exists('entrantName')) {
    function entrantName(array $m, string $side): string
    {
        return $m['team_' . $side . '_name'] ?? $m['participant_' . $side . '_name'] ?? 'TBD';
    }
}

if (!function_exists('matchWinnerSide')) {
    /** 'a' / 'b' from the recorded winner (winner_team_id / winner_participant_id), else null. */
    function matchWinnerSide(array $m): ?string
    {
        foreach (['team', 'participant'] as $kind) {
            $winner = $m['winner_' . $kind . '_id'] ?? null;
            if ($winner) {
                if ((string) $winner === (string) ($m[$kind . '_a_id'] ?? '')) {
                    return 'a';
                }
                if ((string) $winner === (string) ($m[$kind . '_b_id'] ?? '')) {
                    return 'b';
                }
            }
        }
        return null;
    }
}

if (!function_exists('matchStatusBadge')) {
    /** Lifecycle badge: Scheduled → Live (Paused) → Completed / Cancelled. Label is always shown. */
    function matchStatusBadge(string $status, array $labels = []): string
    {
        $map = [
            'scheduled' => ['scheduled', 'Scheduled'],
            'ongoing' => ['live', 'Live'],
            'paused' => ['paused', 'Paused'],
            'completed' => ['completed', 'Completed'],
            'cancelled' => ['cancelled', 'Cancelled'],
        ];
        [$class, $label] = $map[$status] ?? ['scheduled', ucfirst($status)];
        $label = $labels[$status] ?? $label;
        return '<span class="badge badge-status-' . $class . '">' . e($label) . '</span>';
    }
}

if (!function_exists('matchCard')) {
    /**
     * Match card: header (Match #id · round), both sides with score and a winner
     * mark, a VS divider, status badge and optional schedule/venue line.
     * $opts: label (round label), href (whole card links there), labels (status
     * labels), winner ('a'|'b' override), showMeta (bool), current (bool).
     */
    function matchCard(array $m, array $opts = []): string
    {
        $status = (string) ($m['status'] ?? 'scheduled');
        $winner = $opts['winner'] ?? matchWinnerSide($m);
        $hasScore = ($m['team_a_score'] ?? null) !== null;
        $nameA = entrantName($m, 'a');
        $nameB = entrantName($m, 'b');
        $classes = 'match-card is-' . ($status === 'ongoing' ? 'live' : $status) . (!empty($opts['current']) ? ' is-current' : '');

        $side = function (string $s, string $name) use ($m, $winner, $hasScore): string {
            $score = $hasScore ? (string) (int) $m['team_' . $s . '_score'] : '–';
            $isWinner = $winner === $s;
            return '<div class="match-side' . ($isWinner ? ' is-winner' : '') . ($name === 'TBD' ? ' is-tbd' : '') . '">'
                . '<span class="match-team">' . e($name)
                . ($isWinner ? ' <i class="bi bi-trophy-fill match-winner-icon" aria-hidden="true"></i><span class="visually-hidden">(winner)</span>' : '')
                . '</span><span class="match-score">' . $score . '</span></div>';
        };

        $title = 'Match #' . (int) ($m['id'] ?? 0) . (isset($opts['label']) ? ' · ' . $opts['label'] : '');
        $html = '<div class="match-card-head"><span class="match-no">' . e($title) . '</span>'
            . matchStatusBadge($status, $opts['labels'] ?? []) . '</div>'
            . $side('a', $nameA)
            . '<div class="match-vs" aria-hidden="true">VS</div>'
            . $side('b', $nameB);

        if (($opts['showMeta'] ?? true) && (!empty($m['scheduled_at']) || !empty($m['venue']))) {
            $bits = [];
            if (!empty($m['scheduled_at'])) {
                $bits[] = '<i class="bi bi-clock" aria-hidden="true"></i> ' . date('M d, h:i A', strtotime($m['scheduled_at']));
            }
            if (!empty($m['venue'])) {
                $bits[] = '<i class="bi bi-geo-alt" aria-hidden="true"></i> ' . e($m['venue']);
            }
            $html .= '<div class="match-meta">' . implode('<span class="match-meta-sep" aria-hidden="true">·</span>', $bits) . '</div>';
        }

        $aria = $title . ': ' . $nameA . ' versus ' . $nameB;
        if (!empty($opts['href'])) {
            return '<a href="' . e($opts['href']) . '" class="' . $classes . ' match-card-link" aria-label="' . e($aria) . '"'
                . (!empty($opts['current']) ? ' aria-current="true"' : '') . '>' . $html . '</a>';
        }
        return '<article class="' . $classes . '" aria-label="' . e($aria) . '">' . $html . '</article>';
    }
}

if (!function_exists('bracketRoundLabels')) {
    /**
     * Display names for bracket rounds. Only a single-elimination bracket whose
     * rounds halve down to one final match gets Final / Semifinals / Quarterfinals;
     * anything else keeps "Round N".
     */
    function bracketRoundLabels(array $rounds, ?string $format): array
    {
        $labels = [];
        foreach (array_keys($rounds) as $no) {
            $labels[$no] = 'Round ' . $no;
        }
        if ($format !== 'single_elim' || !$rounds) {
            return $labels;
        }
        $nos = array_keys($rounds);
        sort($nos);
        $last = end($nos);
        foreach ($nos as $no) {
            if (count($rounds[$no]) !== 2 ** ($last - $no)) {
                return $labels;   // not a clean knockout tree — keep neutral names
            }
        }
        $names = [0 => 'Final', 1 => 'Semifinals', 2 => 'Quarterfinals'];
        foreach ($nos as $no) {
            if (isset($names[$last - $no])) {
                $labels[$no] = $names[$last - $no];
            }
        }
        return $labels;
    }
}

if (!function_exists('moduleOverview')) {
    /**
     * Summary card for a module page: icon chip, title, meta line and a row of
     * labelled figures. $stats: [['label' => …, 'value' => …, 'tone' => success|warning|danger|info|null, 'hint' => …]]
     * Values are escaped; $badgeHtml must be trusted markup (e.g. matchStatusBadge()).
     */
    function moduleOverview(string $icon, string $title, array $meta, array $stats, string $badgeHtml = ''): string
    {
        $html = '<section class="module-overview card-evora" aria-label="' . e($title) . ' summary">'
            . '<div class="module-overview-head"><span class="module-overview-icon" aria-hidden="true"><i class="bi ' . e($icon) . '"></i></span>'
            . '<div class="module-overview-title"><h2 class="h6 fw-semibold mb-0">' . e($title) . '</h2>';
        $meta = array_values(array_filter($meta, fn ($v) => $v !== null && $v !== ''));
        if ($meta) {
            $html .= '<div class="module-overview-meta">' . implode('<span aria-hidden="true">·</span>', array_map('e', $meta)) . '</div>';
        }
        $html .= '</div>' . ($badgeHtml !== '' ? '<div class="module-overview-badge">' . $badgeHtml . '</div>' : '') . '</div>';
        if ($stats) {
            $html .= '<dl class="module-overview-stats">';
            foreach ($stats as $s) {
                $tone = !empty($s['tone']) ? ' tone-' . $s['tone'] : '';
                $html .= '<div class="module-stat' . $tone . '"><dt>' . e($s['label']) . '</dt><dd>' . e((string) $s['value']) . '</dd>'
                    . (!empty($s['hint']) ? '<div class="module-stat-hint">' . e($s['hint']) . '</div>' : '') . '</div>';
            }
            $html .= '</dl>';
        }
        return $html . '</section>';
    }
}
