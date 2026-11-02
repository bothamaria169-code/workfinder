<?php
declare(strict_types=1);

/**
 * Shared <head> for every page: fonts, icons, the design system and the
 * theme (light / dark, remembered per browser).
 */
function pageHead(string $title, array $opt = []): void
{
    $css    = $opt['css'] ?? ['style.css'];
    $robots = $opt['noindex'] ?? true;
    $desc   = $opt['description'] ?? APP_TAGLINE;
    $v      = '?v=' . (string) @filemtime(BASE_DIR . '/assets/css/app.css');
    ?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <?php if ($robots): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <meta name="theme-color" content="#0e1726">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= e(APP_URL) ?>">
  <link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/img/mark.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <?php foreach ($css as $file): ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/<?= e($file) . $v ?>">
  <?php endforeach; ?>
  <script>try{var t=localStorage.getItem('wf-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<?php
}

function brandMark(string $class = ''): string
{
    return '<a href="' . APP_URL . '/" class="brand ' . e($class) . '" aria-label="' . APP_NAME . ' home">'
         . '<img src="' . APP_URL . '/assets/img/mark.svg" alt="" class="brand__mark" width="32" height="32">'
         . '<span class="brand__word">Work<em>Finder</em></span></a>';
}

function flashHtml(): string
{
    $out = '';
    foreach (takeFlashes() as [$type, $msg]) {
        $icon = $type === 'error' ? 'fa-circle-exclamation' : ($type === 'success' ? 'fa-circle-check' : 'fa-circle-info');
        $out .= '<div class="alert alert-' . e($type) . '"><i class="fa-solid ' . $icon . '"></i><span>' . e($msg) . '</span></div>';
    }
    return $out;
}

/** Split-screen shell for sign-in, sign-up and reset pages. */
function authOpen(string $title, string $heading, string $sub): void
{
    pageHead($title . ' – ' . APP_NAME);
    ?>
<body class="auth">
  <aside class="auth__brand">
    <?= brandMark('brand--light') ?>
    <div class="auth__pitch">
      <h2>Your next job,<br><span>one search away.</span></h2>
      <p>Jobs, companies, industries and skills across South Africa in one place. Apply by email in minutes — and keep a copy of everything you send.</p>
      <div class="auth__demo" aria-hidden="true">
        <div class="demo-file"><i class="fa-solid fa-briefcase" style="color:var(--brand)"></i><div><strong>Junior PHP Developer</strong><small>Sandton · R25k – R32k · 92% match</small></div><i class="fa-solid fa-star" style="color:var(--amber)"></i></div>
        <div class="demo-bubble me">Apply by email</div>
        <div class="demo-file"><i class="fa-solid fa-paper-plane" style="color:var(--mint)"></i><div><strong>Application sent to careers@…</strong><small>CV attached · copy sent to you · just now</small></div><i class="fa-solid fa-check-double"></i></div>
      </div>
      <ul class="auth__ticks">
        <li><i class="fa-solid fa-check"></i>Jobs from trusted South African and remote job boards</li>
        <li><i class="fa-solid fa-check"></i>Skills match on every job</li>
        <li><i class="fa-solid fa-check"></i>Free to start, no card needed</li>
      </ul>
    </div>
  </aside>
  <main class="auth__main">
    <div class="auth__card">
      <div class="auth__mobile-brand"><?= brandMark() ?></div>
      <h1><?= e($heading) ?></h1>
      <p class="auth__sub"><?= $sub ?></p>
      <?= flashHtml() ?>
<?php
}

function authClose(): void
{
    ?>
    </div>
    <p class="auth__legal">© <?= date('Y') ?> <?= APP_NAME ?> · <a href="<?= APP_URL ?>/privacy.php">Privacy</a> · <a href="<?= APP_URL ?>/terms.php">Terms</a></p>
  </main>
  <script>
    document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = document.getElementById(b.dataset.togglePw);
        i.type = i.type === 'password' ? 'text' : 'password';
        b.innerHTML = i.type === 'password' ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
      });
    });
    document.querySelectorAll('form[data-busy]').forEach(function (f) {
      f.addEventListener('submit', function () { var b = f.querySelector('[type=submit]'); if (b) { b.disabled = true; b.classList.add('is-busy'); } });
    });
  </script>
</body>
</html>
<?php
}

function googleButton(string $label = 'Continue with Google'): string
{
    require_once BASE_DIR . '/classes/GoogleAuth.php';
    if (!GoogleAuth::enabled()) return '';
    return '<a class="btn btn-google" href="' . APP_URL . '/auth/google.php">'
         . '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>'
         . e($label) . '</a><div class="divider"><span>or</span></div>';
}

/** Initials tile used in place of company logos. */
function coLogo(?string $name, string $class = ''): string
{
    $words = preg_split('/\s+/', trim((string) $name)) ?: [];
    $ini = strtoupper(mb_substr($words[0] ?? '?', 0, 1) . mb_substr($words[1] ?? '', 0, 1));
    $hue = crc32((string) $name) % 360;
    return '<div class="co-logo ' . e($class) . '" style="background:hsl(' . $hue . ' 70% 94%);color:hsl(' . $hue . ' 55% 35%)" aria-hidden="true">' . e($ini ?: '?') . '</div>';
}

function statusTag(string $status): string
{
    $label = APP_STATUSES[$status] ?? ucfirst($status);
    return '<span class="tag st-' . e($status) . '">' . e($label) . '</span>';
}

// ── Job seeker app shell ──────────────────────────────────────────
/** Same sidebar + top bar layout as the admin dashboard, with the job seeker's menu. */
function appOpen(string $active, string $title, string $sub = '', string $actions = ''): void
{
    $user = getCurrentUser();
    $db   = getDB();
    $uid  = (int) $user['id'];
    $due  = $db->prepare("SELECT COUNT(*) FROM applications WHERE user_id = ? AND follow_up_on IS NOT NULL AND follow_up_on <= CURDATE() AND status IN ('applied','interview')");
    $due->execute([$uid]);
    $saved = $db->prepare('SELECT COUNT(*) FROM saved_jobs WHERE user_id = ?');
    $saved->execute([$uid]);

    $nav = [
        'Find' => [
            ['dashboard', 'dashboard.php', 'fa-gauge-high', 'Dashboard', 0],
            ['jobs',      'jobs.php',      'fa-magnifying-glass', 'Jobs', 0],
            ['companies', 'companies.php', 'fa-building', 'Companies', 0],
            ['industries','industries.php','fa-layer-group', 'Industries', 0],
            ['skills',    'skills.php',    'fa-wand-magic-sparkles', 'Skills', 0],
        ],
        'My hunt' => [
            ['saved',     'saved.php',     'fa-star', 'Saved jobs', (int) $saved->fetchColumn()],
            ['tracker',   'tracker.php',   'fa-table-columns', 'Applications', (int) $due->fetchColumn()],
            ['emails',    'emails.php',    'fa-paper-plane', 'Sent emails', 0],
            ['alerts',    'alerts.php',    'fa-bell', 'Job alerts', 0],
        ],
        'Me' => [
            ['profile',   'profile.php',   'fa-id-card', 'Profile & CV', 0],
            ['account',   'account.php',   'fa-user-gear', 'Account', 0],
        ],
    ];
    if (get_setting('bill_enabled', '1') === '1') {
        $isPro = ($user['plan'] ?? 'free') === 'pro' && !empty($user['plan_expires_at']) && strtotime($user['plan_expires_at']) > time();
        $label = ($user['plan'] ?? '') === 'lifetime' ? 'Plan: Lifetime' : ($isPro ? 'Plan: Pro' : 'Plan & credits');
        $nav['Me'][] = ['billing', 'billing.php', 'fa-gem', $label, 0];
    }
    $name = $user['full_name'] ?: $user['email'];
    pageHead($title . ' – ' . APP_NAME, ['css' => ['style.css', 'admin.css', 'app.css']]);
    ?>
<body class="adm">
<div class="adm-scrim" id="admScrim"></div>
<aside class="adm-side" id="admSide">
  <?= brandMark('brand--light') ?>
  <?php foreach ($nav as $group => $links): ?>
    <div class="adm-side__label"><?= e($group) ?></div>
    <?php foreach ($links as [$slug, $href, $icon, $label, $count]): ?>
      <a href="<?= APP_URL . '/' . $href ?>" class="<?= $active === $slug ? 'is-active' : '' ?>">
        <i class="fa-solid <?= $icon ?>"></i><span><?= e($label) ?></span>
        <?php if ($count): ?><b class="n"><?= (int) $count ?></b><?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="adm-side__foot">
    <?php if ($user['role'] === 'admin'): ?><a href="<?= APP_URL ?>/admin/"><i class="fa-solid fa-shield-halved"></i><span>Admin dashboard</span></a><?php endif; ?>
    <?php if (!empty($_SESSION['impersonator_id'])): ?><a href="<?= APP_URL ?>/admin/users.php?stop_impersonating=1"><i class="fa-solid fa-user-secret"></i><span>Back to my admin account</span></a><?php endif; ?>
  </div>
</aside>

<div class="adm-main">
  <header class="adm-top">
    <button class="icon-btn adm-burger" id="admBurger" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
    <form class="adm-search" action="<?= APP_URL ?>/jobs.php" method="get" role="search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" placeholder="Search jobs, skills or companies…" value="<?= e($_GET['q'] ?? '') ?>">
    </form>
    <div class="adm-top__right">
      <button class="icon-btn" id="themeBtn" title="Switch light / dark"><i class="fa-solid fa-circle-half-stroke"></i></button>
      <div class="adm-me">
        <div class="avatar"><?php if (!empty($user['avatar_url'])): ?><img src="<?= e($user['avatar_url']) ?>" alt=""><?php else: ?><?= e(strtoupper(mb_substr($name, 0, 1))) ?><?php endif; ?></div>
        <div class="adm-me__txt"><strong><?= e($name) ?></strong><small><?= e($user['headline'] ?: 'Job seeker') ?></small></div>
      </div>
      <form method="POST" action="<?= APP_URL ?>/logout.php"><?= csrfField() ?><button class="icon-btn" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button></form>
    </div>
  </header>

  <main class="adm-body">
    <?php if (!empty($_SESSION['impersonator_id'])): ?>
      <div class="alert alert-info"><i class="fa-solid fa-user-secret"></i><span>You're viewing WorkFinder as <strong><?= e($user['email']) ?></strong>. <a href="<?= APP_URL ?>/admin/users.php?stop_impersonating=1">Return to admin</a></span></div>
    <?php endif; ?>
    <div class="adm-head">
      <div><h1><?= e($title) ?></h1><?php if ($sub): ?><p><?= $sub ?></p><?php endif; ?></div>
      <?php if ($actions): ?><div class="adm-head__actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?= flashHtml() ?>
<?php
}

function appClose(string $scripts = ''): void
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

/** One job as a card: company tile, title, meta, skills (matched ones in green), match ring and actions. */
function jobCard(array $j, array $mySkillIds = [], array $savedIds = []): string
{
    $skills = $j['skills'] ?? [];
    $have   = array_filter($skills, fn($s) => in_array((int) $s['id'], $mySkillIds, true));
    $pct    = $skills && $mySkillIds ? (int) round(count($have) / count($skills) * 100) : null;
    $saved  = in_array((int) $j['id'], $savedIds, true);
    $url    = APP_URL . '/job.php?id=' . (int) $j['id'];
    $meta   = [];
    if ($j['location']) $meta[] = '<span><i class="fa-solid fa-location-dot"></i>' . e($j['location']) . '</span>';
    if ($j['is_remote']) $meta[] = '<span><i class="fa-solid fa-house-laptop"></i>Remote</span>';
    $meta[] = '<span><i class="fa-regular fa-clock"></i>' . e(WORK_TYPES[$j['work_type']] ?? 'Full-time') . '</span>';
    if ($pay = formatRand($j['salary_min'] ? (int) $j['salary_min'] : null, $j['salary_max'] ? (int) $j['salary_max'] : null)) $meta[] = '<span><i class="fa-solid fa-money-bill-wave"></i>' . e($pay) . ' pm</span>';
    $meta[] = '<span><i class="fa-regular fa-calendar"></i>' . e(timeAgo($j['posted_at'])) . '</span>';

    $chips = '';
    foreach (array_slice($skills, 0, 8) as $s) {
        $on = in_array((int) $s['id'], $mySkillIds, true);
        $chips .= '<a class="skill ' . ($on ? 'have' : '') . '" href="' . APP_URL . '/jobs.php?skill=' . (int) $s['id'] . '">' . ($on ? '<i class="fa-solid fa-check"></i>' : '') . e($s['name']) . '</a>';
    }

    return '<article class="job ' . ($j['is_featured'] ? 'is-featured' : '') . '">'
        . coLogo($j['company_name'])
        . '<div><h3><a href="' . $url . '">' . e($j['title']) . '</a>' . ($j['is_featured'] ? ' <span class="tag tag-google">Featured</span>' : '') . '</h3>'
        . '<div class="job__co">' . ($j['company_id'] ? '<a href="' . APP_URL . '/company.php?id=' . (int) $j['company_id'] . '">' . e($j['company_name']) . '</a>' : e($j['company_name'] ?: 'Company not listed')) . '</div>'
        . '<div class="job__meta">' . implode('', $meta) . '</div>'
        . '<p class="job__desc">' . e(excerpt($j['description'], 170)) . '</p>'
        . ($chips ? '<div class="skill-chips">' . $chips . '</div>' : '')
        . '</div><div class="job__side">'
        . ($pct !== null ? '<div class="match" style="--p:' . $pct . '" title="Your skills match"><span>' . $pct . '%</span></div>' : '')
        . '<div class="row-actions"><button class="btn-xs save-btn ' . ($saved ? 'is-on' : '') . '" data-save="' . (int) $j['id'] . '"><i class="fa-' . ($saved ? 'solid' : 'regular') . ' fa-star"></i>' . ($saved ? 'Saved' : 'Save') . '</button>'
        . '<a class="btn-xs" href="' . APP_URL . '/apply.php?job=' . (int) $j['id'] . '"><i class="fa-solid fa-paper-plane"></i>Apply</a></div>'
        . '<small class="muted" style="font-size:.72rem;color:var(--text-3)">via ' . e(ucfirst($j['source'])) . '</small>'
        . '</div></article>';
}
