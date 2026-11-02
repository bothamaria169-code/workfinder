<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
pageHead('Terms of Use – ' . APP_NAME, ['noindex' => false]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="login.php">Sign in</a><a href="register.php" class="btn btn-primary">Start free</a></nav></div></header>
<main class="wrap prose">
  <h1>Terms of Use</h1>
  <p>Last updated <?= date('j F Y', filemtime(__FILE__)) ?>. By using <?= APP_NAME ?> you agree to these terms.</p>
  <h2>Using WorkFinder</h2>
  <ul>
    <li>Give true information in your profile and CV. You are responsible for every application you send.</li>
    <li>Use the apply-by-email feature only to apply for work. No spam, bulk mailing or messages unrelated to a job application. We limit how many emails you can send a day and may suspend accounts that misuse it.</li>
    <li>Keep your password safe. You're responsible for activity on your account.</li>
  </ul>
  <h2>Job adverts</h2>
  <p>Many adverts come from third-party job boards. We don't employ, screen or vouch for employers, and we can't guarantee an advert is accurate or still open. <strong>Never pay money to get a job</strong> — report adverts that ask for payment to <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a>.</p>
  <h2>Availability</h2>
  <p>We work hard to keep WorkFinder running, but it is provided “as is” and may change or be unavailable at times.</p>
  <h2>Contact</h2>
  <p><a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a></p>
</main>
</body>
</html>
