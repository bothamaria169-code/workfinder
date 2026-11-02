<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

currentSeeker();
$rows = getDB()->query("SELECT i.*, (SELECT COUNT(*) FROM jobs j WHERE j.industry_id = i.id AND j.is_active = 1) AS jobs,
                               (SELECT COUNT(*) FROM companies c WHERE c.industry_id = i.id) AS companies
                        FROM industries i ORDER BY jobs DESC, i.name")->fetchAll();
appOpen('industries', 'Industries', 'Browse open jobs and companies by industry.');
?>
<div class="tiles">
  <?php foreach ($rows as $i): ?>
    <div class="tile">
      <div class="kpi__ico"><i class="fa-solid <?= e($i['icon']) ?>"></i></div>
      <div style="flex:1;min-width:0"><strong><a href="jobs.php?industry=<?= (int) $i['id'] ?>" style="color:inherit"><?= e($i['name']) ?></a></strong>
        <small><a href="jobs.php?industry=<?= (int) $i['id'] ?>"><?= number_format((int) $i['jobs']) ?> jobs</a> · <a href="companies.php?industry=<?= (int) $i['id'] ?>"><?= number_format((int) $i['companies']) ?> companies</a></small></div>
    </div>
  <?php endforeach; ?>
</div>
<?php appClose();
