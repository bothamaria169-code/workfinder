<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$mine = userSkillIds((int) $user['id']);
$q    = trim((string) ($_GET['q'] ?? ''));
$cat  = (string) ($_GET['cat'] ?? '');
$db   = getDB();

$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = 's.name LIKE ?'; $args[] = "%{$q}%"; }
if ($cat !== '') { $where[] = 's.category = ?'; $args[] = $cat; }
$w = implode(' AND ', $where);
// Demand = open jobs mentioning the skill; trend = this fortnight vs the one before.
$s = $db->prepare("SELECT s.*,
        (SELECT COUNT(*) FROM job_skills js JOIN jobs j ON j.id = js.job_id WHERE js.skill_id = s.id AND j.is_active = 1) AS demand,
        (SELECT COUNT(*) FROM job_skills js JOIN jobs j ON j.id = js.job_id WHERE js.skill_id = s.id AND j.posted_at >= NOW() - INTERVAL 14 DAY) AS recent,
        (SELECT COUNT(*) FROM job_skills js JOIN jobs j ON j.id = js.job_id WHERE js.skill_id = s.id AND j.posted_at < NOW() - INTERVAL 14 DAY AND j.posted_at >= NOW() - INTERVAL 28 DAY) AS before_
     FROM skills s WHERE {$w} ORDER BY demand DESC, s.name");
$s->execute($args);
$skills = $s->fetchAll();
$top = max(1, (int) ($skills[0]['demand'] ?? 1));
$cats = $db->query('SELECT DISTINCT category FROM skills ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

appOpen('skills', 'Skills in demand', 'How many open jobs ask for each skill. Skills you have are ticked — add more on your <a href="profile.php">profile</a>.');
?>
<section class="card">
  <form class="toolbar" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Find a skill…">
    <select name="cat" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($cats as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <span class="count"><?= count($skills) ?> skills</span>
  </form>
  <table class="tbl">
    <thead><tr><th>Skill</th><th>Category</th><th style="width:40%">Open jobs</th><th class="num">Trend (14 days)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($skills as $k):
        $have = in_array((int) $k['id'], $mine, true);
        $trend = (int) $k['recent'] - (int) $k['before_']; ?>
      <tr>
        <td class="cell-main"><strong><?= $have ? '<i class="fa-solid fa-circle-check" style="color:var(--mint)"></i> ' : '' ?><?= e($k['name']) ?></strong></td>
        <td data-label="Category" class="muted"><?= e($k['category']) ?></td>
        <td data-label="Open jobs"><div class="bar-row" style="grid-template-columns:1fr 44px;padding:0"><div class="track"><span style="width:<?= round($k['demand'] / $top * 100) ?>%"></span></div><b><?= (int) $k['demand'] ?></b></div></td>
        <td data-label="Trend" class="num" style="color:<?= $trend > 0 ? 'var(--mint)' : ($trend < 0 ? 'var(--coral)' : 'var(--text-3)') ?>"><?= $trend > 0 ? '▲ ' . $trend : ($trend < 0 ? '▼ ' . abs($trend) : '—') ?></td>
        <td class="num"><a class="btn-xs" href="jobs.php?skill=<?= (int) $k['id'] ?>">Jobs</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php appClose();
