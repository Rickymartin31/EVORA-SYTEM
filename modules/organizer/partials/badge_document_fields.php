<?php
/** One requirement's upload fields on the Verified Badge page. Expects $type (a key of OrganizerBadgeService::DOCS). */
[$docLabel, $docRequired, $docHelp, $docExt] = OrganizerBadgeService::DOCS[$type];
$docKinds = match ($type) {
    'government_id' => OrganizerBadgeService::ID_KINDS,
    'organization_proof' => OrganizerBadgeService::ORG_PROOF_KINDS,
    default => [],
};
$accept = implode(',', array_map(fn ($x) => '.' . $x, $docExt));
?>
<fieldset class="badge-doc-fields mb-3">
    <legend class="h6 fw-semibold mb-1"><?= e($docLabel) ?><?= $docRequired ? ' <span class="text-danger">*</span>' : ' <span class="text-muted small fw-normal">(optional)</span>' ?></legend>
    <p class="small text-muted mb-2"><?= e($docHelp) ?></p>
    <div class="row g-2">
        <?php if ($docKinds): ?>
        <div class="col-md-6">
            <label class="form-label small" for="<?= e($type) ?>Kind">Document type</label>
            <select class="form-select" id="<?= e($type) ?>Kind" name="<?= e($type) ?>_kind" <?= $docRequired ? 'required' : '' ?>>
                <option value="">Choose…</option>
                <?php foreach ($docKinds as $kind): ?>
                    <option <?= old($type . '_kind') === $kind ? 'selected' : '' ?>><?= e($kind) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label small" for="<?= e($type) ?>Ref"><?= $type === 'government_id' ? 'ID number (last 4 digits only)' : 'Registration / permit number' ?></label>
            <input type="text" class="form-control" id="<?= e($type) ?>Ref" name="<?= e($type) ?>_ref" maxlength="<?= $type === 'government_id' ? 4 : 60 ?>" value="<?= e(old($type . '_ref')) ?>">
        </div>
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label small" for="<?= e($type) ?>File">File (<?= e(strtoupper(implode(', ', array_unique(array_map(fn ($x) => $x === 'jpeg' ? 'jpg' : $x, $docExt))))) ?>)</label>
            <input type="file" class="form-control" id="<?= e($type) ?>File" name="<?= e($type) ?>" accept="<?= e($accept) ?>" <?= $docRequired ? 'required' : '' ?>>
        </div>
        <?php if (in_array($type, ['government_id', 'organization_proof'], true)): ?>
        <div class="col-md-6">
            <label class="form-label small" for="<?= e($type) ?>Expires">Valid until <span class="text-muted">(leave blank if no expiry)</span></label>
            <input type="date" class="form-control" id="<?= e($type) ?>Expires" name="<?= e($type) ?>_expires" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= e(old($type . '_expires')) ?>">
        </div>
        <?php endif; ?>
    </div>
</fieldset>
