<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/jobs.php';
require_once __DIR__ . '/includes/layout.php';

startAppSession();
captureAttribution();
if (isLoggedIn() && getCurrentUser()) { redirect(isAdmin() ? 'admin/' : 'dashboard.php'); }

$db = getDB();
$jobsCount = (int) $db->query('SELECT COUNT(*) FROM jobs WHERE is_active = 1')->fetchColumn();
$coCount   = (int) $db->query('SELECT COUNT(*) FROM companies')->fetchColumn();
$newToday  = (int) $db->query('SELECT COUNT(*) FROM jobs WHERE is_active = 1 AND created_at >= CURDATE()')->fetchColumn();
$industries = $db->query('SELECT i.*, (SELECT COUNT(*) FROM jobs j WHERE j.industry_id = i.id AND j.is_active = 1) AS n FROM industries i ORDER BY n DESC, i.name LIMIT 8')->fetchAll();
$latest = $db->query('SELECT id, title, company_name, location, posted_at FROM jobs WHERE is_active = 1 ORDER BY posted_at DESC LIMIT 4')->fetchAll();

pageHead(APP_NAME . ' — Find jobs in South Africa and apply by email', [
    'noindex' => false,
    'description' => 'Search jobs, companies, industries and skills across South Africa. See how well you match, apply by email with your CV in minutes, and keep a copy of every application.',
    'css' => ['style.css', 'admin.css', 'app.css'],
]);
?>
<body>
<header class="site-nav">
  <div class="wrap">
    <?= brandMark() ?>
    <nav>
      <a href="#features" class="hide-sm">Features</a>
      <a href="#how" class="hide-sm">How it works</a>
      <a href="login.php">Sign in</a>
      <a href="register.php" class="btn btn-primary">Start free</a>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="wrap">
    <div>
      <span class="eyebrow"><i class="fa-solid fa-location-dot"></i> Made for South African job seekers</span>
      <h1>Find the job.<br><span>Send the CV.</span> Track it all.</h1>
      <p class="lead">Jobs, companies, industries and skills in one search. See your skills match on every advert, apply by email in minutes, and get a copy of everything you send.</p>
      <form class="searchbar hero-search" action="jobs.php" method="get">
        <label><i class="fa-solid fa-magnifying-glass"></i><input name="q" placeholder="Job title, skill or company"></label>
        <label><i class="fa-solid fa-location-dot"></i><select name="location"><option value="">Anywhere in SA</option><option>Remote</option><?php foreach (PROVINCES as $p): ?><option><?= $p ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary">Search jobs</button>
      </form>
      <div class="stat-strip">
        <div><b><?= number_format($jobsCount) ?></b>open jobs</div>
        <div><b><?= number_format($coCount) ?></b>companies</div>
        <div><b><?= number_format($newToday) ?></b>new today</div>
      </div>
    </div>
    <div class="phone" aria-hidden="true">
      <div class="phone__screen" style="background:var(--bg);border-radius:28px;overflow:hidden">
        <div class="phone__bar"><img src="assets/img/mark.svg" alt=""><div><strong>Matches for you</strong><small>Updated hourly</small></div></div>
        <div class="phone__body">
          <?php foreach ($latest ?: [['title' => 'Junior PHP Developer', 'company_name' => 'Acme Digital', 'location' => 'Sandton'], ['title' => 'Bookkeeper', 'company_name' => 'Karoo Foods', 'location' => 'Cape Town']] as $i => $j): ?>
            <div class="demo-file" style="animation-delay:<?= .2 * $i ?>s;align-self:stretch"><?= coLogo($j['company_name']) ?><div><strong><?= e($j['title']) ?></strong><small><?= e($j['company_name']) ?> · <?= e($j['location']) ?></small></div></div>
          <?php endforeach; ?>
          <div class="phone__chips"><span>Save</span><span>Apply by email</span><span>Alert me</span></div>
        </div>
      </div>
      <div class="float-card one"><i class="fa-solid fa-paper-plane"></i><div>Application sent<small>Copy in your inbox</small></div></div>
      <div class="float-card two"><i class="fa-solid fa-bullseye"></i><div>92% match<small>6 of 7 skills</small></div></div>
    </div>
  </div>
</section>

<section class="section" id="features">
  <div class="wrap">
    <div class="section__head"><h2>Everything for your job hunt</h2><p>Stop juggling ten job sites, a spreadsheet and your sent folder.</p></div>
    <div class="grid-3">
      <div class="feature"><div class="ico"><i class="fa-solid fa-magnifying-glass"></i></div><h3>One search, many sources</h3><p>Jobs from trusted South African and remote job boards, refreshed every hour, with duplicates removed.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-bullseye"></i></div><h3>Your skills match</h3><p>Every job shows which of your skills it asks for, so you spend time on the ones you can win.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-paper-plane"></i></div><h3>Apply by email</h3><p>A ready cover letter and your CV attached. Replies come straight to you, and you get a copy of what was sent.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-building"></i></div><h3>Companies & industries</h3><p>Browse who's hiring by industry and province, follow companies and send open applications.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-table-columns"></i></div><h3>Application board</h3><p>Saved → Applied → Interview → Offer, with follow-up reminders so nothing slips.</p></div>
      <div class="feature"><div class="ico"><i class="fa-solid fa-bell"></i></div><h3>Job alerts</h3><p>Save any search and get new matches by email daily or weekly.</p></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="section__head"><h2>Browse by industry</h2></div>
    <div class="tiles">
      <?php foreach ($industries as $i): ?>
        <a class="tile" href="jobs.php?industry=<?= (int) $i['id'] ?>"><div class="kpi__ico"><i class="fa-solid <?= e($i['icon']) ?>"></i></div><div><strong><?= e($i['name']) ?></strong><small><?= number_format((int) $i['n']) ?> open jobs</small></div></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="how">
  <div class="wrap">
    <div class="section__head"><h2>How it works</h2></div>
    <div class="steps">
      <div class="step"><h3>Tell us what you want</h3><p>Job titles, skills, province and salary. Upload your CV once.</p></div>
      <div class="step"><h3>Search and save</h3><p>Matches are ranked by your skills. Save the good ones to your board.</p></div>
      <div class="step"><h3>Apply and follow up</h3><p>Send your application by email in a minute, and we'll remind you to follow up.</p></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap"><div class="cta-band"><h2>Your next job could be one email away.</h2><p>Free to start. No card needed.</p><a href="register.php" class="btn btn-primary btn-lg">Create my free account <i class="fa-solid fa-arrow-right"></i></a></div></div>
</section>

<footer class="site-foot"><div class="wrap"><span>© <?= date('Y') ?> <?= APP_NAME ?> by SwiftFusion</span><span><a href="privacy.php">Privacy</a><a href="terms.php">Terms</a><a href="mailto:<?= e(ADMIN_EMAIL) ?>">Contact</a></span></div></footer>
</body>
</html>
