<?php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);
$user = current_user($pdo);
$creatorRoll = $user['roll_no'] ?? null;

$errors = [];
$success = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request.";
    }

    $name = trim($_POST['name'] ?? '');
    $des = trim($_POST['description'] ??'');

    if ($name === '') {
        $errors[] = "Club name required.";
    }

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO clubs (name,description,created_by_roll) VALUES (:n,:m,:o)");
        $stmt->execute([':n' => $name,':m' => $des,':o' => $creatorRoll]);
        $success = "Club created successfully.";
    }
}

$csrf = csrf_token();

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Create Club</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/create_club.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/footer.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2>Create Club</h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul><?php foreach ($errors as $e) echo "<li>" . e($e) . "</li>"; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?php echo "<script>alert('Club created successfully.');window.location='dashboard.php';</script>";?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>Club Name</label><br>
        <input type="text" name="name" required><br><br>

        <label>Description</label><br>
        <textarea name="description" required></textarea><br><br>

        <button type="submit">Create</button>
    </form>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
