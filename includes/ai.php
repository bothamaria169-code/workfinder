<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * AI cover letters, on Groq's free API (OpenAI-compatible, Llama 3.3 70B).
 * It writes from the user's profile and the job advert, and can rewrite the
 * user's own draft to be shorter, more formal or more specific.
 *
 * Key: console.groq.com → API Keys, saved in Admin → Settings → AI.
 * Monthly limits per plan are in Admin → Payments → Prices & plans.
 */
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
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . aiKey(), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'           => get_setting('groq_model', 'llama-3.3-70b-versatile'),
            'temperature'     => 0.5,
            'max_tokens'      => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw  = (string) curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') return ['ok' => false, 'data' => [], 'error' => $err];
    $res = json_decode($raw, true) ?: [];
    if ($code !== 200) return ['ok' => false, 'data' => [], 'error' => (string) ($res['error']['message'] ?? 'HTTP ' . $code)];
    $data = json_decode((string) ($res['choices'][0]['message']['content'] ?? ''), true);
    return is_array($data) ? ['ok' => true, 'data' => $data, 'error' => ''] : ['ok' => false, 'data' => [], 'error' => 'The AI answer was not readable.'];
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
    if (aiLeft($user) <= 0) {
        $c = billingConfig();
        return ['ok' => false, 'error' => 'You\'ve used all your AI cover letters this month.' . (isPro($user) ? '' : " Go Pro for {$c['pro_ai']} a month.")];
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
    if (!$ok) return ['ok' => false, 'error' => 'The AI could not write a letter just now' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '. Please try again.'];
    return ['ok' => true, 'subject' => mb_substr(trim((string) ($r['data']['subject'] ?? '')), 0, 250), 'letter' => $letter, 'left' => aiLeft($user)];
}
