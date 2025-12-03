<?php
require_once __DIR__ . '/../config/config.php';

$errors = [];

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/attendee/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    }

    $roll_no = trim($_POST['roll_no'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($roll_no === '' || $password === '') {
        $errors[] = 'Roll number and password required.';
    }

    if (empty($errors)) {

        /* 1) First: check ADMIN table */
        $adminStmt = $pdo->prepare('SELECT roll_no, password FROM admin WHERE roll_no = :roll LIMIT 1');
        $adminStmt->execute([':roll' => $roll_no]);
        $admin = $adminStmt->fetch();

        if ($admin) {
            // roll_no exists in admin table → treat as admin login only
            if (!password_verify($password, $admin['password'])) {
                $errors[] = 'Invalid credentials.';
            } else {
                // admin login success
                $_SESSION['user_roll'] = $admin['roll_no'];   // keep same key so session works
                $_SESSION['is_admin']  = true;                // optional flag if you use it elsewhere

                header('Location: ' . BASE_URL . '/admin/dashboard.php');
                exit;
            }

        } else {
            /* 2) No admin row → check USERS table (normal attendee/club user) */

            $stmt = $pdo->prepare('SELECT roll_no, password_hash, is_active, role FROM users WHERE roll_no = :roll LIMIT 1');
            $stmt->execute([':roll' => $roll_no]);
            $user = $stmt->fetch();

            if (!$user) {
                $errors[] = 'Invalid credentials.';
            } elseif (!$user['is_active']) {
                $errors[] = 'Account not active. Please contact Admin.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid credentials.';
            } else {
                // normal user login success
                $_SESSION['user_roll'] = $user['roll_no'];
                unset($_SESSION['user_row_cache']);

                $after = $_SESSION['after_login_redirect'] ?? null;
                unset($_SESSION['after_login_redirect']);

                // if this user ALSO has role = admin in users table, still support old flow
                if (isset($user['role']) && $user['role'] === 'admin') {
                    header('Location: ' . BASE_URL . '/admin/dashboard.php');
                    exit;
                }

                // club redirect
                $clubStmt = $pdo->prepare('SELECT club_id FROM club_roles WHERE user_roll = :r ORDER BY club_id LIMIT 1');
                $clubStmt->execute([':r' => $user['roll_no']]);
                $clubRow = $clubStmt->fetch();
                if ($clubRow && !empty($clubRow['club_id'])) {
                    header('Location: ' . BASE_URL . '/club/dashboard.php?club_id=' . (int)$clubRow['club_id']);
                    exit;
                }

                // after-login redirect if safe
                if ($after) {
                    if (!preg_match('#/auth/(login|register|forgot_password)\.php#', $after)) {
                        header('Location: ' . $after);
                        exit;
                    }
                }

                // fallback
                header('Location: ' . BASE_URL . '/attendee/dashboard.php');
                exit;
            }
        }
    }
}


$token = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login — Career Pathway</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/login.css">
    <link rel="stylesheet" href="/public/css/footer.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2>Login</h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e) echo '<li>' . $e . '</li>'; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <label>Roll Number</label><br>
        <input type="text" name="roll_no" value="<?= e($_POST['roll_no'] ?? '') ?>" required><br><br>

        <label>Password</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Login</button>
    </form>

    <p><a href="<?= e(BASE_URL) ?>/auth/forgot_password.php">Forgot password?</a></p>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
