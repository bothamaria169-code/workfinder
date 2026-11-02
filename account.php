<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'password') {
        if ($user['password_hash'] && !password_verify((string) ($_POST['current'] ?? ''), $user['password_hash'])) {
            flash('error', 'Your current password is wrong.');
        } elseif ($problem = passwordProblem((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''))) {
            flash('error', $problem);
        } else {
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
        }
    } elseif ($form === 'export') {
        // POPIA: everything we hold about the user, as JSON.
        $data = ['exported_at' => date('c')];
        $u = $user; unset($u['password_hash']);
        $data['account'] = $u;
        foreach (['user_skills' => 'SELECT s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ?',
                  'cvs' => 'SELECT original_name, size, created_at FROM cvs WHERE user_id = ?',
                  'saved_jobs' => 'SELECT j.title, j.company_name, sj.created_at FROM saved_jobs sj JOIN jobs j ON j.id = sj.job_id WHERE sj.user_id = ?',
                  'applications' => 'SELECT title, company_name, status, notes, applied_at, created_at FROM applications WHERE user_id = ?',
                  'sent_emails' => 'SELECT kind, to_email, subject, body, status, created_at, opened_at FROM sent_emails WHERE user_id = ?',
                  'job_alerts' => 'SELECT name, query, frequency, created_at FROM saved_searches WHERE user_id = ?'] as $k => $sql) {
            $s = $db->prepare($sql); $s->execute([$uid]); $data[$k] = $s->fetchAll();
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="workfinder-my-data.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } elseif ($form === 'delete') {
        if ($user['role'] === 'admin') {
            flash('error', 'Admin accounts can\'t be deleted from here.');
        } elseif (strtolower(trim((string) ($_POST['confirm_email'] ?? ''))) !== $user['email']) {
            flash('error', 'Type your email address exactly to confirm.');
        } else {
            foreach (userCvs($uid) as $cv) @unlink(CV_DIR . $cv['file_name']);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            logoutUser();
            startAppSession();
            flash('success', 'Your account and all your data have been deleted.');
            redirect('login.php');
        }
    } elseif ($form === 'prefs') {
        $s = json_decode((string) $user['settings'], true) ?: [];
        $s['alerts_email'] = !empty($_POST['alerts_email']);
        $db->prepare('UPDATE users SET settings = ? WHERE id = ?')->execute([json_encode($s), $uid]);
        if (!$s['alerts_email']) $db->prepare("UPDATE saved_searches SET frequency = 'off' WHERE user_id = ?")->execute([$uid]);
        flash('success', 'Preferences saved.');
    }
    redirect('account.php');
}

$prefs = json_decode((string) $user['settings'], true) ?: [];
appOpen('account', 'Account', e($user['email']) . ' · member since ' . e(date('j M Y', strtotime($user['created_at']))));
?>
<div class="grid-2 even">
  <section class="card">
    <div class="card__head"><div><h2><?= $user['password_hash'] ? 'Change password' : 'Set a password' ?></h2><p>At least 8 characters with a letter and a number.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="password">
      <?php if ($user['password_hash']): ?><div class="field"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div><?php endif; ?>
      <div class="form-grid">
        <div class="field"><label>New password</label><input type="password" name="password" autocomplete="new-password" required></div>
        <div class="field"><label>Confirm</label><input type="password" name="confirm" autocomplete="new-password" required></div>
      </div>
      <button class="btn btn-primary">Save password</button>
    </form>
  </section>
  <div>
    <section class="card">
      <div class="card__head"><h2>Email preferences</h2></div>
      <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="prefs">
        <label class="check"><input type="checkbox" name="alerts_email" value="1" <?= ($prefs['alerts_email'] ?? true) ? 'checked' : '' ?>><span>Send me job alert emails. (Copies of applications you send always go to you.)</span></label>
        <button class="btn btn-ghost">Save</button>
      </form>
    </section>
    <section class="card">
      <div class="card__head"><div><h2>Your data (POPIA)</h2><p>Download everything we hold about you, or delete it all.</p></div></div>
      <div class="card__body">
        <form method="POST" style="margin-bottom:1rem"><?= csrfField() ?><input type="hidden" name="form" value="export"><button class="btn btn-ghost"><i class="fa-solid fa-download"></i> Download my data</button></form>
        <form method="POST" data-confirm="Delete your account, CVs, applications and sent-email history for good? This can't be undone."><?= csrfField() ?><input type="hidden" name="form" value="delete">
          <div class="field"><label>Delete my account <span class="hint">Type your email to confirm</span></label><input name="confirm_email" placeholder="<?= e($user['email']) ?>"></div>
          <button class="btn btn-ghost" style="color:var(--coral);border-color:var(--coral)"><i class="fa-solid fa-trash"></i> Delete my account</button>
        </form>
      </div>
    </section>
  </div>
</div>
<?php appClose();
