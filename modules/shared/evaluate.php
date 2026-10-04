<?php
Auth::requireLogin();
if (Auth::role() === 'admin') {
    redirect('admin-evaluations'); // admins view the analytics instead of rating
}

$categories = [
    'functionality' => 'Functionality — does the system provide the features you need?',
    'usability' => 'Usability — is the system easy to learn and use?',
    'reliability' => 'Reliability — does the system perform consistently without failure?',
    'performance_efficiency' => 'Performance Efficiency — is the system responsive and fast?',
    'security' => 'Security — do you trust the system with your data?',
    'compatibility' => 'Compatibility — does it work well across devices/browsers?',
    'maintainability' => 'Maintainability — is the system easy to update/extend (for admins/devs)?',
    'portability' => 'Portability — can the system be used in different environments?',
];

$pageTitle = 'Evaluate EVORA';
$activeRoute = 'evaluate';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4 mw-form-lg">
    <h6 class="fw-semibold mb-1">System Quality Evaluation</h6>
    <p class="text-muted small mb-4">Based on the ISO/IEC 25010 quality model. Rate each category from 1 (poor) to 5 (excellent).</p>
    <form method="post" action="<?= url('evaluate-save') ?>">
        <?= Csrf::field() ?>
        <?php foreach ($categories as $key => $label): ?>
        <div class="mb-3">
            <label class="form-label small"><?= e($label) ?></label>
            <div class="btn-group w-100" role="group">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <input type="radio" class="btn-check" name="<?= $key ?>" id="<?= $key ?>_<?= $i ?>" value="<?= $i ?>" <?= $i === 3 ? 'checked' : '' ?> required>
                    <label class="btn btn-outline-primary" for="<?= $key ?>_<?= $i ?>"><?= $i ?></label>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="mb-3">
            <label class="form-label small">Additional Comments (optional)</label>
            <textarea name="comment" class="form-control" rows="3" maxlength="1000"></textarea>
        </div>
        <button type="submit" class="btn btn-evora-primary">Submit Evaluation</button>
    </form>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
