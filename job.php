<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$job  = getJob((int) ($_GET['id'] ?? 0));
if (!$job) { flash('error', 'That job is no longer listed.'); redirect('jobs.php'); }

$mine  = userSkillIds($uid);
$saved = in_array((int) $job['id'], userSavedIds($uid), true);
$have  = array_filter($job['skills'], fn($s) => in_array($s['id'], $mine, true));
$pct   = $job['skills'] && $mine ? (int) round(count($have) / count($job['skills']) * 100) : null;
$to    = $job['apply_email'] ?: $job['hr_email'];
$pay   = formatRand($job['salary_min'] ? (int) $job['salary_min'] : null, $job['salary_max'] ? (int) $job['salary_max'] : null);

$app = getDB()->prepare('SELECT * FROM applications WHERE user_id = ? AND job_id = ?');
$app->execute([$uid, $job['id']]);
$app = $app->fetch();

$following = false;
if ($job['company_id']) {
    $f = getDB()->prepare('SELECT 1 FROM followed_companies WHERE user_id = ? AND company_id = ?');
    $f->execute([$uid, $job['company_id']]);
    $following = (bool) $f->fetchColumn();
}

$similar = searchJobs(array_filter(['industry' => $job['industry_id'], 'q' => '']), 1, 5)[0];
$similar = array_filter($similar, fn($j) => (int) $j['id'] !== (int) $job['id']);

appOpen('jobs', $job['title'], e($job['company_name'] ?: 'Company not listed') . ' · ' . e($job['location'] ?: 'South Africa') . ($job['is_active'] ? '' : ' · <span class="tag tag-off">Closed</span>'),
    '<a class="btn btn-ghost" href="jobs.php"><i class="fa-solid fa-arrow-left"></i> Jobs</a>'
    . '<button class="btn btn-ghost save-btn ' . ($saved ? 'is-on' : '') . '" data-save="' . (int) $job['id'] . '"><i class="fa-' . ($saved ? 'solid' : 'regular') . ' fa-star"></i>' . ($saved ? 'Saved' : 'Save') . '</button>'
    . '<a class="btn btn-primary" href="apply.php?job=' . (int) $job['id'] . '"><i class="fa-solid fa-paper-plane"></i> Apply by email</a>');
?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><div class="detail-head"><?= coLogo($job['company_name'], 'lg') ?><div><h2><?= e($job['title']) ?></h2><p><?= e(WORK_TYPES[$job['work_type']] ?? '') ?><?= $job['experience_level'] ? ' · ' . e(LEVELS[$job['experience_level']]) : '' ?> · posted <?= e(timeAgo($job['posted_at'])) ?></p></div></div></div>
    <div class="card__body">
      <?php if ($job['skills']): ?>
        <h3 style="font-size:.95rem;margin-bottom:.4rem">Skills mentioned</h3>
        <div class="skill-chips" style="margin-bottom:1.2rem"><?php foreach ($job['skills'] as $s): $on = in_array($s['id'], $mine, true); ?><a class="skill <?= $on ? 'have' : '' ?>" href="jobs.php?skill=<?= (int) $s['id'] ?>"><?= $on ? '<i class="fa-solid fa-check"></i>' : '' ?><?= e($s['name']) ?></a><?php endforeach; ?></div>
      <?php endif; ?>
      <div class="job-body"><?= e(trim(html_entity_decode(strip_tags((string) $job['description'])))) ?: 'No description was provided.' ?></div>
      <?php if ($job['source'] !== 'manual'): ?><p class="hint" style="margin-top:1rem">This job comes from <?= e(ucfirst($job['source'])) ?>. The full advert may have more detail.</p><?php endif; ?>
    </div>
  </section>

  <div>
    <section class="card">
      <div class="card__head"><h2>At a glance</h2><?php if ($pct !== null): ?><div class="match" style="--p:<?= $pct ?>"><span><?= $pct ?>%</span></div><?php endif; ?></div>
      <div class="card__body facts">
        <div><span>Company</span><strong><?= $job['company_id'] ? '<a href="company.php?id=' . (int) $job['company_id'] . '">' . e($job['company_name']) . '</a>' : e($job['company_name'] ?: '—') ?></strong></div>
        <div><span>Location</span><strong><?= e($job['location'] ?: '—') ?><?= $job['is_remote'] ? ' · Remote' : '' ?></strong></div>
        <div><span>Salary</span><strong><?= $pay ? e($pay) . ' per month' : 'Not stated' ?></strong></div>
        <div><span>Industry</span><strong><?= $job['industry_id'] ? '<a href="jobs.php?industry=' . (int) $job['industry_id'] . '">' . e($job['industry_name']) . '</a>' : '—' ?></strong></div>
        <div><span>Your status</span><strong><?= $app ? statusTag($app['status']) : 'Not saved' ?></strong></div>
        <?php if ($pct !== null): ?><div><span>Skills match</span><strong><?= count($have) ?> of <?= count($job['skills']) ?></strong></div><?php endif; ?>
      </div>
      <div class="card__body" style="border-top:1px solid var(--line);display:grid;gap:.5rem">
        <a class="btn btn-primary btn-block" href="apply.php?job=<?= (int) $job['id'] ?>"><i class="fa-solid fa-paper-plane"></i> Apply by email<?= $to ? '' : ' (add the address)' ?></a>
        <?php if ($job['apply_url']): ?><a class="btn btn-ghost btn-block" href="<?= e($job['apply_url']) ?>" target="_blank" rel="noopener nofollow"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open the original advert</a><?php endif; ?>
        <?php if ($job['company_id']): ?><button class="btn btn-ghost btn-block" data-follow="<?= (int) $job['company_id'] ?>"><?= $following ? '<i class="fa-solid fa-check"></i> Following' : '<i class="fa-solid fa-plus"></i> Follow ' . e($job['company_name']) ?></button><?php endif; ?>
      </div>
    </section>
    <?php if ($similar): ?>
    <section class="card">
      <div class="card__head"><h2>Similar jobs</h2></div>
      <?php foreach ($similar as $j): ?><div class="list-row"><div class="list-row__main"><strong><a href="job.php?id=<?= (int) $j['id'] ?>"><?= e($j['title']) ?></a></strong><small><?= e($j['company_name']) ?> · <?= e($j['location']) ?></small></div></div><?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php appClose();
