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
// v73: a blank low-stock alert means "automatic" (10% of the quantity)
$thresholdInput = trim((string) ($_POST['low_stock_threshold'] ?? ''));
$lowStockThreshold = $thresholdInput === '' ? EventItemService::autoThreshold(max(1, $totalQuantity)) : max(0, (int) $thresholdInput);

// v73: "Add with sizes" creates one item per size (same name, size in variant_label) in one go.
$sizes = [];
if ($action === 'create' && !empty($_POST['use_sizes'])) {
    foreach (EventItemService::SIZES as $size) {
        $qty = (int) ($_POST['size_qty'][$size] ?? 0);
        if ($qty > 0) {
            $sizes[$size] = $qty;
        }
    }
    $customSize = mb_substr(trim((string) ($_POST['custom_size'] ?? '')), 0, 40);
    $customQty = (int) ($_POST['custom_size_qty'] ?? 0);
    if ($customSize !== '' && $customQty > 0) {
        $sizes[$customSize] = $customQty;
    }
    if ($itemName === '' || !$sizes) {
        flash('error', 'Please enter the item name and a quantity for at least one size.');
        redirect('organizer-items', ['event_id' => $eventId]);
    }
    $existing = $db->prepare('SELECT variant_label FROM event_items WHERE event_id = :eid AND item_name = :name');
    $existing->execute(['eid' => $eventId, 'name' => $itemName]);
    $taken = array_map('strtolower', array_filter($existing->fetchAll(PDO::FETCH_COLUMN)));
    $insert = $db->prepare('INSERT INTO event_items (event_id, item_name, item_type, variant_label, total_quantity, low_stock_threshold) VALUES (:eid, :name, :type, :variant, :qty, :threshold)');
    $added = [];
    $skipped = [];
    foreach ($sizes as $size => $qty) {
        if (in_array(strtolower((string) $size), $taken, true)) {
            $skipped[] = $size;
            continue;
        }
        $insert->execute(['eid' => $eventId, 'name' => $itemName, 'type' => $itemType, 'variant' => $size, 'qty' => $qty,
            'threshold' => $thresholdInput === '' ? EventItemService::autoThreshold($qty) : max(0, (int) $thresholdInput)]);
        $added[] = $size . ' (' . $qty . ')';
    }
    if ($added) {
        Logger::log($userId, 'item_added', 'Added "' . $itemName . '" sizes ' . implode(', ', $added) . ' to event #' . $eventId);
    }
    flash($added ? 'success' : 'error', ($added ? 'Added ' . $itemName . ': ' . implode(', ', $added) . '.' : 'No sizes were added.')
        . ($skipped ? ' Already listed, so skipped: ' . implode(', ', $skipped) . ' (edit those instead).' : ''));
    redirect('organizer-items', ['event_id' => $eventId]);
}

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
