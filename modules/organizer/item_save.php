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
$eventStmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$action = $_POST['item_action'] ?? 'create';

$itemName = trim($_POST['item_name'] ?? '');
$totalQuantity = (int) ($_POST['total_quantity'] ?? 0);
$itemType = $_POST['item_type'] ?? 'other';
$allowedTypes = ['kit', 'shirt', 'bib', 'medal', 'other'];
if (!in_array($itemType, $allowedTypes, true)) {
    $itemType = 'other';
}
$variantLabel = trim($_POST['variant_label'] ?? '');
$lowStockThreshold = max(0, (int) ($_POST['low_stock_threshold'] ?? 0));

if ($itemName === '' || $totalQuantity <= 0) {
    flash('error', 'Please provide a valid item name and quantity.');
    redirect('organizer-items', ['event_id' => $eventId]);
}

if ($action === 'update') {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $itemCheck = $db->prepare('SELECT id, claimed_quantity FROM event_items WHERE id = :id AND event_id = :eid');
    $itemCheck->execute(['id' => $itemId, 'eid' => $eventId]);
    $existingItem = $itemCheck->fetch();
    if (!$existingItem) {
        flash('error', 'Item not found.');
        redirect('organizer-items', ['event_id' => $eventId]);
    }
    if ($totalQuantity < (int) $existingItem['claimed_quantity']) {
        flash('error', 'Total quantity can\'t be set below the ' . (int) $existingItem['claimed_quantity'] . ' already claimed.');
        redirect('organizer-items', ['event_id' => $eventId, 'edit' => $itemId]);
    }

    $stmt = $db->prepare(
        'UPDATE event_items SET item_name = :name, item_type = :type, variant_label = :variant,
            total_quantity = :qty, low_stock_threshold = :threshold
         WHERE id = :id AND event_id = :eid'
    );
    $stmt->execute([
        'name' => $itemName, 'type' => $itemType, 'variant' => $variantLabel ?: null,
        'qty' => $totalQuantity, 'threshold' => $lowStockThreshold, 'id' => $itemId, 'eid' => $eventId,
    ]);
    Logger::log($userId, 'item_updated', 'Updated item "' . $itemName . '" for event #' . $eventId);
    flash('success', 'Item updated.');
    redirect('organizer-items', ['event_id' => $eventId]);
}

$stmt = $db->prepare('INSERT INTO event_items (event_id, item_name, item_type, variant_label, total_quantity, low_stock_threshold) VALUES (:eid, :name, :type, :variant, :qty, :threshold)');
$stmt->execute([
    'eid' => $eventId, 'name' => $itemName, 'type' => $itemType,
    'variant' => $variantLabel ?: null, 'qty' => $totalQuantity, 'threshold' => $lowStockThreshold,
]);

Logger::log($userId, 'item_added', 'Added item "' . $itemName . '" to event #' . $eventId);
flash('success', 'Item added.');
redirect('organizer-items', ['event_id' => $eventId]);
