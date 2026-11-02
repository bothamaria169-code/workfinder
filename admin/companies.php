<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/jobs.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    if ($form === 'save') {
        $name = postText('name', 200);
        if ($name === '') { flash('error', 'A company needs a name.'); redirect('admin/companies.php?edit=' . $id); }
        $loc = postText('location', 120);
        $vals = [$name, (int) ($_POST['industry_id'] ?? 0) ?: null, $loc ?: null, provinceFor($loc), postText('size', 30) ?: null,
                 postText('website', 300) ?: null, postText('careers_url', 300) ?: null,
                 filter_var(postText('hr_email', 255), FILTER_VALIDATE_EMAIL) ?: null, postText('description', 5000) ?: null, !empty($_POST['is_verified']) ? 1 : 0];
        try {
            if ($id) {
                $db->prepare('UPDATE companies SET name = ?, industry_id = ?, location = ?, province = ?, size = ?, website = ?, careers_url = ?, hr_email = ?, description = ?, is_verified = ? WHERE id = ?')->execute([...$vals, $id]);
                $db->prepare('UPDATE jobs SET company_name = ? WHERE company_id = ?')->execute([$name, $id]);
                audit('company_edit', $name);
            } else {
                $db->prepare('INSERT INTO companies (name, industry_id, location, province, size, website, careers_url, hr_email, description, is_verified, slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, slugify($name)]);
                $id = (int) $db->lastInsertId();
                audit('company_add', $name);
            }
            flash('success', 'Company saved.');
        } catch (PDOException $e) {
            flash('error', $e->getCode() === '23000' ? 'A company with that name already exists — merge them instead.' : 'Could not save.');
        }
    } elseif ($form === 'merge') {
        // Moves jobs, applications and followers from duplicate $id into $into, then deletes $id.
        $into = (int) ($_POST['into'] ?? 0);
        if ($into && $into !== $id) {
            $name = (string) $db->query('SELECT name FROM companies WHERE id = ' . $into)->fetchColumn();
            $db->prepare('UPDATE jobs SET company_id = ?, company_name = ? WHERE company_id = ?')->execute([$into, $name, $id]);
            $db->prepare('UPDATE applications SET company_id = ? WHERE company_id = ?')->execute([$into, $id]);
            $db->prepare('INSERT IGNORE INTO followed_companies (user_id, company_id, created_at) SELECT user_id, ?, created_at FROM followed_companies WHERE company_id = ?')->execute([$into, $id]);
            $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$id]);
            audit('company_merge', "#{$id} → #{$into}");
            flash('success', 'Merged into ' . $name . '.');
            redirect('admin/companies.php');
        }
    } elseif ($form === 'delete') {
        $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$id]);
        audit('company_delete', "#{$id}");
        flash('success', 'Company deleted. Its jobs stay listed without a company page.');
        redirect('admin/companies.php');
    }
    redirect('admin/companies.php?edit=' . $id);
}

if (isset($_GET['edit'])) {
    $c = (int) $_GET['edit'] ? $db->query('SELECT * FROM companies WHERE id = ' . (int) $_GET['edit'])->fetch() : null;
    $v = fn(string $k) => e($c[$k] ?? '');
    adminOpen('companies', $c ? $c['name'] : 'Add a company', $c ? '<a href="' . APP_URL . '/company.php?id=' . (int) $c['id'] . '" target="_blank">View public page</a>' : '', '<a class="btn btn-ghost" href="companies.php"><i class="fa-solid fa-arrow-left"></i> Companies</a>');
    ?>
    <div class="grid-2">
      <form method="POST" class="card"><?= csrfField() ?><input type="hidden" name="form" value="save"><input type="hidden" name="id" value="<?= (int) ($c['id'] ?? 0) ?>">
        <div class="card__body form-grid">
          <div class="field full"><label>Name</label><input name="name" value="<?= $v('name') ?>" required></div>
          <div class="field"><label>Industry</label><select name="industry_id"><option value="">—</option><?php foreach (allIndustries() as $i): ?><option value="<?= (int) $i['id'] ?>" <?= (int) ($c['industry_id'] ?? 0) === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Location</label><input name="location" value="<?= $v('location') ?>"></div>
          <div class="field"><label>Size</label><select name="size"><option value="">—</option><?php foreach (['1-10', '11-50', '51-200', '201-500', '501-1000', '1000+'] as $sz): ?><option <?= ($c['size'] ?? '') === $sz ? 'selected' : '' ?>><?= $sz ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>HR / careers email <span class="hint">Used for "Send my CV"</span></label><input type="email" name="hr_email" value="<?= $v('hr_email') ?>"></div>
          <div class="field"><label>Website</label><input type="url" name="website" value="<?= $v('website') ?>"></div>
          <div class="field"><label>Careers page</label><input type="url" name="careers_url" value="<?= $v('careers_url') ?>"></div>
          <div class="field full"><label>About</label><textarea name="description" rows="5"><?= $v('description') ?></textarea></div>
          <label class="check full"><input type="checkbox" name="is_verified" value="1" <?= !empty($c['is_verified']) ? 'checked' : '' ?>><span>Verified — details checked by an admin</span></label>
          <div class="form-foot full"><button class="btn btn-primary">Save</button></div>
        </div>
      </form>
      <?php if ($c): ?>
      <div>
        <section class="card">
          <div class="card__head"><div><h2>Merge a duplicate</h2><p>Move this company's jobs and followers into another, then delete this one.</p></div></div>
          <form method="POST" class="card__body" data-confirm="Merge and delete this company?"><?= csrfField() ?><input type="hidden" name="form" value="merge"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <div class="field"><label>Merge into</label><select name="into" required><option value="">Choose…</option><?php foreach ($db->query('SELECT id, name FROM companies WHERE id <> ' . (int) $c['id'] . ' ORDER BY name LIMIT 2000') as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-ghost">Merge</button>
          </form>
        </section>
        <section class="card">
          <div class="card__head"><h2>Delete</h2></div>
          <form method="POST" class="card__body" data-confirm="Delete this company?"><?= csrfField() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-ghost" style="color:var(--coral)"><i class="fa-solid fa-trash"></i> Delete company</button></form>
        </section>
      </div>
      <?php endif; ?>
    </div>
    <?php
    adminClose();
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$missing = !empty($_GET['noemail']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = 'c.name LIKE ?'; $args[] = "%{$q}%"; }
if ($missing) $where[] = "(c.hr_email IS NULL OR c.hr_email = '')";
$w = implode(' AND ', $where);
$cnt = $db->prepare("SELECT COUNT(*) FROM companies c WHERE {$w}");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$off = ($page - 1) * 40;
$s = $db->prepare("SELECT c.*, i.name AS industry_name, (SELECT COUNT(*) FROM jobs j WHERE j.company_id = c.id AND j.is_active = 1) AS open_jobs,
                          (SELECT COUNT(*) FROM followed_companies f WHERE f.company_id = c.id) AS followers
                   FROM companies c LEFT JOIN industries i ON i.id = c.industry_id WHERE {$w} ORDER BY open_jobs DESC, c.name LIMIT 40 OFFSET {$off}");
$s->execute($args);
$rows = $s->fetchAll();

adminOpen('companies', 'Companies', number_format($total) . ' companies. New ones are created automatically from job adverts.', '<a class="btn btn-primary" href="?edit=0"><i class="fa-solid fa-plus"></i> Add company</a>');
?>
<section class="card">
  <form class="toolbar" method="get"><input class="input" name="q" value="<?= e($q) ?>" placeholder="Company name…"><label class="chip-check"><input type="checkbox" name="noemail" value="1" <?= $missing ? 'checked' : '' ?> onchange="this.form.submit()">Missing HR email</label></form>
  <table class="tbl">
    <thead><tr><th>Company</th><th>Industry</th><th>HR email</th><th class="num">Open jobs</th><th class="num">Followers</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr><td class="cell-main"><strong><?= e($c['name']) ?></strong><?= $c['is_verified'] ? ' <i class="fa-solid fa-circle-check" style="color:var(--brand)"></i>' : '' ?><div class="muted"><?= e($c['location'] ?: '—') ?></div></td>
        <td data-label="Industry" class="muted"><?= e($c['industry_name'] ?: '—') ?></td>
        <td data-label="HR email" class="muted"><?= e($c['hr_email'] ?: '—') ?></td>
        <td data-label="Open jobs" class="num"><?= (int) $c['open_jobs'] ?></td>
        <td data-label="Followers" class="num"><?= (int) $c['followers'] ?></td>
        <td class="num"><a class="btn-xs" href="?edit=<?= (int) $c['id'] ?>">Edit</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty">No companies.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= pager($total, $page, 40, array_filter(['q' => $q, 'noemail' => $missing ? 1 : null])) ?>
</section>
<?php adminClose();
