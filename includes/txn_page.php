<?php
/**
 * Shared income / expense management page.
 * pages/income.php and pages/expenses.php set $type ('income' | 'expense') and include this file.
 */
require_once __DIR__ . '/functions.php';
$user = require_login();
$uid  = (int) $user['id'];

$isIncome = ($type === 'income');
$table    = $isIncome ? 'income' : 'expenses';
$dateCol  = $isIncome ? 'income_date' : 'expense_date';
$self     = $isIncome ? 'pages/income.php' : 'pages/expenses.php';
$noun     = $isIncome ? 'Income' : 'Expense';
$colorCls = $isIncome ? 'income' : 'expense';

$categories = get_categories($uid, $type);
$catIds     = array_map('intval', array_column($categories, 'id'));

/* ------------------------------------------------------------------
 * POST: save (add / edit) and delete
 * ------------------------------------------------------------------ */
if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $catId  = (int) ($_POST['category_id'] ?? 0);
        $amount = parse_amount($_POST['amount'] ?? '');
        $date   = (string) ($_POST['date'] ?? '');
        $desc   = clean_str($_POST['description'] ?? '', 255);
        $pm     = (string) ($_POST['payment_method'] ?? '');
        $errors = [];

        if (!in_array($catId, $catIds, true)) $errors[] = 'Please choose a valid category.';
        if ($amount === null)                 $errors[] = 'Enter a valid amount greater than zero (up to two decimals).';
        if (!valid_date($date))               $errors[] = 'Please choose a valid date.';
        if (!in_array($pm, PAYMENT_METHODS, true)) $errors[] = 'Please choose a payment method.';

        if (!$errors && $id > 0) {
            $chk = $pdo->prepare("SELECT id FROM $table WHERE id = :id AND user_id = :u");
            $chk->execute([':id' => $id, ':u' => $uid]);
            if (!$chk->fetch()) $errors[] = 'That record could not be found.';
        }
        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
            redirect($self);
        }

        $catName = '';
        foreach ($categories as $c) if ((int) $c['id'] === $catId) $catName = $c['name'];

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE $table SET category_id = :c, amount = :a, description = :d,
                                   payment_method = :p, $dateCol = :dt WHERE id = :id AND user_id = :u");
            $st->execute([':c' => $catId, ':a' => $amount, ':d' => $desc, ':p' => $pm, ':dt' => $date, ':id' => $id, ':u' => $uid]);
            flash('success', "$noun updated successfully.");
        } else {
            $st = $pdo->prepare("INSERT INTO $table (user_id, category_id, amount, description, payment_method, $dateCol)
                                 VALUES (:u, :c, :a, :d, :p, :dt)");
            $st->execute([':u' => $uid, ':c' => $catId, ':a' => $amount, ':d' => $desc, ':p' => $pm, ':dt' => $date]);
            notify($uid, 'success', 'New transaction added',
                sprintf('%s of %s recorded under %s.', $noun, money($amount), $catName));
            flash('success', "$noun added successfully.");
        }
        if (!$isIncome) {
            check_budget_alerts($uid, $date);
            check_spending_trend($uid);
        }
        redirect($self);
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare("DELETE FROM $table WHERE id = :id AND user_id = :u");
        $st->execute([':id' => $id, ':u' => $uid]);
        flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? "$noun deleted." : 'That record could not be found.');

        $back = (string) ($_POST['return'] ?? '');
        if (($_POST['redirect'] ?? '') === 'transactions') {
            $qs = preg_match('/^[A-Za-z0-9_=&%.\-+]*$/', $back) ? $back : '';
            redirect('pages/transactions.php' . ($qs !== '' ? '?' . $qs : ''));
        }
        redirect($self);
    }
    redirect($self);
}

/* ------------------------------------------------------------------
 * GET: filters, sorting, pagination
 * ------------------------------------------------------------------ */
$q       = clean_str($_GET['q'] ?? '', 100);
$fCat    = (int) ($_GET['category'] ?? 0);
$from    = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to      = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$sortKey = $_GET['sort'] ?? 'date_desc';
$sorts = [
    'date_desc'   => "t.$dateCol DESC, t.id DESC",
    'date_asc'    => "t.$dateCol ASC, t.id ASC",
    'amount_desc' => 't.amount DESC, t.id DESC',
    'amount_asc'  => 't.amount ASC, t.id ASC',
];
if (!isset($sorts[$sortKey])) $sortKey = 'date_desc';

$where  = ['t.user_id = :uid'];
$params = [':uid' => $uid];
if ($q !== '') {
    $where[] = '(t.description LIKE :q1 OR c.name LIKE :q2 OR t.payment_method LIKE :q3)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $like;
}
if ($fCat > 0)  { $where[] = 't.category_id = :cat'; $params[':cat'] = $fCat; }
if ($from !== '') { $where[] = "t.$dateCol >= :from"; $params[':from'] = $from; }
if ($to !== '')   { $where[] = "t.$dateCol <= :to";   $params[':to'] = $to; }
$whereSql = implode(' AND ', $where);
$base = "FROM $table t JOIN categories c ON c.id = t.category_id WHERE $whereSql";

$cs = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(t.amount),0) AS total $base");
$cs->execute($params);
$agg = $cs->fetch();
$totalRows = (int) $agg['n'];
$perPage = 10;
$pages = max(1, (int) ceil($totalRows / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

$ls = db()->prepare("SELECT t.id, t.category_id, t.amount, t.description, t.payment_method, t.$dateCol AS tdate,
                            c.name AS category_name, c.color $base ORDER BY {$sorts[$sortKey]} LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $ls->bindValue($k, $v);
$ls->bindValue(':lim', $perPage, PDO::PARAM_INT);
$ls->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$ls->execute();
$rows = $ls->fetchAll();

[$mFrom, $mTo] = month_range(date('Y-m'));
$monthTotal = sum_between($type, $uid, $mFrom, $mTo);

// Edit via link (?edit=ID) or "add" via link (?add=1)
$autoEdit = null;
if (isset($_GET['edit'])) {
    $es = db()->prepare("SELECT id, category_id, amount, description, payment_method, $dateCol AS date
                           FROM $table WHERE id = :id AND user_id = :u");
    $es->execute([':id' => (int) $_GET['edit'], ':u' => $uid]);
    $autoEdit = $es->fetch() ?: null;
    if (!$autoEdit) flash('error', 'That record could not be found.');
}

$pageTitle = $isIncome ? 'Income' : 'Expenses';
$activeNav = $isIncome ? 'income' : 'expenses';
require __DIR__ . '/header.php';
$hasFilters = ($q !== '' || $fCat || $from !== '' || $to !== '');
?>
<div class="page-head">
    <div>
        <h2><?= $isIncome ? 'Income Management' : 'Expense Management' ?></h2>
        <p><?= $isIncome ? 'Record and review every peso that comes in.' : 'Monitor where your money goes. Every expense updates your balance and budgets.' ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn <?= $isIncome ? 'btn-green' : 'btn-red' ?>" data-modal-open="txnModal" data-reset>
            <?= icon('plus', 16) ?> Add <?= e(strtolower($noun)) ?>
        </button>
    </div>
</div>

<section class="grid grid-3 mb">
    <div class="card stat">
        <div class="stat-icon <?= $isIncome ? 'tone-green' : 'tone-red' ?>"><?= icon($isIncome ? 'up' : 'down', 22) ?></div>
        <div><div class="stat-label">This month</div><div class="stat-value amount-<?= $colorCls ?>"><?= e(money($monthTotal)) ?></div></div>
    </div>
    <div class="card stat">
        <div class="stat-icon tone-blue"><?= icon('list', 22) ?></div>
        <div><div class="stat-label"><?= $hasFilters ? 'Matching records' : 'Total records' ?></div><div class="stat-value"><?= $totalRows ?></div></div>
    </div>
    <div class="card stat">
        <div class="stat-icon tone-navy"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label"><?= $hasFilters ? 'Filtered total' : 'All-time total' ?></div><div class="stat-value"><?= e(money($agg['total'])) ?></div></div>
    </div>
</section>

<section class="card mb filters-card">
    <form method="get" class="filters" action="">
        <div class="form-group grow">
            <label for="fq">Search</label>
            <input type="text" id="fq" name="q" value="<?= e($q) ?>" placeholder="Description, category or payment method">
        </div>
        <div class="form-group">
            <label for="fcat">Category</label>
            <select id="fcat" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $fCat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label for="ffrom">From</label><input type="date" id="ffrom" name="from" value="<?= e($from) ?>"></div>
        <div class="form-group"><label for="fto">To</label><input type="date" id="fto" name="to" value="<?= e($to) ?>"></div>
        <div class="form-group">
            <label for="fsort">Sort by</label>
            <select id="fsort" name="sort">
                <option value="date_desc" <?= $sortKey === 'date_desc' ? 'selected' : '' ?>>Newest first</option>
                <option value="date_asc" <?= $sortKey === 'date_asc' ? 'selected' : '' ?>>Oldest first</option>
                <option value="amount_desc" <?= $sortKey === 'amount_desc' ? 'selected' : '' ?>>Amount: high to low</option>
                <option value="amount_asc" <?= $sortKey === 'amount_asc' ? 'selected' : '' ?>>Amount: low to high</option>
            </select>
        </div>
        <div class="btns">
            <button class="btn" type="submit"><?= icon('search', 16) ?> Filter</button>
            <?php if ($hasFilters || $sortKey !== 'date_desc'): ?>
                <a class="btn btn-outline" href="<?= e(url($self)) ?>">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head"><h3 class="card-title"><?= e($noun) ?> History</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Category</th><th>Description</th><th>Payment method</th><th class="text-right">Amount</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">
                    <?= $hasFilters ? 'No records match your filters.' : 'No ' . e(strtolower($noun)) . ' recorded yet. Click “Add ' . e(strtolower($noun)) . '” to start.' ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $rec = ['id' => (int) $r['id'], 'category_id' => (int) $r['category_id'], 'amount' => number_format((float) $r['amount'], 2, '.', ''),
                        'date' => $r['tdate'], 'description' => $r['description'], 'payment_method' => $r['payment_method']]; ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_date($r['tdate'])) ?></td>
                    <td><span class="cat"><span class="dot" style="background:<?= e($r['color']) ?>"></span><?= e($r['category_name']) ?></span></td>
                    <td><?= e($r['description'] ?: '—') ?></td>
                    <td><?= e($r['payment_method']) ?></td>
                    <td class="text-right nowrap amount-<?= $colorCls ?>"><?= $isIncome ? '+' : '−' ?><?= e(money($r['amount'])) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-outline btn-sm btn-icon js-edit" data-target="txnModal"
                                data-record="<?= e(json_encode($rec)) ?>" title="Edit"><?= icon('edit', 15) ?></button>
                        <button type="button" class="btn btn-outline btn-sm btn-icon js-delete text-red" data-target="deleteModal"
                                data-id="<?= (int) $r['id'] ?>" data-label="<?= e(($r['description'] ?: $r['category_name']) . ' (' . money($r['amount']) . ')') ?>"
                                title="Delete"><?= icon('trash', 15) ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $pages) ?>
</section>

<!-- Add / edit modal -->
<div class="modal" id="txnModal" role="dialog" aria-modal="true" aria-labelledby="txnModalTitle">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-head">
            <h3 id="txnModalTitle" data-title-add="Add <?= e($noun) ?>" data-title-edit="Edit <?= e($noun) ?>">Add <?= e($noun) ?></h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="" data-clear>
            <div class="form-grid">
                <div class="form-group">
                    <label for="m_category">Category</label>
                    <select id="m_category" name="category_id" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="m_amount">Amount (<?= e(currency_symbol()) ?>)</label>
                    <input type="number" id="m_amount" name="amount" step="0.01" min="0.01" max="9999999999.99" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="m_date"><?= $isIncome ? 'Income date' : 'Expense date' ?></label>
                    <input type="date" id="m_date" name="date" value="<?= e(date('Y-m-d')) ?>" data-today required>
                </div>
                <div class="form-group">
                    <label for="m_pm"><?= $isIncome ? 'Received via' : 'Payment method' ?></label>
                    <select id="m_pm" name="payment_method" required>
                        <?php foreach (PAYMENT_METHODS as $pm): ?><option><?= e($pm) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group full">
                    <label for="m_desc">Description / notes</label>
                    <textarea id="m_desc" name="description" maxlength="255" placeholder="Optional"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn <?= $isIncome ? 'btn-green' : 'btn-red' ?>">Save <?= e(strtolower($noun)) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete modal -->
<div class="modal" id="deleteModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head"><h3>Delete <?= e(strtolower($noun)) ?>?</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button></div>
        <p>This will permanently remove <strong data-delete-label>this record</strong> and update your balance and statistics.</p>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="">
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-red">Delete</button>
            </div>
        </form>
    </div>
</div>

<?php if ($autoEdit): ?>
    <span id="autoEdit" data-target="txnModal" data-record="<?= e(json_encode([
        'id' => (int) $autoEdit['id'], 'category_id' => (int) $autoEdit['category_id'],
        'amount' => number_format((float) $autoEdit['amount'], 2, '.', ''), 'date' => $autoEdit['date'],
        'description' => $autoEdit['description'], 'payment_method' => $autoEdit['payment_method'],
    ])) ?>"></span>
<?php elseif (isset($_GET['add'])): ?>
    <span id="reopenModal" data-target="txnModal"></span>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
