<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
if ($user['role'] !== 'admin' && empty($user['onboarded_at'])) redirect('onboarding.php');
$uid = (int) $user['id'];
$db  = getDB();

$q = function (string $sql) use ($db, $uid) { $s = $db->prepare($sql); $s->execute([$uid]); return $s->fetchColumn(); };
$mine     = userSkillIds($uid);
$newMatch = 0;
if ($mine) {
    $ids = implode(',', $mine);
    $newMatch = (int) $db->query("SELECT COUNT(DISTINCT j.id) FROM jobs j JOIN job_skills js ON js.job_id = j.id WHERE j.is_active = 1 AND js.skill_id IN ({$ids}) AND j.posted_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
}
$appliedMonth = (int) $q("SELECT COUNT(*) FROM applications WHERE user_id = ? AND applied_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$interviews   = (int) $q("SELECT COUNT(*) FROM applications WHERE user_id = ? AND status IN ('interview','offer')");
$followUps    = (int) $q("SELECT COUNT(*) FROM applications WHERE user_id = ? AND follow_up_on <= CURDATE() AND status IN ('applied','interview')");
$sentTotal    = (int) $q("SELECT COUNT(*) FROM sent_emails WHERE user_id = ? AND kind = 'application' AND status = 'sent'");
$opened       = (int) $q("SELECT COUNT(*) FROM sent_emails WHERE user_id = ? AND kind = 'application' AND opened_at IS NOT NULL");
$cvs          = userCvs($uid);

$recommended = recommendedJobs($user, 6);
$saved = userSavedIds($uid);

$recent = $db->prepare('SELECT * FROM applications WHERE user_id = ? ORDER BY updated_at DESC LIMIT 6');
$recent->execute([$uid]);
$recent = $recent->fetchAll();

$fromFollowed = $db->prepare('SELECT j.* FROM jobs j JOIN followed_companies f ON f.company_id = j.company_id WHERE f.user_id = ? AND j.is_active = 1 ORDER BY j.posted_at DESC LIMIT 5');
$fromFollowed->execute([$uid]);
$fromFollowed = $fromFollowed->fetchAll();

$first = explode(' ', trim((string) ($user['full_name'] ?: 'there')))[0];
appOpen('dashboard', 'Hi ' . $first . ' 👋', 'Here\'s your job hunt at a glance.',
    '<a class="btn btn-ghost" href="profile.php"><i class="fa-solid fa-id-card"></i> Edit profile</a><a class="btn btn-primary" href="jobs.php"><i class="fa-solid fa-magnifying-glass"></i> Find jobs</a>');
?>
<?php if (!$cvs): ?>
  <div class="alert alert-info"><i class="fa-solid fa-file-arrow-up"></i><span>Upload your CV so it's attached automatically when you apply. <a href="profile.php">Upload CV</a></span></div>
<?php endif; ?>
<?php if (!$mine): ?>
  <div class="alert alert-info"><i class="fa-solid fa-wand-magic-sparkles"></i><span>Add your skills to see a match score on every job. <a href="profile.php">Add skills</a></span></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-bullseye"></i></div><div><div class="kpi__label">New matching jobs</div><div class="kpi__value"><?= number_format($newMatch) ?></div><div class="kpi__delta">last 7 days</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-paper-plane"></i></div><div><div class="kpi__label">Applied this month</div><div class="kpi__value"><?= $appliedMonth ?></div><div class="kpi__delta"><b><?= $sentTotal ?></b> emails sent · <?= $opened ?> opened</div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-handshake"></i></div><div><div class="kpi__label">Interviews & offers</div><div class="kpi__value"><?= $interviews ?></div><div class="kpi__delta">on your board</div></div></div>
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-bell"></i></div><div><div class="kpi__label">Follow-ups due</div><div class="kpi__value"><?= $followUps ?></div><div class="kpi__delta"><a href="tracker.php">Open board</a></div></div></div>
</div>

<div class="grid-2">
  <section>
    <div class="card__head" style="padding:0 0 .8rem;border:0"><h2 style="font-size:1.05rem;font-weight:800">Recommended for you</h2><a href="jobs.php?sort=match">See all</a></div>
    <div class="jobs">
      <?php foreach ($recommended as $j) echo jobCard($j, $mine, $saved); ?>
      <?php if (!$recommended): ?><div class="card"><div class="empty"><i class="fa-solid fa-briefcase"></i>No jobs yet. An admin can sync job sources or load demo data.</div></div><?php endif; ?>
    </div>
  </section>
  <div>
    <section class="card">
      <div class="card__head"><h2>Recent applications</h2><a href="tracker.php">Board</a></div>
      <?php if (!$recent): ?><div class="empty"><i class="fa-solid fa-table-columns"></i>Save or apply to a job and it shows up here.</div><?php endif; ?>
      <?php foreach ($recent as $a): ?>
        <div class="list-row"><?= coLogo($a['company_name']) ?>
          <div class="list-row__main"><strong><?= $a['job_id'] ? '<a href="job.php?id=' . (int) $a['job_id'] . '">' . e($a['title']) . '</a>' : e($a['title']) ?></strong><small><?= e($a['company_name'] ?: '—') ?> · <?= e(timeAgo($a['updated_at'])) ?></small></div>
          <div class="list-row__side"><?= statusTag($a['status']) ?></div></div>
      <?php endforeach; ?>
    </section>
    <section class="card">
      <div class="card__head"><h2>From companies you follow</h2><a href="companies.php">Companies</a></div>
      <?php if (!$fromFollowed): ?><div class="empty"><i class="fa-solid fa-building"></i>Follow companies to see their newest jobs here.</div><?php endif; ?>
      <?php foreach ($fromFollowed as $j): ?>
        <div class="list-row"><div class="list-row__main"><strong><a href="job.php?id=<?= (int) $j['id'] ?>"><?= e($j['title']) ?></a></strong><small><?= e($j['company_name']) ?> · <?= e($j['location'] ?: '') ?></small></div><div class="list-row__side"><?= e(timeAgo($j['posted_at'])) ?></div></div>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<?php appClose();
