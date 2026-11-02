<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saveProfile($uid);
    $err = saveCvUpload($uid, $_FILES['cv'] ?? []);
    if ($err) flash('error', $err);

    // A first job alert from what they told us, so the inbox starts working for them.
    $titles = array_filter(array_map('trim', explode(',', postText('job_titles', 500))));
    $hasAlert = getDB()->prepare('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?');
    $hasAlert->execute([$uid]);
    if ($titles && !(int) $hasAlert->fetchColumn() && !empty($_POST['alert'])) {
        $q = ['q' => $titles[0]];
        if (in_array($_POST['province'] ?? '', PROVINCES, true)) $q['location'] = $_POST['province'];
        getDB()->prepare("INSERT INTO saved_searches (user_id, name, query, frequency) VALUES (?, ?, ?, 'daily')")
            ->execute([$uid, mb_substr(describeSearch($q), 0, 150), json_encode($q)]);
    }
    flash('success', 'Your profile is set up. Here are jobs that match you.');
    redirect('dashboard.php');
}

appOpen('profile', 'Set up your job profile', 'Two minutes now means better matches, a ready-to-send CV and job alerts in your inbox.');
?>
<form method="POST" enctype="multipart/form-data" class="wizard" data-busy>
  <?= csrfField() ?>
  <section class="card">
    <div class="card__head"><div><h2>1 · What you're looking for</h2><p>We use this to rank jobs by how well they match you.</p></div></div>
    <div class="card__body"><?php profileFields($user); ?></div>
  </section>
  <section class="card">
    <div class="card__head"><div><h2>2 · Your CV</h2><p>PDF or Word, up to 5 MB. Attached when you apply by email. Only you (and WorkFinder admins) can open it.</p></div></div>
    <div class="card__body">
      <div class="field"><input type="file" name="cv" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></div>
      <label class="check"><input type="checkbox" name="alert" value="1" checked><span>Email me new jobs that match my first job title every day (you can change this any time).</span></label>
      <div class="form-foot"><button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-check"></i> Save and show my matches</button><a href="dashboard.php" class="btn btn-ghost">Skip for now</a></div>
    </div>
  </section>
</form>
<?php appClose();
