<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/includes/jobs.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        switch ($form) {
            case 'industry_add':
                $name = postText('name', 120);
                if ($name !== '') { $db->prepare('INSERT INTO industries (name, slug, icon) VALUES (?, ?, ?)')->execute([$name, slugify($name), preg_replace('/[^a-z0-9-]/', '', postText('icon', 40)) ?: 'fa-briefcase']); audit('industry_add', $name); }
                break;
            case 'industry_delete':
                $db->prepare('DELETE FROM industries WHERE id = ?')->execute([$id]);
                audit('industry_delete', "#{$id}");
                break;
            case 'skill_add':
                foreach (array_filter(array_map('trim', explode(',', postText('names', 2000)))) as $name) {
                    $db->prepare('INSERT IGNORE INTO skills (name, slug, category) VALUES (?, ?, ?)')->execute([mb_substr($name, 0, 100), slugify($name), postText('category', 60) ?: 'General']);
                }
                audit('skill_add', postText('names', 200));
                break;
            case 'skill_delete':
                $db->prepare('DELETE FROM skills WHERE id = ?')->execute([$id]);
                audit('skill_delete', "#{$id}");
                break;
            case 'synonym_add':
                $alias = strtolower(postText('alias', 100));
                if ($alias !== '') { $db->prepare('INSERT INTO skill_synonyms (skill_id, alias) VALUES (?, ?)')->execute([$id, $alias]); audit('synonym_add', $alias); }
                break;
            case 'synonym_delete':
                $db->prepare('DELETE FROM skill_synonyms WHERE id = ?')->execute([$id]);
                break;
            case 'retag':
                // Re-reads every open job against the current dictionary.
                $n = 0;
                $ins = $db->prepare('INSERT IGNORE INTO job_skills (job_id, skill_id) VALUES (?, ?)');
                foreach ($db->query('SELECT id, title, description FROM jobs WHERE is_active = 1')->fetchAll() as $j) {
                    $db->prepare('DELETE FROM job_skills WHERE job_id = ?')->execute([$j['id']]);
                    foreach (extractSkills($j['title'] . ' ' . $j['description']) as $sid) $ins->execute([$j['id'], $sid]);
                    $n++;
                }
                audit('skills_retag', '', "{$n} jobs");
                flash('success', "Re-tagged skills on {$n} open jobs.");
                break;
        }
        if (!in_array($form, ['retag'], true)) flash('success', 'Saved.');
    } catch (PDOException $e) {
        flash('error', $e->getCode() === '23000' ? 'That already exists.' : 'Could not save.');
    }
    redirect('admin/taxonomy.php');
}

$industries = $db->query('SELECT i.*, (SELECT COUNT(*) FROM jobs j WHERE j.industry_id = i.id) AS jobs FROM industries i ORDER BY i.name')->fetchAll();
$skills = $db->query('SELECT s.*, (SELECT COUNT(*) FROM job_skills js WHERE js.skill_id = s.id) AS jobs, (SELECT COUNT(*) FROM user_skills us WHERE us.skill_id = s.id) AS users,
                      (SELECT GROUP_CONCAT(CONCAT(ss.id, ":", ss.alias) SEPARATOR "|") FROM skill_synonyms ss WHERE ss.skill_id = s.id) AS aliases FROM skills s ORDER BY s.category, s.name')->fetchAll();
$hidden = fn(string $form, int $id) => csrfField() . '<input type="hidden" name="form" value="' . $form . '"><input type="hidden" name="id" value="' . $id . '">';

adminOpen('taxonomy', 'Industries & skills', 'The skills dictionary powers skill matching. Synonyms catch other spellings (e.g. “JS” → JavaScript).',
    '<form method="POST" data-confirm="Re-tag skills on every open job? Takes a moment on big databases.">' . csrfField() . '<input type="hidden" name="form" value="retag"><button class="btn btn-ghost"><i class="fa-solid fa-rotate"></i> Re-tag all jobs</button></form>');
?>
<div class="grid-2">
  <section class="card">
    <div class="card__head"><h2>Skills (<?= count($skills) ?>)</h2></div>
    <form method="POST" class="toolbar"><?= csrfField() ?><input type="hidden" name="form" value="skill_add">
      <input class="input" name="names" placeholder="Add skills, comma separated" required><input class="input" name="category" placeholder="Category" style="min-width:120px;flex:0"><button class="btn-xs">Add</button></form>
    <table class="tbl">
      <thead><tr><th>Skill</th><th>Synonyms</th><th class="num">Jobs</th><th class="num">Users</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($skills as $s): ?>
        <tr><td class="cell-main"><strong><?= e($s['name']) ?></strong><div class="muted"><?= e($s['category']) ?></div></td>
          <td data-label="Synonyms"><div class="chips" style="align-items:center">
            <?php foreach (array_filter(explode('|', (string) $s['aliases'])) as $a): [$aid, $alias] = explode(':', $a, 2); ?>
              <form method="POST" style="display:inline"><?= $hidden('synonym_delete', (int) $aid) ?><button class="skill" title="Remove"><?= e($alias) ?> ×</button></form>
            <?php endforeach; ?>
            <form method="POST" style="display:inline-flex;gap:.2rem"><?= $hidden('synonym_add', (int) $s['id']) ?><input name="alias" class="select-sm" style="width:90px" placeholder="+ alias"></form>
          </div></td>
          <td data-label="Jobs" class="num"><?= (int) $s['jobs'] ?></td><td data-label="Users" class="num"><?= (int) $s['users'] ?></td>
          <td class="num"><form method="POST" data-confirm="Delete this skill?"><?= $hidden('skill_delete', (int) $s['id']) ?><button class="btn-xs danger"><i class="fa-solid fa-trash"></i></button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <section class="card" style="align-self:start">
    <div class="card__head"><h2>Industries (<?= count($industries) ?>)</h2></div>
    <form method="POST" class="toolbar"><?= csrfField() ?><input type="hidden" name="form" value="industry_add"><input class="input" name="name" placeholder="New industry" required><input class="input" name="icon" placeholder="fa-icon" style="min-width:90px;flex:0"><button class="btn-xs">Add</button></form>
    <?php foreach ($industries as $i): ?>
      <div class="list-row"><div class="kpi__ico" style="width:34px;height:34px"><i class="fa-solid <?= e($i['icon']) ?>"></i></div><div class="list-row__main"><strong><?= e($i['name']) ?></strong><small><?= (int) $i['jobs'] ?> jobs</small></div>
        <form method="POST" data-confirm="Delete this industry? Jobs keep their other details."><?= $hidden('industry_delete', (int) $i['id']) ?><button class="btn-xs danger"><i class="fa-solid fa-trash"></i></button></form></div>
    <?php endforeach; ?>
  </section>
</div>
<?php adminClose();
