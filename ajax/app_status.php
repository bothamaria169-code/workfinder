<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$status = (string) ($_POST['status'] ?? '');
if (!isset(APP_STATUSES[$status])) jsonResponse(['ok' => false, 'error' => 'Unknown status.'], 422);
$s = getDB()->prepare("UPDATE applications SET status = ?, applied_at = IF(? <> 'saved', COALESCE(applied_at, NOW()), applied_at) WHERE id = ? AND user_id = ?");
$s->execute([$status, $status, (int) ($_POST['id'] ?? 0), (int) $AJAX_USER['id']]);
jsonResponse(['ok' => true]);
