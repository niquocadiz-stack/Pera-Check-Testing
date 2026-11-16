<?php
require_once __DIR__ . '/../includes/functions.php';
redirect_if_logged_in();

$errors = [];
$email = '';
const MAX_FAILS = 5;
const LOCK_SECONDS = 300;

if (is_post()) {
    csrf_verify();
    $email = strtolower(clean_str($_POST['email'] ?? '', 190));
    $pw    = (string) ($_POST['password'] ?? '');

    if (($_SESSION['lock_until'] ?? 0) > time()) {
        $errors[] = 'Too many failed attempts. Please try again in a few minutes.';
    } elseif ($email === '' || $pw === '') {
        $errors[] = 'Please enter your email and password.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = :e');
        $st->execute([':e' => $email]);
        $row = $st->fetch();
        // verify against a dummy hash when the user is unknown (uniform timing)
        $hash = $row['password_hash'] ?? '$2y$10$oYleM5XitH.5MAePXPYlROAbIyr5pANyViWFx5grmXi0OUke5nBO.';
        $ok = password_verify($pw, $hash);

        if ($row && $ok) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $up = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
                $up->execute([':h' => password_hash($pw, PASSWORD_DEFAULT), ':id' => $row['id']]);
            }
            unset($_SESSION['fails'], $_SESSION['lock_until']);
            login_user((int) $row['id']);
            redirect('pages/dashboard.php');
        }
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= MAX_FAILS) {
            $_SESSION['lock_until'] = time() + LOCK_SECONDS;
            $_SESSION['fails'] = 0;
        }
        $errors[] = 'Incorrect email or password.';
    }
}

$authPage  = true;
$pageTitle = 'Sign in';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand"><span class="brand-mark"><?= icon('wallet', 20) ?></span> <?= e(APP_NAME) ?></div>
        <h2>Welcome back</h2>
        <p class="muted" style="margin:0">Sign in to see where your money goes.</p>

        <?php if ($errors): ?>
            <div class="error-box"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post" action="">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <div class="form-group"><button class="btn btn-block" type="submit">Sign in</button></div>
        </form>
        <p class="auth-foot">New here? <a href="<?= e(url('auth/register.php')) ?>">Create an account</a></p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
