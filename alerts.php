<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['form'] ?? '') === 'delete') {
        $db->prepare('DELETE FROM saved_searches WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('success', 'Alert deleted.');
    } elseif (($_POST['form'] ?? '') === 'frequency' && in_array($_POST['frequency'] ?? '', ['daily', 'weekly', 'off'], true)) {
        $db->prepare('UPDATE saved_searches SET frequency = ? WHERE id = ? AND user_id = ?')->execute([$_POST['frequency'], $id, $uid]);
        flash('success', 'Alert updated.');
    }
    redirect('alerts.php');
}

$s = $db->prepare('SELECT * FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC');
$s->execute([$uid]);
$alerts = $s->fetchAll();

appOpen('alerts', 'Job alerts', 'New jobs matching these searches are emailed to ' . e($user['email']) . '. Create one from any search with “Email me jobs like these”.',
    '<a class="btn btn-primary" href="jobs.php"><i class="fa-solid fa-plus"></i> New alert from a search</a>');
?>
<section class="card">
  <table class="tbl">
    <thead><tr><th>Search</th><th>Matching now</th><th>How often</th><th>Last sent</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $a): $f = json_decode((string) $a['query'], true) ?: []; ?>
      <tr>
        <td class="cell-main"><strong><a href="jobs.php?<?= e(http_build_query($f)) ?>"><?= e($a['name']) ?></a></strong><div class="muted"><?= e(describeSearch($f)) ?></div></td>
        <td data-label="Matching" class="num"><?= number_format(searchJobs($f, 1, 1)[1]) ?></td>
        <td data-label="How often"><form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="frequency"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <select class="select-sm" name="frequency" onchange="this.form.submit()"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'off' => 'Paused'] as $k => $v): ?><option value="<?= $k ?>" <?= $a['frequency'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></form></td>
        <td data-label="Last sent" class="muted"><?= e(timeAgo($a['last_sent_at'])) ?></td>
        <td class="num"><form method="POST" data-confirm="Delete this alert?"><?= csrfField() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn-xs danger"><i class="fa-solid fa-trash"></i></button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$alerts): ?><tr><td colspan="5"><div class="empty"><i class="fa-regular fa-bell"></i>No alerts yet. Search for jobs, then press <strong>Email me jobs like these</strong>.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php appClose();
