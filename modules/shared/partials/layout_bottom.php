    </main>
</div>
<?php if (Auth::role() === 'staff' && in_array('scan_qr', $staffPerms ?? [], true) && ($activeRoute ?? '') !== 'staff-scan'): ?>
<a href="<?= url('staff-scan') ?>" class="scan-fab" aria-label="Scan participant QR code"><i class="bi bi-qr-code-scan" aria-hidden="true"></i>Scan</a>
<?php endif; ?>
<?php if (Auth::role() === 'participant'): ?>
<?php // Phones: an app-style bar for the participant's main pages; "Menu" opens the full sidebar (v48).
$bnActive = $activeRoute ?? '';
$bnUnread = (int) (($navCounts ?? [])['my-registrations'] ?? 0); ?>
<nav class="bottom-nav" aria-label="Main">
    <a href="<?= url('participant-dashboard') ?>" class="<?= $bnActive === 'participant-dashboard' ? 'active' : '' ?>"<?= $bnActive === 'participant-dashboard' ? ' aria-current="page"' : '' ?>><i class="bi bi-house-door" aria-hidden="true"></i>Home</a>
    <a href="<?= url('events') ?>" class="<?= $bnActive === 'events' ? 'active' : '' ?>"<?= $bnActive === 'events' ? ' aria-current="page"' : '' ?>><i class="bi bi-search" aria-hidden="true"></i>Browse</a>
    <a href="<?= url('my-registrations') ?>" class="<?= $bnActive === 'my-registrations' ? 'active' : '' ?>"<?= $bnActive === 'my-registrations' ? ' aria-current="page"' : '' ?>><i class="bi bi-clipboard-check" aria-hidden="true"></i>My Events<?php if ($bnUnread > 0): ?><span class="bn-badge" aria-label="<?= $bnUnread ?> unread"><?= $bnUnread > 9 ? '9+' : $bnUnread ?></span><?php endif; ?></a>
    <a href="<?= url('my-qr') ?>" class="<?= $bnActive === 'my-qr' ? 'active' : '' ?>"<?= $bnActive === 'my-qr' ? ' aria-current="page"' : '' ?>><i class="bi bi-qr-code" aria-hidden="true"></i>My QR</a>
    <button type="button" onclick="document.getElementById('sidebarMobileToggle').click()" aria-controls="evoraSidebar"><i class="bi bi-list" aria-hidden="true"></i>Menu</button>
</nav>
<?php endif; ?>
<?php include __DIR__ . '/evaluation_prompt.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="<?= assetUrl('js/app.js') ?>"></script>
</body>
</html>
