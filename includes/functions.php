<?php
declare(strict_types=1);

// ── JSON response helper ──────────────────────────────────────────
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Safe string ───────────────────────────────────────────────────
function e(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postText(string $key, int $len = 255): string
{
    return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $len);
}

// ── South Africa ──────────────────────────────────────────────────
const PROVINCES = ['Gauteng', 'Western Cape', 'KwaZulu-Natal', 'Eastern Cape', 'Free State', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape'];

const WORK_TYPES = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'internship' => 'Internship', 'temporary' => 'Temporary'];

const LEVELS = ['entry' => 'Entry level', 'junior' => 'Junior', 'mid' => 'Intermediate', 'senior' => 'Senior', 'lead' => 'Lead / Manager'];

const APP_STATUSES = ['saved' => 'Saved', 'applied' => 'Applied', 'interview' => 'Interview', 'offer' => 'Offer', 'rejected' => 'Rejected'];

/** Guesses the province from a free-text location ("Sandton, Johannesburg" → Gauteng). */
function provinceFor(string $location): ?string
{
    $l = strtolower($location);
    foreach (PROVINCES as $p) if (str_contains($l, strtolower($p))) return $p;
    $cities = [
        'Gauteng' => ['johannesburg', 'joburg', 'jhb', 'pretoria', 'tshwane', 'sandton', 'midrand', 'centurion', 'randburg', 'soweto', 'roodepoort', 'kempton', 'germiston', 'benoni', 'boksburg', 'vereeniging', 'rosebank', 'fourways', 'ekurhuleni'],
        'Western Cape' => ['cape town', 'stellenbosch', 'paarl', 'george', 'bellville', 'somerset west', 'worcester', 'mossel bay'],
        'KwaZulu-Natal' => ['durban', 'pietermaritzburg', 'umhlanga', 'richards bay', 'ballito', 'newcastle', 'pinetown'],
        'Eastern Cape' => ['gqeberha', 'port elizabeth', 'east london', 'mthatha', 'makhanda'],
        'Free State' => ['bloemfontein', 'welkom', 'sasolburg'],
        'Limpopo' => ['polokwane', 'tzaneen', 'thohoyandou'],
        'Mpumalanga' => ['mbombela', 'nelspruit', 'witbank', 'emalahleni', 'secunda', 'middelburg'],
        'North West' => ['rustenburg', 'mahikeng', 'potchefstroom', 'klerksdorp', 'brits'],
        'Northern Cape' => ['kimberley', 'upington'],
    ];
    foreach ($cities as $p => $list) foreach ($list as $c) if (str_contains($l, $c)) return $p;
    return null;
}

function formatRand(?int $min, ?int $max): string
{
    $f = fn(int $n) => 'R' . ($n >= 1000 ? number_format($n / 1000, $n % 1000 ? 1 : 0) . 'k' : (string) $n);
    if ($min && $max && $min !== $max) return $f($min) . ' – ' . $f($max);
    if ($min || $max) return $f((int) ($min ?: $max));
    return '';
}

function timeAgo(?string $dt): string
{
    if (!$dt) return '—';
    $s = time() - strtotime($dt);
    return match (true) {
        $s < 60      => 'just now',
        $s < 3600    => floor($s / 60) . ' min ago',
        $s < 86400   => floor($s / 3600) . ' h ago',
        $s < 172800  => 'yesterday',
        $s < 2592000 => floor($s / 86400) . ' days ago',
        default      => date('j M Y', strtotime($dt)),
    };
}

/** Plain-text excerpt of an HTML or text job description. */
function excerpt(?string $text, int $len = 180): string
{
    $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text))));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
}

// ── Sign-up source (first touch) ──────────────────────────────────
function captureAttribution(): void
{
    startAppSession();
    if (!empty($_SESSION['first_touch'])) return;
    $parts = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k) {
        if (!empty($_GET[$k])) $parts[] = substr(preg_replace('/[^\w.\- ]/', '', (string) $_GET[$k]), 0, 60);
    }
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if (!$parts && $ref !== '' && !str_starts_with($ref, APP_URL)) {
        $parts[] = (string) parse_url($ref, PHP_URL_HOST);
    }
    $_SESSION['first_touch'] = $parts ? implode(' / ', array_filter($parts)) : 'Direct';
}

function signupSource(): string
{
    return substr((string) ($_SESSION['first_touch'] ?? 'Direct'), 0, 255);
}

// ── Admin audit trail ─────────────────────────────────────────────
function audit(string $action, string $target = '', string $detail = ''): void
{
    getDB()->prepare('INSERT INTO audit_log (admin_id, action, target, detail, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([$_SESSION['impersonator_id'] ?? $_SESSION['user_id'] ?? null, $action, mb_substr($target, 0, 200), $detail, clientIp()]);
}

// ── Pagination ────────────────────────────────────────────────────
function pager(int $total, int $page, int $per, array $query): string
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) return '';
    $link = function (int $p, string $label, bool $on = false) use ($query) {
        $q = http_build_query(array_merge($query, ['page' => $p]));
        return '<a class="' . ($on ? 'is-on' : '') . '" href="?' . e($q) . '">' . $label . '</a>';
    };
    $out = '<nav class="pager">';
    if ($page > 1) $out .= $link($page - 1, '<i class="fa-solid fa-chevron-left"></i>');
    for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) $out .= $link($p, (string) $p, $p === $page);
    if ($page < $pages) $out .= $link($page + 1, '<i class="fa-solid fa-chevron-right"></i>');
    return $out . '<span>' . number_format($total) . ' results</span></nav>';
}
