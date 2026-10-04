<?php

/**
 * Code-defined registry of the organizer "Tools" tiles shown on event_manage.php,
 * per event category. Replaces the inline $toolGroups arrays that used to live
 * directly in event_manage.php -- same tools, same order, same output, just
 * centralized in one place.
 *
 * Each tool entry:
 *   key         string   stable identifier, used by event_tool_overrides.tool_key
 *   route       string   router route name
 *   icon        string   Bootstrap Icons class
 *   label       string   tile label
 *   description string   short explanation (not rendered yet, used as a title/tooltip)
 *   optional    bool     whether an organizer may toggle this tool off for one event
 *   default_enabled bool default state when optional and no override exists
 *   condition   ?string  key into $context that must be truthy for this tool to show
 *
 * Visibility only: EventTools never bypasses a tool file's own Auth/ownership checks.
 */
class EventTools
{
    private const DEFAULT_CATEGORY = 'default';

    public static function registry(): array
    {
        $staff = ['key' => 'staff', 'route' => 'organizer-staff', 'icon' => 'bi-person-badge', 'label' => 'Staff', 'description' => 'Assign staff and their permissions for this event.'];
        $registrations = ['key' => 'registrations', 'route' => 'organizer-registrations', 'icon' => 'bi-clipboard-check', 'label' => 'Registrations', 'description' => 'Review and manage participant registrations.'];
        $certificates = ['key' => 'certificates', 'route' => 'organizer-certificates', 'icon' => 'bi-award', 'label' => 'Certificate Management', 'description' => 'Customize, preview, and generate certificates for attendees.'];
        $analytics = ['key' => 'analytics', 'route' => 'organizer-event-analytics', 'icon' => 'bi-graph-up-arrow', 'label' => 'Analytics', 'description' => 'Registration, category, and attendance analytics for this event.'];
        $reports = ['key' => 'reports', 'route' => 'organizer-reports', 'icon' => 'bi-bar-chart', 'label' => 'Reports', 'description' => 'Generate and export event reports.'];
        $communicationCenter = ['key' => 'communication_center', 'route' => 'communication-center', 'icon' => 'bi-megaphone', 'label' => 'Communication Center', 'description' => 'Send announcements and updates to participants and staff.'];

        $commonAfter = [$certificates, $analytics, $reports];

        return [
            'sports-tournament' => [
                'before' => [
                    ['key' => 'teams', 'route' => 'organizer-teams', 'icon' => 'bi-people-fill', 'label' => 'Teams', 'description' => 'Manage teams or pairs for this tournament.', 'condition' => 'uses_teams'],
                    ['key' => 'venues', 'route' => 'organizer-venues', 'icon' => 'bi-geo-alt', 'label' => 'Venues', 'description' => 'Manage courts, fields, and playing areas for this event.'],
                    ['key' => 'match_schedule', 'route' => 'organizer-match-schedule', 'icon' => 'bi-calendar-week', 'label' => 'Schedule Generator', 'description' => 'Auto-generate the match schedule with venues and time slots.'],
                    $communicationCenter,
                    $staff,
                    $registrations,
                ],
                'during' => [
                    ['key' => 'matches', 'route' => 'organizer-matches', 'icon' => 'bi-trophy', 'label' => 'Matches & Scores', 'description' => 'Record match results and scores.'],
                    ['key' => 'bracket', 'route' => 'organizer-bracket', 'icon' => 'bi-diagram-3', 'label' => 'Bracket & Standings', 'description' => 'View the tournament bracket and standings.'],
                    ['key' => 'match_center', 'route' => 'match-center', 'icon' => 'bi-broadcast', 'label' => 'Match Center', 'description' => 'Control live matches: start, pause, update scores, and finish.'],
                    ['key' => 'incidents', 'route' => 'incidents', 'icon' => 'bi-exclamation-octagon', 'label' => 'Incident Reports', 'description' => 'Report and resolve tournament incidents and disputes.'],
                ],
                'after' => $commonAfter,
            ],
            'seminar-workshop' => [
                'before' => [
                    ['key' => 'speakers', 'route' => 'organizer-speakers', 'icon' => 'bi-mic', 'label' => 'Speakers', 'description' => 'Manage speakers for this event.'],
                    ['key' => 'schedule', 'route' => 'organizer-schedule', 'icon' => 'bi-clock-history', 'label' => 'Schedule', 'description' => 'Build the session schedule.'],
                    $communicationCenter,
                    $staff,
                    $registrations,
                ],
                'during' => [
                    ['key' => 'session_attendance_scan', 'route' => 'session-attendance-scan', 'icon' => 'bi-qr-code-scan', 'label' => 'Session Attendance', 'description' => 'Scan participant QR codes per session.'],
                    ['key' => 'seminar_dashboard', 'route' => 'seminar-dashboard', 'icon' => 'bi-easel', 'label' => 'Operations Dashboard', 'description' => 'Live seminar operations dashboard.'],
                ],
                'after' => array_merge([
                    ['key' => 'feedback_summary', 'route' => 'feedback-summary', 'icon' => 'bi-star', 'label' => 'Feedback Summary', 'description' => 'View participant feedback and ratings.'],
                ], $commonAfter),
            ],
            'festival' => [
                'before' => [
                    ['key' => 'schedule', 'route' => 'organizer-schedule', 'icon' => 'bi-clock-history', 'label' => 'Schedule', 'description' => 'Build the activity schedule.'],
                    ['key' => 'items', 'route' => 'organizer-items', 'icon' => 'bi-box-seam', 'label' => 'Event Items', 'description' => 'Manage giveaways, kits, or tickets.'],
                    $staff,
                    $registrations,
                ],
                'during' => [],
                'after' => $commonAfter,
            ],
            'fun-run' => [
                'before' => [
                    ['key' => 'checkpoints', 'route' => 'organizer-checkpoints', 'icon' => 'bi-signpost-split', 'label' => 'Route', 'description' => 'Configure the race route and checkpoints.'],
                    ['key' => 'items', 'route' => 'organizer-items', 'icon' => 'bi-box-seam', 'label' => 'Event Items', 'description' => 'Manage bibs, race kits, or medals.'],
                    $communicationCenter,
                    $staff,
                    $registrations,
                ],
                'during' => [
                    ['key' => 'bib_assign', 'route' => 'organizer-bib-assign', 'icon' => 'bi-upc-scan', 'label' => 'Bib Numbers', 'description' => 'Assign bib numbers to approved runners.'],
                    ['key' => 'kit_claim_search', 'route' => 'kit-claim-search', 'icon' => 'bi-search', 'label' => 'Kit Claim Search', 'description' => "Search-based kit claim verification when QR scanning isn't possible."],
                    ['key' => 'checkpoint_scan', 'route' => 'checkpoint-scan', 'icon' => 'bi-qr-code-scan', 'label' => 'Checkpoint Scan', 'description' => 'Scan runner BIB/QR at route checkpoints.'],
                    ['key' => 'race_results', 'route' => 'organizer-race-results', 'icon' => 'bi-flag-fill', 'label' => 'Finish Line Results', 'description' => 'Record finish line times.'],
                    ['key' => 'medical_incidents', 'route' => 'medical-incidents', 'icon' => 'bi-heart-pulse', 'label' => 'Medical & Emergency Log', 'description' => 'Record and resolve medical/safety cases.'],
                    ['key' => 'race_operations_dashboard', 'route' => 'race-operations-dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Control Center', 'description' => 'Live race-day operations dashboard.'],
                ],
                'after' => array_merge([
                    ['key' => 'race_results_dashboard', 'route' => 'organizer-race-results-dashboard', 'icon' => 'bi-bar-chart-steps', 'label' => 'Race Results', 'description' => 'Finishers, DNF, no-show, and finish-time summary.'],
                ], $commonAfter),
            ],
            self::DEFAULT_CATEGORY => [
                'before' => [
                    ['key' => 'items', 'route' => 'organizer-items', 'icon' => 'bi-box-seam', 'label' => 'Event Items', 'description' => 'Manage kits, bibs, prizes, or other items.'],
                    $staff,
                    $registrations,
                ],
                'during' => [],
                'after' => $commonAfter,
            ],
        ];
    }

    private static function categoryTools(?string $categorySlug): array
    {
        $registry = self::registry();

        return $registry[$categorySlug] ?? $registry[self::DEFAULT_CATEGORY];
    }

    /**
     * Resolve the tool tiles to show for one event, applying condition checks
     * and any organizer overrides for optional tools.
     */
    public static function forEvent(PDO $db, array $event, array $context = []): array
    {
        $tools = self::categoryTools($event['category_slug'] ?? null);

        $overridesStmt = $db->prepare('SELECT tool_key, enabled FROM event_tool_overrides WHERE event_id = :id');
        $overridesStmt->execute(['id' => $event['id']]);
        $overrides = [];
        foreach ($overridesStmt->fetchAll() as $row) {
            $overrides[$row['tool_key']] = (bool) $row['enabled'];
        }

        $result = ['before' => [], 'during' => [], 'after' => []];
        foreach (['before', 'during', 'after'] as $section) {
            foreach ($tools[$section] ?? [] as $tool) {
                if (isset($tool['condition']) && empty($context[$tool['condition']])) {
                    continue;
                }

                $optional = !empty($tool['optional']);
                if ($optional) {
                    $defaultEnabled = $tool['default_enabled'] ?? true;
                    $enabled = $overrides[$tool['key']] ?? $defaultEnabled;
                    if (!$enabled) {
                        continue;
                    }
                }

                $result[$section][] = $tool;
            }
        }

        return $result;
    }

    /**
     * Find one tool's definition within a category's full list (all sections),
     * used by tool_toggle.php to validate a tool_key before writing an override.
     */
    public static function findTool(?string $categorySlug, string $toolKey): ?array
    {
        $tools = self::categoryTools($categorySlug);
        foreach (['before', 'during', 'after'] as $section) {
            foreach ($tools[$section] ?? [] as $tool) {
                if ($tool['key'] === $toolKey) {
                    return $tool;
                }
            }
        }

        return null;
    }
}
