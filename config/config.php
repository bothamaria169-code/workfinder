<?php
declare(strict_types=1);

// ── Secrets ──────────────────────────────────────────────────────
// Passwords and keys live in config/secrets.php (not in git). Copy
// config/secrets.example.php to start one.
$secretsFile = __DIR__ . '/secrets.php';
$GLOBALS['WF_SECRETS'] = is_file($secretsFile) ? (require $secretsFile) : [];

function secret(string $key, mixed $default = ''): mixed
{
    return $GLOBALS['WF_SECRETS'][$key] ?? $default;
}

// ── App ──────────────────────────────────────────────────────────
define('APP_NAME', 'WorkFinder');
define('APP_TAGLINE', 'Find jobs, companies and skills — and apply by email in minutes');
define('BASE_DIR', dirname(__DIR__));

$host = $_SERVER['HTTP_HOST'] ?? '';
define('IS_LOCAL', $host === ''
    ? PHP_OS_FAMILY === 'Windows'
    : (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$|\.(localhost|test)(:\d+)?$/', $host));

// Set APP_URL in the environment when the site is behind a proxy or uses a
// custom domain. Otherwise derive it from the current host and install path,
// falling back to secrets.php's app_url for cron/CLI runs.
$appUrl = (string) getenv('APP_URL');
if ($appUrl === '' && $host !== '') {
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $basePath = '/WorkFinder';
    if ($documentRoot && realpath(BASE_DIR) !== false) {
        $relativePath = str_replace('\\', '/', substr(realpath(BASE_DIR), strlen($documentRoot)));
        $basePath = '/' . trim($relativePath, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $appUrl = $scheme . '://' . $host . ($basePath === '/' ? '' : $basePath);
}
if ($appUrl === '') {
    $appUrl = IS_LOCAL ? 'http://localhost/WorkFinder' : (string) secret('app_url', 'https://workfinder.swiftfusion.co.za');
}
define('APP_URL', rtrim($appUrl, '/'));

// The admin mailbox: owns the seeded admin account and receives a copy of
// every sign-up.
define('ADMIN_EMAIL', strtolower((string) secret('admin_email', 'admin@swiftfusion.co.za')));
define('APP_SECRET', (string) secret('app_secret', 'change-me'));

// ── Database ─────────────────────────────────────────────────────
// Live credentials on the server; the local_* ones on XAMPP.
define('DB_HOST', (string) secret('db_host', 'localhost'));
define('DB_NAME', (string) secret(IS_LOCAL ? 'local_db_name' : 'db_name', 'swiftfusionco_workfinder'));
define('DB_USER', (string) secret(IS_LOCAL ? 'local_db_user' : 'db_user', 'root'));
define('DB_PASS', (string) secret(IS_LOCAL ? 'local_db_pass' : 'db_pass', ''));

// ── File paths ───────────────────────────────────────────────────
// CVs are private: uploads/cvs is blocked by .htaccess and files are
// served only through cv.php to their owner or an admin.
define('CV_DIR', BASE_DIR . '/uploads/cvs/');
define('MAX_CV_SIZE', 5 * 1024 * 1024); // 5 MB

// ── Email / SMTP (PHPMailer) ──────────────────────────────────────
// secrets.php sets the defaults; Admin → Settings → Email can override them.
define('SMTP_HOST',     (string) secret('smtp_host', ''));
define('SMTP_PORT',     (int) secret('smtp_port', 465));
define('SMTP_USER',     (string) secret('smtp_user', ''));
define('SMTP_PASS',     (string) secret('smtp_pass', ''));
define('SMTP_FROM_NAME', APP_NAME);

if (!IS_LOCAL) {
    ini_set('display_errors', '0');
}
