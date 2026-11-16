<?php
$nav = [
    'dashboard'     => ['Dashboard',    'home',   'pages/dashboard.php'],
    'income'        => ['Income',       'up',     'pages/income.php'],
    'expenses'      => ['Expenses',     'down',   'pages/expenses.php'],
    'budgets'       => ['Budgets',      'pie',    'pages/budgets.php'],
    'savings'       => ['Savings',      'shield', 'pages/savings.php'],
    'transactions'  => ['Transactions', 'list',   'pages/transactions.php'],
    'reports'       => ['Reports',      'bars',   'pages/reports.php'],
    'notifications' => ['Notifications','bell',   'pages/notifications.php'],
    'settings'      => ['Settings',     'settings','pages/settings.php'],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <span class="brand-mark"><?= icon('wallet', 20) ?></span>
        <span class="brand-name"><?= e(APP_NAME) ?></span>
    </div>
    <nav class="nav">
        <?php foreach ($nav as $key => [$label, $ic, $path]): ?>
            <a class="nav-link<?= $activeNav === $key ? ' active' : '' ?>" href="<?= e(url($path)) ?>">
                <?= icon($ic) ?><span><?= e($label) ?></span>
                <?php if ($key === 'notifications' && ($unread ?? 0) > 0): ?>
                    <span class="nav-badge"><?= (int) $unread ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <form class="sidebar-foot" method="post" action="<?= e(url('auth/logout.php')) ?>">
        <?= csrf_field() ?>
        <button class="nav-link logout-btn" type="submit"><?= icon('logout') ?><span>Log out</span></button>
    </form>
</aside>
