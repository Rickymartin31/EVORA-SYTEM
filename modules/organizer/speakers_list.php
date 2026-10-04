<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

// Staff-only: with no event chosen yet, show a picker instead of erroring.
// Organizer behavior is completely unchanged.
if ($eventId === 0 && $role === 'staff') {
    $eventsStmt = $db->prepare(
        "SELECT e.id, e.name, e.event_date FROM events e
         JOIN event_categories c ON c.id = e.category_id
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE es.user_id = :uid AND sp.permission_key = 'manage_speakers' AND c.slug = 'seminar-workshop'
         ORDER BY e.event_date DESC"
    );
    $eventsStmt->execute(['uid' => $userId]);
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a seminar to manage its speakers.';
    $pageTitle = 'Speakers';
    $activeRoute = 'organizer-speakers';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Speakers']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-mic', 'No seminar/workshop events available to you for speaker management.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('organizer-speakers', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
                    <div class="fw-semibold"><?= e($ev['name']) ?></div>
                    <div class="small text-muted"><?= date('M d, Y', strtotime($ev['event_date'])) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
    <?php
    exit;
}

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_speakers'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have speaker management access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$speakers = $db->prepare('SELECT * FROM event_speakers WHERE event_id = :id ORDER BY id DESC');
$speakers->execute(['id' => $eventId]);
$speakersList = $speakers->fetchAll();

// Assigned sessions per speaker, for the profile view.
$sessionsStmt = $db->prepare('SELECT id, session_title, start_time, end_time FROM event_schedule WHERE speaker_id = :id ORDER BY start_time');

$editSpeaker = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($speakersList as $s) {
        if ((int) $s['id'] === $editId) {
            $editSpeaker = $s;
            break;
        }
    }
}

$pageDescription = 'Speaker and trainer profiles shown with their sessions.';
$pageTitle = 'Speakers';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($role === 'organizer' ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Speakers'],
] : [
    ['label' => $event['name']],
    ['label' => 'Speakers'],
]); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="card-evora p-4" aria-label="Speakers and trainers">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-semibold mb-0">Speakers / Trainers</h2>
                <?php if ($speakersList): ?><span class="small text-muted"><?= count($speakersList) ?> speaker<?= count($speakersList) === 1 ? '' : 's' ?></span><?php endif; ?>
            </div>
            <?php if (empty($speakersList)): ?>
                <?= emptyState('bi-mic-mute', 'No speakers added yet. Add your first speaker with the form, then assign them to sessions.') ?>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($speakersList as $s): ?>
                    <?php $sessionsStmt->execute(['id' => $s['id']]); $assignedSessions = $sessionsStmt->fetchAll(); ?>
                    <?php $initials = strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($s['speaker_name'])), 0, 2)))); ?>
                    <div class="col-md-6">
                        <article class="speaker-card" aria-label="<?= e($s['speaker_name']) ?>">
                            <div class="speaker-head">
                                <?php if ($s['photo_path']): ?>
                                    <img src="<?= uploadUrl($s['photo_path']) ?>" class="avatar avatar-56" alt="Photo of <?= e($s['speaker_name']) ?>" data-fallback-initial="<?= e(mb_strtoupper(mb_substr($s['speaker_name'], 0, 1))) ?>">
                                <?php else: ?>
                                    <span class="speaker-initials" aria-hidden="true"><?= e($initials) ?></span>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <h3 class="speaker-name h6 mb-0"><?= e($s['speaker_name']) ?></h3>
                                    <?php if ($s['position'] || $s['organization']): ?>
                                        <div class="speaker-role"><?= e($s['position']) ?><?= $s['position'] && $s['organization'] ? ' · ' : '' ?><?= e($s['organization']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($s['topic']): ?><div><span class="visually-hidden">Topic: </span><span class="speaker-topic"><i class="bi bi-chat-quote" aria-hidden="true"></i> <?= e($s['topic']) ?></span></div><?php endif; ?>
                            <?php if ($s['speaker_bio']): ?>
                                <p class="speaker-bio"><?= e($s['speaker_bio']) ?></p>
                            <?php else: ?>
                                <p class="speaker-bio text-muted fst-italic">No biography yet.</p>
                            <?php endif; ?>
                            <?php if ($s['contact_email'] || $s['contact_phone'] || $s['social_links']): ?>
                                <ul class="session-facts small">
                                    <?php if ($s['contact_email']): ?><li><i class="bi bi-envelope" aria-hidden="true"></i> <?= e($s['contact_email']) ?></li><?php endif; ?>
                                    <?php if ($s['contact_phone']): ?><li><i class="bi bi-telephone" aria-hidden="true"></i> <?= e($s['contact_phone']) ?></li><?php endif; ?>
                                    <?php if ($s['social_links']): ?><li class="text-break"><i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($s['social_links']) ?></li><?php endif; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="small">
                                <span class="text-muted">Sessions:</span>
                                <?php if (empty($assignedSessions)): ?>
                                    <span class="text-muted">none assigned yet</span>
                                <?php else: foreach ($assignedSessions as $as): ?>
                                    <span class="badge bg-primary"><?= e($as['session_title']) ?></span>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="speaker-card-foot">
                                <a href="<?= url('organizer-speakers', ['event_id' => $eventId, 'edit' => $s['id']]) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Edit <?= e($s['speaker_name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                                <form method="post" action="<?= url('organizer-speaker-save') ?>" class="ms-auto" data-confirm="<?= e($s['speaker_name']) ?> will be removed from this seminar.<?= $assignedSessions ? ' Their sessions stay on the program without a speaker.' : '' ?>" data-confirm-title="Remove this speaker?" data-confirm-button="Remove Speaker" data-confirm-variant="danger">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="speaker_action" value="delete">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="speaker_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remove <?= e($s['speaker_name']) ?>">Remove</button>
                                </form>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3"><?= $editSpeaker ? 'Edit Speaker' : 'Add Speaker' ?></h6>
            <form method="post" action="<?= url('organizer-speaker-save') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="speaker_action" value="<?= $editSpeaker ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editSpeaker): ?><input type="hidden" name="speaker_id" value="<?= $editSpeaker['id'] ?>"><?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small" for="spName">Speaker Name *</label>
                    <input id="spName" type="text" name="speaker_name" class="form-control form-control-sm" value="<?= e($editSpeaker['speaker_name'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spPhoto">Profile Photo</label>
                    <input id="spPhoto" type="file" name="photo" class="form-control form-control-sm" accept=".jpg,.jpeg,.png">
                    <?php if ($editSpeaker && $editSpeaker['photo_path']): ?><div class="form-text">Leave blank to keep the current photo.</div><?php endif; ?>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spOrg">Organization</label>
                    <input id="spOrg" type="text" name="organization" class="form-control form-control-sm" value="<?= e($editSpeaker['organization'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spPos">Position</label>
                    <input id="spPos" type="text" name="position" class="form-control form-control-sm" value="<?= e($editSpeaker['position'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spTopic">Topic</label>
                    <input id="spTopic" type="text" name="topic" class="form-control form-control-sm" value="<?= e($editSpeaker['topic'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spBio">Bio</label>
                    <textarea id="spBio" name="speaker_bio" class="form-control form-control-sm" rows="3"><?= e($editSpeaker['speaker_bio'] ?? '') ?></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spEmail">Contact Email</label>
                    <input id="spEmail" type="email" name="contact_email" class="form-control form-control-sm" value="<?= e($editSpeaker['contact_email'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="spPhone">Contact Phone</label>
                    <input id="spPhone" type="text" name="contact_phone" class="form-control form-control-sm" value="<?= e($editSpeaker['contact_phone'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="spLinks">Social Links</label>
                    <input id="spLinks" type="text" name="social_links" class="form-control form-control-sm" placeholder="LinkedIn, website, etc." value="<?= e($editSpeaker['social_links'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editSpeaker ? 'Update Speaker' : 'Add Speaker' ?></button>
                <?php if ($editSpeaker): ?>
                    <a href="<?= url('organizer-speakers', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
