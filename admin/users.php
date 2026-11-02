<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/profile.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int) ($_POST['id'] ?? 0);
    $form = (string) ($_POST['form'] ?? '');
    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    $u = $s->fetch();
    $back = 'admin/users.php?id=' . $id;
    if (!$u) redirect('admin/users.php');
    $self = $id === (int) $ADMIN['id'];

    switch ($form) {
        case 'toggle':
            if ($self) { flash('error', 'You can\'t disable your own account.'); break; }
            $db->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            audit($u['is_active'] ? 'user_suspend' : 'user_enable', $u['email']);
            flash('success', $u['is_active'] ? 'Account suspended — they are signed out on their next click.' : 'Account enabled.');
            break;
        case 'role':
            if ($self) { flash('error', 'You can\'t change your own role.'); break; }
            $role = $u['role'] === 'admin' ? 'user' : 'admin';
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            audit('user_role', $u['email'], $role);
            flash('success', $role === 'admin' ? 'Now an admin.' : 'Admin rights removed.');
            break;
        case 'edit':
            $limit = (int) ($_POST['daily_email_limit'] ?? 0);
            $db->prepare('UPDATE users SET full_name = ?, phone = ?, daily_email_limit = ? WHERE id = ?')
               ->execute([postText('full_name', 150) ?: null, postText('phone', 50) ?: null, $limit > 0 ? $limit : null, $id]);
            audit('user_edit', $u['email']);
            flash('success', 'Saved.');
            break;
        case 'credits':
            $n = (int) ($_POST['credits'] ?? 0);
            if ($n !== 0) {
                addCredits($id, $n, $n > 0 ? 'gift' : 'adjust', postText('note', 200) ?: 'By admin', (int) $ADMIN['id']);
                audit('user_credits', $u['email'], (string) $n);
                flash('success', ($n > 0 ? 'Added ' : 'Removed ') . abs($n) . ' credits.');
            }
            break;
        case 'pro':
            $m = max(1, min(24, (int) ($_POST['months'] ?? 1)));
            extendPro($id, $m, 'Given by admin', (int) $ADMIN['id']);
            audit('user_pro', $u['email'], "+{$m} months");
            flash('success', "Pro extended by {$m} month(s).");
            break;
        case 'endpro':
            endPro($id, (int) $ADMIN['id']);
            audit('user_pro_end', $u['email']);
            flash('success', 'Moved to the Free plan.');
            break;
        case 'reset':
            $err = sendResetLink($u);
            audit('user_reset_link', $u['email']);
            flash($err ? 'error' : 'success', $err ? 'Could not send: ' . $err : 'Password reset link emailed to ' . $u['email'] . '.');
            break;
        case 'impersonate':
            if ($self || $u['role'] === 'admin') { flash('error', 'You can only log in as a job seeker.'); break; }
            audit('impersonate_start', $u['email']);
            $adminId = (int) $ADMIN['id'];
            beginUserSession($u);
            $_SESSION['impersonator_id'] = $adminId;
            redirect('dashboard.php');
        case 'delete':
            if ($self || $u['role'] === 'admin') { flash('error', 'Remove admin rights before deleting an admin.'); break; }
            foreach (userCvs($id) as $cv) @unlink(CV_DIR . $cv['file_name']);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            audit('user_delete', $u['email']);
            flash('success', 'User and all their data deleted.');
            $back = 'admin/users.php';
            break;
    }
    redirect($back);
}

// ── One user ──────────────────────────────────────────────────────
if (!empty($_GET['id'])) {
    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([(int) $_GET['id']]);
    $u = $s->fetch();
    if (!$u) redirect('admin/users.php');
    $uid = (int) $u['id'];
    $cvs = userCvs($uid);
    $skills = $db->prepare('SELECT s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ? ORDER BY s.name');
    $skills->execute([$uid]);
    $apps = $db->prepare('SELECT * FROM applications WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20');
    $apps->execute([$uid]);
    $mails = $db->prepare('SELECT * FROM sent_emails WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $mails->execute([$uid]);
    $btn = fn(string $form, string $label, string $confirm = '', string $cls = 'btn-ghost') => '<form method="POST" ' . ($confirm ? 'data-confirm="' . e($confirm) . '"' : '') . '>' . csrfField() . '<input type="hidden" name="form" value="' . $form . '"><input type="hidden" name="id" value="' . $uid . '"><button class="btn ' . $cls . '">' . $label . '</button></form>';

    adminOpen('users', $u['full_name'] ?: $u['email'], e($u['email']) . ' · ' . ($u['role'] === 'admin' ? '<span class="tag tag-admin">Admin</span>' : '<span class="tag tag-user">Job seeker</span>') . ' ' . ($u['is_active'] ? '<span class="tag tag-on">Active</span>' : '<span class="tag tag-off">Suspended</span>'),
        '<a class="btn btn-ghost" href="users.php"><i class="fa-solid fa-arrow-left"></i> Users</a>'
        . ($u['role'] !== 'admin' ? $btn('impersonate', '<i class="fa-solid fa-user-secret"></i> Log in as', 'Open WorkFinder as this user? This is recorded in the audit log.') : '')
        . $btn('reset', '<i class="fa-solid fa-key"></i> Send reset link'));
    ?>
    <div class="grid-2">
      <div>
        <section class="card">
          <div class="card__head"><h2>Profile</h2></div>
          <div class="card__body facts">
            <div><span>Headline</span><strong><?= e($u['headline'] ?: '—') ?></strong></div>
            <div><span>Looking for</span><strong><?= e($u['job_titles'] ?: '—') ?></strong></div>
            <div><span>Location</span><strong><?= e(trim(($u['location'] ?: '') . ' ' . ($u['province'] ? '(' . $u['province'] . ')' : '')) ?: '—') ?></strong></div>
            <div><span>Level · salary</span><strong><?= e(LEVELS[$u['experience_level']] ?? '—') ?> · <?= e(formatRand($u['salary_min'] ? (int) $u['salary_min'] : null, $u['salary_max'] ? (int) $u['salary_max'] : null) ?: '—') ?></strong></div>
            <div><span>Skills</span><strong style="text-align:right"><?= e(implode(', ', $skills->fetchAll(PDO::FETCH_COLUMN)) ?: '—') ?></strong></div>
            <div><span>Signed up</span><strong><?= e(date('j M Y H:i', strtotime($u['created_at']))) ?> · <?= e($u['signup_method']) ?> · <?= e($u['signup_source'] ?: 'Direct') ?></strong></div>
            <div><span>Last sign-in</span><strong><?= e(timeAgo($u['last_login_at'])) ?> (<?= (int) $u['login_count'] ?> total)</strong></div>
            <div><span>CVs</span><strong><?php foreach ($cvs as $cv): ?><a href="<?= e(cvUrl($cv)) ?>" target="_blank"><?= e($cv['original_name']) ?></a><br><?php endforeach; ?><?= $cvs ? '' : '—' ?></strong></div>
          </div>
        </section>
        <section class="card">
          <div class="card__head"><h2>Emails sent</h2><a href="emails.php?user=<?= $uid ?>">All</a></div>
          <table class="tbl"><tbody>
            <?php foreach ($mails->fetchAll() as $m): ?><tr><td class="cell-main"><strong><?= e($m['subject']) ?></strong><div class="muted"><?= e($m['kind']) ?> → <?= e($m['to_email']) ?></div></td><td><span class="tag st-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td><td class="muted"><?= e(timeAgo($m['created_at'])) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </section>
        <section class="card">
          <div class="card__head"><h2>Applications</h2></div>
          <table class="tbl"><tbody>
            <?php foreach ($apps->fetchAll() as $a): ?><tr><td class="cell-main"><strong><?= e($a['title']) ?></strong><div class="muted"><?= e($a['company_name'] ?: '—') ?></div></td><td><?= statusTag($a['status']) ?></td><td class="muted"><?= e(timeAgo($a['updated_at'])) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </section>
      </div>
      <div>
        <section class="card">
          <div class="card__head"><h2>Edit</h2></div>
          <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="edit"><input type="hidden" name="id" value="<?= $uid ?>">
            <div class="field"><label>Name</label><input name="full_name" value="<?= e($u['full_name']) ?>"></div>
            <div class="field"><label>Phone</label><input name="phone" value="<?= e($u['phone']) ?>"></div>
            <div class="field"><label>Daily application email limit <span class="hint">Blank = site default (<?= e(get_setting('daily_email_limit', '20')) ?>)</span></label><input type="number" min="0" name="daily_email_limit" value="<?= e($u['daily_email_limit']) ?>"></div>
            <button class="btn btn-primary">Save</button>
          </form>
        </section>
        <?php $usage = usageFor($u); $ledger = $db->prepare('SELECT * FROM credit_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 8'); $ledger->execute([$uid]); ?>
        <section class="card">
          <div class="card__head"><div><h2>Plan & credits</h2><p><?= $usage['pro'] ? 'Pro until ' . e(date('j M Y', strtotime($usage['expires']))) : 'Free plan' ?> · <?= $usage['left'] ?>/<?= $usage['allowance'] ?> applications left · <?= $usage['credits'] ?> credits</p></div></div>
          <div class="card__body" style="display:grid;gap:.7rem">
            <form method="POST" style="display:flex;gap:.4rem;flex-wrap:wrap"><?= csrfField() ?><input type="hidden" name="form" value="credits"><input type="hidden" name="id" value="<?= $uid ?>">
              <input class="input" type="number" name="credits" placeholder="+10 or -5" style="width:110px"><input class="input" name="note" placeholder="Reason" style="flex:1;min-width:120px"><button class="btn btn-ghost">Adjust credits</button></form>
            <form method="POST" style="display:flex;gap:.4rem"><?= csrfField() ?><input type="hidden" name="form" value="pro"><input type="hidden" name="id" value="<?= $uid ?>">
              <select name="months" class="input" style="width:auto"><?php foreach ([1, 3, 6, 12] as $m): ?><option value="<?= $m ?>"><?= $m ?> month<?= $m > 1 ? 's' : '' ?></option><?php endforeach; ?></select><button class="btn btn-ghost"><i class="fa-solid fa-gem"></i> Give Pro</button></form>
            <?php if ($usage['pro']): ?><?= $btn('endpro', 'Move to Free plan', 'End Pro for this user now?') ?><?php endif; ?>
            <?php foreach ($ledger->fetchAll() as $l): ?><div class="list-row" style="padding:.35rem 0"><div class="list-row__main"><small><?= e($l['note'] ?: $l['kind']) ?></small></div><div class="list-row__side"><?= $l['delta'] ? ($l['delta'] > 0 ? '+' : '') . (int) $l['delta'] : '' ?> · <?= e(timeAgo($l['created_at'])) ?></div></div><?php endforeach; ?>
          </div>
        </section>
        <section class="card">
          <div class="card__head"><h2>Access</h2></div>
          <div class="card__body" style="display:grid;gap:.6rem">
            <?= $btn('toggle', $u['is_active'] ? '<i class="fa-solid fa-ban"></i> Suspend account' : '<i class="fa-solid fa-check"></i> Enable account') ?>
            <?= $btn('role', $u['role'] === 'admin' ? '<i class="fa-solid fa-user"></i> Remove admin rights' : '<i class="fa-solid fa-shield-halved"></i> Make admin', $u['role'] === 'admin' ? '' : 'Give this user full admin access?') ?>
            <?= $u['role'] !== 'admin' ? $btn('delete', '<i class="fa-solid fa-trash"></i> Delete user and data', 'Delete this user, their CVs, applications and email history? This cannot be undone.') : '' ?>
          </div>
        </section>
      </div>
    </div>
    <?php
    adminClose();
    exit;
}

// ── List ──────────────────────────────────────────────────────────
$q      = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['filter'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$where  = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(u.email LIKE ? OR u.full_name LIKE ? OR u.job_titles LIKE ?)'; array_push($args, "%{$q}%", "%{$q}%", "%{$q}%"); }
if ($filter === 'admins') $where[] = "u.role = 'admin'";
if ($filter === 'suspended') $where[] = 'u.is_active = 0';
if ($filter === 'nocv') $where[] = 'NOT EXISTS (SELECT 1 FROM cvs c WHERE c.user_id = u.id)';
$w = implode(' AND ', $where);

if (($_GET['export'] ?? '') === 'csv') {
    $s = $db->prepare("SELECT u.id, u.email, u.full_name, u.phone, u.role, u.is_active, u.job_titles, u.province, u.signup_method, u.signup_source, u.created_at, u.last_login_at FROM users u WHERE {$w} ORDER BY u.created_at DESC");
    $s->execute($args);
    audit('users_export', $q ?: 'all');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="workfinder-users-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'email', 'name', 'phone', 'role', 'active', 'looking_for', 'province', 'signup_method', 'source', 'created', 'last_login']);
    foreach ($s as $r) fputcsv($out, array_values($r));
    exit;
}

$c = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$w}");
$c->execute($args);
$total = (int) $c->fetchColumn();
$off = ($page - 1) * 30;
$s = $db->prepare("SELECT u.*, (SELECT COUNT(*) FROM sent_emails se WHERE se.user_id = u.id AND se.kind = 'application' AND se.status = 'sent') AS sent,
                          (SELECT COUNT(*) FROM cvs c WHERE c.user_id = u.id) AS cvs
                   FROM users u WHERE {$w} ORDER BY u.created_at DESC LIMIT 30 OFFSET {$off}");
$s->execute($args);
$rows = $s->fetchAll();

adminOpen('users', 'Users', number_format($total) . ' accounts', '<a class="btn btn-ghost" href="?' . e(http_build_query(['q' => $q, 'filter' => $filter, 'export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>');
?>
<section class="card">
  <form class="toolbar" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Email, name or job title…">
    <select name="filter" onchange="this.form.submit()"><option value="">Everyone</option><option value="admins" <?= $filter === 'admins' ? 'selected' : '' ?>>Admins</option><option value="suspended" <?= $filter === 'suspended' ? 'selected' : '' ?>>Suspended</option><option value="nocv" <?= $filter === 'nocv' ? 'selected' : '' ?>>No CV yet</option></select>
  </form>
  <table class="tbl">
    <thead><tr><th>User</th><th>Looking for</th><th>Role</th><th class="num">CVs</th><th class="num">Applied</th><th>Joined</th><th>Last seen</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td class="cell-main"><div class="who"><div class="avatar"><?= e(strtoupper(mb_substr($u['full_name'] ?: $u['email'], 0, 1))) ?></div><div><strong><a href="?id=<?= (int) $u['id'] ?>"><?= e($u['full_name'] ?: '—') ?></a></strong><small><?= e($u['email']) ?></small></div></div></td>
        <td data-label="Looking for" class="muted"><?= e(mb_strimwidth((string) $u['job_titles'], 0, 40, '…') ?: '—') ?></td>
        <td data-label="Role"><span class="tag tag-<?= $u['role'] === 'admin' ? 'admin' : 'user' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'User' ?></span><?= $u['is_active'] ? '' : ' <span class="tag tag-off">Off</span>' ?></td>
        <td data-label="CVs" class="num"><?= (int) $u['cvs'] ?></td>
        <td data-label="Applied" class="num"><?= (int) $u['sent'] ?></td>
        <td data-label="Joined" class="muted"><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>
        <td data-label="Last seen" class="muted"><?= e(timeAgo($u['last_login_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="empty">No users match.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= pager($total, $page, 30, array_filter(['q' => $q, 'filter' => $filter])) ?>
</section>
<?php adminClose();
