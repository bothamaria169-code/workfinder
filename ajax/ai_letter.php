<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
require_once dirname(__DIR__) . '/includes/ai.php';

$job     = !empty($_POST['job']) ? getJob((int) $_POST['job']) : null;
$company = !$job && !empty($_POST['company']) ? getCompany((int) $_POST['company']) : null;
if (!$job && !$company) jsonResponse(['ok' => false, 'error' => 'Pick a job or company first.'], 422);

set_time_limit(60);
jsonResponse(aiCoverLetter($AJAX_USER, $job, $company, (string) ($_POST['mode'] ?? 'write'), mb_substr((string) ($_POST['draft'] ?? ''), 0, 6000)));
