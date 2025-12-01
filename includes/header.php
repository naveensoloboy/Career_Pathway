<?php
// includes/header.php
// Minimal header + navigation. Expects config/config.php already required.
if (!isset($pdo)) {
    // try to include config if not present
    require_once __DIR__ . '/../config/config.php';
}
$user = current_user($pdo);
?>
<header style="padding:10px; border-bottom:1px solid #ddd;">
    <div class="container" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <a href="<?= e(BASE_URL) ?>/public/index.php" style="text-decoration:none;"><strong>MCQ Club App</strong></a>
        </div>
        <nav>
            <?php if ($user): ?>
                <span>Welcome, <?= e($user['full_name'] ?? $user['roll_no']) ?> (<?= e($user['role']) ?>)</span>
                |
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="<?= e(BASE_URL) ?>/admin/dashboard.php">Admin</a> |
                <?php endif; ?>
                <a href="<?= e(BASE_URL) ?>/attendee/dashboard.php">Dashboard</a> |
                <a href="<?= e(BASE_URL) ?>/auth/logout.php">Logout</a>
            <?php else: ?>
                <a href="<?= e(BASE_URL) ?>/public/index.php">Home</a> |
                <a href="<?= e(BASE_URL) ?>/auth/register.php">Register</a> |
                <a href="<?= e(BASE_URL) ?>/auth/login.php">Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
