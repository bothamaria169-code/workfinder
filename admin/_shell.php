<?php
declare(strict_types=1);

/**
 * Admin dashboard shell: sidebar, top bar and page header. Pages call
 * adminOpen() after handling their POSTs (which redirect), then adminClose().
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

startAppSession();

// "Log in as" sends an admin into a user's account; this brings them back.
if (isset($_GET['stop_impersonating']) && !empty($_SESSION['impersonator_id'])) {
    $adminId = (int) $_SESSION['impersonator_id'];
    $s = getDB()->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
    $s->execute([$adminId]);
    if ($admin = $s->fetch()) {
        audit('impersonate_end', 'user #' . (int) $_SESSION['user_id']);
        unset($_SESSION['impersonator_id']);
        beginUserSession($admin);
        redirect('admin/users.php');
    }
}

requireAdmin();
$ADMIN = getCurrentUser();

/** Every admin POST must carry the CSRF token. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}

function adminOpen(string $active, string $title, string $sub = '', string $actions = ''): void
{
    global $ADMIN;
    $db = getDB();
    $newUsers = (int) $db->query('SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 1 DAY)')->fetchColumn();
    $vouchers = (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
    $failed   = (int) $db->query("SELECT COUNT(*) FROM sent_emails WHERE status = 'failed' AND created_at > (NOW() - INTERVAL 1 DAY)")->fetchColumn();

    $nav = [
        'Overview' => [
            ['dashboard', 'index.php',     'fa-gauge-high', 'Dashboard', 0],
            ['users',     'users.php',     'fa-users',      'Users',     $newUsers],
            ['emails',    'emails.php',    'fa-envelope-open-text', 'Emails', $failed],
            ['payments',  'payments.php',  'fa-money-bill-wave', 'Payments', $vouchers],
        ],
        'Content' => [
            ['jobs',      'jobs.php',      'fa-briefcase',  'Jobs', 0],
            ['companies', 'companies.php', 'fa-building',   'Companies', 0],
            ['taxonomy',  'taxonomy.php',  'fa-tags',       'Industries & skills', 0],
            ['sources',   'sources.php',   'fa-plug',       'Job sources', 0],
        ],
        'Setup' => [
            ['settings', 'settings.php', 'fa-sliders',   'Settings',   0],
            ['audit',    'audit.php',    'fa-clock-rotate-left', 'Audit log', 0],
            ['account',  'account.php',  'fa-user-gear', 'My account', 0],
        ],
    ];
    $name = $ADMIN['full_name'] ?: $ADMIN['email'];
    pageHead($title . ' – Admin – ' . APP_NAME, ['css' => ['style.css', 'admin.css', 'app.css']]);
    ?>
<body class="adm">
<div class="adm-scrim" id="admScrim"></div>
<aside class="adm-side" id="admSide">
  <?= brandMark('brand--light') ?>
  <?php foreach ($nav as $group => $links): ?>
    <div class="adm-side__label"><?= e($group) ?></div>
    <?php foreach ($links as [$slug, $href, $icon, $label, $count]): ?>
      <a href="<?= $href ?>" class="<?= $active === $slug ? 'is-active' : '' ?>">
        <i class="fa-solid <?= $icon ?>"></i><span><?= e($label) ?></span>
        <?php if ($count): ?><b class="n"><?= (int) $count ?></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="adm-side__foot">
    <a href="<?= APP_URL ?>/dashboard.php"><i class="fa-solid fa-briefcase"></i><span>Open the job seeker app</span></a>
    <a href="<?= APP_URL ?>/" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>View website</span></a>
  </div>
</aside>

<div class="adm-main">
  <header class="adm-top">
    <button class="icon-btn adm-burger" id="admBurger" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
    <form class="adm-search" action="users.php" method="get" role="search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" placeholder="Search users by email or name…" value="<?= e($_GET['q'] ?? '') ?>">
    </form>
    <div class="adm-top__right">
      <button class="icon-btn" id="themeBtn" title="Switch light / dark"><i class="fa-solid fa-circle-half-stroke"></i></button>
      <div class="adm-me">
        <div class="avatar"><?= e(strtoupper(mb_substr($name, 0, 1))) ?></div>
        <div class="adm-me__txt"><strong><?= e($name) ?></strong><small>Administrator</small></div>
      </div>
      <form method="POST" action="<?= APP_URL ?>/logout.php"><?= csrfField() ?><button class="icon-btn" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button></form>
    </div>
  </header>

  <main class="adm-body">
    <div class="adm-head">
      <div><h1><?= e($title) ?></h1><?php if ($sub): ?><p><?= $sub ?></p><?php endif; ?></div>
      <?php if ($actions): ?><div class="adm-head__actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?= flashHtml() ?>
<?php
}

function adminClose(string $scripts = ''): void
{
    ?>
  </main>
</div>
<div class="toast" id="toast"></div>
<script>window.WF = { url: <?= json_encode(APP_URL) ?>, csrf: <?= json_encode(csrfToken()) ?> };</script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= (string) @filemtime(BASE_DIR . '/assets/js/app.js') ?>"></script>
<?= $scripts ?>
</body>
</html>
<?php
}
