<?php
declare(strict_types=1);

require_once __DIR__ . '/applications.php';
require_once __DIR__ . '/layout.php';

/** Signed-in job seeker or a redirect to sign in. Admin pages use admin/_shell.php instead. */
function currentSeeker(): array
{
    startAppSession();
    if (!isLoggedIn()) {
        $_SESSION['after_login'] = ltrim(substr((string) ($_SERVER['REQUEST_URI'] ?? ''), strlen((string) parse_url(APP_URL, PHP_URL_PATH))), '/');
        redirect('login.php');
    }
    $user = getCurrentUser();
    if (!$user) redirect('login.php');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();
    return $user;
}

/** Saves the profile fields shared by onboarding and the profile page. */
function saveProfile(int $uid): void
{
    $db = getDB();
    $types = array_values(array_intersect((array) ($_POST['work_types'] ?? []), array_keys(WORK_TYPES)));
    $min = (int) ($_POST['salary_min'] ?? 0);
    $max = (int) ($_POST['salary_max'] ?? 0);
    $db->prepare('UPDATE users SET full_name = ?, phone = ?, headline = ?, job_titles = ?, location = ?, province = ?, experience_level = ?,
                  salary_min = ?, salary_max = ?, work_types = ?, bio = ?, onboarded_at = COALESCE(onboarded_at, NOW()) WHERE id = ?')
       ->execute([
           postText('full_name', 150) ?: null, postText('phone', 50) ?: null, postText('headline', 200) ?: null,
           implode(', ', array_filter(array_map('trim', explode(',', postText('job_titles', 500))))) ?: null,
           postText('location', 120) ?: null,
           in_array($_POST['province'] ?? '', PROVINCES, true) ? $_POST['province'] : (provinceFor(postText('location', 120)) ?: null),
           isset(LEVELS[$_POST['experience_level'] ?? '']) ? $_POST['experience_level'] : null,
           $min ?: null, $max ?: null, implode(',', $types) ?: null, postText('bio', 3000) ?: null, $uid,
       ]);

    $db->prepare('DELETE FROM user_skills WHERE user_id = ?')->execute([$uid]);
    $ins = $db->prepare('INSERT IGNORE INTO user_skills (user_id, skill_id) SELECT ?, id FROM skills WHERE id = ?');
    foreach (array_slice(array_unique(array_map('intval', (array) ($_POST['skills'] ?? []))), 0, 40) as $sid) $ins->execute([$uid, $sid]);

    $db->prepare('DELETE FROM user_industries WHERE user_id = ?')->execute([$uid]);
    $ins = $db->prepare('INSERT IGNORE INTO user_industries (user_id, industry_id) SELECT ?, id FROM industries WHERE id = ?');
    foreach (array_slice(array_unique(array_map('intval', (array) ($_POST['industries'] ?? []))), 0, 10) as $iid) $ins->execute([$uid, $iid]);
}

/**
 * Stores an uploaded CV (PDF or Word, max 5 MB) under a random name.
 *
 * @return string '' on success, else the problem
 */
function saveCvUpload(int $uid, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) return 'The upload failed. Please try again.';
    if ($file['size'] > MAX_CV_SIZE) return 'Your CV must be 5 MB or smaller.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = match ($mime) {
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        default => null,
    };
    if ($ext === null && $mime === 'application/zip' && str_ends_with(strtolower($file['name']), '.docx')) $ext = 'docx';
    if ($ext === null) return 'Please upload your CV as a PDF or Word document.';

    if (!is_dir(CV_DIR)) mkdir(CV_DIR, 0755, true);
    $name = 'cv_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], CV_DIR . $name)) return 'Could not save your CV. Please try again.';

    $original = preg_replace('/[^\w .()-]/u', '', basename((string) $file['name'])) ?: ('CV.' . $ext);
    $db = getDB();
    $hasDefault = $db->prepare('SELECT COUNT(*) FROM cvs WHERE user_id = ? AND is_default = 1');
    $hasDefault->execute([$uid]);
    $db->prepare('INSERT INTO cvs (user_id, file_name, original_name, mime, size, is_default) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $name, mb_substr($original, 0, 255), $mime, (int) $file['size'], (int) $hasDefault->fetchColumn() ? 0 : 1]);
    return '';
}

function userCvs(int $uid): array
{
    $s = getDB()->prepare('SELECT * FROM cvs WHERE user_id = ? ORDER BY is_default DESC, created_at DESC');
    $s->execute([$uid]);
    return $s->fetchAll();
}

function cvUrl(array $cv): string
{
    return APP_URL . '/cv.php?id=' . (int) $cv['id'];
}

function userIndustryIds(int $uid): array
{
    $s = getDB()->prepare('SELECT industry_id FROM user_industries WHERE user_id = ?');
    $s->execute([$uid]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/** JSON for a .tagbox's data-selected. */
function selectedSkillsJson(int $uid): string
{
    $s = getDB()->prepare('SELECT s.id, s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ? ORDER BY s.name');
    $s->execute([$uid]);
    return json_encode(array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $s->fetchAll()));
}

/** The profile form body, shared by onboarding and the profile page. */
function profileFields(array $user): void
{
    $uid  = (int) $user['id'];
    $mine = userIndustryIds($uid);
    $types = explode(',', (string) $user['work_types']);
    $skillOpts = json_encode(array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], allSkills()));
    ?>
    <div class="form-grid">
      <div class="field"><label>Full name</label><input name="full_name" value="<?= e($user['full_name']) ?>" required></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($user['phone']) ?>" placeholder="e.g. 082 123 4567"></div>
      <div class="field full"><label>Headline <span class="hint">Your current role, or what you do</span></label><input name="headline" value="<?= e($user['headline']) ?>" placeholder="e.g. Junior Web Developer · BSc IT graduate"></div>
      <div class="field full"><label>Jobs you're looking for <span class="hint">Separate with commas</span></label><input name="job_titles" value="<?= e($user['job_titles']) ?>" placeholder="e.g. PHP developer, web developer, IT support"></div>
      <div class="field full"><label>Your skills <span class="hint">Type and press Enter</span></label>
        <div><div class="tagbox" data-name="skills[]" data-options='<?= e($skillOpts) ?>' data-selected='<?= e(selectedSkillsJson($uid)) ?>'><input type="text" placeholder="PHP, Excel, Customer service…"></div></div>
      </div>
      <div class="field"><label>Town / city</label><input name="location" value="<?= e($user['location']) ?>" placeholder="e.g. Pretoria"></div>
      <div class="field"><label>Province</label><select name="province"><option value="">Choose…</option><?php foreach (PROVINCES as $p): ?><option <?= $user['province'] === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Experience level</label><select name="experience_level"><option value="">Choose…</option><?php foreach (LEVELS as $k => $v): ?><option value="<?= $k ?>" <?= $user['experience_level'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Salary you want (per month, R)</label>
        <div style="display:flex;gap:.5rem"><input type="number" name="salary_min" min="0" step="500" value="<?= e($user['salary_min']) ?>" placeholder="From"><input type="number" name="salary_max" min="0" step="500" value="<?= e($user['salary_max']) ?>" placeholder="To"></div></div>
      <div class="field full"><label>Work type</label><div class="chips">
        <?php foreach (WORK_TYPES as $k => $v): ?><label class="chip-check"><input type="checkbox" name="work_types[]" value="<?= $k ?>" <?= in_array($k, $types, true) ? 'checked' : '' ?>><?= $v ?></label><?php endforeach; ?>
      </div></div>
      <div class="field full"><label>Industries you're interested in</label><div class="chips">
        <?php foreach (allIndustries() as $i): ?><label class="chip-check"><input type="checkbox" name="industries[]" value="<?= (int) $i['id'] ?>" <?= in_array((int) $i['id'], $mine, true) ? 'checked' : '' ?>><?= e($i['name']) ?></label><?php endforeach; ?>
      </div></div>
      <div class="field full"><label>About you <span class="hint">Optional — used to draft cover letters</span></label><textarea name="bio" rows="4"><?= e($user['bio']) ?></textarea></div>
    </div>
    <?php
}
