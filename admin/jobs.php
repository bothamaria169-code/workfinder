<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/demo.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    switch ($form) {
        case 'demo':
            $n = loadDemoData();
            audit('demo_load', '', "{$n} jobs");
            flash('success', "Demo data loaded: {$n} jobs and 20 sample companies. Remove it any time from Jobs.");
            break;
        case 'demo_remove':
            $n = removeDemoData();
            audit('demo_remove', '', "{$n} jobs");
            flash('success', "Removed {$n} demo jobs.");
            break;
        case 'save':
            $job = [
                'source' => 'manual', 'title' => postText('title', 250), 'company_name' => postText('company_name', 200), 'location' => postText('location', 150),
                'industry_id' => (int) ($_POST['industry_id'] ?? 0) ?: null, 'description' => postText('description', 20000),
                'apply_email' => postText('apply_email', 255), 'apply_url' => postText('apply_url', 600),
                'salary_min' => (int) ($_POST['salary_min'] ?? 0), 'salary_max' => (int) ($_POST['salary_max'] ?? 0),
                'work_type' => (string) ($_POST['work_type'] ?? 'full_time'), 'experience_level' => (string) ($_POST['experience_level'] ?? ''),
                'is_remote' => !empty($_POST['is_remote']), 'is_featured' => !empty($_POST['is_featured']),
                'expires_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['expires_at'] ?? '')) ? $_POST['expires_at'] . ' 23:59:59' : null,
            ];
            if ($job['title'] === '') { flash('error', 'A job needs a title.'); break; }
            if ($id) {
                $cid = $job['company_name'] !== '' ? companyIdFor($job['company_name'], $job['industry_id'], $job['location']) : null;
                $db->prepare('UPDATE jobs SET title = ?, company_id = ?, company_name = ?, location = ?, province = ?, industry_id = ?, description = ?, apply_email = ?, apply_url = ?,
                              salary_min = ?, salary_max = ?, work_type = ?, experience_level = ?, is_remote = ?, is_featured = ?, expires_at = COALESCE(?, expires_at) WHERE id = ?')
                   ->execute([$job['title'], $cid, $job['company_name'] ?: null, $job['location'] ?: null, provinceFor($job['location']), $job['industry_id'], $job['description'],
                              filter_var($job['apply_email'], FILTER_VALIDATE_EMAIL) ?: null, $job['apply_url'] ?: null, $job['salary_min'] ?: null, $job['salary_max'] ?: null,
                              isset(WORK_TYPES[$job['work_type']]) ? $job['work_type'] : 'full_time', isset(LEVELS[$job['experience_level']]) ? $job['experience_level'] : null,
                              $job['is_remote'] ? 1 : 0, $job['is_featured'] ? 1 : 0, $job['expires_at'], $id]);
                $db->prepare('DELETE FROM job_skills WHERE job_id = ?')->execute([$id]);
                $ins = $db->prepare('INSERT IGNORE INTO job_skills (job_id, skill_id) VALUES (?, ?)');
                foreach (extractSkills($job['title'] . ' ' . $job['description']) as $sid) $ins->execute([$id, $sid]);
                audit('job_edit', "#{$id} " . $job['title']);
                flash('success', 'Job updated.');
            } else {
                $ok = upsertJob($job);
                audit('job_add', $job['title']);
                flash($ok ? 'success' : 'error', $ok ? 'Job published.' : 'That job (same title, company and location) already exists.');
            }
            break;
        case 'toggle':
            $db->prepare('UPDATE jobs SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            audit('job_toggle', "#{$id}");
            break;
        case 'feature':
            $db->prepare('UPDATE jobs SET is_featured = 1 - is_featured WHERE id = ?')->execute([$id]);
            audit('job_feature', "#{$id}");
            break;
        case 'delete':
            $db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
            audit('job_delete', "#{$id}");
            flash('success', 'Job deleted.');
            break;
        case 'import':
            $f = $_FILES['csv'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('error', 'Choose a CSV file.'); break; }
            $h = fopen($f['tmp_name'], 'r');
            $head = array_map(fn($x) => strtolower(trim((string) $x, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($h) ?: []);
            if (!in_array('title', $head, true)) { flash('error', 'The first row must be headings and include “title”.'); break; }
            $added = 0; $rows = 0;
            while (($r = fgetcsv($h)) !== false && $rows < 5000) {
                $rows++;
                $row = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
                $iid = null;
                if (!empty($row['industry'])) { $s = $db->prepare('SELECT id FROM industries WHERE name = ? OR slug = ?'); $s->execute([$row['industry'], slugify($row['industry'])]); $iid = (int) $s->fetchColumn() ?: null; }
                if (upsertJob(['source' => 'manual', 'title' => $row['title'] ?? '', 'company_name' => $row['company'] ?? '', 'location' => $row['location'] ?? '', 'industry_id' => $iid,
                    'description' => $row['description'] ?? '', 'apply_email' => $row['apply_email'] ?? '', 'apply_url' => $row['apply_url'] ?? '',
                    'salary_min' => (int) ($row['salary_min'] ?? 0), 'salary_max' => (int) ($row['salary_max'] ?? 0), 'work_type' => $row['work_type'] ?? 'full_time'])) $added++;
            }
            audit('jobs_import', $f['name'], "{$added} of {$rows}");
            flash('success', "Imported {$added} new jobs from {$rows} rows (duplicates skipped).");
            break;
    }
    redirect('admin/jobs.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^\w=&%.-]/', '', (string) $_POST['back']) : ''));
}

// ── Add / edit form ───────────────────────────────────────────────
if (isset($_GET['edit'])) {
    $j = (int) $_GET['edit'] ? $db->query('SELECT * FROM jobs WHERE id = ' . (int) $_GET['edit'])->fetch() : null;
    $v = fn(string $k) => e($j[$k] ?? '');
    adminOpen('jobs', $j ? 'Edit job' : 'Post a job', $j ? e($j['title']) : 'Published straight away. Skills are picked up from the description.', '<a class="btn btn-ghost" href="jobs.php"><i class="fa-solid fa-arrow-left"></i> Jobs</a>');
    ?>
    <form method="POST" class="card" style="max-width:900px"><?= csrfField() ?><input type="hidden" name="form" value="save"><input type="hidden" name="id" value="<?= (int) ($j['id'] ?? 0) ?>">
      <div class="card__body form-grid">
        <div class="field full"><label>Job title</label><input name="title" value="<?= $v('title') ?>" required></div>
        <div class="field"><label>Company</label><input name="company_name" value="<?= $v('company_name') ?>" list="cos"><datalist id="cos"><?php foreach ($db->query('SELECT name FROM companies ORDER BY name LIMIT 500') as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?></datalist></div>
        <div class="field"><label>Location</label><input name="location" value="<?= $v('location') ?>" placeholder="e.g. Sandton, Johannesburg"></div>
        <div class="field"><label>Industry</label><select name="industry_id"><option value="">—</option><?php foreach (allIndustries() as $i): ?><option value="<?= (int) $i['id'] ?>" <?= (int) ($j['industry_id'] ?? 0) === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Work type</label><select name="work_type"><?php foreach (WORK_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($j['work_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Level</label><select name="experience_level"><option value="">—</option><?php foreach (LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= ($j['experience_level'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Salary per month (R)</label><div style="display:flex;gap:.5rem"><input type="number" name="salary_min" value="<?= $v('salary_min') ?>" placeholder="From"><input type="number" name="salary_max" value="<?= $v('salary_max') ?>" placeholder="To"></div></div>
        <div class="field"><label>Apply email</label><input type="email" name="apply_email" value="<?= $v('apply_email') ?>"></div>
        <div class="field"><label>Apply link (optional)</label><input type="url" name="apply_url" value="<?= $v('apply_url') ?>"></div>
        <div class="field"><label>Closes on</label><input type="date" name="expires_at" value="<?= $j && $j['expires_at'] ? e(substr($j['expires_at'], 0, 10)) : '' ?>"></div>
        <div class="field full"><label>Description</label><textarea name="description" rows="12"><?= $v('description') ?></textarea></div>
        <div class="field full chips"><label class="chip-check"><input type="checkbox" name="is_remote" value="1" <?= !empty($j['is_remote']) ? 'checked' : '' ?>>Remote</label><label class="chip-check"><input type="checkbox" name="is_featured" value="1" <?= !empty($j['is_featured']) ? 'checked' : '' ?>>Featured (shown first)</label></div>
        <div class="form-foot full"><button class="btn btn-primary"><?= $j ? 'Save job' : 'Publish job' ?></button></div>
      </div>
    </form>
    <?php
    adminClose();
    exit;
}

// ── List ──────────────────────────────────────────────────────────
$q = trim((string) ($_GET['q'] ?? ''));
$source = (string) ($_GET['source'] ?? '');
$state = (string) ($_GET['state'] ?? 'active');
$page = max(1, (int) ($_GET['page'] ?? 1));
$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(j.title LIKE ? OR j.company_name LIKE ?)'; array_push($args, "%{$q}%", "%{$q}%"); }
if ($source !== '') { $where[] = 'j.source = ?'; $args[] = $source; }
if ($state === 'active') $where[] = 'j.is_active = 1';
if ($state === 'closed') $where[] = 'j.is_active = 0';
if ($state === 'featured') $where[] = 'j.is_featured = 1';
$w = implode(' AND ', $where);
$c = $db->prepare("SELECT COUNT(*) FROM jobs j WHERE {$w}");
$c->execute($args);
$total = (int) $c->fetchColumn();
$off = ($page - 1) * 40;
$s = $db->prepare("SELECT j.*, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id AND a.status <> 'saved') AS applied FROM jobs j WHERE {$w} ORDER BY j.created_at DESC LIMIT 40 OFFSET {$off}");
$s->execute($args);
$rows = $s->fetchAll();
$sources = $db->query('SELECT DISTINCT source FROM jobs ORDER BY source')->fetchAll(PDO::FETCH_COLUMN);
$back = http_build_query(array_filter(compact('q', 'source', 'state', 'page')));
$hasDemo = (int) $db->query("SELECT COUNT(*) FROM jobs WHERE source = 'demo'")->fetchColumn();
$btn = fn(string $form, int $id, string $label, string $confirm = '') => '<form method="POST"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrfField() . '<input type="hidden" name="form" value="' . $form . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn-xs">' . $label . '</button></form>';

adminOpen('jobs', 'Jobs', number_format($total) . ' jobs',
    ($hasDemo ? '<form method="POST" data-confirm="Remove all demo jobs?">' . csrfField() . '<input type="hidden" name="form" value="demo_remove"><button class="btn btn-ghost"><i class="fa-solid fa-broom"></i> Remove demo data</button></form>'
              : '<form method="POST">' . csrfField() . '<input type="hidden" name="form" value="demo"><button class="btn btn-ghost"><i class="fa-solid fa-flask"></i> Load demo data</button></form>')
    . '<a class="btn btn-primary" href="?edit=0"><i class="fa-solid fa-plus"></i> Post a job</a>');
?>
<section class="card">
  <form class="toolbar" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="Title or company…">
    <select name="source" onchange="this.form.submit()"><option value="">All sources</option><?php foreach ($sources as $src): ?><option <?= $source === $src ? 'selected' : '' ?>><?= e($src) ?></option><?php endforeach; ?></select>
    <select name="state" onchange="this.form.submit()"><?php foreach (['active' => 'Open', 'closed' => 'Closed', 'featured' => 'Featured', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $state === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  </form>
  <table class="tbl">
    <thead><tr><th>Job</th><th>Source</th><th>Location</th><th class="num">Applied</th><th>Posted</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $j): ?>
      <tr>
        <td class="cell-main"><strong><a href="<?= APP_URL ?>/job.php?id=<?= (int) $j['id'] ?>" target="_blank"><?= e($j['title']) ?></a></strong><?= $j['is_featured'] ? ' <span class="tag tag-google">Featured</span>' : '' ?><?= $j['is_active'] ? '' : ' <span class="tag tag-off">Closed</span>' ?><div class="muted"><?= e($j['company_name'] ?: '—') ?><?= $j['apply_email'] ? ' · ' . e($j['apply_email']) : '' ?></div></td>
        <td data-label="Source"><span class="tag tag-user"><?= e($j['source']) ?></span></td>
        <td data-label="Location" class="muted"><?= e($j['location'] ?: '—') ?></td>
        <td data-label="Applied" class="num"><?= (int) $j['applied'] ?></td>
        <td data-label="Posted" class="muted"><?= e(timeAgo($j['posted_at'])) ?></td>
        <td class="num"><div class="row-actions">
          <a class="btn-xs" href="?edit=<?= (int) $j['id'] ?>">Edit</a>
          <?= $btn('feature', (int) $j['id'], $j['is_featured'] ? 'Unfeature' : 'Feature') ?>
          <?= $btn('toggle', (int) $j['id'], $j['is_active'] ? 'Close' : 'Reopen') ?>
          <?= $btn('delete', (int) $j['id'], '<i class="fa-solid fa-trash"></i>', 'Delete this job?') ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty">No jobs. Post one, import a CSV, connect a job source or load demo data.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= pager($total, $page, 40, array_filter(compact('q', 'source', 'state'))) ?>
</section>

<section class="card" style="margin-top:1.2rem;max-width:760px">
  <div class="card__head"><div><h2>Import jobs from CSV</h2><p>Headings in row 1. Required: <code>title</code>. Optional: <code>company, location, industry, description, apply_email, apply_url, salary_min, salary_max, work_type</code>.</p></div></div>
  <form method="POST" enctype="multipart/form-data" class="card__body" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center"><?= csrfField() ?><input type="hidden" name="form" value="import"><input type="file" name="csv" accept=".csv,text/csv" required><button class="btn btn-dark"><i class="fa-solid fa-file-import"></i> Import</button></form>
</section>
<?php adminClose();
