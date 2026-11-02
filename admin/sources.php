<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/sources/sources.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string) ($_POST['code'] ?? '');
    if (($_POST['form'] ?? '') === 'toggle') {
        $db->prepare('UPDATE job_sources SET enabled = 1 - enabled WHERE code = ?')->execute([$code]);
        audit('source_toggle', $code);
    } elseif (($_POST['form'] ?? '') === 'sync') {
        set_time_limit(280);
        $log = syncJobs($code !== 'all' ? $code : null);
        if ($code === 'all') set_setting('jobs_synced_at', date('Y-m-d H:i:s'));
        audit('source_sync', $code, implode('; ', $log));
        flash('success', $log ? 'Sync finished — ' . implode(' · ', $log) : 'Nothing synced. Check the source is switched on and has its API key.');
    } elseif (($_POST['form'] ?? '') === 'keywords') {
        set_setting('sync_keywords', postText('sync_keywords', 1000));
        set_setting('sync_every_minutes', (string) max(15, (int) $_POST['sync_every_minutes']));
        flash('success', 'Saved.');
    }
    redirect('admin/sources.php');
}

$rows = $db->query('SELECT js.*, (SELECT COUNT(*) FROM jobs j WHERE j.source = js.code AND j.is_active = 1) AS open_jobs FROM job_sources js ORDER BY js.code')->fetchAll();
$objs = jobSources();
$help = [
    'adzuna' => 'Free key at developer.adzuna.com — South Africa (country “za”). Best SA coverage.',
    'careerjet' => 'Free affiliate ID at careerjet.co.za/partners. South African jobs.',
    'jooble' => 'Free API key at jooble.org/api/about. South African jobs.',
    'remotive' => 'No key needed. Remote jobs open to South Africa / worldwide.',
    'arbeitnow' => 'No key needed. Remote jobs (mostly Europe-based companies).',
];
adminOpen('sources', 'Job sources', 'Official job APIs only — no scraping. Cron pulls new jobs every ' . e(get_setting('sync_every_minutes', '60')) . ' minutes; last run ' . e(timeAgo(get_setting('jobs_synced_at') ?: null)) . '.',
    '<form method="POST">' . csrfField() . '<input type="hidden" name="form" value="sync"><input type="hidden" name="code" value="all"><button class="btn btn-primary"><i class="fa-solid fa-rotate"></i> Sync all now</button></form>');
?>
<section class="card">
  <table class="tbl">
    <thead><tr><th>Source</th><th>Status</th><th class="num">Open jobs</th><th>Last run</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $ready = isset($objs[$r['code']]) && $objs[$r['code']]->ready(); ?>
      <tr>
        <td class="cell-main"><strong><?= e($r['name']) ?></strong><div class="muted"><?= e($help[$r['code']] ?? '') ?></div></td>
        <td data-label="Status"><?= $r['enabled'] ? '<span class="tag tag-on">On</span>' : '<span class="tag tag-off">Off</span>' ?> <?= $ready ? '' : '<span class="tag tag-google">Needs key</span>' ?>
          <?php if ($r['last_status']): ?><div class="muted" style="max-width:340px"><?= e(mb_strimwidth($r['last_status'], 0, 160, '…')) ?></div><?php endif; ?></td>
        <td data-label="Open jobs" class="num"><?= (int) $r['open_jobs'] ?></td>
        <td data-label="Last run" class="muted"><?= e(timeAgo($r['last_run_at'])) ?><?= $r['last_run_at'] ? ' · +' . (int) $r['last_count'] : '' ?></td>
        <td class="num"><div class="row-actions">
          <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="sync"><input type="hidden" name="code" value="<?= e($r['code']) ?>"><button class="btn-xs" <?= $ready ? '' : 'disabled' ?>>Sync now</button></form>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="toggle"><input type="hidden" name="code" value="<?= e($r['code']) ?>"><button class="btn-xs"><?= $r['enabled'] ? 'Turn off' : 'Turn on' ?></button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="card" style="margin-top:1.2rem;max-width:760px">
  <div class="card__head"><div><h2>What to pull</h2><p>Each sync searches these terms, plus the job titles users are looking for. API keys are in <a href="settings.php">Settings</a>.</p></div></div>
  <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="keywords">
    <div class="field"><label>Search terms (comma separated)</label><textarea name="sync_keywords" rows="3"><?= e(get_setting('sync_keywords', 'developer, accountant, administrator, sales, nurse, engineer, driver, call centre, teacher, marketing, data analyst, artisan')) ?></textarea></div>
    <div class="field"><label>Sync every (minutes)</label><input type="number" min="15" name="sync_every_minutes" value="<?= e(get_setting('sync_every_minutes', '60')) ?>"></div>
    <button class="btn btn-primary">Save</button>
  </form>
</section>
<?php adminClose();
