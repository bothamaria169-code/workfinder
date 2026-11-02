<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$uid = (int) $AJAX_USER['id'];
$cid = (int) ($_POST['company_id'] ?? 0);
$db  = getDB();
$del = $db->prepare('DELETE FROM followed_companies WHERE user_id = ? AND company_id = ?');
$del->execute([$uid, $cid]);
if ($del->rowCount()) jsonResponse(['ok' => true, 'following' => false]);
$db->prepare('INSERT IGNORE INTO followed_companies (user_id, company_id) SELECT ?, id FROM companies WHERE id = ?')->execute([$uid, $cid]);
jsonResponse(['ok' => true, 'following' => true]);
