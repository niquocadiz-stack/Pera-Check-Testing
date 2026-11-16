<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();
$self = 'pages/notifications.php';

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'read') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $uid]);
    } elseif ($action === 'read_all') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u')->execute([':u' => $uid]);
        flash('success', 'All notifications marked as read.');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $uid]);
    } elseif ($action === 'clear') {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = :u')->execute([':u' => $uid]);
        flash('success', 'All notifications cleared.');
    }
    redirect($self . (($_POST['filter'] ?? '') === 'unread' ? '?filter=unread' : ''));
}

$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
$perPage = 15;
$cnt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u' . ($filter === 'unread' ? ' AND is_read = 0' : ''));
$cnt->execute([':u' => $uid]);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

$st = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :u' . ($filter === 'unread' ? ' AND is_read = 0' : '')
    . ' ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off');
$st->bindValue(':u', $uid, PDO::PARAM_INT);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();
$items = $st->fetchAll();

$tone = ['success' => 'tone-green', 'info' => 'tone-blue', 'warning' => 'tone-orange', 'danger' => 'tone-red'];
$ico  = ['success' => 'check', 'info' => 'info', 'warning' => 'alert', 'danger' => 'alert'];

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Notifications &amp; Alerts</h2>
        <p>Budget warnings, savings milestones and spending insights.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="read_all">
            <button class="btn btn-outline" type="submit"><?= icon('check', 16) ?> Mark all read</button>
        </form>
        <form method="post" action="<?= e(url($self)) ?>" data-confirm="Delete all notifications?">
            <?= csrf_field() ?><input type="hidden" name="action" value="clear">
            <button class="btn btn-outline text-red" type="submit"><?= icon('trash', 16) ?> Clear all</button>
        </form>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <div class="tabs">
            <a class="tab <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All</a>
            <a class="tab <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">Unread</a>
        </div>
        <span class="muted small"><?= $total ?> notification<?= $total === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$items): ?>
        <p class="empty"><?= $filter === 'unread' ? 'You are all caught up!' : 'No notifications yet.' ?></p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($items as $n): ?>
                <li class="notif <?= $n['is_read'] ? '' : 'unread' ?>">
                    <div class="notif-icon <?= $tone[$n['type']] ?>"><?= icon($ico[$n['type']], 18) ?></div>
                    <div class="grow">
                        <div class="notif-title"><?= e($n['title']) ?> <?php if (!$n['is_read']): ?><span class="badge badge-info">New</span><?php endif; ?></div>
                        <div class="muted"><?= e($n['message']) ?></div>
                        <div class="muted small"><?= e(time_ago($n['created_at'])) ?></div>
                    </div>
                    <div class="notif-actions">
                        <?php if (!$n['is_read']): ?>
                            <form method="post" action="<?= e(url($self)) ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                <button class="btn btn-outline btn-sm btn-icon" type="submit" title="Mark as read"><?= icon('check', 15) ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url($self)) ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <input type="hidden" name="filter" value="<?= e($filter) ?>">
                            <button class="btn btn-outline btn-sm btn-icon text-red" type="submit" title="Delete"><?= icon('trash', 15) ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?= render_pagination($page, $pages) ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
