<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];

// "Save this search" turns the current filters into a job alert.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = searchFilters($_POST);
    $freq = in_array($_POST['frequency'] ?? '', ['daily', 'weekly'], true) ? $_POST['frequency'] : 'daily';
    $count = getDB()->prepare('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?');
    $count->execute([$uid]);
    if ((int) $count->fetchColumn() >= (int) get_setting('max_alerts', '10')) {
        flash('error', 'You have the maximum number of job alerts. Delete one first.');
    } else {
        getDB()->prepare('INSERT INTO saved_searches (user_id, name, query, frequency) VALUES (?, ?, ?, ?)')
            ->execute([$uid, mb_substr(postText('name', 150) ?: describeSearch($f), 0, 150), json_encode($f), $freq]);
        flash('success', 'Job alert created — new matches will be emailed to ' . $user['email'] . ' ' . $freq . '.');
    }
    redirect('jobs.php?' . http_build_query($f));
}

$f    = searchFilters($_GET);
$sort = in_array($_GET['sort'] ?? '', ['recent', 'match', 'salary'], true) ? $_GET['sort'] : 'recent';
$page = max(1, (int) ($_GET['page'] ?? 1));
$mine = userSkillIds($uid);
[$jobs, $total] = searchJobs($f, $page, 20, $sort, $mine);
$saved = userSavedIds($uid);
$industries = allIndustries();
$sel = fn(string $k, string $v) => (($f[$k] ?? '') === $v) ? 'selected' : '';

appOpen('jobs', 'Find jobs', number_format($total) . ' open ' . ($total === 1 ? 'job' : 'jobs') . ($f ? ' for <strong>' . e(describeSearch($f)) . '</strong>' : ' across South Africa and remote'));
?>
<form class="searchbar" method="get" style="margin-bottom:1rem">
  <label><i class="fa-solid fa-magnifying-glass"></i><input name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Job title, skill or company"></label>
  <label><i class="fa-solid fa-location-dot"></i><input name="location" value="<?= e($f['location'] ?? '') ?>" placeholder="City, province or Remote" list="places"></label>
  <datalist id="places"><option value="Remote"><?php foreach (PROVINCES as $p): ?><option value="<?= $p ?>"><?php endforeach; ?></datalist>
  <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  <?php foreach (['industry', 'skill', 'company', 'work_type', 'level', 'salary', 'days', 'remote'] as $k): if (isset($f[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e($f[$k]) ?>"><?php endif; endforeach; ?>
</form>

<section class="card" style="margin-bottom:1rem">
  <form class="filters" method="get" onchange="this.submit()">
    <input type="hidden" name="q" value="<?= e($f['q'] ?? '') ?>"><input type="hidden" name="location" value="<?= e($f['location'] ?? '') ?>">
    <?php if (isset($f['skill'])): ?><input type="hidden" name="skill" value="<?= e($f['skill']) ?>"><?php endif; ?>
    <?php if (isset($f['company'])): ?><input type="hidden" name="company" value="<?= e($f['company']) ?>"><?php endif; ?>
    <select name="industry"><option value="">All industries</option><?php foreach ($industries as $i): ?><option value="<?= (int) $i['id'] ?>" <?= $sel('industry', (string) $i['id']) ?>><?= e($i['name']) ?></option><?php endforeach; ?></select>
    <select name="work_type"><option value="">Any work type</option><?php foreach (WORK_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $sel('work_type', $k) ?>><?= $v ?></option><?php endforeach; ?></select>
    <select name="level"><option value="">Any level</option><?php foreach (LEVELS as $k => $v): ?><option value="<?= $k ?>" <?= $sel('level', $k) ?>><?= $v ?></option><?php endforeach; ?></select>
    <select name="salary"><option value="">Any salary</option><?php foreach ([10000, 20000, 30000, 50000, 80000] as $s): ?><option value="<?= $s ?>" <?= $sel('salary', (string) $s) ?>>R<?= number_format($s / 1000) ?>k+ pm</option><?php endforeach; ?></select>
    <select name="days"><option value="">Any time</option><?php foreach ([1 => 'Last 24 hours', 3 => 'Last 3 days', 7 => 'Last 7 days', 30 => 'Last 30 days'] as $d => $l): ?><option value="<?= $d ?>" <?= $sel('days', (string) $d) ?>><?= $l ?></option><?php endforeach; ?></select>
    <select name="sort"><option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Newest first</option><option value="match" <?= $sort === 'match' ? 'selected' : '' ?>>Best skills match</option><option value="salary" <?= $sort === 'salary' ? 'selected' : '' ?>>Highest salary</option></select>
  </form>
  <div class="toolbar" style="border-bottom:0">
    <?php if (isset($f['skill']) || isset($f['company'])): ?>
      <span class="hint" style="margin:0">Filtered by <?= isset($f['skill']) ? 'skill' : 'company' ?>.</span> <a class="btn-xs" href="jobs.php?<?= e(http_build_query(array_diff_key($f, ['skill' => 1, 'company' => 1]))) ?>"><i class="fa-solid fa-xmark"></i> Clear</a>
    <?php endif; ?>
    <form method="POST" style="margin-left:auto;display:flex;gap:.4rem;align-items:center"><?= csrfField() ?>
      <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <select name="frequency" class="select-sm"><option value="daily">Daily</option><option value="weekly">Weekly</option></select>
      <button class="btn-xs"><i class="fa-solid fa-bell"></i> Email me jobs like these</button>
    </form>
  </div>
</section>

<div class="jobs">
  <?php foreach ($jobs as $j) echo jobCard($j, $mine, $saved); ?>
  <?php if (!$jobs): ?><div class="card"><div class="empty"><i class="fa-solid fa-magnifying-glass"></i>No jobs match these filters. Try fewer filters or a broader word.</div></div><?php endif; ?>
</div>
<div class="card" style="margin-top:1rem"><?= pager($total, $page, 20, array_merge($f, ['sort' => $sort])) ?></div>
<?php appClose();
