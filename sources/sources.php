<?php
declare(strict_types=1);

/**
 * Job sources. Each one turns an official job API into WorkFinder's job
 * shape (see upsertJob()). Only APIs that allow it are used — no scraping.
 * Keys live in Admin → Settings → Job APIs. Add a source: write a class that
 * implements JobSource, add it to jobSources(), and insert a job_sources row.
 */
require_once dirname(__DIR__) . '/includes/jobs.php';

interface JobSource
{
    public function code(): string;
    public function ready(): bool;
    /** @return array<int, array> jobs in upsertJob() shape */
    public function fetch(string $keywords, string $location = ''): array;
}

/** @return array<string, JobSource> */
function jobSources(): array
{
    return ['adzuna' => new AdzunaSource(), 'careerjet' => new CareerjetSource(), 'jooble' => new JoobleSource(), 'remotive' => new RemotiveSource(), 'arbeitnow' => new ArbeitnowSource()];
}

function httpJson(string $url, ?array $postJson = null, array $headers = []): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: WorkFinder/1.0 (+' . APP_URL . ')'], $headers);
    if ($postJson !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postJson));
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        throw new RuntimeException("HTTP {$code} " . ($err ?: mb_substr((string) $body, 0, 160)));
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) throw new RuntimeException('Not JSON: ' . mb_substr((string) $body, 0, 120));
    return $data;
}

/** Many APIs give yearly pay; WorkFinder shows monthly rands. */
function monthly(mixed $v): ?int
{
    $n = (int) round((float) $v);
    if ($n <= 0) return null;
    return $n > 150000 ? (int) round($n / 12) : $n;
}

final class AdzunaSource implements JobSource
{
    public function code(): string { return 'adzuna'; }
    public function ready(): bool { return get_setting('adzuna_app_id') !== '' && get_setting('adzuna_app_key') !== ''; }

    public function fetch(string $keywords, string $location = ''): array
    {
        $url = 'https://api.adzuna.com/v1/api/jobs/za/search/1?' . http_build_query(array_filter([
            'app_id' => get_setting('adzuna_app_id'), 'app_key' => get_setting('adzuna_app_key'),
            'results_per_page' => 50, 'what' => $keywords, 'where' => $location, 'max_days_old' => 14, 'sort_by' => 'date',
            'content-type' => 'application/json',
        ]));
        $out = [];
        foreach (httpJson($url)['results'] ?? [] as $r) {
            $out[] = [
                'source' => 'adzuna', 'external_id' => (string) ($r['id'] ?? ''), 'title' => strip_tags((string) ($r['title'] ?? '')),
                'company_name' => (string) ($r['company']['display_name'] ?? ''), 'location' => (string) ($r['location']['display_name'] ?? ''),
                'description' => strip_tags((string) ($r['description'] ?? '')), 'apply_url' => (string) ($r['redirect_url'] ?? ''),
                'salary_min' => monthly($r['salary_min'] ?? 0), 'salary_max' => monthly($r['salary_max'] ?? 0),
                'work_type' => ($r['contract_type'] ?? '') === 'contract' ? 'contract' : (($r['contract_time'] ?? '') === 'part_time' ? 'part_time' : 'full_time'),
                'category' => (string) ($r['category']['label'] ?? ''),
                'posted_at' => !empty($r['created']) ? date('Y-m-d H:i:s', strtotime($r['created'])) : null,
            ];
        }
        return $out;
    }
}

final class CareerjetSource implements JobSource
{
    public function code(): string { return 'careerjet'; }
    public function ready(): bool { return get_setting('careerjet_affid') !== ''; }

    public function fetch(string $keywords, string $location = ''): array
    {
        $url = 'http://public.api.careerjet.net/search?' . http_build_query([
            'locale_code' => 'en_ZA', 'keywords' => $keywords, 'location' => $location ?: 'South Africa', 'affid' => get_setting('careerjet_affid'),
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 'user_agent' => 'WorkFinder', 'pagesize' => 50, 'sort' => 'date',
        ]);
        $out = [];
        foreach (httpJson($url)['jobs'] ?? [] as $r) {
            $out[] = [
                'source' => 'careerjet', 'external_id' => md5((string) ($r['url'] ?? '')), 'title' => strip_tags((string) ($r['title'] ?? '')),
                'company_name' => (string) ($r['company'] ?? ''), 'location' => (string) ($r['locations'] ?? ''),
                'description' => strip_tags((string) ($r['description'] ?? '')), 'apply_url' => (string) ($r['url'] ?? ''),
                'salary_min' => monthly($r['salary_min'] ?? 0), 'salary_max' => monthly($r['salary_max'] ?? 0),
                'posted_at' => !empty($r['date']) ? date('Y-m-d H:i:s', strtotime($r['date'])) : null,
            ];
        }
        return $out;
    }
}

final class JoobleSource implements JobSource
{
    public function code(): string { return 'jooble'; }
    public function ready(): bool { return get_setting('jooble_key') !== ''; }

    public function fetch(string $keywords, string $location = ''): array
    {
        $data = httpJson('https://za.jooble.org/api/' . rawurlencode(get_setting('jooble_key')), ['keywords' => $keywords, 'location' => $location, 'page' => '1']);
        $out = [];
        foreach ($data['jobs'] ?? [] as $r) {
            $type = strtolower((string) ($r['type'] ?? ''));
            $out[] = [
                'source' => 'jooble', 'external_id' => (string) ($r['id'] ?? ''), 'title' => strip_tags((string) ($r['title'] ?? '')),
                'company_name' => (string) ($r['company'] ?? ''), 'location' => (string) ($r['location'] ?? ''),
                'description' => strip_tags((string) ($r['snippet'] ?? '')), 'apply_url' => (string) ($r['link'] ?? ''),
                'work_type' => str_contains($type, 'part') ? 'part_time' : (str_contains($type, 'contract') || str_contains($type, 'temp') ? 'contract' : (str_contains($type, 'intern') ? 'internship' : 'full_time')),
                'posted_at' => !empty($r['updated']) ? date('Y-m-d H:i:s', strtotime($r['updated'])) : null,
            ];
        }
        return $out;
    }
}

final class RemotiveSource implements JobSource
{
    public function code(): string { return 'remotive'; }
    public function ready(): bool { return true; }

    public function fetch(string $keywords, string $location = ''): array
    {
        $out = [];
        foreach (httpJson('https://remotive.com/api/remote-jobs?' . http_build_query(['search' => $keywords, 'limit' => 30]))['jobs'] ?? [] as $r) {
            $where = (string) ($r['candidate_required_location'] ?? '');
            // Only roles open to South Africa or worldwide.
            if ($where !== '' && !preg_match('/worldwide|anywhere|south africa|africa|emea|global/i', $where)) continue;
            $out[] = [
                'source' => 'remotive', 'external_id' => (string) ($r['id'] ?? ''), 'title' => (string) ($r['title'] ?? ''),
                'company_name' => (string) ($r['company_name'] ?? ''), 'location' => 'Remote' . ($where ? " ({$where})" : ''), 'is_remote' => 1,
                'description' => trim(strip_tags(str_replace(['</p>', '<br>', '</li>'], "\n", (string) ($r['description'] ?? '')))),
                'apply_url' => (string) ($r['url'] ?? ''), 'category' => (string) ($r['category'] ?? ''),
                'work_type' => match ((string) ($r['job_type'] ?? '')) { 'part_time' => 'part_time', 'contract', 'freelance' => 'contract', 'internship' => 'internship', default => 'full_time' },
                'posted_at' => !empty($r['publication_date']) ? date('Y-m-d H:i:s', strtotime($r['publication_date'])) : null,
            ];
        }
        return $out;
    }
}

final class ArbeitnowSource implements JobSource
{
    public function code(): string { return 'arbeitnow'; }
    public function ready(): bool { return true; }

    /** Arbeitnow has no keyword search; it returns the latest board, filtered here to remote roles. */
    public function fetch(string $keywords, string $location = ''): array
    {
        $out = [];
        foreach (httpJson('https://www.arbeitnow.com/api/job-board-api')['data'] ?? [] as $r) {
            if (empty($r['remote'])) continue;
            if ($keywords !== '' && stripos(($r['title'] ?? '') . ' ' . implode(' ', $r['tags'] ?? []), $keywords) === false) continue;
            $out[] = [
                'source' => 'arbeitnow', 'external_id' => (string) ($r['slug'] ?? ''), 'title' => (string) ($r['title'] ?? ''),
                'company_name' => (string) ($r['company_name'] ?? ''), 'location' => 'Remote', 'is_remote' => 1,
                'description' => trim(strip_tags(str_replace(['</p>', '<br>', '</li>'], "\n", (string) ($r['description'] ?? '')))),
                'apply_url' => (string) ($r['url'] ?? ''),
                'posted_at' => !empty($r['created_at']) ? date('Y-m-d H:i:s', (int) $r['created_at']) : null,
            ];
        }
        return $out;
    }
}

/** Search terms for a sync: the admin's list plus the job titles users are hunting for. */
function syncKeywords(int $max = 12): array
{
    $terms = array_map('trim', explode(',', get_setting('sync_keywords', 'developer, accountant, administrator, sales, nurse, engineer, driver, call centre, teacher, marketing, data analyst, artisan')));
    foreach (getDB()->query("SELECT job_titles FROM users WHERE job_titles IS NOT NULL AND job_titles <> '' ORDER BY last_login_at DESC LIMIT 50") as $r) {
        foreach (explode(',', (string) $r['job_titles']) as $t) $terms[] = trim(strtolower($t));
    }
    $terms = array_values(array_unique(array_filter($terms)));
    shuffle($terms); // different slice each run, so every term gets its turn
    return array_slice($terms, 0, $max);
}

/**
 * Pulls fresh jobs from every enabled source and records the outcome on job_sources.
 *
 * @return string[] log lines
 */
function syncJobs(?string $only = null): array
{
    $db = getDB();
    $log = [];
    $rows = $db->query('SELECT * FROM job_sources')->fetchAll();
    $keywords = syncKeywords();
    foreach (jobSources() as $code => $src) {
        $row = current(array_filter($rows, fn($r) => $r['code'] === $code));
        if ($only !== null && $only !== $code) continue;
        if ($only === null && (!$row || !(int) $row['enabled'])) continue;
        if (!$src->ready()) {
            $db->prepare('UPDATE job_sources SET last_status = ? WHERE code = ?')->execute(['Needs an API key (Admin → Settings → Job APIs)', $code]);
            continue;
        }
        $new = 0; $errors = [];
        foreach ($code === 'arbeitnow' ? [''] : $keywords as $kw) {
            try {
                foreach ($src->fetch($kw) as $job) if (upsertJob($job)) $new++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                if (count($errors) >= 3) break;
            }
        }
        $status = $errors ? 'Errors: ' . mb_substr(implode(' | ', array_unique($errors)), 0, 400) : 'OK';
        $db->prepare('UPDATE job_sources SET last_run_at = NOW(), last_status = ?, last_count = ? WHERE code = ?')->execute([$status, $new, $code]);
        $log[] = "{$code}: {$new} new" . ($errors ? ' (' . count($errors) . ' errors)' : '');
    }
    // Jobs past their expiry, or older than 45 days, leave search results.
    $db->exec("UPDATE jobs SET is_active = 0 WHERE is_active = 1 AND source <> 'manual' AND (expires_at < NOW() OR posted_at < NOW() - INTERVAL 45 DAY)");
    return $log;
}
