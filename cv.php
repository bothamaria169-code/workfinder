<?php
declare(strict_types=1);

// CVs are private: only the owner or an admin can open one.
require_once __DIR__ . '/includes/auth.php';

startAppSession();
if (!isLoggedIn() || !($user = getCurrentUser())) redirect('login.php');

$s = getDB()->prepare('SELECT * FROM cvs WHERE id = ?');
$s->execute([(int) ($_GET['id'] ?? 0)]);
$cv = $s->fetch();
if (!$cv || ((int) $cv['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin') || !is_file(CV_DIR . $cv['file_name'])) {
    http_response_code(404);
    exit('CV not found.');
}
header('Content-Type: ' . ($cv['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize(CV_DIR . $cv['file_name']));
header('Content-Disposition: ' . ($cv['mime'] === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $cv['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile(CV_DIR . $cv['file_name']);
