<?php
require_once __DIR__ . '/../includes/functions.php';
redirect_if_logged_in();

$errors = [];
$old = ['full_name' => '', 'email' => ''];

if (is_post()) {
    csrf_verify();
    $name  = clean_str($_POST['full_name'] ?? '', 100);
    $email = strtolower(clean_str($_POST['email'] ?? '', 190));
    $pw    = (string) ($_POST['password'] ?? '');
    $pw2   = (string) ($_POST['confirm_password'] ?? '');
    $old   = ['full_name' => $name, 'email' => $email];

    if (mb_strlen($name) < 2) {
        $errors[] = 'Please enter your full name (at least 2 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $errors[] = 'Password must be at least 8 characters and contain letters and numbers.';
    }
    if (strlen($pw) > 72) {
        $errors[] = 'Password must be 72 characters or fewer.';
    }
    if ($pw !== $pw2) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $st = db()->prepare('SELECT id FROM users WHERE email = :e');
        $st->execute([':e' => $email]);
        if ($st->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, currency)
                                  VALUES (:n, :e, :p, :c)');
            $ins->execute([':n' => $name, ':e' => $email, ':p' => password_hash($pw, PASSWORD_DEFAULT), ':c' => 'PHP']);
            $newId = (int) $pdo->lastInsertId();
            seed_default_categories($newId);
            notify($newId, 'success', 'Welcome to ' . APP_NAME . '!',
                'Your account is ready. Start by adding income, then set a monthly budget.');
            $pdo->commit();
            login_user($newId);
            flash('success', 'Account created. Welcome, ' . $name . '!');
            redirect('pages/dashboard.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = ($ex->getCode() === '23000')
                ? 'An account with this email already exists.'
                : 'Could not create the account. Please try again.';
        }
    }
}

$authPage  = true;
$pageTitle = 'Create account';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand"><span class="brand-mark"><?= icon('wallet', 20) ?></span> <?= e(APP_NAME) ?></div>
        <h2>Create your account</h2>
        <p class="muted" style="margin:0">Track income, expenses, budgets and savings in one place.</p>

        <?php if ($errors): ?>
            <div class="error-box"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post" action="" data-match="password,confirm_password">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="100" autocomplete="name">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required maxlength="190" autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
                <span class="hint">At least 8 characters, with letters and numbers.</span>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password">
            </div>
            <div class="form-group"><button class="btn btn-block" type="submit">Create account</button></div>
        </form>
        <p class="auth-foot">Already have an account? <a href="<?= e(url('auth/login.php')) ?>">Sign in</a></p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
