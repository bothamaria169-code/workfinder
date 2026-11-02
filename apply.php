<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/profile.php';

$user    = currentSeeker();
$uid     = (int) $user['id'];
$job     = !empty($_REQUEST['job']) ? getJob((int) $_REQUEST['job']) : null;
$company = !$job && !empty($_REQUEST['company']) ? getCompany((int) $_REQUEST['company']) : null;
if (!$job && !$company) { flash('error', 'Pick a job or company to apply to.'); redirect('jobs.php'); }

$cvs     = userCvs($uid);
$to      = (string) ($_POST['to'] ?? ($job['apply_email'] ?? '') ?: ($job['hr_email'] ?? '') ?: ($company['hr_email'] ?? ''));
$subject = (string) ($_POST['subject'] ?? ('Application: ' . ($job['title'] ?? (($user['job_titles'] ? trim(explode(',', $user['job_titles'])[0]) : 'Open application'))) . ' – ' . ($user['full_name'] ?: $user['email'])));
$letter  = (string) ($_POST['letter'] ?? defaultCoverLetter($user, $job, $company));
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cv = null;
    foreach ($cvs as $c) if ((int) $c['id'] === (int) ($_POST['cv_id'] ?? 0)) $cv = $c;
    $r = sendApplication($user, $to, mb_substr($subject, 0, 250), mb_substr($letter, 0, 8000), $cv, $job, $company);
    if ($r['ok']) {
        flash('success', 'Application sent to ' . $to . '. A copy is on its way to ' . $user['email'] . '.');
        redirect('emails.php');
    }
    $error = $r['error'];
}

$left = max(0, dailyLimit($user) - sentToday($uid));
$where = $job ? $job['title'] . ' · ' . ($job['company_name'] ?: '') : $company['name'];
appOpen('jobs', 'Apply by email', e($where), '<a class="btn btn-ghost" href="' . ($job ? 'job.php?id=' . (int) $job['id'] : 'company.php?id=' . (int) $company['id']) . '"><i class="fa-solid fa-arrow-left"></i> Back</a>');
?>
<?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div><?php endif; ?>
<div class="grid-2">
  <form method="POST" class="card" data-busy>
    <?= csrfField() ?>
    <input type="hidden" name="<?= $job ? 'job' : 'company' ?>" value="<?= (int) ($job['id'] ?? $company['id']) ?>">
    <div class="card__head"><div><h2>Your email</h2><p>Sent from <?= e(get_setting('smtp_user', SMTP_USER) ?: ADMIN_EMAIL) ?> in your name. Replies go straight to <?= e($user['email']) ?>.</p></div></div>
    <div class="card__body">
      <div class="field"><label>To (employer's email)</label><input type="email" name="to" value="<?= e($to) ?>" placeholder="careers@company.co.za" required>
        <?php if (!$to): ?><div class="hint">This advert has no email address. Check the original advert<?= $job && $job['apply_url'] ? ' (<a href="' . e($job['apply_url']) . '" target="_blank" rel="noopener nofollow">open it</a>)' : '' ?> for one, or apply on the company's website.</div><?php endif; ?></div>
      <div class="field"><label>Subject</label><input name="subject" value="<?= e($subject) ?>" maxlength="250" required></div>
      <div class="field"><label>Cover letter <span class="hint">Edit it to fit the job — specific beats generic</span></label><textarea name="letter" rows="14" required><?= e($letter) ?></textarea></div>
      <div class="field"><label>Attach CV</label>
        <?php if ($cvs): ?>
          <select name="cv_id"><?php foreach ($cvs as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $c['is_default'] ? 'selected' : '' ?>><?= e($c['original_name']) ?></option><?php endforeach; ?><option value="0">Don't attach a CV</option></select>
        <?php else: ?>
          <div class="hint">No CV uploaded yet. <a href="profile.php">Upload one</a> — most employers expect it.</div><input type="hidden" name="cv_id" value="0">
        <?php endif; ?>
      </div>
      <div class="form-foot"><button class="btn btn-primary btn-lg" type="submit" <?= $left ? '' : 'disabled' ?>><i class="fa-solid fa-paper-plane"></i> Send application</button><span class="hint" style="margin:0"><?= $left ?> of <?= dailyLimit($user) ?> application emails left today</span></div>
    </div>
  </form>

  <div>
    <section class="card">
      <div class="card__head"><h2>What happens when you send</h2></div>
      <div class="card__body">
        <ol class="steps-list">
          <li>The employer gets your letter with your CV attached. Your name shows as the sender and <strong>replies go to your inbox</strong>.</li>
          <li>You get <strong>a copy of exactly what was sent</strong> at <?= e($user['email']) ?>.</li>
          <li>It's logged under <a href="emails.php">Sent emails</a> — you'll see when it's opened.</li>
          <li>The job moves to <strong>Applied</strong> on your <a href="tracker.php">board</a>, with a follow-up reminder in 7 days.</li>
        </ol>
      </div>
    </section>
    <section class="card">
      <div class="card__head"><h2>Tips</h2></div>
      <div class="card__body"><ul class="steps-list">
        <li>Mention the exact job title and where you saw it.</li>
        <li>Name 2–3 skills from the advert that you really have.</li>
        <li>Keep it under 250 words, and check the spelling of the company name.</li>
        <li>Name your CV file clearly, e.g. <em>Thandi-Mokoena-CV.pdf</em>.</li>
      </ul></div>
    </section>
  </div>
</div>
<?php appClose();
