<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
pageHead('Privacy Policy – ' . APP_NAME, ['noindex' => false]);
?>
<body>
<header class="site-nav"><div class="wrap"><?= brandMark() ?><nav><a href="login.php">Sign in</a><a href="register.php" class="btn btn-primary">Start free</a></nav></div></header>
<main class="wrap prose">
  <h1>Privacy Policy</h1>
  <p>Last updated <?= date('j F Y', filemtime(__FILE__)) ?>. <?= APP_NAME ?> is operated by SwiftFusion, South Africa. We process personal information in line with the Protection of Personal Information Act (POPIA).</p>

  <h2>What we collect</h2>
  <ul>
    <li><strong>Account details:</strong> your name, email address, phone and, if you use Google sign-in, your Google profile name and photo.</li>
    <li><strong>Your job profile:</strong> job titles, skills, industries, location, experience level, salary expectations and the short bio you write.</li>
    <li><strong>CVs</strong> you upload. They are stored privately and only opened by you, or by our admins when you ask for help.</li>
    <li><strong>Your job hunt:</strong> saved jobs, followed companies, application statuses and notes, job alerts.</li>
    <li><strong>Emails you send through WorkFinder:</strong> recipient, subject, cover letter, attached CV name and whether it was opened.</li>
    <li><strong>Usage data:</strong> sign-in times, IP address, browser, and how you found us.</li>
  </ul>

  <h2>How we use it</h2>
  <p>To match you with jobs, send the applications you ask us to send (with your CV) to the employer address you choose, send you copies and job alerts, keep the service secure, and improve WorkFinder. We send an application only when you press <em>Send</em>.</p>

  <h2>Who we share with</h2>
  <p>The employers you choose to apply to receive your cover letter and CV. Otherwise only the services needed to run WorkFinder: our hosting and email providers. We never sell your information.</p>

  <h2>Job listings</h2>
  <p>Jobs come from public job board APIs (such as Adzuna, Careerjet, Jooble and Remotive) and from employers. Adverts link to their original source.</p>

  <h2>Your rights</h2>
  <p>You can download all your data or delete your account at any time under <em>Account</em>. Deleting your account removes your profile, CVs, applications and sent-email history. You can also email <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a>.</p>

  <h2>Employers</h2>
  <p>If you don't want to receive applications by email through WorkFinder, use the link at the bottom of any application email, or email us, and we'll block your address.</p>
</main>
</body>
</html>
