<?php
/**
 * Shared helpers: session, security, formatting, financial calculations.
 */

require_once __DIR__ . '/../config/database.php';

/* ------------------------------------------------------------------
 * Session + security headers
 * ------------------------------------------------------------------ */
if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name('fintrack_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net; "
        . "frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
}

// The app never uses array inputs; drop them so type juggling can't cause errors.
foreach ($_GET as $k => $v)  { if (is_array($v)) unset($_GET[$k]); }
foreach ($_POST as $k => $v) { if (is_array($v)) unset($_POST[$k]); }

/* ------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------ */
const PAYMENT_METHODS = ['Cash', 'Bank Transfer', 'Debit Card', 'Credit Card', 'E-Wallet (GCash/Maya)', 'Check', 'Other'];

const CURRENCIES = [
    'PHP' => ['₱', 'Philippine Peso', 2],
    'USD' => ['$', 'US Dollar', 2],
    'EUR' => ['€', 'Euro', 2],
    'GBP' => ['£', 'British Pound', 2],
    'JPY' => ['¥', 'Japanese Yen', 0],
    'AUD' => ['A$', 'Australian Dollar', 2],
    'CAD' => ['C$', 'Canadian Dollar', 2],
    'SGD' => ['S$', 'Singapore Dollar', 2],
    'INR' => ['₹', 'Indian Rupee', 2],
    'KRW' => ['₩', 'South Korean Won', 0],
];

const DEFAULT_CATEGORIES = [
    'income' => [
        'Salary' => '#16a34a', 'Allowance' => '#0ea5e9', 'Business' => '#8b5cf6',
        'Freelance' => '#14b8a6', 'Investments' => '#f59e0b', 'Other Income' => '#64748b',
    ],
    'expense' => [
        'Food' => '#ef4444', 'Transportation' => '#f97316', 'Education' => '#6366f1',
        'Shopping' => '#ec4899', 'Entertainment' => '#a855f7', 'Bills and Utilities' => '#0ea5e9',
        'Healthcare' => '#14b8a6', 'Personal Care' => '#eab308', 'Other Expenses' => '#64748b',
    ],
];

/* ------------------------------------------------------------------
 * Small utilities
 * ------------------------------------------------------------------ */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/** Trim, strip control chars and cap the length of free text. */
function clean_str($value, int $max = 255): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
}

function valid_date(?string $s): bool
{
    if ($s === null || $s === '') {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

function valid_month(?string $s): bool
{
    return $s !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $s) === 1;
}

/** Returns a positive amount with max 2 decimals, or null when invalid. */
function parse_amount($raw): ?float
{
    $raw = str_replace([',', ' '], '', trim((string) $raw));
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    $n = round((float) $raw, 2);
    return ($n > 0 && $n <= 9999999999.99) ? $n : null;
}

function fmt_date(?string $d): string
{
    return $d ? date('M d, Y', strtotime($d)) : '—';
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day(s) ago';
    return date('M d, Y', strtotime($datetime));
}

/** Build a query string from the current GET params with overrides. */
function qs(array $override = []): string
{
    $q = array_merge($_GET, $override);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
    }
    return http_build_query($q);
}

/* ------------------------------------------------------------------
 * Flash messages
 * ------------------------------------------------------------------ */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Abort the request when the CSRF token is missing or wrong. */
function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Security token expired or invalid. Please go back, refresh the page and try again.');
    }
}

/* ------------------------------------------------------------------
 * Authentication / access control
 * ------------------------------------------------------------------ */
function current_user(): array
{
    return $GLOBALS['CURRENT_USER'] ?? [];
}

function uid(): int
{
    return (int) (current_user()['id'] ?? 0);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid']  = $userId;
    $_SESSION['last'] = time();
}

/** Guard for every dashboard page. Loads the logged-in user. */
function require_login(): array
{
    if (empty($_SESSION['uid'])) {
        redirect('auth/login.php');
    }
    if (time() - (int) ($_SESSION['last'] ?? 0) > SESSION_TIMEOUT) {
        logout_user();
        session_start();
        flash('warning', 'Your session expired. Please sign in again.');
        redirect('auth/login.php');
    }
    $_SESSION['last'] = time();
    $st = db()->prepare('SELECT id, full_name, email, currency, avatar, created_at FROM users WHERE id = :id');
    $st->execute([':id' => (int) $_SESSION['uid']]);
    $user = $st->fetch();
    if (!$user) {
        logout_user();
        session_start();
        redirect('auth/login.php');
    }
    $GLOBALS['CURRENT_USER'] = $user;
    return $user;
}

function redirect_if_logged_in(): void
{
    if (!empty($_SESSION['uid'])) {
        redirect('pages/dashboard.php');
    }
}

function seed_default_categories(int $userId): void
{
    $st = db()->prepare('INSERT IGNORE INTO categories (user_id, name, type, color) VALUES (:u, :n, :t, :c)');
    foreach (DEFAULT_CATEGORIES as $type => $list) {
        foreach ($list as $name => $color) {
            $st->execute([':u' => $userId, ':n' => $name, ':t' => $type, ':c' => $color]);
        }
    }
}

/* ------------------------------------------------------------------
 * Money formatting
 * ------------------------------------------------------------------ */
function money($amount, ?string $currency = null): string
{
    $code = $currency ?? (current_user()['currency'] ?? 'PHP');
    [$symbol, , $dec] = CURRENCIES[$code] ?? CURRENCIES['PHP'];
    $amount = (float) $amount;
    $out = $symbol . number_format(abs($amount), $dec);
    return ($amount < 0 && round(abs($amount), $dec) > 0) ? '-' . $out : $out;
}

function currency_symbol(): string
{
    return (CURRENCIES[current_user()['currency'] ?? 'PHP'] ?? CURRENCIES['PHP'])[0];
}

function currency_decimals(): int
{
    return (CURRENCIES[current_user()['currency'] ?? 'PHP'] ?? CURRENCIES['PHP'])[2];
}

/* ------------------------------------------------------------------
 * Categories
 * ------------------------------------------------------------------ */
function get_categories(int $userId, ?string $type = null): array
{
    $sql = 'SELECT id, name, type, color FROM categories WHERE user_id = :u';
    $p = [':u' => $userId];
    if ($type !== null) {
        $sql .= ' AND type = :t';
        $p[':t'] = $type;
    }
    $st = db()->prepare($sql . ' ORDER BY type, name');
    $st->execute($p);
    return $st->fetchAll();
}

/* ------------------------------------------------------------------
 * Financial calculations (always from real DB records)
 * ------------------------------------------------------------------ */

/** SQL for a unified income + expense list. Needs :u1 and :u2 bound. */
function txn_union_sql(): string
{
    return "SELECT 'income' AS type, i.id, i.category_id, i.amount, i.description, i.payment_method,
                   i.income_date AS tdate, i.created_at
              FROM income i WHERE i.user_id = :u1
            UNION ALL
            SELECT 'expense', x.id, x.category_id, x.amount, x.description, x.payment_method,
                   x.expense_date, x.created_at
              FROM expenses x WHERE x.user_id = :u2";
}

/** Lifetime totals. Balance = income - expenses - money set aside in savings. */
function user_totals(int $userId): array
{
    $st = db()->prepare(
        'SELECT (SELECT COALESCE(SUM(amount),0) FROM income  WHERE user_id = :a) AS income,
                (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = :b) AS expense,
                (SELECT COALESCE(SUM(current_amount),0) FROM savings_goals WHERE user_id = :c) AS savings'
    );
    $st->execute([':a' => $userId, ':b' => $userId, ':c' => $userId]);
    $r = $st->fetch();
    $income = (float) $r['income'];
    $expense = (float) $r['expense'];
    $savings = (float) $r['savings'];
    return [
        'income'  => $income,
        'expense' => $expense,
        'savings' => $savings,
        'balance' => round($income - $expense - $savings, 2),
    ];
}

/** Sum of income/expenses between two dates (inclusive). */
function sum_between(string $type, int $userId, string $from, string $to): float
{
    $table = $type === 'income' ? 'income' : 'expenses';
    $col   = $type === 'income' ? 'income_date' : 'expense_date';
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM $table WHERE user_id = :u AND $col BETWEEN :f AND :t");
    $st->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
    return (float) $st->fetchColumn();
}

function month_range(string $month): array
{
    $first = $month . '-01';
    return [$first, date('Y-m-t', strtotime($first))];
}

/** Income/expense totals for the last $n months (oldest first). */
function monthly_series(int $userId, int $n = 6): array
{
    $keys = [];
    $labels = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-01') . " -$i month");
        $keys[] = date('Y-m', $ts);
        $labels[] = date('M Y', $ts);
    }
    $start = $keys[0] . '-01';
    $result = ['labels' => $labels, 'income' => array_fill(0, $n, 0.0), 'expense' => array_fill(0, $n, 0.0)];
    foreach (['income' => ['income', 'income_date'], 'expense' => ['expenses', 'expense_date']] as $k => [$table, $col]) {
        $st = db()->prepare("SELECT DATE_FORMAT($col,'%Y-%m') AS m, SUM(amount) AS total
                               FROM $table WHERE user_id = :u AND $col >= :s GROUP BY m");
        $st->execute([':u' => $userId, ':s' => $start]);
        foreach ($st->fetchAll() as $row) {
            $idx = array_search($row['m'], $keys, true);
            if ($idx !== false) {
                $result[$k][$idx] = (float) $row['total'];
            }
        }
    }
    return $result;
}

/** Category totals between two dates, biggest first. */
function category_breakdown(int $userId, string $type, string $from, string $to): array
{
    $table = $type === 'income' ? 'income' : 'expenses';
    $col   = $type === 'income' ? 'income_date' : 'expense_date';
    $st = db()->prepare("SELECT c.name, c.color, SUM(t.amount) AS total, COUNT(*) AS cnt
                           FROM $table t JOIN categories c ON c.id = t.category_id
                          WHERE t.user_id = :u AND t.$col BETWEEN :f AND :t
                          GROUP BY c.id, c.name, c.color ORDER BY total DESC");
    $st->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
    return $st->fetchAll();
}

function recent_transactions(int $userId, int $limit = 6): array
{
    $st = db()->prepare('SELECT t.*, c.name AS category_name, c.color
                           FROM (' . txn_union_sql() . ') t JOIN categories c ON c.id = t.category_id
                          ORDER BY t.tdate DESC, t.created_at DESC, t.id DESC LIMIT :lim');
    $st->bindValue(':u1', $userId, PDO::PARAM_INT);
    $st->bindValue(':u2', $userId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/* ------------------------------------------------------------------
 * Budgets
 * ------------------------------------------------------------------ */

/** Budgets of a month with spent / remaining / percent / status. */
function budget_rows(int $userId, string $month): array
{
    $st = db()->prepare(
        "SELECT b.id, b.category_id, b.month, b.amount, c.name AS category_name, c.color,
                COALESCE((SELECT SUM(e.amount) FROM expenses e
                           WHERE e.user_id = b.user_id
                             AND e.expense_date >= CONCAT(b.month, '-01')
                             AND e.expense_date <  DATE_ADD(CONCAT(b.month, '-01'), INTERVAL 1 MONTH)
                             AND (b.category_id IS NULL OR e.category_id = b.category_id)), 0) AS spent
           FROM budgets b LEFT JOIN categories c ON c.id = b.category_id
          WHERE b.user_id = :u AND b.month = :m
          ORDER BY (b.category_id IS NULL) DESC, c.name"
    );
    $st->execute([':u' => $userId, ':m' => $month]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['label']     = $r['category_id'] === null ? 'Overall monthly budget' : $r['category_name'];
        $r['amount']    = (float) $r['amount'];
        $r['spent']     = (float) $r['spent'];
        $r['remaining'] = round($r['amount'] - $r['spent'], 2);
        $r['pct']       = $r['amount'] > 0 ? ($r['spent'] / $r['amount']) * 100 : 0;
        if ($r['pct'] >= 100)     { $r['level'] = 100; $r['state'] = 'over'; }
        elseif ($r['pct'] >= 90)  { $r['level'] = 90;  $r['state'] = 'critical'; }
        elseif ($r['pct'] >= 75)  { $r['level'] = 75;  $r['state'] = 'warning'; }
        else                      { $r['level'] = 0;   $r['state'] = 'ok'; }
    }
    return $rows;
}

/**
 * Remaining budget for a month: the overall budget if set, otherwise the
 * sum of category budgets. Returns null when no budget exists.
 */
function remaining_budget(int $userId, string $month): ?array
{
    $rows = budget_rows($userId, $month);
    if (!$rows) {
        return null;
    }
    foreach ($rows as $r) {
        if ($r['category_id'] === null) {
            return ['budget' => $r['amount'], 'spent' => $r['spent'], 'remaining' => $r['remaining']];
        }
    }
    $budget = array_sum(array_column($rows, 'amount'));
    $spent  = array_sum(array_column($rows, 'spent'));
    return ['budget' => $budget, 'spent' => $spent, 'remaining' => round($budget - $spent, 2)];
}

/* ------------------------------------------------------------------
 * Notifications
 * ------------------------------------------------------------------ */
function notify(int $userId, string $type, string $title, string $message, ?string $refKey = null): void
{
    $st = db()->prepare('INSERT IGNORE INTO notifications (user_id, type, title, message, ref_key)
                         VALUES (:u, :t, :ti, :m, :r)');
    $st->execute([':u' => $userId, ':t' => $type, ':ti' => $title, ':m' => $message, ':r' => $refKey]);
}

function unread_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
    $st->execute([':u' => $userId]);
    return (int) $st->fetchColumn();
}

/** Create 75% / 90% / 100% budget alerts (once per level) for the month of $date. */
function check_budget_alerts(int $userId, string $date): void
{
    $month = substr($date, 0, 7);
    foreach (budget_rows($userId, $month) as $r) {
        if ($r['level'] === 0) {
            continue;
        }
        $monthName = date('F Y', strtotime($month . '-01'));
        $ref = 'budget-' . $r['id'] . '-' . $r['level'];
        if ($r['level'] === 100) {
            $title = 'Budget exceeded';
            $msg = sprintf('%s budget for %s has been %s (%s of %s used).', $r['label'], $monthName,
                $r['spent'] > $r['amount'] ? 'exceeded' : 'reached', money($r['spent']), money($r['amount']));
            notify($userId, 'danger', $title, $msg, $ref);
        } else {
            $title = 'Budget almost exhausted';
            $msg = sprintf('%d%% of the %s budget for %s is used (%s left).', $r['level'], $r['label'], $monthName,
                money($r['remaining']));
            notify($userId, 'warning', $title, $msg, $ref);
        }
    }
}

/** Notify (once per month) when spending is already above last month's total. */
function check_spending_trend(int $userId): void
{
    $thisMonth = date('Y-m');
    $prevMonth = date('Y-m', strtotime('first day of last month'));
    [$cf, $ct] = month_range($thisMonth);
    [$pf, $pt] = month_range($prevMonth);
    $cur  = sum_between('expense', $userId, $cf, $ct);
    $prev = sum_between('expense', $userId, $pf, $pt);
    if ($prev > 0 && $cur > $prev) {
        notify($userId, 'warning', 'Spending is higher than last month',
            sprintf('You have spent %s in %s, already above last month\'s %s.', money($cur), date('F'), money($prev)),
            'trend-' . $thisMonth);
    }
}

/* ------------------------------------------------------------------
 * UI helpers
 * ------------------------------------------------------------------ */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'up'       => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'down'     => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>',
        'pie'      => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'target'   => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'bars'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'settings' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'     => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'edit'     => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'printer'  => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'wallet'   => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'arrowup'  => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
        'arrowdown'=> '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $p . '</svg>';
}

function avatar_html(array $user, string $class = ''): string
{
    if (!empty($user['avatar'])) {
        return '<img class="avatar ' . e($class) . '" src="' . e(url('assets/images/uploads/' . $user['avatar']))
            . '" alt="Profile picture">';
    }
    $initial = mb_strtoupper(mb_substr($user['full_name'] ?? '?', 0, 1));
    return '<span class="avatar avatar-initial ' . e($class) . '">' . e($initial) . '</span>';
}

/** Pagination links keeping the current filters. */
function render_pagination(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Pagination">';
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false): string {
        if ($disabled) {
            return '<span class="page-link disabled">' . $label . '</span>';
        }
        return '<a class="page-link' . ($active ? ' active' : '') . '" href="?' . e(qs(['page' => $p])) . '">' . $label . '</a>';
    };
    $html .= $link($page - 1, '&laquo; Prev', false, $page <= 1);
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1) {
        $html .= $link(1, '1');
        if ($start > 2) $html .= '<span class="page-link disabled">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $link($i, (string) $i, $i === $page);
    }
    if ($end < $pages) {
        if ($end < $pages - 1) $html .= '<span class="page-link disabled">…</span>';
        $html .= $link($pages, (string) $pages);
    }
    $html .= $link($page + 1, 'Next &raquo;', false, $page >= $pages);
    return $html . '</nav>';
}

/** Emit chart definitions for assets/js/app.js. */
function chart_data_tag(array $charts): string
{
    $json = json_encode([
        'currency' => currency_symbol(),
        'decimals' => currency_decimals(),
        'charts'   => $charts,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return '<script type="application/json" id="chart-data">' . $json . '</script>';
}
