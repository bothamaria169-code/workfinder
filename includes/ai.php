<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * AI cover letters, on Groq's free API (OpenAI-compatible).
 * It writes from the user's profile and the job advert, and can rewrite the
 * user's own draft to be shorter, more formal or more specific.
 *
 * Groq retires models from time to time (llama-3.3-70b-versatile went in
 * August 2026), so the model is not fixed: the key's own model list is read
 * from Groq, the best one in GROQ_PREFERRED is used, and a retired model is
 * swapped for the next best automatically.
 *
 * Key: console.groq.com → API Keys, saved in Admin → Settings → AI.
 * Monthly limits per plan are in Admin → Payments → Prices & plans.
 */
const GROQ_PREFERRED = [
    'openai/gpt-oss-120b', 'qwen/qwen3.6-27b', 'moonshotai/kimi-k2-instruct-0905', 'moonshotai/kimi-k2-instruct',
    'openai/gpt-oss-20b', 'meta-llama/llama-4-maverick-17b-128e-instruct', 'meta-llama/llama-4-scout-17b-16e-instruct',
    'qwen/qwen3-32b', 'llama-3.3-70b-versatile',
];

/** Chat models this key can use, from Groq (cached for a day). Empty on failure. */
function groqModels(bool $refresh = false): array
{
    $cached = json_decode(get_setting('groq_models_cache'), true) ?: [];
    if (!$refresh && !empty($cached['at']) && $cached['at'] > time() - 86400 && !empty($cached['ids'])) return $cached['ids'];
    if (aiKey() === '') return [];
    $ch = curl_init('https://api.groq.com/openai/v1/models');
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . aiKey()], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
    $res = json_decode((string) curl_exec($ch), true) ?: [];
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return $cached['ids'] ?? [];
    $ids = [];
    foreach ($res['data'] ?? [] as $m) {
        $id = (string) ($m['id'] ?? '');
        if ($id === '' || (isset($m['active']) && !$m['active'])) continue;
        // Speech, moderation and agent systems can't write letters.
        if (preg_match('/whisper|tts|playai|orpheus|guard|safeguard|prompt-guard|compound|distil/i', $id)) continue;
        $ids[] = $id;
    }
    sort($ids);
    set_setting('groq_models_cache', json_encode(['at' => time(), 'ids' => $ids]));
    return $ids;
}

/** The best available model: the admin's choice if Groq still offers it, else the first preferred one. */
function groqModel(bool $refresh = false): string
{
    $chosen = get_setting('groq_model');
    $ids = groqModels($refresh);
    if (!$ids) return $chosen ?: GROQ_PREFERRED[0];
    if ($chosen !== '' && in_array($chosen, $ids, true)) return $chosen;
    foreach (GROQ_PREFERRED as $m) if (in_array($m, $ids, true)) return $m;
    return $ids[0];
}

/** Pulls the JSON object out of a reply that may wrap it in text or ``` fences. */
function jsonFromText(string $text): ?array
{
    $d = json_decode($text, true);
    if (is_array($d)) return $d;
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $d = json_decode($m[0], true);
        if (is_array($d)) return $d;
    }
    return null;
}
function aiKey(): string
{
    return trim(get_setting('groq_api_key', (string) secret('groq_api_key', '')));
}

function aiEnabled(): bool
{
    return aiKey() !== '' && get_setting('ai_enabled', '1') === '1';
}

/** AI letters left this month for this user. */
function aiLeft(array $user): int
{
    $c = billingConfig();
    $limit = !billingOn() ? max($c['pro_ai'], 100) : (isPro($user) ? $c['pro_ai'] : $c['free_ai']);
    $s = getDB()->prepare('SELECT COUNT(*) FROM ai_requests WHERE user_id = ? AND ok = 1 AND created_at >= ?');
    $s->execute([$user['id'], date('Y-m-01 00:00:00')]);
    return max(0, $limit - (int) $s->fetchColumn());
}

function logAi(int $userId, string $kind, bool $ok): void
{
    getDB()->prepare('INSERT INTO ai_requests (user_id, kind, ok) VALUES (?, ?, ?)')->execute([$userId, $kind, $ok ? 1 : 0]);
}

/**
 * One chat completion that must answer with JSON.
 *
 * @return array{ok: bool, data: array, error: string}
 */
function groqJson(string $system, string $user, int $maxTokens = 900): array
{
    $model = groqModel();
    $r = groqCall($model, $system, $user, $maxTokens, true);
    // Model retired or not on this key: re-read the list, switch, try once more.
    if (!$r['ok'] && preg_match('/does not exist|do not have access|decommission|deprecat|model_not_found|not supported/i', $r['error'])) {
        $next = groqModel(true);
        if ($next === $model) {
            $ids = groqModels();
            foreach (GROQ_PREFERRED as $m) if ($m !== $model && (!$ids || in_array($m, $ids, true))) { $next = $m; break; }
        }
        if ($next !== $model) {
            set_setting('groq_model', $next);
            error_log("WorkFinder: Groq model {$model} unavailable, switched to {$next}");
            $r = groqCall($next, $system, $user, $maxTokens, true);
        }
    }
    // Some models reject JSON mode; ask again for plain text and read the JSON out of it.
    if (!$r['ok'] && preg_match('/json|response_format/i', $r['error'])) {
        $r = groqCall(groqModel(), $system, $user, $maxTokens, false);
    }
    return $r;
}

/** @return array{ok: bool, data: array, error: string, model: string} */
function groqCall(string $model, string $system, string $user, int $maxTokens, bool $jsonMode): array
{
    $reasoning = (bool) preg_match('#^openai/gpt-oss|qwen3#', $model);
    $body = [
        'model'       => $model,
        'temperature' => 0.5,
        // Reasoning models spend tokens thinking before they answer.
        'max_completion_tokens' => $reasoning ? $maxTokens + 2500 : $maxTokens,
        'messages'    => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
    ];
    if ($jsonMode) $body['response_format'] = ['type' => 'json_object'];
    if (str_starts_with($model, 'openai/gpt-oss')) $body['reasoning_effort'] = 'low';
    if (str_starts_with($model, 'qwen/qwen3')) $body['reasoning_format'] = 'hidden';

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . aiKey(), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 50,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw  = (string) curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') return ['ok' => false, 'data' => [], 'error' => $err, 'model' => $model];
    $res = json_decode($raw, true) ?: [];
    if ($code !== 200) return ['ok' => false, 'data' => [], 'error' => (string) ($res['error']['message'] ?? 'HTTP ' . $code), 'model' => $model];
    $data = jsonFromText((string) ($res['choices'][0]['message']['content'] ?? ''));
    return $data !== null ? ['ok' => true, 'data' => $data, 'error' => '', 'model' => $model] : ['ok' => false, 'data' => [], 'error' => 'The AI answer was not readable.', 'model' => $model];
}

const AI_TONES = [
    'write'   => 'Write a fresh cover letter.',
    'shorter' => 'Rewrite the current draft to be shorter and punchier (about 150 words), keeping every true fact.',
    'formal'  => 'Rewrite the current draft in a more formal, professional tone.',
    'friendly'=> 'Rewrite the current draft in a warm, confident, friendly tone.',
    'tailor'  => 'Rewrite the current draft so it speaks directly to the requirements in the job advert.',
];

/**
 * A cover letter for this user and job (or open application to a company).
 *
 * @return array{ok: bool, subject?: string, letter?: string, error?: string, left?: int}
 */
function aiCoverLetter(array $user, ?array $job, ?array $company, string $mode = 'write', string $draft = ''): array
{
    if (!aiEnabled()) return ['ok' => false, 'error' => 'AI cover letters are not switched on yet.'];
    $paidWithCredit = false;
    if (aiLeft($user) <= 0) {
        // Monthly AI letters used up: a credit (token) pays for this one.
        $paidWithCredit = billingOn() && takeCredit((int) $user['id'], 'AI cover letter');
        if (!$paidWithCredit) {
            $c = billingConfig();
            return ['ok' => false, 'error' => 'You\'ve used all your AI cover letters this month and have no credits left.' . (isPro($user) ? '' : " Go Pro for {$c['pro_ai']} a month.")];
        }
    }
    $mode = isset(AI_TONES[$mode]) ? $mode : 'write';

    $skills = getDB()->prepare('SELECT s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ? ORDER BY s.name');
    $skills->execute([$user['id']]);
    $profile = array_filter([
        'Name'              => $user['full_name'],
        'Headline'          => $user['headline'],
        'Looking for'       => $user['job_titles'],
        'Experience level'  => LEVELS[$user['experience_level'] ?? ''] ?? null,
        'Location'          => trim(($user['location'] ?? '') . ' ' . ($user['province'] ?? '')),
        'Skills'            => implode(', ', $skills->fetchAll(PDO::FETCH_COLUMN)),
        'About'             => $user['bio'],
        'Phone'             => $user['phone'],
        'Email'             => $user['email'],
    ]);
    $target = $job
        ? "JOB ADVERT\nTitle: {$job['title']}\nCompany: " . ($job['company_name'] ?: 'not stated') . "\nLocation: " . ($job['location'] ?: 'not stated')
          . "\nSkills mentioned: " . implode(', ', array_column($job['skills'] ?? [], 'name')) . "\nDescription:\n" . mb_substr(excerpt($job['description'], 3500), 0, 3500)
        : "OPEN APPLICATION (no specific vacancy)\nCompany: {$company['name']}\nIndustry: " . ($company['industry_name'] ?? '') . "\nAbout the company: " . mb_substr((string) $company['description'], 0, 1200);

    $system = <<<SYS
You write job application cover letters for South African job seekers. Answer ONLY with JSON: {"subject":"","letter":""}.
Rules:
- Use South African English spelling (e.g. "organisation", "programme"). Plain text, no markdown, no placeholders like [Company] — leave out anything you don't know.
- 180–260 words unless asked to be shorter. Start "Dear Hiring Manager," (or the person's name if the advert gives one). End "Kind regards," then the applicant's name, phone and email on separate lines.
- Only claim skills and experience that appear in the APPLICANT profile. Never invent qualifications, employers, years or numbers.
- Connect 2–3 of the applicant's real skills to what the advert asks for. Mention the exact job title and company.
- Mention that the CV is attached. Confident, specific, no clichés like "I am writing to express my interest".
- subject: "Application: <job title> – <applicant name>" (or "Open application – <applicant name>").
SYS;
    $prompt = "TASK: " . AI_TONES[$mode] . "\n\nAPPLICANT\n" . implode("\n", array_map(fn($k, $v) => "{$k}: {$v}", array_keys($profile), $profile))
            . "\n\n{$target}" . ($draft !== '' && $mode !== 'write' ? "\n\nCURRENT DRAFT\n" . mb_substr($draft, 0, 4000) : '');

    $r = groqJson($system, $prompt);
    $letter = trim((string) ($r['data']['letter'] ?? ''));
    $ok = $r['ok'] && mb_strlen($letter) > 80;
    logAi((int) $user['id'], 'letter_' . $mode, $ok);
    if (!$ok && $paidWithCredit) addCredits((int) $user['id'], 1, 'refund', 'AI letter failed');
    if (!$ok) return ['ok' => false, 'error' => 'The AI could not write a letter just now' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '. Please try again.'];
    return ['ok' => true, 'subject' => mb_substr(trim((string) ($r['data']['subject'] ?? '')), 0, 250), 'letter' => $letter, 'left' => aiLeft($user)];
}
