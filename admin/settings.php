<?php
declare(strict_types=1);

require_once __DIR__ . '/_shell.php';
require_once BASE_DIR . '/classes/GoogleAuth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string) ($_POST['form'] ?? '');
    switch ($form) {
        case 'email':
            foreach (['smtp_host', 'smtp_port', 'smtp_user', 'mail_from_name', 'signup_notify_email'] as $k) set_setting($k, postText($k, 255));
            save_secret_setting('smtp_pass', (string) ($_POST['smtp_pass'] ?? ''));
            audit('settings_email');
            flash('success', 'Email settings saved. Send a test to check them.');
            break;
        case 'test':
            $to = filter_var($_POST['to'] ?? '', FILTER_VALIDATE_EMAIL) ?: $ADMIN['email'];
            $err = sendMail($to, APP_NAME . ' test email', emailLayout('It works ✅', '<p>This test was sent from ' . e(APP_URL) . ' at ' . date('H:i') . '. Application emails, copies and job alerts will be delivered the same way.</p>'));
            flash($err ? 'error' : 'success', $err ? 'Test failed: ' . $err : 'Test email sent to ' . $to . '. Check the inbox (and spam).');
            break;
        case 'apis':
            set_setting('adzuna_app_id', postText('adzuna_app_id', 100));
            foreach (['adzuna_app_key', 'jooble_key'] as $k) save_secret_setting($k, (string) ($_POST[$k] ?? ''));
            set_setting('careerjet_affid', postText('careerjet_affid', 100));
            audit('settings_apis');
            flash('success', 'Job API keys saved. Use Job sources → Sync now to test them.');
            break;
        case 'google':
            set_setting('google_client_id', postText('google_client_id', 255));
            save_secret_setting('google_client_secret', (string) ($_POST['google_client_secret'] ?? ''));
            set_setting('google_login_enabled', !empty($_POST['google_login_enabled']) ? '1' : '0');
            audit('settings_google');
            flash('success', 'Google sign-in saved.');
            break;
        case 'limits':
            set_setting('daily_email_limit', (string) max(1, (int) $_POST['daily_email_limit']));
            set_setting('max_alerts', (string) max(1, (int) $_POST['max_alerts']));
            set_setting('registrations_open', !empty($_POST['registrations_open']) ? '1' : '0');
            audit('settings_limits');
            flash('success', 'Saved.');
            break;
    }
    redirect('admin/settings.php');
}

$s = fn(string $k, string $d = '') => e(get_setting($k, $d));
$smtpOk = get_setting('smtp_pass', SMTP_PASS) !== '';
$cron = get_setting('cron_last_run');
$cronKey = (string) secret('cron_key', '');
adminOpen('settings', 'Settings', 'Email delivery, job APIs, sign-in and limits.');
?>
<div class="grid-2 even">
  <section class="card">
    <div class="card__head"><div><h2>Email delivery</h2><p>The mailbox that sends applications, copies and alerts.</p></div></div>
    <div class="card__body">
      <div class="status-line <?= $smtpOk ? 'ok' : 'off' ?>"><span class="dot"></span><?= $smtpOk ? 'SMTP is set up' : 'No SMTP password — using PHP mail(), which often lands in spam' ?></div>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="form" value="email">
        <div class="form-grid">
          <div class="field"><label>SMTP host</label><input name="smtp_host" value="<?= $s('smtp_host', SMTP_HOST) ?>" placeholder="mail.swiftfusion.co.za"></div>
          <div class="field"><label>Port</label><input name="smtp_port" value="<?= $s('smtp_port', (string) SMTP_PORT) ?>"></div>
          <div class="field"><label>Mailbox (username)</label><input name="smtp_user" value="<?= $s('smtp_user', SMTP_USER) ?>"></div>
          <div class="field"><label>Password</label><input type="password" name="smtp_pass" value="<?= e(masked(get_setting('smtp_pass', SMTP_PASS))) ?>" autocomplete="new-password"></div>
          <div class="field"><label>Sender name</label><input name="mail_from_name" value="<?= $s('mail_from_name', APP_NAME) ?>"></div>
          <div class="field"><label>Send new sign-up alerts to</label><input type="email" name="signup_notify_email" value="<?= $s('signup_notify_email', ADMIN_EMAIL) ?>"></div>
        </div>
        <button class="btn btn-primary">Save</button>
      </form>
      <form method="POST" class="toolbar" style="padding:1rem 0 0;border:0"><?= csrfField() ?><input type="hidden" name="form" value="test"><input class="input" type="email" name="to" value="<?= e($ADMIN['email']) ?>"><button class="btn-xs"><i class="fa-solid fa-paper-plane"></i> Send test email</button></form>
    </div>
  </section>

  <section class="card">
    <div class="card__head"><div><h2>Job APIs</h2><p>Free keys. Without them only Remotive/Arbeitnow (remote jobs), manual jobs and CSV imports are used.</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="apis">
      <div class="form-grid">
        <div class="field"><label>Adzuna App ID <span class="hint"><a href="https://developer.adzuna.com/" target="_blank" rel="noopener">get one</a></span></label><input name="adzuna_app_id" value="<?= $s('adzuna_app_id') ?>"></div>
        <div class="field"><label>Adzuna App Key</label><input type="password" name="adzuna_app_key" value="<?= e(masked(get_setting('adzuna_app_key'))) ?>" autocomplete="new-password"></div>
        <div class="field"><label>Careerjet affiliate ID <span class="hint"><a href="https://www.careerjet.co.za/partners/" target="_blank" rel="noopener">get one</a></span></label><input name="careerjet_affid" value="<?= $s('careerjet_affid') ?>"></div>
        <div class="field"><label>Jooble API key <span class="hint"><a href="https://jooble.org/api/about" target="_blank" rel="noopener">get one</a></span></label><input type="password" name="jooble_key" value="<?= e(masked(get_setting('jooble_key'))) ?>" autocomplete="new-password"></div>
      </div>
      <button class="btn btn-primary">Save keys</button> <a class="btn btn-ghost" href="sources.php">Job sources</a>
    </form>
  </section>

  <section class="card">
    <div class="card__head"><div><h2>Google sign-in</h2><p>Google Cloud Console → APIs & Services → Credentials → OAuth client ID (Web).</p></div></div>
    <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="google">
      <div class="status-line <?= GoogleAuth::enabled() ? 'ok' : 'off' ?>"><span class="dot"></span><?= GoogleAuth::enabled() ? 'On — the Google button shows on sign-in pages' : 'Off' ?></div>
      <label class="hint" style="display:block">Authorised redirect URI — paste exactly:</label>
      <div class="copy-box"><code><?= e(GoogleAuth::redirectUri()) ?></code><button type="button" class="btn-xs" data-copy="<?= e(GoogleAuth::redirectUri()) ?>"><i class="fa-regular fa-copy"></i></button></div>
      <div class="field"><label>Client ID</label><input name="google_client_id" value="<?= $s('google_client_id') ?>"></div>
      <div class="field"><label>Client secret</label><input type="password" name="google_client_secret" value="<?= e(masked(get_setting('google_client_secret'))) ?>" autocomplete="new-password"></div>
      <label class="check"><input type="checkbox" name="google_login_enabled" value="1" <?= get_setting('google_login_enabled', '1') === '1' ? 'checked' : '' ?>><span>Show “Continue with Google”</span></label>
      <button class="btn btn-primary">Save</button>
    </form>
  </section>

  <div>
    <section class="card">
      <div class="card__head"><div><h2>Limits & sign-ups</h2></div></div>
      <form method="POST" class="card__body"><?= csrfField() ?><input type="hidden" name="form" value="limits">
        <div class="form-grid">
          <div class="field"><label>Application emails per user per day</label><input type="number" min="1" name="daily_email_limit" value="<?= $s('daily_email_limit', '20') ?>"></div>
          <div class="field"><label>Job alerts per user</label><input type="number" min="1" name="max_alerts" value="<?= $s('max_alerts', '10') ?>"></div>
        </div>
        <label class="check"><input type="checkbox" name="registrations_open" value="1" <?= get_setting('registrations_open', '1') === '1' ? 'checked' : '' ?>><span>Allow new sign-ups</span></label>
        <button class="btn btn-primary">Save</button>
      </form>
    </section>
    <section class="card">
      <div class="card__head"><div><h2>Cron job</h2><p>Syncs jobs, sends alerts and reminders.</p></div></div>
      <div class="card__body">
        <div class="status-line <?= $cron && strtotime($cron) > time() - 3600 ? 'ok' : 'off' ?>"><span class="dot"></span><?= $cron ? 'Last ran ' . e(timeAgo($cron)) : 'Has not run yet' ?></div>
        <p class="hint">cPanel → Cron Jobs → every 15 minutes:</p>
        <div class="copy-box"><code>/usr/local/bin/php /home/swiftfusionco/workfinder.swiftfusion.co.za/cron.php &gt;/dev/null 2&gt;&amp;1</code></div>
        <?php if ($cronKey !== '' && $cronKey !== 'make-a-random-string'): ?><p class="hint">Or a URL cron: <code><?= e(APP_URL) ?>/cron.php?key=…</code> (key is <code>cron_key</code> in secrets.php)</p><?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php adminClose();
