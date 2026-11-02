<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'limit') {
        set_setting('daily_email_limit', (string) max(1, (int) $_POST['daily_email_limit']));
        audit('setting_email_limit', '', (string) $_POST['daily_email_limit']);
        flash('success', 'Daily limit saved.');
    } elseif ($form === 'optout_add' && filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $db->prepare('INSERT IGNORE INTO email_optouts (email) VALUES (?)')->execute([strtolower(trim($_POST['email']))]);
        audit('optout_add', strtolower(trim($_POST['email'])));
        flash('success', 'Address blocked for applications.');
    } elseif ($form === 'optout_remove') {
        $db->prepare('DELETE FROM email_optouts WHERE email = ?')->execute([(string) $_POST['email']]);
        audit('optout_remove', (string) $_POST['email']);
        flash('success', 'Address unblocked.');
    } elseif ($form === 'bounced') {
        $db->prepare("UPDATE sent_emails SET status = 'bounced' WHERE id = ?")->execute([(int) $_POST['id']]);
        flash('success', 'Marked as bounced.');
    }
    redirect('admin/emails.php');
}

$kind   = in_array($_GET['kind'] ?? '', ['application', 'copy', 'alert', 'system'], true) ? $_GET['kind'] : '';
$status = in_array($_GET['status'] ?? '', ['sent', 'failed', 'bounced'], true) ? $_GET['status'] : '';
$userId = (int) ($_GET['user'] ?? 0);
$q      = trim((string) ($_GET['q'] ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));
$where = ['1=1']; $args = [];
if ($kind) { $where[] = 'se.kind = ?'; $args[] = $kind; }
if ($status) { $where[] = 'se.status = ?'; $args[] = $status; }
if ($userId) { $where[] = 'se.user_id = ?'; $args[] = $userId; }
if ($q !== '') { $where[] = '(se.to_email LIKE ? OR se.subject LIKE ? OR u.email LIKE ?)'; array_push($args, "%{$q}%", "%{$q}%", "%{$q}%"); }
$w = implode(' AND ', $where);
$c = $db->prepare("SELECT COUNT(*) FROM sent_emails se LEFT JOIN users u ON u.id = se.user_id WHERE {$w}");
$c->execute($args);
$total = (int) $c->fetchColumn();
$off = ($page - 1) * 40;
$s = $db->prepare("SELECT se.*, u.email AS user_email FROM sent_emails se LEFT JOIN users u ON u.id = se.user_id WHERE {$w} ORDER BY se.created_at DESC LIMIT 40 OFFSET {$off}");
$s->execute($args);
$rows = $s->fetchAll();
$stats = $db->query("SELECT kind, SUM(status='sent') ok, SUM(status='failed') bad, SUM(opened_at IS NOT NULL) opened FROM sent_emails WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY kind")->fetchAll(PDO::FETCH_UNIQUE);
$optouts = $db->query('SELECT * FROM email_optouts ORDER BY created_at DESC LIMIT 50')->fetchAll();
$app = $stats['application'] ?? ['ok' => 0, 'bad' => 0, 'opened' => 0];

adminOpen('emails', 'Emails', 'Every email WorkFinder sent — applications, users\' copies, job alerts and system mail.');
?>
<div class="kpis">
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-paper-plane"></i></div><div><div class="kpi__label">Applications (30 days)</div><div class="kpi__value"><?= (int) $app['ok'] ?></div><div class="kpi__delta"><?= (int) $app['opened'] ?> opened</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-copy"></i></div><div><div class="kpi__label">Copies to users</div><div class="kpi__value"><?= (int) ($stats['copy']['ok'] ?? 0) ?></div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-bell"></i></div><div><div class="kpi__label">Job alerts</div><div class="kpi__value"><?= (int) ($stats['alert']['ok'] ?? 0) ?></div></div></div>
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi__label">Failed</div><div class="kpi__value"><?= array_sum(array_map(fn($r) => (int) $r['bad'], $stats)) ?></div><div class="kpi__delta"><a href="?status=failed">Show</a></div></div></div>
</div>

<section class="card">
  <form class="toolbar" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Recipient, subject or user email…">
    <select name="kind" onchange="this.form.submit()"><option value="">All kinds</option><?php foreach (['application' => 'Applications', 'copy' => 'Copies to users', 'alert' => 'Job alerts', 'system' => 'System'] as $k => $v): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
    <select name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (['sent', 'failed', 'bounced'] as $k): ?><option <?= $status === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select>
    <?php if ($userId): ?><input type="hidden" name="user" value="<?= $userId ?>"><?php endif; ?>
    <span class="count"><?= number_format($total) ?> emails</span>
  </form>
  <table class="tbl">
    <thead><tr><th>Email</th><th>From user</th><th>To</th><th>Status</th><th>Opened</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="cell-main"><strong><?= e($r['subject']) ?></strong><div class="muted"><?= e($r['kind']) ?><?= $r['attachment_name'] ? ' · <i class="fa-solid fa-paperclip"></i> ' . e($r['attachment_name']) : '' ?></div><?php if ($r['error']): ?><div class="muted" style="color:var(--coral)"><?= e(mb_strimwidth($r['error'], 0, 120, '…')) ?></div><?php endif; ?></td>
        <td data-label="User"><?= $r['user_id'] ? '<a href="users.php?id=' . (int) $r['user_id'] . '">' . e($r['user_email']) . '</a>' : '—' ?></td>
        <td data-label="To"><?= e($r['to_email']) ?></td>
        <td data-label="Status"><span class="tag st-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td data-label="Opened" class="muted"><?= $r['opened_at'] ? e(timeAgo($r['opened_at'])) . ' (' . (int) $r['open_count'] . '×)' : '—' ?></td>
        <td data-label="When" class="muted"><?= e(date('j M, H:i', strtotime($r['created_at']))) ?></td>
        <td class="num"><?php if ($r['status'] === 'sent' && $r['kind'] === 'application'): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="bounced"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn-xs" title="Mark as bounced">Bounced</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="empty">No emails yet.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= pager($total, $page, 40, array_filter(compact('kind', 'status', 'q') + ['user' => $userId ?: null])) ?>
</section>

<div class="grid-2 even" style="margin-top:1.2rem">
  <section class="card">
    <div class="card__head"><div><h2>Sending limit</h2><p>Protects the mailbox's reputation. Per user, per day.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="limit">
      <div class="field"><label>Application emails per user per day</label><input type="number" min="1" name="daily_email_limit" value="<?= e(get_setting('daily_email_limit', '20')) ?>"></div>
      <button class="btn btn-primary">Save</button>
    </form>
  </section>
  <section class="card">
    <div class="card__head"><div><h2>Blocked employer addresses</h2><p>Employers who opted out. Users can't email applications to these.</p></div></div>
    <form method="POST" class="toolbar"><?= csrfField() ?><input type="hidden" name="form" value="optout_add"><input class="input" type="email" name="email" placeholder="hr@company.co.za" required><button class="btn-xs">Block</button></form>
    <?php foreach ($optouts as $o): ?><div class="list-row"><div class="list-row__main"><strong><?= e($o['email']) ?></strong><small><?= e(timeAgo($o['created_at'])) ?></small></div><form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="optout_remove"><input type="hidden" name="email" value="<?= e($o['email']) ?>"><button class="btn-xs">Unblock</button></form></div><?php endforeach; ?>
  </section>
</div>
<?php adminClose();
