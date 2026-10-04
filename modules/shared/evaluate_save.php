<?php
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('evaluate');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

// Administrators view the analytics; they do not rate EVORA (the database refuses it too).
if (!in_array(Auth::role(), EvaluationService::PLATFORM_ROLES, true)) {
    flash('error', 'Only participants, organizers and staff rate EVORA. Administrators view the analytics.');
    redirect('admin-evaluations');
}

$fields = ['functionality', 'usability', 'reliability', 'performance_efficiency', 'security', 'compatibility', 'maintainability', 'portability'];
$values = [];

foreach ($fields as $field) {
    $val = (int) ($_POST[$field] ?? 0);
    if ($val < 1 || $val > 5) {
        flash('error', 'Please rate every category from 1 to 5.');
        redirect('evaluate');
    }
    $values[$field] = $val;
}

$comment = trim($_POST['comment'] ?? '');

// One evaluation per user — checked server-side against the authenticated
// session id, never trusting anything from the form itself.
$existing = $db->prepare('SELECT id, functionality, comment FROM system_evaluations WHERE user_id = :uid');
$existing->execute(['uid' => $userId]);
if ($existingRow = $existing->fetch()) {
    if ($existingRow['functionality'] !== null) {
        flash('error', 'You have already submitted an evaluation.');
        redirect('evaluate');
    }
    // The user gave a quick star rating earlier: complete that same evaluation
    // with the ISO/IEC 25010 scores (still one evaluation per user).
    $update = $db->prepare(
        'UPDATE system_evaluations SET functionality = :functionality, usability = :usability, reliability = :reliability,
            performance_efficiency = :performance_efficiency, security = :security, compatibility = :compatibility,
            maintainability = :maintainability, portability = :portability, comment = COALESCE(comment, :comment)
         WHERE id = :id AND user_id = :user_id AND functionality IS NULL'
    );
    $update->execute(array_merge(['id' => $existingRow['id'], 'user_id' => $userId, 'comment' => $comment ?: null], $values));
    Logger::log($userId, 'evaluation_submitted', 'Completed the system evaluation survey');
    flash('success', 'Thank you for your feedback!');
    redirect('evaluate');
}

$stmt = $db->prepare(
    'INSERT INTO system_evaluations (user_id, functionality, usability, reliability, performance_efficiency, security, compatibility, maintainability, portability, comment)
     VALUES (:user_id, :functionality, :usability, :reliability, :performance_efficiency, :security, :compatibility, :maintainability, :portability, :comment)'
);
$stmt->execute(array_merge(['user_id' => $userId, 'comment' => $comment ?: null], $values));
$evaluationId = (int) $db->lastInsertId();

Logger::log($userId, 'evaluation_submitted', 'Submitted a system evaluation');
PayoutRequestService::notifyAdmins($db, 'New System Feedback', 'New system feedback submitted (ISO/IEC 25010 survey).',
    ['category' => 'system', 'notice_type' => 'informational', 'reference_type' => 'system_evaluation', 'reference_id' => $evaluationId], 'platform_feedback');
flash('success', 'Thank you for your feedback!');
redirect('evaluate');
