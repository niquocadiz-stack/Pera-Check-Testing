<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();
$self = 'pages/settings.php';
$uploadDir = __DIR__ . '/../assets/images/uploads/';

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ---- profile ---- */
    if ($action === 'profile') {
        $name  = clean_str($_POST['full_name'] ?? '', 100);
        $email = strtolower(clean_str($_POST['email'] ?? '', 190));
        $cur   = (string) ($_POST['currency'] ?? '');
        $errors = [];
        if (mb_strlen($name) < 2) $errors[] = 'Please enter your full name (at least 2 characters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (!isset(CURRENCIES[$cur])) $errors[] = 'Please choose a valid currency.';
        if (!$errors) {
            $dup = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id');
            $dup->execute([':e' => $email, ':id' => $uid]);
            if ($dup->fetch()) $errors[] = 'That email is already used by another account.';
        }
        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
        } else {
            $pdo->prepare('UPDATE users SET full_name = :n, email = :e, currency = :c WHERE id = :id')
                ->execute([':n' => $name, ':e' => $email, ':c' => $cur, ':id' => $uid]);
            flash('success', 'Profile updated.');
        }
        redirect($self);
    }

    /* ---- password ---- */
    if ($action === 'password') {
        $curPw = (string) ($_POST['current_password'] ?? '');
        $new   = (string) ($_POST['new_password'] ?? '');
        $conf  = (string) ($_POST['confirm_password'] ?? '');
        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $row->execute([':id' => $uid]);
        $hash = (string) $row->fetchColumn();
        $errors = [];
        if (!password_verify($curPw, $hash)) $errors[] = 'Your current password is incorrect.';
        if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors[] = 'New password must be at least 8 characters and contain letters and numbers.';
        }
        if (strlen($new) > 72) $errors[] = 'New password must be 72 characters or fewer.';
        if ($new !== $conf) $errors[] = 'New passwords do not match.';
        if ($errors) {
            foreach ($errors as $er) flash('error', $er);
        } else {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $uid]);
            session_regenerate_id(true);
            notify($uid, 'info', 'Password changed', 'Your account password was changed successfully.');
            flash('success', 'Password changed successfully.');
        }
        redirect($self);
    }

    /* ---- avatar ---- */
    if ($action === 'avatar') {
        if (!empty($_POST['remove'])) {
            if ($user['avatar'] && is_file($uploadDir . $user['avatar'])) @unlink($uploadDir . $user['avatar']);
            $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute([':id' => $uid]);
            flash('success', 'Profile picture removed.');
            redirect($self);
        }
        $f = $_FILES['avatar'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Please choose an image to upload.');
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('error', 'The upload failed. Please try again.');
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            flash('error', 'The image must be 2 MB or smaller.');
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime]) || @getimagesize($f['tmp_name']) === false) {
                flash('error', 'Only JPG, PNG, GIF or WebP images are allowed.');
            } else {
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $fname = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                if (move_uploaded_file($f['tmp_name'], $uploadDir . $fname)) {
                    if ($user['avatar'] && is_file($uploadDir . $user['avatar'])) @unlink($uploadDir . $user['avatar']);
                    $pdo->prepare('UPDATE users SET avatar = :a WHERE id = :id')->execute([':a' => $fname, ':id' => $uid]);
                    flash('success', 'Profile picture updated.');
                } else {
                    flash('error', 'Could not save the image. Check folder permissions.');
                }
            }
        }
        redirect($self);
    }

    /* ---- categories ---- */
    if ($action === 'add_category') {
        $name  = clean_str($_POST['name'] ?? '', 60);
        $ctype = $_POST['type'] ?? '';
        $color = (string) ($_POST['color'] ?? '#64748b');
        if (mb_strlen($name) < 2 || !in_array($ctype, ['income', 'expense'], true) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            flash('error', 'Enter a category name (2+ characters) and choose a type.');
        } else {
            $ins = $pdo->prepare('INSERT IGNORE INTO categories (user_id, name, type, color) VALUES (:u, :n, :t, :c)');
            $ins->execute([':u' => $uid, ':n' => $name, ':t' => $ctype, ':c' => $color]);
            flash($ins->rowCount() ? 'success' : 'error', $ins->rowCount() ? 'Category added.' : 'That category already exists.');
        }
        redirect($self . '#categories');
    }
    if ($action === 'delete_category') {
        $cid = (int) ($_POST['id'] ?? 0);
        $own = $pdo->prepare('SELECT id, type FROM categories WHERE id = :id AND user_id = :u');
        $own->execute([':id' => $cid, ':u' => $uid]);
        $cat = $own->fetch();
        if (!$cat) {
            flash('error', 'Category not found.');
        } else {
            $tb = $cat['type'] === 'income' ? 'income' : 'expenses';
            $use = $pdo->prepare("SELECT COUNT(*) FROM $tb WHERE category_id = :c AND user_id = :u");
            $use->execute([':c' => $cid, ':u' => $uid]);
            if ((int) $use->fetchColumn() > 0) {
                flash('error', 'This category has transactions and cannot be deleted. Delete or re-categorize them first.');
            } else {
                $pdo->prepare('DELETE FROM categories WHERE id = :id AND user_id = :u')->execute([':id' => $cid, ':u' => $uid]);
                flash('success', 'Category deleted.');
            }
        }
        redirect($self . '#categories');
    }

    /* ---- delete account ---- */
    if ($action === 'delete_account') {
        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $row->execute([':id' => $uid]);
        if (!password_verify((string) ($_POST['password'] ?? ''), (string) $row->fetchColumn())) {
            flash('error', 'Incorrect password. Your account was not deleted.');
            redirect($self . '#danger');
        }
        if ($user['avatar'] && is_file($uploadDir . $user['avatar'])) @unlink($uploadDir . $user['avatar']);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
        logout_user();
        session_start();
        flash('success', 'Your account and all its data were permanently deleted.');
        redirect('auth/login.php');
    }
    redirect($self);
}

/* ---- account information ---- */
$cnt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM income WHERE user_id = :a) AS inc,
                             (SELECT COUNT(*) FROM expenses WHERE user_id = :b) AS exp,
                             (SELECT COUNT(*) FROM budgets WHERE user_id = :c) AS bud,
                             (SELECT COUNT(*) FROM savings_goals WHERE user_id = :d) AS sav');
$cnt->execute([':a' => $uid, ':b' => $uid, ':c' => $uid, ':d' => $uid]);
$counts = $cnt->fetch();
$cats = get_categories($uid);

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h2>Settings &amp; Profile</h2><p>Manage your account, security and categories.</p></div></div>

<div class="grid grid-2 mb">
    <section class="card settings-section">
        <div class="card-head"><h3 class="card-title">Profile picture</h3></div>
        <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
            <?= avatar_html($user, 'avatar-lg') ?>
            <div style="flex:1;min-width:220px">
                <form method="post" action="<?= e(url($self)) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?><input type="hidden" name="action" value="avatar">
                    <div class="form-group">
                        <label for="avatar">Upload new picture</label>
                        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
                        <span class="hint">JPG, PNG, GIF or WebP, max 2 MB.</span>
                    </div>
                    <div class="form-actions" style="justify-content:flex-start"><button class="btn" type="submit">Upload</button></div>
                </form>
                <?php if ($user['avatar']): ?>
                    <form method="post" action="<?= e(url($self)) ?>" style="margin-top:8px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="avatar"><input type="hidden" name="remove" value="1">
                        <button class="btn btn-outline btn-sm" type="submit">Remove picture</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card settings-section">
        <div class="card-head"><h3 class="card-title">Account information</h3></div>
        <dl class="detail-grid">
            <dt>Name</dt><dd><?= e($user['full_name']) ?></dd>
            <dt>Email</dt><dd><?= e($user['email']) ?></dd>
            <dt>Member since</dt><dd><?= e(date('F j, Y', strtotime($user['created_at']))) ?></dd>
            <dt>Currency</dt><dd><?= e($user['currency']) ?> (<?= e(currency_symbol()) ?>)</dd>
            <dt>Records</dt><dd><?= (int) $counts['inc'] ?> income · <?= (int) $counts['exp'] ?> expenses · <?= (int) $counts['bud'] ?> budgets · <?= (int) $counts['sav'] ?> goals</dd>
        </dl>
    </section>
</div>

<div class="grid grid-2 mb">
    <section class="card settings-section">
        <div class="card-head"><h3 class="card-title">Personal details</h3></div>
        <form method="post" action="<?= e(url($self)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <div class="form-grid" style="grid-template-columns:1fr">
                <div class="form-group"><label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" maxlength="100" required></div>
                <div class="form-group"><label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" maxlength="190" required></div>
                <div class="form-group"><label for="currency">Currency</label>
                    <select id="currency" name="currency">
                        <?php foreach (CURRENCIES as $code => [$sym, $cname]): ?>
                            <option value="<?= e($code) ?>" <?= $user['currency'] === $code ? 'selected' : '' ?>><?= e("$sym  $code – $cname") ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Changes how amounts are displayed. Existing amounts are not converted.</span></div>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Save changes</button></div>
        </form>
    </section>

    <section class="card settings-section">
        <div class="card-head"><h3 class="card-title">Change password</h3></div>
        <form method="post" action="<?= e(url($self)) ?>" data-match="new_password,confirm_password">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <div class="form-grid" style="grid-template-columns:1fr">
                <div class="form-group"><label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
                <div class="form-group"><label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password">
                    <span class="hint">At least 8 characters, with letters and numbers.</span></div>
                <div class="form-group"><label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Update password</button></div>
        </form>
    </section>
</div>

<section class="card settings-section mb" id="categories">
    <div class="card-head"><h3 class="card-title">Categories</h3></div>
    <form method="post" action="<?= e(url($self)) ?>" class="filters mb" style="margin-bottom:18px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_category">
        <div class="form-group grow"><label for="cname">New category name</label>
            <input type="text" id="cname" name="name" maxlength="60" required placeholder="e.g. Pets"></div>
        <div class="form-group"><label for="ctype">Type</label>
            <select id="ctype" name="type"><option value="expense">Expense</option><option value="income">Income</option></select></div>
        <div class="form-group" style="flex:0 0 90px"><label for="ccolor">Color</label>
            <input type="color" id="ccolor" name="color" value="#2563eb" style="width:100%;height:40px;border:1px solid #cfd6e6;border-radius:9px;padding:2px"></div>
        <div class="btns"><button class="btn" type="submit"><?= icon('plus', 16) ?> Add</button></div>
    </form>
    <div class="grid grid-2">
        <?php foreach (['income' => 'Income categories', 'expense' => 'Expense categories'] as $tk => $tl): ?>
            <div>
                <div class="label" style="margin-bottom:8px"><?= e($tl) ?></div>
                <div class="cat-list">
                    <?php foreach ($cats as $c): if ($c['type'] !== $tk) continue; ?>
                        <span class="cat-pill"><span class="dot" style="background:<?= e($c['color']) ?>"></span><?= e($c['name']) ?>
                            <form method="post" action="<?= e(url($self)) ?>" data-confirm="Delete the category “<?= e($c['name']) ?>”?">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" title="Delete category" aria-label="Delete <?= e($c['name']) ?>"><?= icon('x', 13) ?></button>
                            </form></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="hint" style="margin-top:12px">A category can only be deleted when it has no transactions.</p>
</section>

<section class="card settings-section" id="danger" style="border-color:#f4c4c4">
    <div class="card-head"><h3 class="card-title text-red">Delete account</h3></div>
    <p class="muted" style="margin-top:0">Permanently deletes your account and all income, expenses, budgets, savings and notifications. This cannot be undone.</p>
    <form method="post" action="<?= e(url($self)) ?>" class="filters" data-confirm="This permanently deletes your account and ALL your data. Continue?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_account">
        <div class="form-group"><label for="del_pw">Confirm with your password</label>
            <input type="password" id="del_pw" name="password" required autocomplete="current-password"></div>
        <div class="btns"><button class="btn btn-red" type="submit"><?= icon('trash', 16) ?> Delete my account</button></div>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
