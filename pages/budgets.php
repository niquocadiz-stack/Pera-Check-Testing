<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();

$month = valid_month($_GET['month'] ?? null) ? $_GET['month'] : date('Y-m');
$categories = get_categories($uid, 'expense');
$catIds = array_map('intval', array_column($categories, 'id'));
$self = 'pages/budgets.php?month=' . $month;

/** Find an existing budget for (user, category|overall, month), optionally ignoring one id. */
function budget_exists(PDO $pdo, int $uid, int $catId, string $month, int $ignoreId = 0): bool
{
    $sql = 'SELECT id FROM budgets WHERE user_id = :u AND month = :m AND id <> :ig AND '
        . ($catId > 0 ? 'category_id = :c' : 'category_id IS NULL');
    $p = [':u' => $uid, ':m' => $month, ':ig' => $ignoreId];
    if ($catId > 0) $p[':c'] = $catId;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (bool) $st->fetch();
}

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $pMonth = valid_month($_POST['month'] ?? null) ? $_POST['month'] : $month;
    $self = 'pages/budgets.php?month=' . $pMonth;

    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $catId  = (int) ($_POST['category_id'] ?? 0);
        $amount = parse_amount($_POST['amount'] ?? '');
        $errors = [];
        if ($catId !== 0 && !in_array($catId, $catIds, true)) $errors[] = 'Please choose a valid category.';
        if ($amount === null) $errors[] = 'Enter a valid budget amount greater than zero.';
        if (!$errors && budget_exists($pdo, $uid, $catId, $pMonth, $id)) {
            $errors[] = 'A budget for that category already exists in ' . date('F Y', strtotime($pMonth . '-01')) . '. Edit it instead.';
        }
        if (!$errors && $id > 0) {
            $chk = $pdo->prepare('SELECT id FROM budgets WHERE id = :id AND user_id = :u');
            $chk->execute([':id' => $id, ':u' => $uid]);
            if (!$chk->fetch()) $errors[] = 'That budget could not be found.';
        }
        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
            redirect($self);
        }
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE budgets SET category_id = :c, month = :m, amount = :a WHERE id = :id AND user_id = :u');
            $st->execute([':c' => $catId ?: null, ':m' => $pMonth, ':a' => $amount, ':id' => $id, ':u' => $uid]);
            flash('success', 'Budget updated.');
        } else {
            $st = $pdo->prepare('INSERT INTO budgets (user_id, category_id, month, amount) VALUES (:u, :c, :m, :a)');
            $st->execute([':u' => $uid, ':c' => $catId ?: null, ':m' => $pMonth, ':a' => $amount]);
            flash('success', 'Budget created.');
        }
        check_budget_alerts($uid, $pMonth . '-01');
        redirect($self);
    }

    if ($action === 'delete') {
        $st = $pdo->prepare('DELETE FROM budgets WHERE id = :id AND user_id = :u');
        $st->execute([':id' => (int) ($_POST['id'] ?? 0), ':u' => $uid]);
        flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Budget deleted.' : 'That budget could not be found.');
        redirect($self);
    }

    if ($action === 'copy_prev') {
        $prev = date('Y-m', strtotime($pMonth . '-01 -1 month'));
        $src = $pdo->prepare('SELECT category_id, amount FROM budgets WHERE user_id = :u AND month = :m');
        $src->execute([':u' => $uid, ':m' => $prev]);
        $added = 0;
        foreach ($src->fetchAll() as $b) {
            $cid = (int) ($b['category_id'] ?? 0);
            if (budget_exists($pdo, $uid, $cid, $pMonth)) continue;
            $ins = $pdo->prepare('INSERT INTO budgets (user_id, category_id, month, amount) VALUES (:u, :c, :m, :a)');
            $ins->execute([':u' => $uid, ':c' => $cid ?: null, ':m' => $pMonth, ':a' => $b['amount']]);
            $added++;
        }
        flash($added ? 'success' : 'warning', $added ? "$added budget(s) copied from " . date('F Y', strtotime($prev . '-01')) . '.'
            : 'Nothing to copy: no new budgets found in the previous month.');
        check_budget_alerts($uid, $pMonth . '-01');
        redirect($self);
    }
    redirect($self);
}

$rows      = budget_rows($uid, $month);
$remaining = remaining_budget($uid, $month);
$prevM = date('Y-m', strtotime($month . '-01 -1 month'));
$nextM = date('Y-m', strtotime($month . '-01 +1 month'));
$stateClass = ['ok' => '', 'warning' => 'warning', 'critical' => 'critical', 'over' => 'over'];

$pageTitle = 'Budgets';
$activeNav = 'budgets';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Budget Management</h2>
        <p>Set spending limits and stay ahead of them. Alerts fire at 75%, 90% and 100%.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e(url($self)) ?>" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="copy_prev"><input type="hidden" name="month" value="<?= e($month) ?>">
            <button class="btn btn-outline" type="submit">Copy last month</button>
        </form>
        <button type="button" class="btn" data-modal-open="budgetModal" data-reset><?= icon('plus', 16) ?> Add budget</button>
    </div>
</div>

<section class="card mb">
    <div class="card-head">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <a class="btn btn-outline btn-sm" href="?month=<?= e($prevM) ?>">&laquo;</a>
            <form method="get" action="" style="margin:0"><input type="month" name="month" value="<?= e($month) ?>" data-autosubmit aria-label="Month"></form>
            <a class="btn btn-outline btn-sm" href="?month=<?= e($nextM) ?>">&raquo;</a>
            <?php if ($month !== date('Y-m')): ?><a class="btn btn-outline btn-sm" href="?month=<?= e(date('Y-m')) ?>">This month</a><?php endif; ?>
        </div>
        <?php if ($remaining): ?>
            <div class="summary-strip">
                <div><span class="muted small">Budgeted</span><strong><?= e(money($remaining['budget'])) ?></strong></div>
                <div><span class="muted small">Spent</span><strong class="text-red"><?= e(money($remaining['spent'])) ?></strong></div>
                <div><span class="muted small">Remaining</span><strong class="<?= $remaining['remaining'] < 0 ? 'text-red' : 'text-green' ?>"><?= e(money($remaining['remaining'])) ?></strong></div>
            </div>
        <?php endif; ?>
    </div>
    <?php foreach ($rows as $b): if ($b['level'] === 0) continue; ?>
        <div class="alert <?= $b['state'] === 'over' ? 'alert-danger' : 'alert-warning' ?>">
            <?= icon('alert', 16) ?>
            <span><strong><?= e($b['label']) ?></strong>:
            <?php if ($b['level'] === 100): ?>
                budget <?= $b['spent'] > $b['amount'] ? 'exceeded by ' . e(money($b['spent'] - $b['amount'])) : 'fully used' ?>.
            <?php else: ?>
                <?= $b['level'] ?>% used, only <?= e(money($b['remaining'])) ?> left.
            <?php endif; ?></span>
        </div>
    <?php endforeach; ?>
</section>

<?php if (!$rows): ?>
    <section class="card"><p class="empty">No budgets for <?= e(date('F Y', strtotime($month . '-01'))) ?> yet.<br>
        Click “Add budget” to set a monthly limit, overall or per category.</p></section>
<?php else: ?>
<section class="grid grid-3">
    <?php foreach ($rows as $b):
        $rec = ['id' => (int) $b['id'], 'category_id' => (int) ($b['category_id'] ?? 0), 'month' => $b['month'],
                'amount' => number_format($b['amount'], 2, '.', '')]; ?>
        <div class="card">
            <div class="card-head" style="margin-bottom:10px">
                <h3 class="card-title">
                    <?php if ($b['category_id'] === null): ?><?= icon('pie', 16) ?>
                    <?php else: ?><span class="dot" style="background:<?= e($b['color']) ?>"></span><?php endif; ?>
                    <?= e($b['label']) ?>
                </h3>
                <span class="badge <?= $b['state'] === 'ok' ? 'badge-income' : ($b['state'] === 'over' ? 'badge-expense' : 'badge-warn') ?>"><?= number_format($b['pct'], 0) ?>%</span>
            </div>
            <div class="progress <?= $stateClass[$b['state']] ?>"><span style="width:<?= min(100, round($b['pct'], 1)) ?>%"></span></div>
            <div class="goal-amounts" style="margin-top:10px">
                <span class="muted">Spent <strong class="text-red"><?= e(money($b['spent'])) ?></strong></span>
                <span class="muted">of <strong><?= e(money($b['amount'])) ?></strong></span>
            </div>
            <div style="margin-top:6px"><?= $b['remaining'] >= 0
                ? '<span class="text-green">' . e(money($b['remaining'])) . ' remaining</span>'
                : '<span class="text-red">' . e(money(abs($b['remaining']))) . ' over budget</span>' ?></div>
            <?php if ($b['level'] > 0): ?>
                <div class="small <?= $b['state'] === 'over' ? 'text-red' : 'text-orange' ?>" style="margin-top:6px">
                    <?= $b['level'] === 100 ? 'Limit reached or exceeded' : $b['level'] . '% of this budget is used' ?>
                </div>
            <?php endif; ?>
            <div class="goal-actions" style="margin-top:14px">
                <button type="button" class="btn btn-outline btn-sm js-edit" data-target="budgetModal" data-record="<?= e(json_encode($rec)) ?>"><?= icon('edit', 14) ?> Edit</button>
                <button type="button" class="btn btn-outline btn-sm text-red js-delete" data-target="deleteModal" data-id="<?= (int) $b['id'] ?>"
                        data-label="<?= e($b['label'] . ' budget') ?>"><?= icon('trash', 14) ?> Delete</button>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="modal" id="budgetModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head">
            <h3 data-title-add="Add Budget" data-title-edit="Edit Budget">Add Budget</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="" data-clear>
            <div class="form-grid" style="grid-template-columns:1fr">
                <div class="form-group">
                    <label for="b_month">Month</label>
                    <input type="month" id="b_month" name="month" value="<?= e($month) ?>" required>
                </div>
                <div class="form-group">
                    <label for="b_cat">Applies to</label>
                    <select id="b_cat" name="category_id">
                        <option value="0">Overall monthly budget (all expenses)</option>
                        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="b_amount">Budget amount (<?= e(currency_symbol()) ?>)</label>
                    <input type="number" id="b_amount" name="amount" step="0.01" min="0.01" required placeholder="e.g. 5000">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn">Save budget</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="deleteModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head"><h3>Delete budget?</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button></div>
        <p>Remove the <strong data-delete-label>budget</strong>? Your expenses stay untouched.</p>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <input type="hidden" name="id" value="">
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-red">Delete</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
