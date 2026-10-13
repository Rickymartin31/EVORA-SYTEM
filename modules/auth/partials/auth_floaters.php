<?php
/** Floating EVORA icons behind the sign-in pages (v65). Decorative only: hidden from screen readers. */
$floaters = [
    ['bi-calendar-check', 8, 14, 0], ['bi-trophy', 86, 12, 2], ['bi-qr-code', 12, 72, 4], ['bi-ticket-perforated', 82, 66, 1],
    ['bi-geo-alt', 22, 40, 3], ['bi-award', 74, 36, 5], ['bi-people', 6, 92, 2], ['bi-mic', 92, 88, 4],
];
$chips = [['5K', 18, 24, 1], ['Checked in ✓', 78, 22, 3], ['Paid', 15, 58, 5], ['Bib 101', 88, 52, 0], ['Final 3–1', 70, 84, 2]];
?>
<div class="auth-floaters" aria-hidden="true">
    <?php foreach ($floaters as $i => [$icon, $x, $y, $d]): ?>
        <i class="bi <?= $icon ?> auth-floater" style="--x:<?= $x ?>%;--y:<?= $y ?>%;--d:<?= $d ?>s;--t:<?= 11 + ($i % 4) * 2 ?>s"></i>
    <?php endforeach; ?>
    <?php foreach ($chips as $i => [$text, $x, $y, $d]): ?>
        <span class="auth-floater auth-floater-chip" style="--x:<?= $x ?>%;--y:<?= $y ?>%;--d:<?= $d ?>s;--t:<?= 13 + ($i % 3) * 2 ?>s"><?= e($text) ?></span>
    <?php endforeach; ?>
</div>
