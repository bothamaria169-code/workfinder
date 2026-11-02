<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// ── Skills dictionary ─────────────────────────────────────────────
/** All skills as [id, name], for tag inputs. */
function allSkills(): array
{
    return getDB()->query('SELECT id, name FROM skills ORDER BY name')->fetchAll();
}

function allIndustries(): array
{
    return getDB()->query('SELECT id, name, slug, icon FROM industries ORDER BY name')->fetchAll();
}

/** Skill ids the user has. */
function userSkillIds(int $uid): array
{
    $s = getDB()->prepare('SELECT skill_id FROM user_skills WHERE user_id = ?');
    $s->execute([$uid]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

function userSavedIds(int $uid): array
{
    $s = getDB()->prepare('SELECT job_id FROM saved_jobs WHERE user_id = ?');
    $s->execute([$uid]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Finds dictionary skills (and their synonyms) mentioned in a job's text.
 * Whole-word, case-insensitive; "C#" and ".NET" style names are matched literally.
 *
 * @return int[] skill ids
 */
function extractSkills(string $text): array
{
    static $terms = null;
    if ($terms === null) {
        $terms = [];
        foreach (getDB()->query('SELECT id, name FROM skills') as $r) $terms[] = [(int) $r['id'], $r['name']];
        foreach (getDB()->query('SELECT skill_id, alias FROM skill_synonyms') as $r) $terms[] = [(int) $r['skill_id'], $r['alias']];
    }
    $text = ' ' . strtolower(html_entity_decode(strip_tags($text))) . ' ';
    $found = [];
    foreach ($terms as [$id, $term]) {
        $t = preg_quote(strtolower($term), '/');
        // Not inside a longer word, and not the tail of a dotted name ("js" in "node.js").
        if (preg_match('/(?<![a-z0-9.])' . $t . '(?![a-z0-9#+]|\.[a-z])/u', $text)) $found[$id] = true;
    }
    return array_keys($found);
}

// ── Companies ─────────────────────────────────────────────────────
/** The company with this name, created on first sight. */
function companyIdFor(string $name, ?int $industryId = null, string $location = ''): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    $slug = slugify($name);
    $db = getDB();
    $s = $db->prepare('SELECT id FROM companies WHERE slug = ?');
    $s->execute([$slug]);
    if ($id = $s->fetchColumn()) return (int) $id;
    $db->prepare('INSERT INTO companies (name, slug, industry_id, location, province) VALUES (?, ?, ?, ?, ?)')
       ->execute([mb_substr($name, 0, 200), $slug, $industryId, mb_substr($location, 0, 120) ?: null, provinceFor($location)]);
    return (int) $db->lastInsertId();
}

/** Best-guess industry for a job from its title/category text. */
function guessIndustry(string $text): ?int
{
    $map = [
        'information-technology' => ['developer', 'software', 'it ', 'programmer', 'devops', 'data ', 'network', 'cyber', 'engineer - software', 'web', 'ict', 'systems'],
        'finance-and-banking' => ['account', 'finance', 'bank', 'audit', 'tax', 'bookkeep', 'payroll', 'credit', 'insurance'],
        'healthcare' => ['nurse', 'nursing', 'medical', 'pharmac', 'health', 'clinic', 'doctor', 'care'],
        'engineering' => ['engineer', 'technician', 'mechanical', 'electrical', 'civil'],
        'mining' => ['mine', 'mining', 'geolog'],
        'retail' => ['retail', 'store', 'cashier', 'merchand', 'shop'],
        'education' => ['teacher', 'tutor', 'lecturer', 'school', 'education'],
        'marketing-and-media' => ['marketing', 'social media', 'content', 'brand', 'seo', 'media', 'design'],
        'logistics-and-transport' => ['driver', 'logistic', 'warehouse', 'transport', 'supply chain', 'forklift', 'dispatch'],
        'hospitality-and-tourism' => ['chef', 'waiter', 'hotel', 'hospitality', 'tourism', 'restaurant', 'cook'],
        'construction' => ['construction', 'site manager', 'builder', 'quantity surveyor'],
        'call-centre-and-customer-service' => ['call centre', 'call center', 'customer service', 'agent', 'customer support'],
        'legal' => ['legal', 'attorney', 'lawyer', 'paralegal'],
        'manufacturing' => ['production', 'factory', 'manufactur', 'operator'],
    ];
    $t = strtolower($text);
    foreach ($map as $slug => $words) foreach ($words as $w) if (str_contains($t, $w)) {
        $s = getDB()->prepare('SELECT id FROM industries WHERE slug = ?');
        $s->execute([$slug]);
        return ($id = $s->fetchColumn()) ? (int) $id : null;
    }
    return null;
}

// ── Saving jobs from any source ───────────────────────────────────
/**
 * Inserts or refreshes one job. $j keys: source, external_id, title,
 * company_name, location, description, apply_url, apply_email, salary_min,
 * salary_max, work_type, is_remote, posted_at, industry_id.
 * De-duplicates on title + company + location, whatever the source.
 *
 * @return bool true when the job is new
 */
function upsertJob(array $j): bool
{
    $db = getDB();
    $title = trim(mb_substr((string) $j['title'], 0, 250));
    if ($title === '') return false;
    $company  = trim((string) ($j['company_name'] ?? ''));
    $location = trim((string) ($j['location'] ?? ''));
    $hash = sha1(strtolower(preg_replace('/\W+/', '', $title) . '|' . preg_replace('/\W+/', '', $company) . '|' . preg_replace('/\W+/', '', $location)));

    $s = $db->prepare('SELECT id FROM jobs WHERE dedupe_hash = ?');
    $s->execute([$hash]);
    if ($id = $s->fetchColumn()) {
        $db->prepare('UPDATE jobs SET is_active = 1, expires_at = ? WHERE id = ?')->execute([$j['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+45 days')), $id]);
        return false;
    }

    $industry = $j['industry_id'] ?? guessIndustry($title . ' ' . ($j['category'] ?? ''));
    $companyId = $company !== '' ? companyIdFor($company, $industry, $location) : null;
    $desc = (string) ($j['description'] ?? '');
    $isRemote = !empty($j['is_remote']) || preg_match('/\bremote\b/i', $title . ' ' . $location) ? 1 : 0;

    $db->prepare('INSERT INTO jobs (source, external_id, title, company_id, company_name, industry_id, location, province, is_remote, work_type, experience_level, salary_min, salary_max, description, apply_url, apply_email, posted_at, expires_at, is_featured, dedupe_hash)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([
           $j['source'] ?? 'manual', $j['external_id'] ?? null, $title, $companyId, $company ?: null, $industry,
           mb_substr($location, 0, 150) ?: null, provinceFor($location), $isRemote,
           in_array($j['work_type'] ?? '', array_keys(WORK_TYPES), true) ? $j['work_type'] : 'full_time',
           in_array($j['experience_level'] ?? '', array_keys(LEVELS), true) ? $j['experience_level'] : guessLevel($title),
           !empty($j['salary_min']) ? (int) $j['salary_min'] : null, !empty($j['salary_max']) ? (int) $j['salary_max'] : null,
           $desc, mb_substr((string) ($j['apply_url'] ?? ''), 0, 600) ?: null,
           filter_var($j['apply_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: (emailIn($desc) ?: null),
           $j['posted_at'] ?? date('Y-m-d H:i:s'), $j['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+45 days')),
           !empty($j['is_featured']) ? 1 : 0, $hash,
       ]);
    $jobId = (int) $db->lastInsertId();
    $ins = $db->prepare('INSERT IGNORE INTO job_skills (job_id, skill_id) VALUES (?, ?)');
    foreach (extractSkills($title . ' ' . $desc) as $sid) $ins->execute([$jobId, $sid]);
    return true;
}

function guessLevel(string $title): ?string
{
    $t = strtolower($title);
    return match (true) {
        (bool) preg_match('/\b(intern|internship|graduate|learnership|trainee|entry)\b/', $t) => 'entry',
        (bool) preg_match('/\bjunior|jnr\b/', $t) => 'junior',
        (bool) preg_match('/\b(senior|snr|sr\.?)\b/', $t) => 'senior',
        (bool) preg_match('/\b(lead|head|manager|director|principal)\b/', $t) => 'lead',
        (bool) preg_match('/\b(intermediate|mid)\b/', $t) => 'mid',
        default => null,
    };
}

/** First email address in a block of text (often "send CVs to …" in descriptions). */
function emailIn(string $text): ?string
{
    return preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', strip_tags($text), $m) ? strtolower($m[0]) : null;
}

// ── Search ────────────────────────────────────────────────────────
/**
 * Filters: q, location, province, industry, skill, company, work_type,
 * level, remote, salary (minimum), days (posted within), featured.
 *
 * @return array{0: array, 1: int} [jobs with their skills, total]
 */
function searchJobs(array $f, int $page = 1, int $per = 20, string $sort = 'recent', array $mySkills = []): array
{
    $where = ['j.is_active = 1'];
    $args  = [];
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(j.title LIKE ? OR j.company_name LIKE ? OR MATCH(j.title, j.description) AGAINST (? IN NATURAL LANGUAGE MODE))';
        array_push($args, "%{$q}%", "%{$q}%", $q);
    }
    if (($loc = trim((string) ($f['location'] ?? ''))) !== '') {
        if (strtolower($loc) === 'remote') { $where[] = 'j.is_remote = 1'; }
        elseif (in_array($loc, PROVINCES, true)) { $where[] = 'j.province = ?'; $args[] = $loc; }
        else { $where[] = '(j.location LIKE ? OR j.province = ?)'; array_push($args, "%{$loc}%", provinceFor($loc) ?? '-'); }
    }
    foreach (['industry' => 'j.industry_id', 'company' => 'j.company_id'] as $k => $col) {
        if (!empty($f[$k])) { $where[] = "{$col} = ?"; $args[] = (int) $f[$k]; }
    }
    if (!empty($f['skill'])) { $where[] = 'EXISTS (SELECT 1 FROM job_skills x WHERE x.job_id = j.id AND x.skill_id = ?)'; $args[] = (int) $f['skill']; }
    if (!empty($f['work_type']) && isset(WORK_TYPES[$f['work_type']])) { $where[] = 'j.work_type = ?'; $args[] = $f['work_type']; }
    if (!empty($f['level']) && isset(LEVELS[$f['level']])) { $where[] = 'j.experience_level = ?'; $args[] = $f['level']; }
    if (!empty($f['remote'])) $where[] = 'j.is_remote = 1';
    if (!empty($f['salary'])) { $where[] = 'COALESCE(j.salary_max, j.salary_min) >= ?'; $args[] = (int) $f['salary']; }
    if (!empty($f['days'])) { $where[] = 'j.posted_at >= NOW() - INTERVAL ? DAY'; $args[] = (int) $f['days']; }
    if (!empty($f['since'])) { $where[] = 'j.created_at > ?'; $args[] = $f['since']; }

    $db = getDB();
    $w = implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) FROM jobs j WHERE {$w}");
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $order = 'j.is_featured DESC, j.posted_at DESC';
    $select = 'j.*';
    if ($sort === 'match' && $mySkills) {
        $ids = implode(',', array_map('intval', $mySkills));
        $select .= ", (SELECT COUNT(*) FROM job_skills m WHERE m.job_id = j.id AND m.skill_id IN ({$ids})) AS matched";
        $order = 'matched DESC, j.posted_at DESC';
    } elseif ($sort === 'salary') {
        $order = 'COALESCE(j.salary_max, j.salary_min, 0) DESC, j.posted_at DESC';
    }
    $off = max(0, ($page - 1) * $per);
    $s = $db->prepare("SELECT {$select} FROM jobs j WHERE {$w} ORDER BY {$order} LIMIT {$per} OFFSET {$off}");
    $s->execute($args);
    return [attachSkills($s->fetchAll()), $total];
}

/** Adds ['skills' => [[id, name], …]] to each job row. */
function attachSkills(array $jobs): array
{
    if (!$jobs) return $jobs;
    $ids = implode(',', array_map(fn($j) => (int) $j['id'], $jobs));
    $map = [];
    foreach (getDB()->query("SELECT js.job_id, s.id, s.name FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id IN ({$ids}) ORDER BY s.name") as $r) {
        $map[(int) $r['job_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
    }
    foreach ($jobs as &$j) $j['skills'] = $map[(int) $j['id']] ?? [];
    return $jobs;
}

function getJob(int $id): ?array
{
    $s = getDB()->prepare('SELECT j.*, i.name AS industry_name, c.hr_email, c.website, c.careers_url FROM jobs j
                           LEFT JOIN industries i ON i.id = j.industry_id LEFT JOIN companies c ON c.id = j.company_id WHERE j.id = ?');
    $s->execute([$id]);
    $j = $s->fetch();
    return $j ? attachSkills([$j])[0] : null;
}

function getCompany(int $id): ?array
{
    $s = getDB()->prepare('SELECT c.*, i.name AS industry_name, (SELECT COUNT(*) FROM jobs j WHERE j.company_id = c.id AND j.is_active = 1) AS open_jobs
                           FROM companies c LEFT JOIN industries i ON i.id = c.industry_id WHERE c.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Search query from GET, limited to known filter keys. */
function searchFilters(array $src): array
{
    $keys = ['q', 'location', 'industry', 'skill', 'company', 'work_type', 'level', 'remote', 'salary', 'days'];
    $f = [];
    foreach ($keys as $k) if (isset($src[$k]) && $src[$k] !== '') $f[$k] = mb_substr(trim((string) $src[$k]), 0, 120);
    return $f;
}

/** Human summary of a saved search, e.g. "PHP · Gauteng · Remote". */
function describeSearch(array $f): string
{
    $db = getDB();
    $parts = [];
    if (!empty($f['q'])) $parts[] = '"' . $f['q'] . '"';
    if (!empty($f['location'])) $parts[] = $f['location'];
    if (!empty($f['industry'])) { $s = $db->prepare('SELECT name FROM industries WHERE id = ?'); $s->execute([(int) $f['industry']]); $parts[] = (string) $s->fetchColumn(); }
    if (!empty($f['skill'])) { $s = $db->prepare('SELECT name FROM skills WHERE id = ?'); $s->execute([(int) $f['skill']]); $parts[] = (string) $s->fetchColumn(); }
    if (!empty($f['work_type'])) $parts[] = WORK_TYPES[$f['work_type']] ?? '';
    if (!empty($f['remote'])) $parts[] = 'Remote';
    if (!empty($f['salary'])) $parts[] = 'R' . number_format((int) $f['salary']) . '+';
    return implode(' · ', array_filter($parts)) ?: 'All jobs';
}

/** Jobs that fit the user's profile: their skills, then their job titles, newest first. */
function recommendedJobs(array $user, int $limit = 6): array
{
    $mine = userSkillIds((int) $user['id']);
    if (!$mine && trim((string) $user['job_titles']) === '') {
        return searchJobs([], 1, $limit)[0];
    }
    $f = [];
    if (!$mine) $f['q'] = trim(explode(',', (string) $user['job_titles'])[0]);
    return searchJobs($f, 1, $limit, $mine ? 'match' : 'recent', $mine)[0];
}
