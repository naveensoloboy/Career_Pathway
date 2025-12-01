<?php
// club/dashboard.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'];
$club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : 0;
if ($club_id <= 0) {
    die('Club not specified.');
}

// load club
$club = $pdo->prepare('SELECT * FROM clubs WHERE id = :id LIMIT 1');
$club->execute([':id' => $club_id]);
$clubRow = $club->fetch();
if (!$clubRow) die('Club not found.');

// get user's role in club
$roleInfo = get_club_role($pdo, $user_roll, $club_id);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= e($clubRow['name']) ?> — Club Dashboard</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/footer.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/dashboard.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2>Club Officers — Dashboard</h2>
    <p><?= e($clubRow['description'] ?? '') ?></p>

    <?php if (!$roleInfo): ?>
        <p>You are not a member of this club. Contact admin to add you.</p>
    <?php else: ?>
        <br><h3 style="text-align:center;">Your role: <strong><?= e($roleInfo['role']) ?></strong></h3><br>

        <div class="cards">
            <?php if (in_array($roleInfo['role'], ['club_secretary','club_joint_secretary','club_member'], true) && $roleInfo['can_post_questions']): ?>
                <div class="card">
                    <h3>Create Test</h3>
                    <p>Create a daily or weekly test.</p>
                    <a href="<?= e(BASE_URL) ?>/club/create_test.php?club_id=<?= (int)$club_id ?>">Open</a>
                </div>

                <div class="card">
                    <h3>Add Questions (pending)</h3>
                    <p>Submit MCQs for admin approval.</p>
                    <a href="<?= e(BASE_URL) ?>/club/add_questions.php?club_id=<?= (int)$club_id ?>">Open</a>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3>Test Status</h3>
                <p>View who attended a test and download results.</p>
                <a href="<?= e(BASE_URL) ?>/club/test_status.php?club_id=<?= (int)$club_id ?>">Open</a>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
