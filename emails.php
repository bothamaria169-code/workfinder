<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$db   = getDB();

// Re-send a failed application with the same letter, CV and recipient.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'resend') {
    $s = $db->prepare("SELECT se.*, a.job_id, a.company_id FROM sent_emails se LEFT JOIN applications a ON a.id = se.application_id
                       WHERE se.id = ? AND se.user_id = ? AND se.kind = 'application' AND se.status = 'failed'");
    $s->execute([(int) $_POST['id'], $uid]);
    if ($m = $s->fetch()) {
        $cv = null;
        foreach (userCvs($uid) as $c) if ($c['original_name'] === $m['attachment_name']) { $cv = $c; break; }
        $r = sendApplication($user, $m['to_email'], $m['subject'], (string) $m['body'], $cv, $m['job_id'] ? getJob((int) $m['job_id']) : null, $m['company_id'] ? getCompany((int) $m['company_id']) : null);
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Sent this time. A copy is in your inbox.' : $r['error']);
    }
    redirect('emails.php');
}

$kind = in_array($_GET['kind'] ?? '', ['application', 'copy', 'alert'], true) ? $_GET['kind'] : 'application';
$page = max(1, (int) ($_GET['page'] ?? 1));
$c = $db->prepare('SELECT COUNT(*) FROM sent_emails WHERE user_id = ? AND kind = ?');
$c->execute([$uid, $kind]);
$total = (int) $c->fetchColumn();
$off = ($page - 1) * 25;
$s = $db->prepare("SELECT se.*, a.title, a.company_name, a.job_id FROM sent_emails se LEFT JOIN applications a ON a.id = se.application_id
                   WHERE se.user_id = ? AND se.kind = ? ORDER BY se.created_at DESC LIMIT 25 OFFSET {$off}");
$s->execute([$uid, $kind]);
$rows = $s->fetchAll();
$view = null;
if (!empty($_GET['view'])) {
    $v = $db->prepare('SELECT * FROM sent_emails WHERE id = ? AND user_id = ?');
    $v->execute([(int) $_GET['view'], $uid]);
    $view = $v->fetch() ?: null;
}

appOpen('emails', 'Sent emails', 'Every application you send is kept here, and a copy goes to ' . e($user['email']) . '.');
?>
<div class="tabs">
  <a href="?kind=application" class="<?= $kind === 'application' ? 'is-on' : '' ?>">Applications</a>
  <a href="?kind=copy" class="<?= $kind === 'copy' ? 'is-on' : '' ?>">Copies to me</a>
  <a href="?kind=alert" class="<?= $kind === 'alert' ? 'is-on' : '' ?>">Job alerts</a>
</div>
<?php if ($view): ?>
<section class="card" style="margin-bottom:1.2rem">
  <div class="card__head"><div><h2><?= e($view['subject']) ?></h2><p>To <?= e($view['to_email']) ?> · <?= e(date('j M Y, H:i', strtotime($view['created_at']))) ?><?= $view['attachment_name'] ? ' · <i class="fa-solid fa-paperclip"></i> ' . e($view['attachment_name']) : '' ?></p></div><a href="emails.php?kind=<?= e($kind) ?>">Close</a></div>
  <div class="card__body"><div class="email-preview"><?= e($view['body'] ?: '(This email\'s text isn\'t stored.)') ?></div>
  <?php if ($view['error']): ?><div class="alert alert-error" style="margin-top:1rem"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($view['error']) ?></span></div><?php endif; ?></div>
</section>
<?php endif; ?>
<section class="card">
  <table class="tbl">
    <thead><tr><th>Email</th><th>To</th><th>Status</th><th>Opened</th><th>Sent</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="cell-main"><strong><?= e($r['subject']) ?></strong><?php if ($r['title']): ?><div class="muted"><?= e($r['title']) ?> · <?= e($r['company_name'] ?: '') ?></div><?php endif; ?></td>
        <td data-label="To"><?= e($r['to_email']) ?></td>
        <td data-label="Status"><span class="tag st-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td data-label="Opened" class="muted"><?= $r['kind'] !== 'application' ? '—' : ($r['opened_at'] ? '<i class="fa-solid fa-eye" style="color:var(--mint)"></i> ' . e(timeAgo($r['opened_at'])) : 'Not yet') ?></td>
        <td data-label="Sent" class="muted"><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
        <td class="num"><div class="row-actions">
          <a class="btn-xs" href="?kind=<?= e($kind) ?>&view=<?= (int) $r['id'] ?>">View</a>
          <?php if ($r['status'] === 'failed' && $r['kind'] === 'application'): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="resend"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn-xs"><i class="fa-solid fa-rotate-right"></i> Re-send</button></form><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><i class="fa-solid fa-paper-plane"></i>Nothing sent yet. Find a job and press <strong>Apply by email</strong>.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= pager($total, $page, 25, ['kind' => $kind]) ?>
</section>
<p class="hint">“Opened” uses a tiny image in the email, so it only shows when the employer's email app loads images — not seeing it doesn't mean it wasn't read.</p>
<?php appClose();
