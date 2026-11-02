<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Plans and credits.
 *
 *  - Free: a few application emails and AI cover letters a month, 2 job alerts.
 *  - Pro (R49/month): a large monthly allowance, more alerts and AI letters.
 *  - Credits (R10 = 10 application emails): never expire, used once the
 *    month's allowance is gone, on any plan.
 *
 * One credit = one application email that was actually delivered; a failed
 * send is refunded. Prices and allowances are in Admin → Payments, and
 * billing can be switched off there (then only the daily limit applies).
 * Payment is a bank deposit (EFT) with proof, an OTT voucher or a 1Voucher;
 * the admin checks each one and approves it (payments table).
 */
function billingOn(): bool
{
    return get_setting('bill_enabled', '1') === '1';
}

function billingConfig(): array
{
    return [
        'free_emails'  => (int) get_setting('bill_free_emails', '10'),
        'pro_emails'   => (int) get_setting('bill_pro_emails', '300'),
        'pro_price'    => (int) get_setting('bill_pro_price', '49'),
        'pack_price'   => (int) get_setting('bill_pack_price', '10'),
        'pack_size'    => (int) get_setting('bill_pack_size', '10'),
        'free_ai'      => (int) get_setting('bill_free_ai', '5'),
        'pro_ai'       => (int) get_setting('bill_pro_ai', '100'),
        'free_alerts'  => (int) get_setting('bill_free_alerts', '2'),
        'pro_alerts'   => (int) get_setting('bill_pro_alerts', '20'),
        'signup_gift'  => (int) get_setting('bill_signup_gift', '0'),
    ];
}

/** What a user can buy. Keys are stored on payments.package. */
function billingPackages(): array
{
    $c = billingConfig();
    return [
        'credits_1' => ['label' => $c['pack_size'] . ' application credits',       'price' => $c['pack_price'],     'credits' => $c['pack_size'],     'months' => 0, 'note' => 'Never expire'],
        'credits_5' => ['label' => ($c['pack_size'] * 5) . ' application credits', 'price' => $c['pack_price'] * 5, 'credits' => $c['pack_size'] * 5, 'months' => 0, 'note' => 'Never expire'],
        'pro_1'     => ['label' => 'Pro · 1 month',  'price' => $c['pro_price'],     'credits' => 0, 'months' => 1, 'note' => $c['pro_emails'] . ' applications, ' . $c['pro_ai'] . ' AI letters and ' . $c['pro_alerts'] . ' alerts a month'],
        'pro_3'     => ['label' => 'Pro · 3 months', 'price' => $c['pro_price'] * 3, 'credits' => 0, 'months' => 3, 'note' => 'Same as Pro, paid for 3 months'],
    ];
}

/** Ways to pay, in the order the billing page shows them. Admin → Payments → How you get paid. */
function paymentMethods(): array
{
    $bank = ownerBankDetails();
    return [
        'eft'      => ['label' => 'Bank deposit (EFT)', 'short' => 'EFT',      'icon' => 'fa-building-columns', 'on' => get_setting('pay_eft_on', '1') === '1' && $bank['account'] !== ''],
        'ott'      => ['label' => 'OTT voucher',        'short' => 'OTT',      'icon' => 'fa-ticket',           'on' => get_setting('pay_ott_on', '1') === '1'],
        '1voucher' => ['label' => '1Voucher',           'short' => '1Voucher', 'icon' => 'fa-ticket-simple',    'on' => get_setting('pay_1voucher_on', '1') === '1'],
    ];
}

function methodLabel(string $method): string
{
    return paymentMethods()[$method]['label'] ?? strtoupper($method);
}

/** The owner's account that customers deposit into. */
function ownerBankDetails(): array
{
    return [
        'holder'  => get_setting('eft_holder'),
        'bank'    => get_setting('eft_bank'),
        'account' => get_setting('eft_account'),
        'branch'  => get_setting('eft_branch'),
        'type'    => get_setting('eft_type'),
    ];
}

/** The same reference every time for a customer, so admins can match deposits on the statement. */
function eftReference(array $user): string
{
    return 'WF' . str_pad((string) $user['id'], 5, '0', STR_PAD_LEFT);
}

function isPro(array $user): bool
{
    return ($user['plan'] ?? 'free') === 'pro' && !empty($user['plan_expires_at']) && strtotime($user['plan_expires_at']) > time();
}

function freshUser(int $userId): array
{
    $s = getDB()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$userId]);
    return $s->fetch() ?: [];
}

/** This month's application allowance, how much is used, and the credit balance. */
function usageFor(array $user): array
{
    $c = billingConfig();
    $s = getDB()->prepare("SELECT COUNT(*) FROM sent_emails WHERE user_id = ? AND kind = 'application' AND status = 'sent' AND charge_source = 'plan' AND created_at >= ?");
    $s->execute([$user['id'], date('Y-m-01 00:00:00')]);
    $used      = (int) $s->fetchColumn();
    $pro       = isPro($user);
    $allowance = $pro ? $c['pro_emails'] : $c['free_emails'];
    return [
        'on'        => billingOn(),
        'pro'       => $pro,
        'plan'      => $pro ? 'Pro' : 'Free',
        'allowance' => $allowance,
        'used'      => $used,
        'left'      => max(0, $allowance - $used),
        'credits'   => (int) ($user['credits'] ?? 0),
        'renews'    => date('j M', strtotime('first day of next month')),
        'expires'   => $pro ? $user['plan_expires_at'] : null,
    ];
}

function alertLimit(array $user): int
{
    if (!billingOn()) return (int) get_setting('max_alerts', '10');
    $c = billingConfig();
    return isPro($user) ? $c['pro_alerts'] : $c['free_alerts'];
}

/**
 * Reserves one application email before it is sent: from the monthly
 * allowance first, then a credit.
 *
 * @return array{0: ?string, 1: string} [charge source 'plan'|'credit'|'off', or null when out; message]
 */
function reserveApplication(array $user): array
{
    if (!billingOn()) return ['off', ''];
    $u = usageFor(freshUser((int) $user['id']));
    if ($u['left'] > 0) return ['plan', ''];
    // Atomic: two tabs can't spend the same last credit.
    $take = getDB()->prepare('UPDATE users SET credits = credits - 1 WHERE id = ? AND credits > 0');
    $take->execute([$user['id']]);
    if ($take->rowCount() === 1) return ['credit', ''];
    $c = billingConfig();
    return [null, "You've used all {$u['allowance']} application emails in your {$u['plan']} plan this month and have no credits left. "
        . "Top up R{$c['pack_price']} for {$c['pack_size']} applications" . ($u['pro'] ? '' : ", or go Pro for R{$c['pro_price']}/month ({$c['pro_emails']} a month)") . '.'];
}

/** Records the credit spent on a delivered email, or gives it back when the send failed. */
function settleApplication(int $userId, string $source, bool $sent, int $emailId): void
{
    if ($source !== 'credit') return;
    if ($sent) {
        getDB()->prepare("INSERT INTO credit_ledger (user_id, delta, kind, note) VALUES (?, -1, 'use', ?)")->execute([$userId, 'Application email #' . $emailId]);
    } else {
        getDB()->prepare('UPDATE users SET credits = credits + 1 WHERE id = ?')->execute([$userId]);
    }
}

function addCredits(int $userId, int $delta, string $kind, string $note = '', ?int $adminId = null): void
{
    $db = getDB();
    $db->prepare('UPDATE users SET credits = GREATEST(0, credits + ?) WHERE id = ?')->execute([$delta, $userId]);
    $db->prepare('INSERT INTO credit_ledger (user_id, delta, kind, note, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$userId, $delta, $kind, mb_substr($note, 0, 255), $adminId]);
}

/** Pro time is added on top of any time still left. */
function extendPro(int $userId, int $months, string $note = '', ?int $adminId = null): void
{
    $u     = freshUser($userId);
    $start = isPro($u) ? strtotime($u['plan_expires_at']) : time();
    $until = date('Y-m-d H:i:s', strtotime("+{$months} month", $start));
    getDB()->prepare("UPDATE users SET plan = 'pro', plan_expires_at = ? WHERE id = ?")->execute([$until, $userId]);
    getDB()->prepare("INSERT INTO credit_ledger (user_id, delta, kind, note, created_by) VALUES (?, 0, 'pro', ?, ?)")
        ->execute([$userId, "Pro +{$months} month(s) until " . date('j M Y', strtotime($until)) . ($note ? " · {$note}" : ''), $adminId]);
}

function endPro(int $userId, ?int $adminId = null): void
{
    getDB()->prepare("UPDATE users SET plan = 'free', plan_expires_at = NULL WHERE id = ?")->execute([$userId]);
    getDB()->prepare("INSERT INTO credit_ledger (user_id, delta, kind, note, created_by) VALUES (?, 0, 'pro', 'Pro ended by admin', ?)")->execute([$userId, $adminId]);
}

/** Admin approved a payment: give the user what they paid for and tell them. */
function approvePayment(int $paymentId, int $adminId, string $note = ''): string
{
    $db = getDB();
    $claim = $db->prepare("UPDATE payments SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ? AND status = 'pending'");
    $claim->execute([$adminId, $note ?: null, $paymentId]);
    if ($claim->rowCount() !== 1) return 'That payment was already reviewed.';

    $p   = $db->query('SELECT * FROM payments WHERE id = ' . $paymentId)->fetch();
    $pkg = billingPackages()[$p['package']] ?? null;
    if (!$pkg) return 'Unknown package.';
    $via = methodLabel((string) $p['method']);
    if ($pkg['credits']) addCredits((int) $p['user_id'], $pkg['credits'], 'purchase', $via . ' #' . $paymentId, $adminId);
    if ($pkg['months'])  extendPro((int) $p['user_id'], $pkg['months'], $via . ' #' . $paymentId, $adminId);

    $u = freshUser((int) $p['user_id']);
    $what = $pkg['credits'] ? "<strong>{$pkg['credits']} application credits</strong> were added. Your balance is now <strong>{$u['credits']}</strong>."
                            : '<strong>Pro</strong> is active until <strong>' . date('j F Y', strtotime($u['plan_expires_at'])) . '</strong>.';
    sendMail($u['email'], 'Payment approved — ' . $pkg['label'], emailLayout('Thank you — you\'re all set!',
        '<p>We verified your ' . ($p['method'] === 'eft' ? 'bank deposit' : $via) . ' of <strong>R' . number_format((float) ($p['voucher_amount'] ?: $p['amount']), 2) . '</strong>. ' . $what . '</p>', 'Find jobs', APP_URL . '/jobs.php'));
    return '';
}

function rejectPayment(int $paymentId, int $adminId, string $reason): string
{
    $db = getDB();
    $s = $db->prepare("UPDATE payments SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ? AND status = 'pending'");
    $s->execute([$adminId, $reason ?: 'Payment could not be verified', $paymentId]);
    if ($s->rowCount() !== 1) return 'That payment was already reviewed.';
    $p = $db->query('SELECT p.*, u.email FROM payments p JOIN users u ON u.id = p.user_id WHERE p.id = ' . $paymentId)->fetch();
    $eft  = $p['method'] === 'eft';
    $what = $eft ? 'bank deposit' : methodLabel((string) $p['method']);
    sendMail($p['email'], 'We couldn\'t verify your ' . $what, emailLayout('Payment not approved',
        '<p>We couldn\'t verify the ' . e($what) . ' you sent for <strong>' . e(billingPackages()[$p['package']]['label'] ?? $p['package']) . '</strong>.</p>'
        . '<p><strong>Reason:</strong> ' . e($reason ?: 'The payment could not be verified') . '</p>'
        . '<p>' . ($eft ? 'Deposits between banks can take up to 2 business days to clear. Check the reference and proof of payment and try again' : 'Please check the PIN and try again')
        . ', or reply to this email and we\'ll help.</p>', 'Try again', APP_URL . '/billing.php'));
    return '';
}

/** A short line for the sidebar and apply page. */
function usageBadge(array $user): string
{
    $u = usageFor($user);
    if (!$u['on']) return '';
    return $u['plan'] . ' · ' . $u['left'] . '/' . $u['allowance'] . ' applications left' . ($u['credits'] ? ' + ' . $u['credits'] . ' credits' : '');
}
