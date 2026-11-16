<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];

$totals = user_totals($uid);

$month = date('Y-m');
[$mFrom, $mTo] = month_range($month);
$prevMonth = date('Y-m', strtotime('first day of last month'));
[$pFrom, $pTo] = month_range($prevMonth);

$monthIncome  = sum_between('income', $uid, $mFrom, $mTo);
$monthExpense = sum_between('expense', $uid, $mFrom, $mTo);
$prevExpense  = sum_between('expense', $uid, $pFrom, $pTo);
$prevIncome   = sum_between('income', $uid, $pFrom, $pTo);
$expenseChange = $prevExpense > 0 ? (($monthExpense - $prevExpense) / $prevExpense) * 100 : null;

$remaining = remaining_budget($uid, $month);
$budgets   = budget_rows($uid, $month);

$series    = monthly_series($uid, 6);
$trend     = monthly_series($uid, 12);
$breakdown = category_breakdown($uid, 'expense', $mFrom, $mTo);
$recent    = recent_transactions($uid, 6);

$st = db()->prepare('SELECT type, title, message, created_at FROM notifications
                      WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 6');
$st->execute([':u' => $uid]);
$activities = $st->fetchAll();

$charts = [
    [
        'id' => 'chartIncomeExpense', 'type' => 'bar', 'labels' => $series['labels'],
        'datasets' => [
            ['label' => 'Income',   'data' => $series['income'],  'color' => '#16a34a'],
            ['label' => 'Expenses', 'data' => $series['expense'], 'color' => '#dc2626'],
        ],
        'empty' => 'Add income or expenses to see this chart.',
    ],
    [
        'id' => 'chartCategories', 'type' => 'doughnut',
        'labels' => array_column($breakdown, 'name'),
        'colors' => array_column($breakdown, 'color'),
        'datasets' => [['label' => 'Expenses', 'data' => array_map('floatval', array_column($breakdown, 'total'))]],
        'empty' => 'No expenses recorded this month.',
    ],
    [
        'id' => 'chartTrend', 'type' => 'line', 'fill' => true, 'labels' => $trend['labels'],
        'datasets' => [['label' => 'Spending', 'data' => $trend['expense'], 'color' => '#2563eb']],
        'empty' => 'Spending trends appear once you record expenses.',
    ],
];

$pageTitle  = 'Dashboard';
$activeNav  = 'dashboard';
$needCharts = true;
require __DIR__ . '/../includes/header.php';

$stateClass = ['ok' => '', 'warning' => 'warning', 'critical' => 'critical', 'over' => 'over'];
?>
<div class="page-head">
    <div>
        <h2>Hello, <?= e(explode(' ', $user['full_name'])[0]) ?> 👋</h2>
        <p>Here is your financial overview for <?= e(date('F Y')) ?>.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-green" href="<?= e(url('pages/income.php?add=1')) ?>"><?= icon('plus', 16) ?> Add income</a>
        <a class="btn btn-red" href="<?= e(url('pages/expenses.php?add=1')) ?>"><?= icon('plus', 16) ?> Add expense</a>
    </div>
</div>

<section class="grid grid-5 mb">
    <div class="card stat">
        <div class="stat-icon tone-blue"><?= icon('wallet', 22) ?></div>
        <div>
            <div class="stat-label">Total Balance</div>
            <div class="stat-value <?= $totals['balance'] < 0 ? 'text-red' : '' ?>"><?= e(money($totals['balance'])) ?></div>
        </div>
    </div>
    <div class="card stat">
        <div class="stat-icon tone-green"><?= icon('up', 22) ?></div>
        <div>
            <div class="stat-label">Total Income</div>
            <div class="stat-value text-green"><?= e(money($totals['income'])) ?></div>
            <div class="stat-sub"><?= e(money($monthIncome)) ?> this month</div>
        </div>
    </div>
    <div class="card stat">
        <div class="stat-icon tone-red"><?= icon('down', 22) ?></div>
        <div>
            <div class="stat-label">Total Expenses</div>
            <div class="stat-value text-red"><?= e(money($totals['expense'])) ?></div>
            <div class="stat-sub"><?= e(money($monthExpense)) ?> this month</div>
        </div>
    </div>
    <div class="card stat">
        <div class="stat-icon tone-navy"><?= icon('shield', 22) ?></div>
        <div>
            <div class="stat-label">Total Savings</div>
            <div class="stat-value"><?= e(money($totals['savings'])) ?></div>
            <div class="stat-sub">Across all goals</div>
        </div>
    </div>
    <div class="card stat">
        <div class="stat-icon <?= ($remaining && $remaining['remaining'] < 0) ? 'tone-red' : 'tone-orange' ?>"><?= icon('pie', 22) ?></div>
        <div>
            <div class="stat-label">Remaining Budget</div>
            <?php if ($remaining): ?>
                <div class="stat-value <?= $remaining['remaining'] < 0 ? 'text-red' : '' ?>"><?= e(money($remaining['remaining'])) ?></div>
                <div class="stat-sub">of <?= e(money($remaining['budget'])) ?> for <?= e(date('M')) ?></div>
            <?php else: ?>
                <div class="stat-value muted">—</div>
                <div class="stat-sub"><a href="<?= e(url('pages/budgets.php')) ?>">Set a budget</a></div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="grid grid-2-1 mb">
    <div class="card">
        <div class="card-head"><h3 class="card-title">Income vs. Expenses <span class="muted small">· last 6 months</span></h3></div>
        <div class="chart-box"><canvas id="chartIncomeExpense"></canvas></div>
    </div>
    <div class="card">
        <div class="card-head"><h3 class="card-title">Expense Breakdown <span class="muted small">· <?= e(date('F')) ?></span></h3></div>
        <div class="chart-box"><canvas id="chartCategories"></canvas></div>
    </div>
</section>

<section class="grid grid-2-1 mb">
    <div class="card">
        <div class="card-head"><h3 class="card-title">Monthly Spending Trend <span class="muted small">· last 12 months</span></h3></div>
        <div class="chart-box"><canvas id="chartTrend"></canvas></div>
    </div>
    <div class="card">
        <div class="card-head"><h3 class="card-title">Monthly Overview</h3><span class="muted small"><?= e(date('F Y')) ?></span></div>
        <ul class="list">
            <li><div class="grow">Income</div><strong class="text-green"><?= e(money($monthIncome)) ?></strong></li>
            <li><div class="grow">Expenses</div><strong class="text-red"><?= e(money($monthExpense)) ?></strong></li>
            <li><div class="grow">Net this month</div>
                <strong class="<?= ($monthIncome - $monthExpense) < 0 ? 'text-red' : 'text-green' ?>"><?= e(money($monthIncome - $monthExpense)) ?></strong></li>
            <li><div class="grow">Last month's expenses<div class="muted small"><?= e(date('F', strtotime($pFrom))) ?></div></div>
                <strong><?= e(money($prevExpense)) ?></strong></li>
        </ul>
        <?php if ($expenseChange === null): ?>
            <div class="alert alert-info"><?= icon('info', 16) ?><span>No spending last month to compare with yet.</span></div>
        <?php elseif ($expenseChange > 0): ?>
            <div class="alert alert-warning"><?= icon('alert', 16) ?><span>Spending is <strong><?= number_format($expenseChange, 1) ?>% higher</strong> than last month.</span></div>
        <?php else: ?>
            <div class="alert alert-success"><?= icon('check', 16) ?><span>Spending is <strong><?= number_format(abs($expenseChange), 1) ?>% lower</strong> than last month. Nice!</span></div>
        <?php endif; ?>
    </div>
</section>

<section class="grid grid-2-1 mb">
    <div class="card">
        <div class="card-head">
            <h3 class="card-title">Recent Transactions</h3>
            <a class="btn btn-outline btn-sm" href="<?= e(url('pages/transactions.php')) ?>">View all</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="4" class="empty">No transactions yet. Add your first income or expense.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $t): ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($t['tdate'])) ?></td>
                        <td><span class="cat"><span class="dot" style="background:<?= e($t['color']) ?>"></span><?= e($t['category_name']) ?></span></td>
                        <td><?= e($t['description'] ?: '—') ?></td>
                        <td class="text-right nowrap amount-<?= e($t['type']) ?>"><?= $t['type'] === 'income' ? '+' : '−' ?><?= e(money($t['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-head">
            <h3 class="card-title">Budget Status</h3>
            <a class="btn btn-outline btn-sm" href="<?= e(url('pages/budgets.php')) ?>">Manage</a>
        </div>
        <?php if (!$budgets): ?>
            <p class="empty">No budgets for <?= e(date('F')) ?> yet.</p>
        <?php endif; ?>
        <?php foreach (array_slice($budgets, 0, 5) as $b): ?>
            <div class="budget-item">
                <div class="progress-row">
                    <strong><?= e($b['label']) ?></strong>
                    <span class="muted"><?= e(money($b['spent'])) ?> / <?= e(money($b['amount'])) ?></span>
                </div>
                <div class="progress <?= $stateClass[$b['state']] ?>"><span style="width:<?= min(100, round($b['pct'], 1)) ?>%"></span></div>
                <?php if ($b['level'] > 0): ?>
                    <div class="small <?= $b['state'] === 'over' ? 'text-red' : 'text-orange' ?>" style="margin-top:4px">
                        <?= $b['level'] === 100 ? 'Budget reached or exceeded' : $b['level'] . '% of budget used' ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card mb">
    <div class="card-head">
        <h3 class="card-title">Recent Activities &amp; Alerts</h3>
        <a class="btn btn-outline btn-sm" href="<?= e(url('pages/notifications.php')) ?>">All notifications</a>
    </div>
    <?php if (!$activities): ?>
        <p class="empty">Nothing yet. Your activity and budget alerts will show up here.</p>
    <?php else: ?>
        <ul class="list">
            <?php
            $tone = ['success' => 'tone-green', 'info' => 'tone-blue', 'warning' => 'tone-orange', 'danger' => 'tone-red'];
            $ico  = ['success' => 'check', 'info' => 'info', 'warning' => 'alert', 'danger' => 'alert'];
            foreach ($activities as $a): ?>
                <li>
                    <div class="notif-icon <?= $tone[$a['type']] ?>"><?= icon($ico[$a['type']], 18) ?></div>
                    <div class="grow">
                        <div class="notif-title"><?= e($a['title']) ?></div>
                        <div class="muted small"><?= e($a['message']) ?></div>
                    </div>
                    <span class="muted small nowrap"><?= e(time_ago($a['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?= chart_data_tag($charts) ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
