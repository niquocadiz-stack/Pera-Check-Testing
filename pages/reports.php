<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();

/* ---- period selection ---- */
$period = in_array($_GET['period'] ?? '', ['daily', 'weekly', 'monthly', 'yearly'], true) ? $_GET['period'] : 'monthly';
$date   = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$month  = valid_month($_GET['month'] ?? null) ? $_GET['month'] : date('Y-m');
$year   = (int) ($_GET['year'] ?? date('Y'));
$year   = min(max($year, 2000), 2100);

switch ($period) {
    case 'daily':
        $from = $to = $date;
        $label = date('l, F j, Y', strtotime($date));
        break;
    case 'weekly':
        $mon = (new DateTime($date))->modify('monday this week');
        $sun = (clone $mon)->modify('+6 days');
        $from = $mon->format('Y-m-d');
        $to = $sun->format('Y-m-d');
        $label = $mon->format('M j') . ' – ' . $sun->format('M j, Y');
        break;
    case 'yearly':
        $from = $year . '-01-01';
        $to = $year . '-12-31';
        $label = (string) $year;
        break;
    default:
        [$from, $to] = month_range($month);
        $label = date('F Y', strtotime($from));
}

/* ---- chart buckets ---- */
$keys = [];
$labels = [];
if ($period === 'yearly') {
    for ($m = 1; $m <= 12; $m++) {
        $keys[] = sprintf('%d-%02d', $year, $m);
        $labels[] = date('M', mktime(0, 0, 0, $m, 1));
    }
    $fmt = '%Y-%m';
} elseif ($period === 'daily') {
    $keys[] = $from;
    $labels[] = date('M j', strtotime($from));
    $fmt = '%Y-%m-%d';
} else {
    for ($d = new DateTime($from); $d <= new DateTime($to); $d->modify('+1 day')) {
        $keys[] = $d->format('Y-m-d');
        $labels[] = $period === 'weekly' ? $d->format('D j') : $d->format('j');
    }
    $fmt = '%Y-%m-%d';
}
$bucket = ['income' => array_fill(0, count($keys), 0.0), 'expense' => array_fill(0, count($keys), 0.0)];
foreach (['income' => ['income', 'income_date'], 'expense' => ['expenses', 'expense_date']] as $k => [$tb, $col]) {
    $st = $pdo->prepare("SELECT DATE_FORMAT($col, '$fmt') AS k, SUM(amount) AS s FROM $tb
                          WHERE user_id = :u AND $col BETWEEN :f AND :t GROUP BY k");
    $st->execute([':u' => $uid, ':f' => $from, ':t' => $to]);
    foreach ($st->fetchAll() as $r) {
        $i = array_search($r['k'], $keys, true);
        if ($i !== false) $bucket[$k][$i] = (float) $r['s'];
    }
}

/* ---- summaries ---- */
$totalIncome  = array_sum($bucket['income']);
$totalExpense = array_sum($bucket['expense']);
$net          = $totalIncome - $totalExpense;
$savingsRate  = $totalIncome > 0 ? ($net / $totalIncome) * 100 : null;

$incomeCats  = category_breakdown($uid, 'income', $from, $to);
$expenseCats = category_breakdown($uid, 'expense', $from, $to);

$ss = $pdo->prepare("SELECT type, SUM(amount) AS s FROM savings_transactions
                      WHERE user_id = :u AND txn_date BETWEEN :f AND :t GROUP BY type");
$ss->execute([':u' => $uid, ':f' => $from, ':t' => $to]);
$sav = ['deposit' => 0.0, 'withdraw' => 0.0];
foreach ($ss->fetchAll() as $r) $sav[$r['type']] = (float) $r['s'];
$totalSaved = user_totals($uid)['savings'];

$ts = $pdo->prepare('SELECT t.type, t.id, t.amount, t.description, t.payment_method, t.tdate, c.name AS category_name
                       FROM (' . txn_union_sql() . ') t JOIN categories c ON c.id = t.category_id
                      WHERE t.tdate BETWEEN :f AND :t ORDER BY t.tdate DESC, t.created_at DESC, t.id DESC');
$ts->execute([':u1' => $uid, ':u2' => $uid, ':f' => $from, ':t' => $to]);
$txns = $ts->fetchAll();

/* ---- CSV export ---- */
if (($_GET['export'] ?? '') === 'csv') {
    $cell = function ($v) {
        if (is_string($v) && !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
            return "'" . $v;   // neutralise spreadsheet formula injection
        }
        return $v;
    };
    $row = function ($out, array $r) use ($cell) { fputcsv($out, array_map($cell, $r)); };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fintrack-' . $period . '-report-' . $from . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $row($out, [APP_NAME . ' ' . ucfirst($period) . ' Report']);
    $row($out, ['Period', $label]);
    $row($out, ['Account', $user['full_name']]);
    $row($out, []);
    $row($out, ['SUMMARY']);
    $row($out, ['Total income', number_format($totalIncome, 2, '.', '')]);
    $row($out, ['Total expenses', number_format($totalExpense, 2, '.', '')]);
    $row($out, ['Net', number_format($net, 2, '.', '')]);
    $row($out, ['Savings deposited', number_format($sav['deposit'], 2, '.', '')]);
    $row($out, ['Savings withdrawn', number_format($sav['withdraw'], 2, '.', '')]);
    $row($out, []);
    $row($out, ['INCOME BY CATEGORY']);
    $row($out, ['Category', 'Amount', 'Share %']);
    foreach ($incomeCats as $c) $row($out, [$c['name'], number_format($c['total'], 2, '.', ''), $totalIncome > 0 ? number_format($c['total'] / $totalIncome * 100, 1) : '0']);
    $row($out, []);
    $row($out, ['EXPENSES BY CATEGORY']);
    $row($out, ['Category', 'Amount', 'Share %']);
    foreach ($expenseCats as $c) $row($out, [$c['name'], number_format($c['total'], 2, '.', ''), $totalExpense > 0 ? number_format($c['total'] / $totalExpense * 100, 1) : '0']);
    $row($out, []);
    $row($out, ['TRANSACTIONS']);
    $row($out, ['ID', 'Type', 'Date', 'Category', 'Description', 'Payment method', 'Amount']);
    foreach ($txns as $t) {
        $row($out, [($t['type'] === 'income' ? 'INC-' : 'EXP-') . $t['id'], ucfirst($t['type']), $t['tdate'],
            $t['category_name'], $t['description'], $t['payment_method'],
            ($t['type'] === 'income' ? '' : '-') . number_format($t['amount'], 2, '.', '')]);
    }
    fclose($out);
    exit;
}

$charts = [
    [
        'id' => 'chartCompare', 'type' => 'bar', 'labels' => $labels,
        'datasets' => [
            ['label' => 'Income',   'data' => $bucket['income'],  'color' => '#16a34a'],
            ['label' => 'Expenses', 'data' => $bucket['expense'], 'color' => '#dc2626'],
        ],
        'empty' => 'No income or expenses in this period.',
    ],
    [
        'id' => 'chartExpenseCats', 'type' => 'doughnut',
        'labels' => array_column($expenseCats, 'name'), 'colors' => array_column($expenseCats, 'color'),
        'datasets' => [['label' => 'Expenses', 'data' => array_map('floatval', array_column($expenseCats, 'total'))]],
        'empty' => 'No expenses in this period.',
    ],
];

$pageTitle  = 'Reports';
$activeNav  = 'reports';
$needCharts = true;
require __DIR__ . '/../includes/header.php';

$exportQs = qs(['export' => 'csv']);
$tabQs = fn(string $p) => http_build_query(['period' => $p, 'date' => $date, 'month' => $month, 'year' => $year]);
?>
<div class="page-head">
    <div>
        <h2>Financial Reports</h2>
        <p><?= e(ucfirst($period)) ?> report &middot; <strong><?= e($label) ?></strong></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline" href="?<?= e($exportQs) ?>"><?= icon('download', 16) ?> Export CSV</a>
        <button type="button" class="btn btn-navy" data-print><?= icon('printer', 16) ?> Print</button>
    </div>
</div>

<section class="card mb filters-card">
    <div class="tabs mb" style="margin-bottom:14px">
        <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $pk => $pl): ?>
            <a class="tab <?= $period === $pk ? 'active' : '' ?>" href="?<?= e($tabQs($pk)) ?>"><?= e($pl) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="filters" action="">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <?php if ($period === 'daily' || $period === 'weekly'): ?>
            <div class="form-group"><label for="rdate"><?= $period === 'daily' ? 'Select day' : 'Any day in the week' ?></label>
                <input type="date" id="rdate" name="date" value="<?= e($date) ?>" required></div>
        <?php elseif ($period === 'monthly'): ?>
            <div class="form-group"><label for="rmonth">Select month</label>
                <input type="month" id="rmonth" name="month" value="<?= e($month) ?>" required></div>
        <?php else: ?>
            <div class="form-group"><label for="ryear">Select year</label>
                <select id="ryear" name="year">
                    <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 10; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select></div>
        <?php endif; ?>
        <div class="btns"><button class="btn" type="submit">View report</button></div>
    </form>
</section>

<section class="grid grid-4 mb">
    <div class="card stat"><div class="stat-icon tone-green"><?= icon('up', 22) ?></div>
        <div><div class="stat-label">Income</div><div class="stat-value text-green"><?= e(money($totalIncome)) ?></div></div></div>
    <div class="card stat"><div class="stat-icon tone-red"><?= icon('down', 22) ?></div>
        <div><div class="stat-label">Expenses</div><div class="stat-value text-red"><?= e(money($totalExpense)) ?></div></div></div>
    <div class="card stat"><div class="stat-icon tone-blue"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Net</div><div class="stat-value <?= $net < 0 ? 'text-red' : 'text-green' ?>"><?= e(money($net)) ?></div>
            <div class="stat-sub"><?= $savingsRate === null ? 'No income in period' : number_format($savingsRate, 1) . '% of income kept' ?></div></div></div>
    <div class="card stat"><div class="stat-icon tone-navy"><?= icon('shield', 22) ?></div>
        <div><div class="stat-label">Net saved</div><div class="stat-value"><?= e(money($sav['deposit'] - $sav['withdraw'])) ?></div>
            <div class="stat-sub">Total savings: <?= e(money($totalSaved)) ?></div></div></div>
</section>

<section class="grid grid-2-1 mb">
    <div class="card">
        <div class="card-head"><h3 class="card-title">Income vs. Expense Comparison</h3></div>
        <div class="chart-box"><canvas id="chartCompare"></canvas></div>
    </div>
    <div class="card">
        <div class="card-head"><h3 class="card-title">Expense Breakdown by Category</h3></div>
        <div class="chart-box"><canvas id="chartExpenseCats"></canvas></div>
    </div>
</section>

<section class="grid grid-3 mb">
    <?php
    $catTable = function (string $title, array $cats, float $total, string $cls) { ?>
        <div class="card">
            <div class="card-head"><h3 class="card-title"><?= e($title) ?></h3></div>
            <table class="table">
                <thead><tr><th>Category</th><th class="text-right">Amount</th><th class="text-right">Share</th></tr></thead>
                <tbody>
                <?php if (!$cats): ?><tr><td colspan="3" class="empty">No data.</td></tr><?php endif; ?>
                <?php foreach ($cats as $c): ?>
                    <tr>
                        <td><span class="cat"><span class="dot" style="background:<?= e($c['color']) ?>"></span><?= e($c['name']) ?></span></td>
                        <td class="text-right nowrap <?= $cls ?>"><?= e(money($c['total'])) ?></td>
                        <td class="text-right muted"><?= $total > 0 ? number_format($c['total'] / $total * 100, 1) : '0.0' ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php };
    $catTable('Income Summary', $incomeCats, $totalIncome, 'amount-income');
    $catTable('Expense Summary', $expenseCats, $totalExpense, 'amount-expense');
    ?>
    <div class="card">
        <div class="card-head"><h3 class="card-title">Savings Summary</h3></div>
        <ul class="list">
            <li><div class="grow">Deposited in period</div><strong class="text-green"><?= e(money($sav['deposit'])) ?></strong></li>
            <li><div class="grow">Withdrawn in period</div><strong class="text-orange"><?= e(money($sav['withdraw'])) ?></strong></li>
            <li><div class="grow">Net saved</div><strong><?= e(money($sav['deposit'] - $sav['withdraw'])) ?></strong></li>
            <li><div class="grow">Total savings now</div><strong><?= e(money($totalSaved)) ?></strong></li>
        </ul>
    </div>
</section>

<section class="card">
    <div class="card-head"><h3 class="card-title">Transactions in this period <span class="muted small">(<?= count($txns) ?>)</span></h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Payment</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            <?php if (!$txns): ?><tr><td colspan="7" class="empty">No transactions in this period.</td></tr><?php endif; ?>
            <?php foreach ($txns as $t): $inc = $t['type'] === 'income'; ?>
                <tr>
                    <td class="nowrap"><?= $inc ? 'INC-' : 'EXP-' ?><?= (int) $t['id'] ?></td>
                    <td class="nowrap"><?= e(fmt_date($t['tdate'])) ?></td>
                    <td><span class="badge <?= $inc ? 'badge-income' : 'badge-expense' ?>"><?= $inc ? 'Income' : 'Expense' ?></span></td>
                    <td><?= e($t['category_name']) ?></td>
                    <td><?= e($t['description'] ?: '—') ?></td>
                    <td><?= e($t['payment_method']) ?></td>
                    <td class="text-right nowrap <?= $inc ? 'amount-income' : 'amount-expense' ?>"><?= $inc ? '+' : '−' ?><?= e(money($t['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?= chart_data_tag($charts) ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
