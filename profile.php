<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'profile') {
        saveProfile($uid);
        flash('success', 'Profile saved.');
    } elseif ($form === 'cv') {
        $err = saveCvUpload($uid, $_FILES['cv'] ?? []);
        flash($err ? 'error' : 'success', $err ?: 'CV uploaded.');
    } elseif ($form === 'cv_default') {
        $db->prepare('UPDATE cvs SET is_default = (id = ?) WHERE user_id = ?')->execute([(int) $_POST['id'], $uid]);
        flash('success', 'Default CV updated.');
    } elseif ($form === 'cv_delete') {
        $s = $db->prepare('SELECT * FROM cvs WHERE id = ? AND user_id = ?');
        $s->execute([(int) $_POST['id'], $uid]);
        if ($cv = $s->fetch()) {
            @unlink(CV_DIR . $cv['file_name']);
            $db->prepare('DELETE FROM cvs WHERE id = ?')->execute([$cv['id']]);
            if ($cv['is_default']) $db->prepare('UPDATE cvs SET is_default = 1 WHERE user_id = ? ORDER BY created_at DESC LIMIT 1')->execute([$uid]);
            flash('success', 'CV deleted.');
        }
    }
    redirect('profile.php');
}

$cvs = userCvs($uid);
appOpen('profile', 'Profile & CV', 'What you tell us here drives your job matches, alerts and cover letters.');
?>
<div class="grid-2">
  <form method="POST" class="card" data-busy>
    <?= csrfField() ?><input type="hidden" name="form" value="profile">
    <div class="card__head"><div><h2>Job profile</h2><p>Skills you add show as green chips on matching jobs.</p></div></div>
    <div class="card__body"><?php profileFields($user); ?><div class="form-foot"><button class="btn btn-primary">Save profile</button></div></div>
  </form>

  <section class="card" style="align-self:start">
    <div class="card__head"><div><h2>Your CVs</h2><p>The default CV is attached when you apply.</p></div></div>
    <?php if (!$cvs): ?><div class="empty"><i class="fa-regular fa-file-lines"></i>No CV yet — upload one below.</div><?php endif; ?>
    <?php foreach ($cvs as $cv): ?>
      <div class="list-row">
        <div class="kpi__ico" style="width:36px;height:36px"><i class="fa-solid fa-file-<?= $cv['mime'] === 'application/pdf' ? 'pdf' : 'word' ?>"></i></div>
        <div class="list-row__main"><strong><a href="<?= e(cvUrl($cv)) ?>" target="_blank"><?= e($cv['original_name']) ?></a></strong><small><?= number_format($cv['size'] / 1024) ?> KB · <?= e(timeAgo($cv['created_at'])) ?><?= $cv['is_default'] ? ' · <b style="color:var(--mint)">Default</b>' : '' ?></small></div>
        <div class="row-actions">
          <?php if (!$cv['is_default']): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="cv_default"><input type="hidden" name="id" value="<?= (int) $cv['id'] ?>"><button class="btn-xs">Make default</button></form><?php endif; ?>
          <form method="POST" data-confirm="Delete this CV?"><?= csrfField() ?><input type="hidden" name="form" value="cv_delete"><input type="hidden" name="id" value="<?= (int) $cv['id'] ?>"><button class="btn-xs danger"><i class="fa-solid fa-trash"></i></button></form>
        </div>
      </div>
    <?php endforeach; ?>
    <form method="POST" enctype="multipart/form-data" class="card__body" style="border-top:1px solid var(--line)" data-busy>
      <?= csrfField() ?><input type="hidden" name="form" value="cv">
      <div class="field"><label>Upload a CV <span class="hint">PDF or Word, max 5 MB</span></label><input type="file" name="cv" accept=".pdf,.doc,.docx" required></div>
      <button class="btn btn-dark"><i class="fa-solid fa-upload"></i> Upload</button>
    </form>
  </section>
</div>
<?php appClose();
