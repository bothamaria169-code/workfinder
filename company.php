<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$c    = getCompany((int) ($_GET['id'] ?? 0));
if (!$c) { flash('error', 'Company not found.'); redirect('companies.php'); }

[$jobs, $total] = searchJobs(['company' => $c['id']], 1, 50);
$mine = userSkillIds($uid);
$f = getDB()->prepare('SELECT 1 FROM followed_companies WHERE user_id = ? AND company_id = ?');
$f->execute([$uid, $c['id']]);
$following = (bool) $f->fetchColumn();

appOpen('companies', $c['name'], e($c['industry_name'] ?: 'Industry not set') . ($c['location'] ? ' · ' . e($c['location']) : ''),
    '<a class="btn btn-ghost" href="companies.php"><i class="fa-solid fa-arrow-left"></i> Companies</a>'
    . '<button class="btn btn-ghost" data-follow="' . (int) $c['id'] . '">' . ($following ? '<i class="fa-solid fa-check"></i> Following' : '<i class="fa-solid fa-plus"></i> Follow') . '</button>'
    . '<a class="btn btn-primary" href="apply.php?company=' . (int) $c['id'] . '"><i class="fa-solid fa-paper-plane"></i> Send my CV</a>');
?>
<div class="grid-2">
  <section>
    <div class="card__head" style="padding:0 0 .8rem;border:0"><h2 style="font-size:1.05rem;font-weight:800"><?= $total ?> open <?= $total === 1 ? 'job' : 'jobs' ?></h2></div>
    <div class="jobs">
      <?php foreach ($jobs as $j) echo jobCard($j, $mine, userSavedIds($uid)); ?>
      <?php if (!$jobs): ?><div class="card"><div class="empty"><i class="fa-solid fa-briefcase"></i>No open jobs right now. Follow <?= e($c['name']) ?> to hear first, or send your CV as an open application.</div></div><?php endif; ?>
    </div>
  </section>
  <section class="card" style="align-self:start">
    <div class="card__head"><div class="detail-head"><?= coLogo($c['name'], 'lg') ?><div><h2><?= e($c['name']) ?></h2><p><?= $c['is_verified'] ? '<i class="fa-solid fa-circle-check" style="color:var(--brand)"></i> Verified by WorkFinder' : 'Listed from job adverts' ?></p></div></div></div>
    <div class="card__body facts">
      <div><span>Industry</span><strong><?= e($c['industry_name'] ?: '—') ?></strong></div>
      <div><span>Location</span><strong><?= e($c['location'] ?: '—') ?></strong></div>
      <div><span>Size</span><strong><?= e($c['size'] ?: '—') ?></strong></div>
      <div><span>Website</span><strong><?= $c['website'] ? '<a href="' . e($c['website']) . '" target="_blank" rel="noopener nofollow">' . e(parse_url($c['website'], PHP_URL_HOST) ?: $c['website']) . '</a>' : '—' ?></strong></div>
      <div><span>Careers page</span><strong><?= $c['careers_url'] ? '<a href="' . e($c['careers_url']) . '" target="_blank" rel="noopener nofollow">Open</a>' : '—' ?></strong></div>
      <div><span>Applications email</span><strong><?= $c['hr_email'] ? e($c['hr_email']) : '—' ?></strong></div>
    </div>
    <?php if ($c['description']): ?><div class="card__body job-body" style="border-top:1px solid var(--line)"><?= e($c['description']) ?></div><?php endif; ?>
  </section>
</div>
<?php appClose();
