<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$s = getDB()->prepare('SELECT j.* FROM saved_jobs sj JOIN jobs j ON j.id = sj.job_id WHERE sj.user_id = ? ORDER BY sj.created_at DESC');
$s->execute([$uid]);
$jobs = attachSkills($s->fetchAll());

appOpen('saved', 'Saved jobs', count($jobs) . ' saved. Saved jobs also sit in the “Saved” column of your applications board.');
?>
<div class="jobs">
  <?php foreach ($jobs as $j) echo jobCard($j, userSkillIds($uid), array_map(fn($x) => (int) $x['id'], $jobs)); ?>
  <?php if (!$jobs): ?><div class="card"><div class="empty"><i class="fa-regular fa-star"></i>Tap <strong>Save</strong> on any job to keep it here.</div></div><?php endif; ?>
</div>
<?php appClose();
