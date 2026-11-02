<?php
declare(strict_types=1);

/**
 * Scheduled jobs: pulls fresh jobs from the job APIs (hourly), sends job
 * alerts, follow-up reminders, and tidies old security rows.
 *
 * cPanel → Cron Jobs, every 15 minutes:
 *   /usr/local/bin/php /home/swiftfusionco/workfinder.swiftfusion.co.za/cron.php >/dev/null 2>&1
 * or, from a URL cron service:
 *   https://workfinder.swiftfusion.co.za/cron.php?key=CRON_KEY
 */
require_once __DIR__ . '/sources/sources.php';
require_once __DIR__ . '/includes/applications.php';

$cronKey = (string) secret('cron_key', '');
if (PHP_SAPI !== 'cli' && ($cronKey === '' || !hash_equals($cronKey, (string) ($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit('Forbidden');
}
set_time_limit(280);

$log = [];
$last = get_setting('jobs_synced_at');
$every = max(15, (int) get_setting('sync_every_minutes', '60'));
if ($last === '' || strtotime($last) < time() - $every * 60) {
    set_setting('jobs_synced_at', date('Y-m-d H:i:s'));
    $log = array_merge($log, syncJobs());
}
if ($n = runAlerts()) $log[] = "Job alerts sent: {$n}";
if ((int) date('G') >= 8 && ($n = runFollowUpReminders())) $log[] = "Follow-up reminders: {$n}";

$db = getDB();
$db->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 90 DAY');
$db->exec('DELETE FROM password_resets WHERE created_at < NOW() - INTERVAL 7 DAY');
set_setting('cron_last_run', date('Y-m-d H:i:s'));

header('Content-Type: text/plain');
echo date('c') . ' ok' . ($log ? "\n" . implode("\n", $log) : '') . "\n";
