<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/jobs.php';

$db = getDB();
$n  = fn(string $sql) => (int) $db->query($sql)->fetchColumn();

$users     = $n('SELECT COUNT(*) FROM users');
$usersWeek = $n('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY');
$active    = $n('SELECT COUNT(*) FROM users WHERE last_login_at >= NOW() - INTERVAL 30 DAY');
$jobs      = $n('SELECT COUNT(*) FROM jobs WHERE is_active = 1');
$jobsToday = $n('SELECT COUNT(*) FROM jobs WHERE created_at >= CURDATE()');
$sentToday = $n("SELECT COUNT(*) FROM sent_emails WHERE kind = 'application' AND status = 'sent' AND created_at >= CURDATE()");
$failToday = $n("SELECT COUNT(*) FROM sent_emails WHERE status = 'failed' AND created_at >= CURDATE()");
$companies = $n('SELECT COUNT(*) FROM companies');

// Last 30 days: sign-ups and applications per day.
$days = [];
for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-{$i} days"))] = ['u' => 0, 'a' => 0];
foreach ($db->query('SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY d') as $r) $days[$r['d']]['u'] = (int) $r['c'];
foreach ($db->query("SELECT DATE(created_at) d, COUNT(*) c FROM sent_emails WHERE kind = 'application' AND status = 'sent' AND created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY d") as $r) $days[$r['d']]['a'] = (int) $r['c'];

$bySource = $db->query('SELECT source, COUNT(*) c FROM jobs WHERE is_active = 1 GROUP BY source ORDER BY c DESC')->fetchAll();
$topSkills = $db->query('SELECT s.name, COUNT(*) c FROM user_skills us JOIN skills s ON s.id = us.skill_id GROUP BY s.id ORDER BY c DESC LIMIT 8')->fetchAll();
$topDemand = $db->query('SELECT s.name, COUNT(*) c FROM job_skills js JOIN skills s ON s.id = js.skill_id JOIN jobs j ON j.id = js.job_id WHERE j.is_active = 1 GROUP BY s.id ORDER BY c DESC LIMIT 8')->fetchAll();
$recentUsers = $db->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 6')->fetchAll();
$cron = get_setting('cron_last_run');

adminOpen('dashboard', 'Dashboard', 'WorkFinder at a glance' . ($cron ? ' · cron last ran ' . e(timeAgo($cron)) : ' · <span style="color:var(--coral)">cron has not run yet — see Settings</span>'),
    $jobs ? '' : '<form method="POST" action="jobs.php">' . csrfField() . '<input type="hidden" name="form" value="demo"><button class="btn btn-primary"><i class="fa-solid fa-flask"></i> Load demo data</button></form>');
?>
<div class="kpis">
  <div class="kpi"><div class="kpi__ico"><i class="fa-solid fa-users"></i></div><div><div class="kpi__label">Users</div><div class="kpi__value"><?= number_format($users) ?></div><div class="kpi__delta"><b>+<?= $usersWeek ?></b> this week · <?= $active ?> active</div></div></div>
  <div class="kpi kpi--mint"><div class="kpi__ico"><i class="fa-solid fa-briefcase"></i></div><div><div class="kpi__label">Open jobs</div><div class="kpi__value"><?= number_format($jobs) ?></div><div class="kpi__delta"><b>+<?= $jobsToday ?></b> today · <?= number_format($companies) ?> companies</div></div></div>
  <div class="kpi kpi--violet"><div class="kpi__ico"><i class="fa-solid fa-paper-plane"></i></div><div><div class="kpi__label">Applications sent today</div><div class="kpi__value"><?= $sentToday ?></div><div class="kpi__delta"><a href="emails.php">Email log</a></div></div></div>
  <div class="kpi kpi--amber"><div class="kpi__ico"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi__label">Failed emails today</div><div class="kpi__value"><?= $failToday ?></div><div class="kpi__delta"><a href="emails.php?status=failed">Review</a></div></div></div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card__head"><div><h2>Last 30 days</h2><p>Sign-ups and application emails sent</p></div></div>
    <div class="card__body"><div class="chart-box"><canvas id="trend"></canvas></div></div>
  </section>
  <section class="card">
    <div class="card__head"><h2>Open jobs by source</h2><a href="sources.php">Sources</a></div>
    <div class="card__body">
      <?php $max = max(1, (int) ($bySource[0]['c'] ?? 1)); foreach ($bySource as $r): ?>
        <div class="bar-row"><span><?= e(ucfirst($r['source'])) ?></span><div class="track"><span style="width:<?= round($r['c'] / $max * 100) ?>%"></span></div><b><?= (int) $r['c'] ?></b></div>
      <?php endforeach; ?>
      <?php if (!$bySource): ?><div class="empty"><i class="fa-solid fa-plug"></i>No jobs yet. Add API keys in Settings, or load demo data.</div><?php endif; ?>
    </div>
  </section>
</div>

<div class="grid-2 even">
  <section class="card">
    <div class="card__head"><h2>Skills users have vs. jobs ask for</h2><a href="taxonomy.php">Skills</a></div>
    <div class="card__body grid-2 even" style="margin:0">
      <div><h3 class="hint" style="margin:0 0 .5rem">Users have</h3><?php $m = max(1, (int) ($topSkills[0]['c'] ?? 1)); foreach ($topSkills as $r): ?><div class="bar-row" style="grid-template-columns:90px 1fr 30px"><span><?= e($r['name']) ?></span><div class="track"><span style="width:<?= round($r['c'] / $m * 100) ?>%"></span></div><b><?= (int) $r['c'] ?></b></div><?php endforeach; ?></div>
      <div><h3 class="hint" style="margin:0 0 .5rem">Jobs ask for</h3><?php $m = max(1, (int) ($topDemand[0]['c'] ?? 1)); foreach ($topDemand as $r): ?><div class="bar-row" style="grid-template-columns:90px 1fr 30px"><span><?= e($r['name']) ?></span><div class="track"><span style="width:<?= round($r['c'] / $m * 100) ?>%;background:var(--violet)"></span></div><b><?= (int) $r['c'] ?></b></div><?php endforeach; ?></div>
    </div>
  </section>
  <section class="card">
    <div class="card__head"><h2>Newest users</h2><a href="users.php">All users</a></div>
    <?php foreach ($recentUsers as $u): ?>
      <div class="list-row"><div class="avatar"><?= e(strtoupper(mb_substr($u['full_name'] ?: $u['email'], 0, 1))) ?></div>
        <div class="list-row__main"><strong><a href="users.php?id=<?= (int) $u['id'] ?>"><?= e($u['full_name'] ?: $u['email']) ?></a></strong><small><?= e($u['email']) ?> · <?= e($u['signup_source'] ?: 'Direct') ?></small></div>
        <div class="list-row__side"><?= e(timeAgo($u['created_at'])) ?></div></div>
    <?php endforeach; ?>
  </section>
</div>
<?php
$labels = array_map(fn($d) => date('j M', strtotime($d)), array_keys($days));
adminClose('<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script><script>
(function(){var css=getComputedStyle(document.documentElement);var c=function(v){return css.getPropertyValue(v).trim();};
new Chart(document.getElementById("trend"),{type:"bar",data:{labels:' . json_encode($labels) . ',datasets:[
{label:"Sign-ups",data:' . json_encode(array_column($days, 'u')) . ',backgroundColor:c("--brand"),borderRadius:4},
{label:"Applications sent",data:' . json_encode(array_column($days, 'a')) . ',backgroundColor:c("--violet"),borderRadius:4}]},
options:{maintainAspectRatio:false,plugins:{legend:{labels:{color:c("--text-2")}}},scales:{x:{ticks:{color:c("--text-3"),maxTicksLimit:10},grid:{display:false}},y:{beginAtZero:true,ticks:{color:c("--text-3"),precision:0},grid:{color:c("--line")}}}}});})();
</script>');
