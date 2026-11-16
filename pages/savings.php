<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();
$self = 'pages/savings.php';

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ---- create / edit goal ---- */
    if ($action === 'save_goal') {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = clean_str($_POST['name'] ?? '', 100);
        $target = parse_amount($_POST['target_amount'] ?? '');
        $tdate  = (string) ($_POST['target_date'] ?? '');
        $errors = [];
        if (mb_strlen($name) < 2) $errors[] = 'Please enter a goal name (at least 2 characters).';
        if ($target === null)     $errors[] = 'Enter a valid target amount greater than zero.';
        if ($tdate !== '' && !valid_date($tdate)) $errors[] = 'Please choose a valid target date.';
        if (!$errors && $id > 0) {
            $chk = $pdo->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :u');
            $chk->execute([':id' => $id, ':u' => $uid]);
            if (!$chk->fetch()) $errors[] = 'That savings goal could not be found.';
        }
        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
            redirect($self);
        }
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE savings_goals SET name = :n, target_amount = :t, target_date = :d WHERE id = :id AND user_id = :u');
            $st->execute([':n' => $name, ':t' => $target, ':d' => $tdate ?: null, ':id' => $id, ':u' => $uid]);
            flash('success', 'Savings goal updated.');
        } else {
            $st = $pdo->prepare('INSERT INTO savings_goals (user_id, name, target_amount, target_date) VALUES (:u, :n, :t, :d)');
            $st->execute([':u' => $uid, ':n' => $name, ':t' => $target, ':d' => $tdate ?: null]);
            notify($uid, 'info', 'Savings goal created', sprintf('New goal “%s” with a target of %s.', $name, money($target)));
            flash('success', 'Savings goal created.');
        }
        redirect($self);
    }

    /* ---- deposit / withdraw ---- */
    if ($action === 'money') {
        $id     = (int) ($_POST['id'] ?? 0);
        $mode   = $_POST['mode'] ?? '';
        $amount = parse_amount($_POST['amount'] ?? '');
        $note   = clean_str($_POST['note'] ?? '', 255);
        if (!in_array($mode, ['deposit', 'withdraw'], true) || $amount === null) {
            flash('error', 'Enter a valid amount greater than zero.');
            redirect($self);
        }
        try {
            $pdo->beginTransaction();
            $gs = $pdo->prepare('SELECT * FROM savings_goals WHERE id = :id AND user_id = :u FOR UPDATE');
            $gs->execute([':id' => $id, ':u' => $uid]);
            $goal = $gs->fetch();
            if (!$goal) throw new RuntimeException('That savings goal could not be found.');

            $old = (float) $goal['current_amount'];
            if ($mode === 'deposit') {
                $available = user_totals($uid)['balance'];
                if ($amount > $available + 0.001) {
                    throw new RuntimeException('Not enough available balance (' . money($available) . ') to save that amount.');
                }
                $new = round($old + $amount, 2);
            } else {
                if ($amount > $old + 0.001) {
                    throw new RuntimeException('You can only withdraw up to ' . money($old) . ' from this goal.');
                }
                $new = round($old - $amount, 2);
            }
            $pdo->prepare('UPDATE savings_goals SET current_amount = :c WHERE id = :id')->execute([':c' => $new, ':id' => $id]);
            $pdo->prepare('INSERT INTO savings_transactions (goal_id, user_id, type, amount, note, txn_date)
                           VALUES (:g, :u, :t, :a, :n, :d)')
                ->execute([':g' => $id, ':u' => $uid, ':t' => $mode, ':a' => $amount, ':n' => $note, ':d' => date('Y-m-d')]);
            $pdo->commit();
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $ex->getMessage());
            redirect($self);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Could not save the transaction. Please try again.');
            redirect($self);
        }

        if ($mode === 'deposit') {
            notify($uid, 'success', 'Money added to savings', sprintf('%s added to “%s”.', money($amount), $goal['name']));
            flash('success', money($amount) . ' added to ' . $goal['name'] . '.');
        } else {
            notify($uid, 'info', 'Money withdrawn from savings', sprintf('%s withdrawn from “%s”.', money($amount), $goal['name']));
            flash('success', money($amount) . ' withdrawn from ' . $goal['name'] . '.');
        }
        if ($old < (float) $goal['target_amount'] && $new >= (float) $goal['target_amount']) {
            notify($uid, 'success', 'Savings goal achieved! 🎉',
                sprintf('You reached your target of %s for “%s”.', money($goal['target_amount']), $goal['name']), 'goal-' . $id . '-achieved');
            flash('success', 'Congratulations! You reached your goal “' . $goal['name'] . '”.');
        }
        redirect($self);
    }

    /* ---- delete goal ---- */
    if ($action === 'delete') {
        $st = $pdo->prepare('DELETE FROM savings_goals WHERE id = :id AND user_id = :u');
        $st->execute([':id' => (int) ($_POST['id'] ?? 0), ':u' => $uid]);
        flash($st->rowCount() ? 'success' : 'error',
            $st->rowCount() ? 'Savings goal deleted. Its saved amount is back in your balance.' : 'That savings goal could not be found.');
        redirect($self);
    }
    redirect($self);
}

/* ---- data ---- */
$gs = $pdo->prepare('SELECT * FROM savings_goals WHERE user_id = :u ORDER BY (current_amount >= target_amount), target_date IS NULL, target_date, id DESC');
$gs->execute([':u' => $uid]);
$goals = $gs->fetchAll();

$totalSaved = array_sum(array_map(fn($g) => (float) $g['current_amount'], $goals));
$completed  = count(array_filter($goals, fn($g) => (float) $g['current_amount'] >= (float) $g['target_amount']));
$balance    = user_totals($uid)['balance'];

$ts = $pdo->prepare('SELECT s.type, s.amount, s.note, s.txn_date, s.created_at, g.name
                       FROM savings_transactions s JOIN savings_goals g ON g.id = s.goal_id
                      WHERE s.user_id = :u ORDER BY s.created_at DESC, s.id DESC LIMIT 10');
$ts->execute([':u' => $uid]);
$history = $ts->fetchAll();

$pageTitle = 'Savings';
$activeNav = 'savings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Savings Tracker</h2>
        <p>Set goals, add money as you go, and watch the progress.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn" data-modal-open="goalModal" data-reset><?= icon('plus', 16) ?> New savings goal</button>
    </div>
</div>

<section class="grid grid-3 mb">
    <div class="card stat"><div class="stat-icon tone-navy"><?= icon('shield', 22) ?></div>
        <div><div class="stat-label">Total saved</div><div class="stat-value"><?= e(money($totalSaved)) ?></div></div></div>
    <div class="card stat"><div class="stat-icon tone-blue"><?= icon('target', 22) ?></div>
        <div><div class="stat-label">Goals</div><div class="stat-value"><?= count($goals) ?></div><div class="stat-sub"><?= $completed ?> achieved</div></div></div>
    <div class="card stat"><div class="stat-icon tone-green"><?= icon('wallet', 22) ?></div>
        <div><div class="stat-label">Available to save</div><div class="stat-value <?= $balance < 0 ? 'text-red' : '' ?>"><?= e(money($balance)) ?></div></div></div>
</section>

<?php if (!$goals): ?>
    <section class="card"><p class="empty">No savings goals yet. Create one, like “New Laptop”, and start saving.</p></section>
<?php else: ?>
<section class="grid grid-3 mb">
    <?php foreach ($goals as $g):
        $cur = (float) $g['current_amount']; $tgt = (float) $g['target_amount'];
        $pct = $tgt > 0 ? ($cur / $tgt) * 100 : 0;
        $done = $cur >= $tgt;
        $left = max(0, $tgt - $cur);
        $rec = ['id' => (int) $g['id'], 'name' => $g['name'], 'target_amount' => number_format($tgt, 2, '.', ''), 'target_date' => $g['target_date']];
        $daysLeft = $g['target_date'] ? (int) floor((strtotime($g['target_date']) - strtotime(date('Y-m-d'))) / 86400) : null;
    ?>
        <div class="card goal-card">
            <div class="card-head" style="margin:0">
                <div class="goal-name"><?= e($g['name']) ?></div>
                <?php if ($done): ?><span class="badge badge-income"><?= icon('check', 13) ?> Achieved</span>
                <?php else: ?><span class="badge badge-info"><?= number_format($pct, 1) ?>%</span><?php endif; ?>
            </div>
            <div class="progress <?= $done ? '' : 'savings' ?>"><span style="width:<?= min(100, round($pct, 1)) ?>%"></span></div>
            <div class="goal-amounts">
                <span><strong><?= e(money($cur)) ?></strong> <span class="muted">saved</span></span>
                <span class="muted">Target <strong><?= e(money($tgt)) ?></strong></span>
            </div>
            <div class="small muted">
                <?php if ($done): ?>
                    Goal reached 🎉
                <?php else: ?>
                    <?= e(money($left)) ?> to go
                    <?php if ($daysLeft !== null): ?>
                        &middot; <?= $daysLeft >= 0 ? e(fmt_date($g['target_date'])) . ' (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)'
                            : '<span class="text-red">target date passed (' . e(fmt_date($g['target_date'])) . ')</span>' ?>
                        <?php if ($daysLeft > 0): $months = max(1, (int) ceil($daysLeft / 30)); ?>
                            <br>Save about <strong><?= e(money($left / $months)) ?></strong>/month to make it.
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="goal-actions">
                <button type="button" class="btn btn-green btn-sm js-money" data-target="moneyModal" data-mode="deposit"
                        data-id="<?= (int) $g['id'] ?>" data-name="<?= e($g['name']) ?>"><?= icon('arrowup', 14) ?> Add money</button>
                <button type="button" class="btn btn-outline btn-sm js-money" data-target="moneyModal" data-mode="withdraw"
                        data-id="<?= (int) $g['id'] ?>" data-name="<?= e($g['name']) ?>"><?= icon('arrowdown', 14) ?> Withdraw</button>
                <button type="button" class="btn btn-outline btn-sm btn-icon js-edit" data-target="goalModal"
                        data-record="<?= e(json_encode($rec)) ?>" title="Edit"><?= icon('edit', 14) ?></button>
                <button type="button" class="btn btn-outline btn-sm btn-icon text-red js-delete" data-target="deleteModal"
                        data-id="<?= (int) $g['id'] ?>" data-label="<?= e($g['name']) ?>" title="Delete"><?= icon('trash', 14) ?></button>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-head"><h3 class="card-title">Recent Savings Activity</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Goal</th><th>Type</th><th>Note</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            <?php if (!$history): ?><tr><td colspan="5" class="empty">No savings activity yet.</td></tr><?php endif; ?>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_date($h['txn_date'])) ?></td>
                    <td><?= e($h['name']) ?></td>
                    <td><span class="badge <?= $h['type'] === 'deposit' ? 'badge-income' : 'badge-warn' ?>"><?= $h['type'] === 'deposit' ? 'Deposit' : 'Withdrawal' ?></span></td>
                    <td><?= e($h['note'] ?: '—') ?></td>
                    <td class="text-right nowrap <?= $h['type'] === 'deposit' ? 'amount-income' : 'amount-expense' ?>"><?= $h['type'] === 'deposit' ? '+' : '−' ?><?= e(money($h['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Goal modal -->
<div class="modal" id="goalModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head">
            <h3 data-title-add="New Savings Goal" data-title-edit="Edit Savings Goal">New Savings Goal</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_goal">
            <input type="hidden" name="id" value="" data-clear>
            <div class="form-grid" style="grid-template-columns:1fr">
                <div class="form-group"><label for="g_name">Goal name</label>
                    <input type="text" id="g_name" name="name" maxlength="100" required placeholder="e.g. New Laptop"></div>
                <div class="form-group"><label for="g_target">Target amount (<?= e(currency_symbol()) ?>)</label>
                    <input type="number" id="g_target" name="target_amount" step="0.01" min="0.01" required placeholder="e.g. 40000"></div>
                <div class="form-group"><label for="g_date">Target date <span class="muted">(optional)</span></label>
                    <input type="date" id="g_date" name="target_date"></div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn">Save goal</button>
            </div>
        </form>
    </div>
</div>

<!-- Add / withdraw modal -->
<div class="modal" id="moneyModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head">
            <h3>Update savings: <span data-goal-name></span></h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="money">
            <input type="hidden" name="id" value="">
            <div class="form-grid" style="grid-template-columns:1fr">
                <div class="form-group"><label for="s_mode">Action</label>
                    <select id="s_mode" name="mode">
                        <option value="deposit">Add money to savings</option>
                        <option value="withdraw">Withdraw from savings</option>
                    </select></div>
                <div class="form-group"><label for="s_amount">Amount (<?= e(currency_symbol()) ?>)</label>
                    <input type="number" id="s_amount" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                    <span class="hint">Available balance: <?= e(money($balance)) ?></span></div>
                <div class="form-group"><label for="s_note">Note <span class="muted">(optional)</span></label>
                    <input type="text" id="s_note" name="note" maxlength="255"></div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-green">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete modal -->
<div class="modal" id="deleteModal" role="dialog" aria-modal="true">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog sm">
        <div class="modal-head"><h3>Delete savings goal?</h3>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= icon('x', 18) ?></button></div>
        <p>Delete <strong data-delete-label>this goal</strong> and its history? The saved amount returns to your available balance.</p>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
