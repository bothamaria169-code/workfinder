<?php
declare(strict_types=1);

// Employers who don't want applications by email through WorkFinder.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$email = strtolower(trim((string) ($_REQUEST['e'] ?? '')));
$sig   = (string) ($_REQUEST['s'] ?? '');
$valid = filter_var($email, FILTER_VALIDATE_EMAIL) && hash_equals(substr(hash_hmac('sha256', $email, APP_SECRET), 0, 24), $sig);
$done  = false;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    getDB()->prepare('INSERT IGNORE INTO email_optouts (email) VALUES (?)')->execute([$email]);
    $done = true;
}
authOpen('Stop application emails', $done ? 'Done' : 'Stop application emails', $valid ? 'For <strong>' . e($email) . '</strong>' : 'This link is not valid.');
if ($valid && !$done): ?>
  <p style="margin-bottom:1.2rem;color:var(--text-2)">Job seekers using WorkFinder will no longer be able to email applications to this address. They'll be pointed to your website instead.</p>
  <form method="POST"><input type="hidden" name="e" value="<?= e($email) ?>"><input type="hidden" name="s" value="<?= e($sig) ?>"><button class="btn btn-primary btn-block">Stop application emails</button></form>
<?php elseif ($done): ?>
  <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span>No more applications will be sent to this address through WorkFinder.</span></div>
<?php endif;
authClose();
