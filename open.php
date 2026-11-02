<?php
declare(strict_types=1);

// 1×1 pixel in application emails: records when the employer opens it.
require_once __DIR__ . '/config/db.php';

$t = (string) ($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $t)) {
    try {
        getDB()->prepare('UPDATE sent_emails SET open_count = open_count + 1, opened_at = COALESCE(opened_at, NOW()) WHERE open_token = ?')->execute([$t]);
    } catch (Throwable) {
    }
}
header('Content-Type: image/gif');
header('Cache-Control: no-store, max-age=0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
