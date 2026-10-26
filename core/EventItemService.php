<?php
/**
 * Event items helpers (v73).
 *
 * Sizes: an item added with sizes is stored as one row per size with the same item_name and the
 * size in variant_label (e.g. "Event Shirt" / "M"). Rows of one event with the same item_name form
 * a group, and a participant can claim only ONE item of a group (one shirt, in their size).
 * v74: items can say what is inside (event_items.contents), e.g. "T-shirt and race bib".
 */
class EventItemService
{
    /**
     * Quick-add buttons: label => [item name, type, uses sizes, what's inside].
     * v74: a fun run hands out ONE race kit (T-shirt in the runner's size + race bib) before the
     * race; finisher medals are handed out at the finish line and are not tracked in EVORA.
     */
    public const PRESETS_FUN_RUN = [
        'Race Kit' => ['Race Kit', 'kit', true, 'T-shirt and race bib'],
    ];
    public const PRESETS_OTHER = [
        'Event Shirt' => ['Event Shirt', 'shirt', true, ''],
        'Event Kit' => ['Event Kit', 'kit', false, ''],
        'Certificate' => ['Certificate', 'other', false, ''],
    ];

    public static function presetsFor(?string $categorySlug): array
    {
        return $categorySlug === 'fun-run' ? self::PRESETS_FUN_RUN : self::PRESETS_OTHER;
    }

    /** v75: the item type follows its name, since the form no longer asks for it. */
    public static function typeFromName(string $name): string
    {
        $n = strtolower($name);

        return match (true) {
            str_contains($n, 'kit') => 'kit',
            str_contains($n, 'shirt') || str_contains($n, 'jersey') || str_contains($n, 'singlet') => 'shirt',
            str_contains($n, 'bib') => 'bib',
            str_contains($n, 'medal') => 'medal',
            default => 'other',
        };
    }

    /** "Race Kit (T-shirt and race bib)" for messages; the plain name when nothing is listed inside. */
    public static function nameWithContents(string $name, ?string $contents): string
    {
        $contents = trim((string) $contents);

        return $name . ($contents !== '' ? ' (' . $contents . ')' : '');
    }

    public const SIZES = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'];

    /** Low stock alert used when the organizer leaves it blank: 10% of the quantity (off below 10 pieces). */
    public static function autoThreshold(int $quantity): int
    {
        return max(0, (int) floor($quantity * 0.1));
    }

    /** "Event Shirt (M)" */
    public static function label(array $item): string
    {
        $variant = trim((string) ($item['variant_label'] ?? ''));

        return $item['item_name'] . ($variant !== '' ? ' (' . $variant . ')' : '');
    }

    /**
     * The item of the same group (same event, same item name) this registration already claimed,
     * or null. Used to stop a participant from claiming a second size.
     */
    public static function claimedInGroup(PDO $db, int $registrationId, array $item): ?array
    {
        $stmt = $db->prepare('SELECT i.* FROM item_claims c JOIN event_items i ON i.id = c.event_item_id
            WHERE c.registration_id = :rid AND i.event_id = :eid AND i.item_name = :name LIMIT 1');
        $stmt->execute(['rid' => $registrationId, 'eid' => $item['event_id'], 'name' => $item['item_name']]);

        return $stmt->fetch() ?: null;
    }

    /** Suggested quantity: approved participants, or the event's slot limit when no one is approved yet. */
    public static function suggestedQuantity(PDO $db, array $event): array
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = :e AND status = "approved"');
        $stmt->execute(['e' => $event['id']]);
        $approved = (int) $stmt->fetchColumn();
        $slots = (int) ($event['max_participants'] ?? 0);

        return ['approved' => $approved, 'slots' => $slots, 'quantity' => $approved > 0 ? $approved : ($slots > 0 ? $slots : 0)];
    }
}
