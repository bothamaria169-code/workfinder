<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

$saved = toggleSaved((int) $AJAX_USER['id'], (int) ($_POST['job_id'] ?? 0));
jsonResponse(['ok' => true, 'saved' => $saved]);
