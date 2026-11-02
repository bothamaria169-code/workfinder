<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$db   = getDB();

$q        = trim((string) ($_GET['q'] ?? ''));
$industry = (int) ($_GET['industry'] ?? 0);
$province = in_array($_GET['province'] ?? '', PROVINCES, true) ? $_GET['province'] : '';
$hiring   = !empty($_GET['hiring']);
$followed = !empty($_GET['followed']);
$page     = max(1, (int) ($_GET['page'] ?? 1));

$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(c.name LIKE ? OR c.description LIKE ?)'; array_push($args, "%{$q}%", "%{$q}%"); }
if ($industry) { $where[] = 'c.industry_id = ?'; $args[] = $industry; }
if ($province) { $where[] = 'c.province = ?'; $args[] = $province; }
if ($followed) { $where[] = 'EXISTS (SELECT 1 FROM followed_companies f WHERE f.company_id = c.id AND f.user_id = ?)'; $args[] = $uid; }
$having = $hiring ? 'HAVING open_jobs > 0' : '';
$w = implode(' AND ', $where);

$sql = "SELECT c.*, i.name AS industry_name, (SELECT COUNT(*) FROM jobs j WHERE j.company_id = c.id AND j.is_active = 1) AS open_jobs,
               EXISTS (SELECT 1 FROM followed_companies f WHERE f.company_id = c.id AND f.user_id = {$uid}) AS following
        FROM companies c LEFT JOIN industries i ON i.id = c.industry_id WHERE {$w} {$having}";
$count = $db->prepare("SELECT COUNT(*) FROM ({$sql}) t");
$count->execute($args);
$total = (int) $count->fetchColumn();
$off = ($page - 1) * 24;
$s = $db->prepare("{$sql} ORDER BY open_jobs DESC, c.is_verified DESC, c.name LIMIT 24 OFFSET {$off}");
$s->execute($args);
$companies = $s->fetchAll();

appOpen('companies', 'Companies', number_format($total) . ' companies — follow the ones you like and apply to them directly.');
?>
<section class="card" style="margin-bottom:1rem">
  <form class="filters" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Company name…">
    <select name="industry" onchange="this.form.submit()"><option value="">All industries</option><?php foreach (allIndustries() as $i): ?><option value="<?= (int) $i['id'] ?>" <?= $industry === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select>
    <select name="province" onchange="this.form.submit()"><option value="">All provinces</option><?php foreach (PROVINCES as $p): ?><option <?= $province === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select>
    <label class="chip-check"><input type="checkbox" name="hiring" value="1" <?= $hiring ? 'checked' : '' ?> onchange="this.form.submit()">Hiring now</label>
    <label class="chip-check"><input type="checkbox" name="followed" value="1" <?= $followed ? 'checked' : '' ?> onchange="this.form.submit()">Following</label>
  </form>
</section>
<div class="tiles">
  <?php foreach ($companies as $c): ?>
    <div class="tile" style="flex-direction:column;align-items:stretch">
      <a href="company.php?id=<?= (int) $c['id'] ?>" style="display:flex;gap:.8rem;align-items:center;color:inherit;text-decoration:none"><?= coLogo($c['name']) ?>
        <div style="min-width:0"><strong><?= e($c['name']) ?><?= $c['is_verified'] ? ' <i class="fa-solid fa-circle-check" style="color:var(--brand)" title="Verified"></i>' : '' ?></strong><small><?= e($c['industry_name'] ?: 'Industry not set') ?><?= $c['location'] ? ' · ' . e($c['location']) : '' ?></small></div></a>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.4rem">
        <a href="jobs.php?company=<?= (int) $c['id'] ?>" class="tag tag-<?= $c['open_jobs'] ? 'on' : 'user' ?>"><?= (int) $c['open_jobs'] ?> open <?= (int) $c['open_jobs'] === 1 ? 'job' : 'jobs' ?></a>
        <button class="btn-xs" data-follow="<?= (int) $c['id'] ?>"><?= $c['following'] ? '<i class="fa-solid fa-check"></i> Following' : '<i class="fa-solid fa-plus"></i> Follow' ?></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$companies): ?><div class="card"><div class="empty"><i class="fa-solid fa-building"></i>No companies match.</div></div><?php endif; ?>
<div class="card" style="margin-top:1rem"><?= pager($total, $page, 24, array_filter(compact('q', 'industry', 'province', 'hiring', 'followed'))) ?></div>
<?php appClose();
