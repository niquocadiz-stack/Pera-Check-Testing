<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();

/* ---- filters ---- */
$q       = clean_str($_GET['q'] ?? '', 100);
$type    = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$fCat    = (int) ($_GET['category'] ?? 0);
$from    = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to      = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$sortKey = $_GET['sort'] ?? 'date_desc';
$sorts = [
    'date_desc'   => 't.tdate DESC, t.created_at DESC, t.id DESC',
    'date_asc'    => 't.tdate ASC, t.created_at ASC, t.id ASC',
    'amount_desc' => 't.amount DESC, t.id DESC',
    'amount_asc'  => 't.amount ASC, t.id ASC',
    'category'    => 'c.name ASC, t.tdate DESC',
];
if (!isset($sorts[$sortKey])) $sortKey = 'date_desc';

$where  = ['1 = 1'];
$params = [':u1' => $uid, ':u2' => $uid];
if ($type !== '') { $where[] = 't.type = :type'; $params[':type'] = $type; }
if ($q !== '') {
    $where[] = '(t.description LIKE :q1 OR c.name LIKE :q2 OR t.payment_method LIKE :q3 OR CONCAT(IF(t.type = \'income\', \'INC-\', \'EXP-\'), t.id) LIKE :q4)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $like;
}
if ($fCat > 0)    { $where[] = 't.category_id = :cat'; $params[':cat'] = $fCat; }
if ($from !== '') { $where[] = 't.tdate >= :from'; $params[':from'] = $from; }
if ($to !== '')   { $where[] = 't.tdate <= :to';   $params[':to'] = $to; }

$base = 'FROM (' . txn_union_sql() . ') t JOIN categories c ON c.id = t.category_id WHERE ' . implode(' AND ', $where);

$cs = $pdo->prepare("SELECT COUNT(*) AS n,
                            COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS inc,
                            COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS exp $base");
$cs->execute($params);
$agg = $cs->fetch();
$totalRows = (int) $agg['n'];
$perPage = 12;
$pages = max(1, (int) ceil($totalRows / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

$ls = $pdo->prepare("SELECT t.type, t.id, t.category_id, t.amount, t.description, t.payment_method, t.tdate, t.created_at,
                            c.name AS category_name, c.color $base ORDER BY {$sorts[$sortKey]} LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $ls->bindValue($k, $v);
$ls->bindValue(':lim', $perPage, PDO::PARAM_INT);
$ls->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$ls->execute();
$rows = $ls->fetchAll();

$allCats = get_categories($uid);
$hasFilters = ($q !== '' || $type !== '' || $fCat || $from !== '' || $to !== '');
$returnQs = qs();

$pageTitle = 'Transactions';
$activeNav = 'transactions';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Transaction History</h2>
        <p>Every income and expense in one place.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-green" href="<?= e(url('pages/income.php?add=1')) ?>"><?= icon('plus', 16) ?> Income</a>
        <a class="btn btn-red" href="<?= e(url('pages/expenses.php?add=1')) ?>"><?= icon('plus', 16) ?> Expense</a>
    </div>
</div>

<section class="grid grid-3 mb">
    <div class="card stat"><div class="stat-icon tone-green"><?= icon('up', 22) ?></div>
        <div><div class="stat-label"><?= $hasFilters ? 'Income (filtered)' : 'Total income' ?></div><div class="stat-value amount-income"><?= e(money($agg['inc'])) ?></div></div></div>
    <div class="card stat"><div class="stat-icon tone-red"><?= icon('down', 22) ?></div>
        <div><div class="stat-label"><?= $hasFilters ? 'Expenses (filtered)' : 'Total expenses' ?></div><div class="stat-value amount-expense"><?= e(money($agg['exp'])) ?></div></div></div>
    <div class="card stat"><div class="stat-icon tone-blue"><?= icon('list', 22) ?></div>
        <div><div class="stat-label">Net / records</div>
            <div class="stat-value <?= ($agg['inc'] - $agg['exp']) < 0 ? 'text-red' : 'text-green' ?>"><?= e(money($agg['inc'] - $agg['exp'])) ?></div>
            <div class="stat-sub"><?= $totalRows ?> transaction<?= $totalRows === 1 ? '' : 's' ?></div></div></div>
</section>

<section class="card mb filters-card">
    <form method="get" class="filters" action="">
        <div class="form-group grow"><label for="fq">Search</label>
            <input type="text" id="fq" name="q" value="<?= e($q) ?>" placeholder="ID, description, category, payment method"></div>
        <div class="form-group"><label for="ftype">Type</label>
            <select id="ftype" name="type">
                <option value="">All types</option>
                <option value="income" <?= $type === 'income' ? 'selected' : '' ?>>Income</option>
                <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Expenses</option>
            </select></div>
        <div class="form-group"><label for="fcat">Category</label>
            <select id="fcat" name="category">
                <option value="">All categories</option>
                <?php foreach (['income' => 'Income', 'expense' => 'Expense'] as $tk => $tl): ?>
                    <optgroup label="<?= e($tl) ?>">
                        <?php foreach ($allCats as $c): if ($c['type'] !== $tk) continue; ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $fCat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select></div>
        <div class="form-group"><label for="ffrom">From</label><input type="date" id="ffrom" name="from" value="<?= e($from) ?>"></div>
        <div class="form-group"><label for="fto">To</label><input type="date" id="fto" name="to" value="<?= e($to) ?>"></div>
        <div class="form-group"><label for="fsort">Sort by</label>
            <select id="fsort" name="sort">
                <option value="date_desc" <?= $sortKey === 'date_desc' ? 'selected' : '' ?>>Newest first</option>
                <option value="date_asc" <?= $sortKey === 'date_asc' ? 'selected' : '' ?>>Oldest first</option>
                <option value="amount_desc" <?= $sortKey === 'amount_desc' ? 'selected' : '' ?>>Amount: high to low</option>
                <option value="amount_asc" <?= $sortKey === 'amount_asc' ? 'selected' : '' ?>>Amount: low to high</option>
                <option value="category" <?= $sortKey === 'category' ? 'selected' : '' ?>>Category A–Z</option>
            </select></div>
        <div class="btns">
            <button class="btn" type="submit"><?= icon('search', 16) ?> Filter</button>
            <?php if ($hasFilters || $sortKey !== 'date_desc'): ?><a class="btn btn-outline" href="<?= e(url('pages/transactions.php')) ?>">Reset</a><?php endif; ?>
        </div>
    </form>
</section>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>ID</th><th>Type</th><th>Category</th><th>Description</th><th>Date</th><th>Payment</th><th class="text-right">Amount</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="empty"><?= $hasFilters ? 'No transactions match your filters.' : 'No transactions yet. Add income or an expense to get started.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $isInc = $r['type'] === 'income';
                $tid = ($isInc ? 'INC-' : 'EXP-') . $r['id'];
                $page_ = $isInc ? 'pages/income.php' : 'pages/expenses.php';
                $view = ['id' => $tid, 'type' => $isInc ? 'Income' : 'Expense', 'category' => $r['category_name'],
                         'description' => $r['description'], 'amount' => ($isInc ? '+' : '−') . money($r['amount']),
                         'date' => fmt_date($r['tdate']), 'payment_method' => $r['payment_method'],
                         'created' => date('M d, Y g:i A', strtotime($r['created_at']))]; ?>
                <tr>
                    <td class="nowrap"><strong><?= e($tid) ?></strong></td>
                    <td><span class="badge <?= $isInc ? 'badge-income' : 'badge-expense' ?>"><?= $isInc ? 'Income' : 'Expense' ?></span></td>
                    <td><span class="cat"><span class="dot" style="background:<?= e($r['color']) ?>"></span><?= e($r['category_name']) ?></span></td>
                    <td><?= e($r['description'] ?: '—') ?></td>
                    <td class="nowrap"><?= e(fmt_date($r['tdate'])) ?></td>
                    <td><?= e($r['payment_method']) ?></td>
                    <td class="text-right nowrap <?= $isInc ? 'amount-income' : 'amount-expense' ?>"><?= $isInc ? '+' : '−' ?><?= e(money($r['amount'])) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-outline btn-sm btn-icon js-view" data-target="viewModal"
                                data-record="<?= e(json_encode($view)) ?>" title="View"><?= icon('eye', 15) ?></button>
                        <a class="btn btn-outline btn-sm btn-icon" href="<?= e(url($page_ . '?edit=' . (int) $r['id'])) ?>" title="Edit"><?= icon('edit', 15) ?></a>
                        <button type="button" class="btn btn-outline btn-sm btn-icon text-red js-delete" data-target="deleteModal"
                                data-id="<?= (int) $r['id'] ?>" data-action="<?= e(url($page_)) ?>"
                                data-label="<?= e($tid . ' · ' . money($r['amount'])) ?>" title="Delete"><?= icon('trash', 15) ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $pages) ?>
</section>

<!-- View modal -->
<div class="modal" id="viewModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-head"><h3>Transaction details</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button></div>
        <dl class="detail-grid">
            <dt>Transaction ID</dt><dd data-field="id"></dd>
            <dt>Type</dt><dd data-field="type"></dd>
            <dt>Category</dt><dd data-field="category"></dd>
            <dt>Amount</dt><dd><strong data-field="amount"></strong></dd>
            <dt>Date</dt><dd data-field="date"></dd>
            <dt>Payment method</dt><dd data-field="payment_method"></dd>
            <dt>Description</dt><dd data-field="description"></dd>
            <dt>Recorded on</dt><dd data-field="created"></dd>
        </dl>
        <div class="form-actions"><button type="button" class="btn btn-outline" data-modal-close>Close</button></div>
    </div>
</div>

<!-- Delete modal (posts to income.php or expenses.php depending on the row) -->
<div class="modal" id="deleteModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head"><h3>Delete transaction?</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button></div>
        <p>This will permanently remove <strong data-delete-label>this transaction</strong> and update your balance and statistics.</p>
        <form method="post" action="<?= e(url('pages/income.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="redirect" value="transactions">
            <input type="hidden" name="return" value="<?= e($returnQs) ?>">
            <input type="hidden" name="id" value="">
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-red">Delete</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
