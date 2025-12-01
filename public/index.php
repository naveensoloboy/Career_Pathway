<?php
require_once __DIR__ . '/../config/config.php';

// If logged in, redirect to role-specific dashboard
if (is_logged_in()) {
    $u = current_user($pdo);
    if ($u && $u['role'] === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    } else {
        header('Location: ' . BASE_URL . '/attendee/dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>MCQ Club App</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/index.css">
    <link rel="stylesheet" href="/public/css/footer.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h1>Welcome to MCQ Club App</h1>
    <p>Automate your club MCQ tests — daily and weekly.</p>
</main>

    <div class="cards">
        <div class="card">
            
            <p>Create account to take tests.</p>
            <a href="<?= e(BASE_URL) ?>/auth/register.php">Register</a> 
            <a href="<?= e(BASE_URL) ?>/auth/login.php">Login</a>
        </div>

        
    </div>


<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
