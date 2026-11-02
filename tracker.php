<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user = currentSeeker();
$uid  = (int) $user['id'];
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    if ($form === 'add') {
        $title = postText('title', 250);
        if ($title !== '') {
            $status = isset(APP_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'applied';
            $db->prepare('INSERT INTO applications (user_id, title, company_name, status, notes, applied_at) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$uid, $title, postText('company_name', 200) ?: null, $status, postText('notes', 3000) ?: null, $status === 'saved' ? null : date('Y-m-d H:i:s')]);
            flash('success', 'Added to your board.');
        }
    } elseif ($form === 'edit') {
        $status = isset(APP_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'saved';
        $fu = (string) ($_POST['follow_up_on'] ?? '');
        $db->prepare("UPDATE applications SET status = ?, notes = ?, follow_up_on = ?, applied_at = IF(? <> 'saved', COALESCE(applied_at, NOW()), applied_at) WHERE id = ? AND user_id = ?")
           ->execute([$status, postText('notes', 3000) ?: null, preg_match('/^\d{4}-\d{2}-\d{2}$/', $fu) ? $fu : null, $status, $id, $uid]);
        flash('success', 'Updated.');
    } elseif ($form === 'delete') {
        $db->prepare('DELETE FROM applications WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('success', 'Removed from your board.');
    }
    redirect('tracker.php' . (isset($_POST['view']) && $_POST['view'] === 'table' ? '?view=table' : ''));
}

$s = $db->prepare('SELECT a.*, (SELECT COUNT(*) FROM sent_emails se WHERE se.application_id = a.id AND se.kind = \'application\' AND se.status = \'sent\') AS emails
                   FROM applications a WHERE a.user_id = ? ORDER BY a.updated_at DESC');
$s->execute([$uid]);
$apps = $s->fetchAll();
$by = array_fill_keys(array_keys(APP_STATUSES), []);
foreach ($apps as $a) $by[$a['status']][] = $a;
$view = ($_GET['view'] ?? '') === 'table' ? 'table' : 'board';
$edit = null;
foreach ($apps as $a) if ((int) $a['id'] === (int) ($_GET['edit'] ?? 0)) $edit = $a;

appOpen('tracker', 'Applications', 'Drag cards between columns as things move. Follow-up dates are emailed to you as reminders.',
    '<a class="btn btn-ghost" href="tracker.php?view=' . ($view === 'board' ? 'table' : 'board') . '"><i class="fa-solid fa-' . ($view === 'board' ? 'table-list' : 'table-columns') . '"></i> ' . ($view === 'board' ? 'Table' : 'Board') . ' view</a>'
    . '<a class="btn btn-primary" href="#add"><i class="fa-solid fa-plus"></i> Add application</a>');
?>
<?php if ($edit): ?>
<section class="card" style="margin-bottom:1.2rem">
  <div class="card__head"><div><h2><?= e($edit['title']) ?></h2><p><?= e($edit['company_name'] ?: '') ?></p></div><a href="tracker.php">Close</a></div>
  <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="edit"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <div class="form-grid">
      <div class="field"><label>Status</label><select name="status"><?php foreach (APP_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $edit['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Follow up on</label><input type="date" name="follow_up_on" value="<?= e($edit['follow_up_on']) ?>"></div>
      <div class="field full"><label>Notes <span class="hint">Interview times, contacts, salary discussed…</span></label><textarea name="notes" rows="4"><?= e($edit['notes']) ?></textarea></div>
    </div>
    <div class="form-foot"><button class="btn btn-primary">Save</button></div>
  </form>
  <form method="POST" class="card__body" style="padding-top:0" data-confirm="Remove this from your board?"><?= csrfField() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn-xs danger"><i class="fa-solid fa-trash"></i> Remove</button></form>
</section>
<?php endif; ?>

<?php if ($view === 'board'): ?>
<div class="board">
  <?php foreach (APP_STATUSES as $st => $label): ?>
    <div class="col" data-status="<?= $st ?>" data-label="<?= $label ?>">
      <div class="col__head"><span><?= statusTag($st) ?></span><b><?= count($by[$st]) ?></b></div>
      <?php foreach ($by[$st] as $a): ?>
        <div class="app-card" draggable="true" data-id="<?= (int) $a['id'] ?>">
          <strong><a href="tracker.php?edit=<?= (int) $a['id'] ?>" style="color:inherit"><?= e($a['title']) ?></a></strong>
          <small><?= e($a['company_name'] ?: '—') ?></small>
          <small><?= $a['applied_at'] ? 'Applied ' . e(date('j M', strtotime($a['applied_at']))) : 'Saved ' . e(timeAgo($a['created_at'])) ?><?= $a['emails'] ? ' · <i class="fa-solid fa-envelope"></i> ' . (int) $a['emails'] : '' ?></small>
          <?php if ($a['follow_up_on'] && in_array($a['status'], ['applied', 'interview'], true)): ?><small class="due"><i class="fa-regular fa-bell"></i> Follow up <?= e(date('j M', strtotime($a['follow_up_on']))) ?></small><?php endif; ?>
          <?php if ($a['job_id']): ?><small><a href="job.php?id=<?= (int) $a['job_id'] ?>">View job</a><?= $a['status'] === 'saved' ? ' · <a href="apply.php?job=' . (int) $a['job_id'] . '">Apply</a>' : '' ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<section class="card">
  <table class="tbl">
    <thead><tr><th>Job</th><th>Status</th><th>Applied</th><th>Follow up</th><th>Emails</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($apps as $a): ?>
      <tr><td class="cell-main"><strong><?= e($a['title']) ?></strong><div class="muted"><?= e($a['company_name'] ?: '—') ?></div></td>
        <td data-label="Status"><?= statusTag($a['status']) ?></td>
        <td data-label="Applied" class="muted"><?= $a['applied_at'] ? e(date('j M Y', strtotime($a['applied_at']))) : '—' ?></td>
        <td data-label="Follow up" class="muted"><?= $a['follow_up_on'] ? e(date('j M Y', strtotime($a['follow_up_on']))) : '—' ?></td>
        <td data-label="Emails" class="num"><?= (int) $a['emails'] ?></td>
        <td class="num"><a class="btn-xs" href="tracker.php?view=table&edit=<?= (int) $a['id'] ?>">Edit</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$apps): ?><tr><td colspan="6"><div class="empty">Nothing here yet.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="card" id="add" style="margin-top:1.2rem;max-width:760px">
  <div class="card__head"><div><h2>Add an application</h2><p>Applied somewhere outside WorkFinder? Track it here too.</p></div></div>
  <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="add"><input type="hidden" name="view" value="<?= $view ?>">
    <div class="form-grid">
      <div class="field"><label>Job title</label><input name="title" required></div>
      <div class="field"><label>Company</label><input name="company_name"></div>
      <div class="field"><label>Status</label><select name="status"><?php foreach (APP_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'applied' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Notes</label><input name="notes"></div>
    </div>
    <button class="btn btn-primary">Add</button>
  </form>
</section>
<?php appClose();
