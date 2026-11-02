<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';

$db = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$off = ($page - 1) * 50;
$rows = $db->query("SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.admin_id ORDER BY a.created_at DESC LIMIT 50 OFFSET {$off}")->fetchAll();

adminOpen('audit', 'Audit log', 'Every admin action: who, what and when.');
?>
<section class="card">
  <table class="tbl">
    <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="muted" data-label="When"><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td><td data-label="Admin"><?= e($r['email'] ?: '—') ?></td>
        <td data-label="Action"><span class="tag tag-user"><?= e($r['action']) ?></span></td><td data-label="Target"><?= e($r['target']) ?></td>
        <td data-label="Detail" class="muted"><?= e(mb_strimwidth((string) $r['detail'], 0, 120, '…')) ?></td><td data-label="IP" class="muted"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty">Nothing yet.</div></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= pager($total, $page, 50, []) ?>
</section>
<?php adminClose();
