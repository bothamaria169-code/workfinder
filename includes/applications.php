<?php
declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

// ── Tracker ───────────────────────────────────────────────────────
/** The user's tracker row for a job (created as "saved" if missing). */
function applicationFor(int $uid, array $job): int
{
    $db = getDB();
    $s = $db->prepare('SELECT id FROM applications WHERE user_id = ? AND job_id = ?');
    $s->execute([$uid, $job['id']]);
    if ($id = $s->fetchColumn()) return (int) $id;
    $db->prepare('INSERT INTO applications (user_id, job_id, company_id, title, company_name) VALUES (?, ?, ?, ?, ?)')
       ->execute([$uid, $job['id'], $job['company_id'], $job['title'], $job['company_name']]);
    return (int) $db->lastInsertId();
}

function toggleSaved(int $uid, int $jobId): bool
{
    $db = getDB();
    $s = $db->prepare('DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?');
    $s->execute([$uid, $jobId]);
    if ($s->rowCount()) {
        // Unsaving removes it from the board only if nothing else happened with it.
        $db->prepare("DELETE FROM applications WHERE user_id = ? AND job_id = ? AND status = 'saved' AND (notes IS NULL OR notes = '')")->execute([$uid, $jobId]);
        return false;
    }
    $job = getJob($jobId);
    if (!$job) return false;
    $db->prepare('INSERT IGNORE INTO saved_jobs (user_id, job_id) VALUES (?, ?)')->execute([$uid, $jobId]);
    applicationFor($uid, $job);
    return true;
}

// ── Limits ────────────────────────────────────────────────────────
function dailyLimit(array $user): int
{
    return (int) ($user['daily_email_limit'] ?? 0) ?: (int) get_setting('daily_email_limit', '20');
}

function sentToday(int $uid): int
{
    $s = getDB()->prepare("SELECT COUNT(*) FROM sent_emails WHERE user_id = ? AND kind = 'application' AND status = 'sent' AND created_at >= CURDATE()");
    $s->execute([$uid]);
    return (int) $s->fetchColumn();
}

/** Why this application can't go out right now, or null when it can. */
function applyBlocker(array $user, string $to, ?int $jobId): ?string
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'That email address doesn\'t look right.';
    if (strtolower($to) === strtolower($user['email'])) return 'That\'s your own address — use the employer\'s email.';
    $db = getDB();
    $s = $db->prepare('SELECT 1 FROM email_optouts WHERE email = ?');
    $s->execute([strtolower($to)]);
    if ($s->fetchColumn()) return 'This employer has asked not to receive applications through WorkFinder. Use the job\'s website link instead.';
    if (sentToday((int) $user['id']) >= dailyLimit($user)) return 'You\'ve reached today\'s limit of ' . dailyLimit($user) . ' application emails. It resets at midnight.';
    $s = $db->prepare("SELECT 1 FROM sent_emails se LEFT JOIN applications a ON a.id = se.application_id
                       WHERE se.user_id = ? AND se.kind = 'application' AND se.status = 'sent' AND se.to_email = ?
                         AND se.created_at > NOW() - INTERVAL 7 DAY AND (a.job_id <=> ?)");
    $s->execute([$user['id'], strtolower($to), $jobId]);
    if ($s->fetchColumn()) return 'You already sent this application to that address in the last 7 days.';
    return null;
}

// ── Cover letter ──────────────────────────────────────────────────
function defaultCoverLetter(array $user, ?array $job, ?array $company = null): string
{
    $name    = $user['full_name'] ?: 'Your name';
    $role    = $job['title'] ?? ($user['job_titles'] ? trim(explode(',', $user['job_titles'])[0]) : 'a suitable position');
    $coName  = $job['company_name'] ?? ($company['name'] ?? 'your company');
    $skills  = getDB()->prepare('SELECT s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ? ORDER BY s.name LIMIT 6');
    $skills->execute([$user['id']]);
    $list    = implode(', ', $skills->fetchAll(PDO::FETCH_COLUMN));
    $source  = $job ? ' I saw the vacancy advertised and' : ' I';

    return "Dear Hiring Manager,\n\n"
        . "I would like to apply for the {$role} position at {$coName}.{$source} believe my experience is a strong fit.\n\n"
        . ($list ? "My key skills include {$list}. " : '')
        . ($user['headline'] ? "I currently work as {$user['headline']}. " : '')
        . "I am reliable, quick to learn and committed to doing excellent work.\n\n"
        . "Please find my CV attached. I would welcome the opportunity to discuss how I can contribute to your team, and I am available for an interview at your convenience.\n\n"
        . "Kind regards,\n{$name}\n" . ($user['phone'] ? $user['phone'] . "\n" : '') . $user['email'];
}

// ── Sending ───────────────────────────────────────────────────────
function logEmail(array $row): int
{
    $db = getDB();
    $db->prepare('INSERT INTO sent_emails (user_id, application_id, kind, to_email, subject, body, attachment_name, status, error, open_token)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$row['user_id'] ?? null, $row['application_id'] ?? null, $row['kind'], strtolower($row['to_email']), mb_substr($row['subject'], 0, 300),
                  $row['body'] ?? null, $row['attachment_name'] ?? null, $row['status'] ?? 'sent', $row['error'] ?? null, $row['open_token'] ?? null]);
    return (int) $db->lastInsertId();
}

function openPixel(string $token): string
{
    return '<img src="' . APP_URL . '/open.php?t=' . $token . '" width="1" height="1" alt="" style="display:block;border:0">';
}

/**
 * Sends one application from the user to an employer, from the app's
 * mailbox with Reply-To set to the user. On success it is logged, the job
 * moves to "Applied", and the user gets a copy plus a confirmation.
 *
 * @return array{ok: bool, error?: string, id?: int}
 */
function sendApplication(array $user, string $to, string $subject, string $letter, ?array $cv, ?array $job, ?array $company = null): array
{
    $to = strtolower(trim($to));
    if ($why = applyBlocker($user, $to, $job ? (int) $job['id'] : null)) return ['ok' => false, 'error' => $why];
    $subject = trim($subject) ?: 'Application';
    $letter  = trim($letter);
    if (mb_strlen($letter) < 40) return ['ok' => false, 'error' => 'Please write a short cover letter (at least a few sentences).'];

    $db  = getDB();
    $uid = (int) $user['id'];
    $appId = $job ? applicationFor($uid, $job) : null;
    if (!$job && $company) {
        $db->prepare('INSERT INTO applications (user_id, company_id, title, company_name) VALUES (?, ?, ?, ?)')
           ->execute([$uid, $company['id'], 'Open application', $company['name']]);
        $appId = (int) $db->lastInsertId();
    }

    $token  = bin2hex(random_bytes(16));
    $optout = APP_URL . '/unsubscribe.php?e=' . rawurlencode($to) . '&s=' . substr(hash_hmac('sha256', $to, APP_SECRET), 0, 24);
    $html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#0e1726;max-width:640px">'
          . nl2br(e($letter))
          . '<p style="margin-top:26px;font-size:12px;color:#8390a3;border-top:1px solid #e4e8f1;padding-top:10px">Sent by ' . e($user['full_name'] ?: $user['email'])
          . ' via <a href="' . APP_URL . '" style="color:#8390a3">' . APP_NAME . '</a>. Reply to this email to reach the applicant directly. '
          . '<a href="' . e($optout) . '" style="color:#8390a3">Don\'t want applications by email?</a></p>'
          . openPixel($token) . '</div>';

    $files = [];
    $cvPath = $cv ? CV_DIR . $cv['file_name'] : null;
    if ($cvPath && is_file($cvPath)) $files[] = [$cvPath, $cv['original_name']];

    $error = sendMailAs($to, $subject, $html, $files, $user['email'], $user['full_name'] ?: $user['email']);
    $id = logEmail([
        'user_id' => $uid, 'application_id' => $appId, 'kind' => 'application', 'to_email' => $to, 'subject' => $subject,
        'body' => $letter, 'attachment_name' => $files ? $cv['original_name'] : null,
        'status' => $error === '' ? 'sent' : 'failed', 'error' => $error ?: null, 'open_token' => $token,
    ]);
    if ($error !== '') return ['ok' => false, 'error' => 'The email could not be sent: ' . $error, 'id' => $id];

    if ($appId) {
        $db->prepare("UPDATE applications SET status = IF(status IN ('saved'), 'applied', status), applied_at = COALESCE(applied_at, NOW()),
                      follow_up_on = COALESCE(follow_up_on, CURDATE() + INTERVAL 7 DAY) WHERE id = ?")->execute([$appId]);
    }

    // The user's copy of exactly what was sent, plus the confirmation.
    $where = $job ? e($job['title']) . ' at ' . e($job['company_name'] ?: 'the company') : e($company['name'] ?? $to);
    $copy = emailLayout('Your application was sent ✅',
        '<p>Your application for <strong>' . $where . '</strong> was delivered to <strong>' . e($to) . '</strong> on ' . date('j M Y \a\t H:i') . '.</p>'
        . emailRows(['To' => $to, 'Subject' => $subject, 'CV attached' => $files ? $cv['original_name'] : 'No CV attached', 'Replies go to' => $user['email']])
        . '<p style="margin-top:20px"><strong>Your email, as sent:</strong></p>'
        . '<div style="background:#f7f8fc;border:1px solid #e3e8f2;border-radius:10px;padding:14px;font-size:14px">' . nl2br(e($letter)) . '</div>'
        . '<p style="color:#4a5768;font-size:13px">We\'ll remind you to follow up in 7 days if you haven\'t heard back.</p>',
        'View my applications', APP_URL . '/tracker.php');
    $copyErr = sendMail($user['email'], 'Sent: ' . $subject, $copy, $files ? [$files[0][0]] : []);
    logEmail(['user_id' => $uid, 'application_id' => $appId, 'kind' => 'copy', 'to_email' => $user['email'], 'subject' => 'Sent: ' . $subject,
              'status' => $copyErr === '' ? 'sent' : 'failed', 'error' => $copyErr ?: null]);

    return ['ok' => true, 'id' => $id];
}

/**
 * Like sendMail(), but shows the applicant's name as the sender and lets
 * attachments keep their original file names.
 *
 * @param array<int, array{0: string, 1: string}> $files [path, display name]
 */
function sendMailAs(string $to, string $subject, string $html, array $files, string $replyTo, string $fromName): string
{
    $host = get_setting('smtp_host', SMTP_HOST);
    $port = (int) get_setting('smtp_port', (string) SMTP_PORT);
    $user = get_setting('smtp_user', SMTP_USER);
    $pass = get_setting('smtp_pass', SMTP_PASS);
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        if ($host !== '' && $pass !== '') {
            $mail->isSMTP();
            $mail->Host = $host; $mail->Port = $port; $mail->SMTPAuth = true;
            $mail->Username = $user; $mail->Password = $pass; $mail->Timeout = 20;
            $mail->SMTPSecure = $port === 465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->isMail();
        }
        $mail->setFrom($user !== '' ? $user : ADMIN_EMAIL, mb_substr($fromName, 0, 60) . ' via ' . APP_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo($replyTo, $fromName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br />', '<br>', '</p>'], "\n", $html))));
        foreach ($files as [$path, $name]) $mail->addAttachment($path, $name);
        $mail->send();
        return '';
    } catch (Throwable $e) {
        error_log('WorkFinder application mail to ' . $to . ' failed: ' . $e->getMessage());
        return $e->getMessage();
    }
}

// ── Job alerts ────────────────────────────────────────────────────
/**
 * Emails each due saved search its new matching jobs (daily or weekly).
 *
 * @return int alerts sent
 */
function runAlerts(int $max = 100): int
{
    $db = getDB();
    $due = $db->query("SELECT ss.*, u.email, u.full_name FROM saved_searches ss JOIN users u ON u.id = ss.user_id
                       WHERE u.is_active = 1 AND ss.frequency <> 'off'
                         AND (ss.last_sent_at IS NULL
                              OR (ss.frequency = 'daily'  AND ss.last_sent_at < NOW() - INTERVAL 23 HOUR)
                              OR (ss.frequency = 'weekly' AND ss.last_sent_at < NOW() - INTERVAL 7 DAY))
                       ORDER BY ss.last_sent_at IS NOT NULL, ss.last_sent_at LIMIT {$max}")->fetchAll();
    $sent = 0;
    foreach ($due as $a) {
        $f = json_decode((string) $a['query'], true) ?: [];
        $f['since'] = $a['last_sent_at'] ?: date('Y-m-d H:i:s', strtotime($a['frequency'] === 'weekly' ? '-7 days' : '-1 day'));
        [$jobs, $total] = searchJobs($f, 1, 10);
        $db->prepare('UPDATE saved_searches SET last_sent_at = NOW() WHERE id = ?')->execute([$a['id']]);
        if (!$jobs) continue;

        $rows = '';
        foreach ($jobs as $j) {
            $pay = formatRand($j['salary_min'] ? (int) $j['salary_min'] : null, $j['salary_max'] ? (int) $j['salary_max'] : null);
            $rows .= '<div style="border:1px solid #e3e8f2;border-radius:12px;padding:12px 14px;margin-bottom:10px">'
                   . '<a href="' . APP_URL . '/job.php?id=' . (int) $j['id'] . '" style="font-weight:700;color:#0e1726;text-decoration:none;font-size:15px">' . e($j['title']) . '</a>'
                   . '<div style="color:#4a5768;font-size:13px">' . e($j['company_name'] ?: '') . ' · ' . e($j['location'] ?: 'South Africa') . ($pay ? ' · ' . e($pay) . ' pm' : '') . '</div>'
                   . '<div style="margin-top:6px;font-size:13px"><a href="' . APP_URL . '/job.php?id=' . (int) $j['id'] . '" style="color:#0d9488">View</a> · '
                   . '<a href="' . APP_URL . '/apply.php?job=' . (int) $j['id'] . '" style="color:#0d9488">Apply by email</a></div></div>';
        }
        $html = emailLayout(e((string) $total) . ' new ' . ($total === 1 ? 'job' : 'jobs') . ' for “' . e($a['name']) . '”',
            $rows . ($total > count($jobs) ? '<p>…and ' . ($total - count($jobs)) . ' more.</p>' : ''),
            'See all matching jobs', APP_URL . '/jobs.php?' . http_build_query(json_decode((string) $a['query'], true) ?: []),
            '<p style="font-size:12px;color:#8390a3;margin-top:18px">You get this ' . e($a['frequency']) . '. <a href="' . APP_URL . '/alerts.php" style="color:#8390a3">Change or stop alerts</a>.</p>');
        $err = sendMail($a['email'], $total . ' new jobs: ' . $a['name'], $html);
        logEmail(['user_id' => $a['user_id'], 'kind' => 'alert', 'to_email' => $a['email'], 'subject' => $total . ' new jobs: ' . $a['name'],
                  'status' => $err === '' ? 'sent' : 'failed', 'error' => $err ?: null]);
        if ($err === '') $sent++;
    }
    return $sent;
}

/** Reminds users about applications whose follow-up date is today. */
function runFollowUpReminders(): int
{
    $db = getDB();
    if (get_setting('followups_sent_on') === date('Y-m-d')) return 0;
    set_setting('followups_sent_on', date('Y-m-d'));
    $rows = $db->query("SELECT a.*, u.email, u.full_name FROM applications a JOIN users u ON u.id = a.user_id
                        WHERE u.is_active = 1 AND a.follow_up_on = CURDATE() AND a.status IN ('applied','interview')")->fetchAll();
    $byUser = [];
    foreach ($rows as $r) $byUser[$r['email']][] = $r;
    foreach ($byUser as $email => $apps) {
        $list = '';
        foreach ($apps as $a) $list .= '<li><strong>' . e($a['title']) . '</strong> — ' . e($a['company_name'] ?: '') . ' (applied ' . date('j M', strtotime((string) $a['applied_at'])) . ')</li>';
        sendMail($email, 'Time to follow up on ' . count($apps) . ' application' . (count($apps) > 1 ? 's' : ''),
            emailLayout('Follow-up reminder', '<p>A short, polite follow-up a week after applying makes you stand out:</p><ul>' . $list . '</ul>', 'Open my applications', APP_URL . '/tracker.php'));
    }
    return count($byUser);
}
