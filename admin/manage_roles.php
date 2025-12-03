<?php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

$errors = [];
$success = null;

$clubs = $pdo->query("SELECT id, name FROM clubs")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!validate_csrf($_POST['csrf_token'])) {
        $errors[] = "Invalid request.";
    } else {
        $club_id = intval($_POST['club_id']);
        $roll = trim($_POST['roll_no']);
        $role = trim($_POST['role']);
        $can_post = isset($_POST['can_post']) ? 1 : 0;

        // check if user exists
        $u = $pdo->prepare("SELECT roll_no FROM users WHERE roll_no = :r LIMIT 1");
        $u->execute([':r' => $roll]);
        if (!$u->fetch()) {
            $errors[] = "User not found.";
        } else {
            try {
                $pdo->beginTransaction();

                // update or insert club role
                $stmt = $pdo->prepare("
                    INSERT INTO club_roles (club_id, user_roll, role, can_post_questions)
                    VALUES (:cid, :r, :role, :cp)
                    ON DUPLICATE KEY UPDATE role = VALUES(role), can_post_questions = VALUES(can_post_questions)
                ");
                $stmt->execute([':cid' => $club_id, ':r' => $roll, ':role' => $role, ':cp' => $can_post]);

                // also update the users.role column to reflect this club role
                // Note: this changes the global role — adjust if you prefer a different behavior
                $updUser = $pdo->prepare("UPDATE users SET role = :role WHERE roll_no = :r");
                $updUser->execute([':role' => $role, ':r' => $roll]);

                $pdo->commit();
                $success = "Role updated successfully and user role synchronized.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manage Club Roles</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <link rel="stylesheet" href="/public/css/create_club.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2>Manage Roles</h2>

    <?php if ($errors): ?>
        <div class="errors"><ul><?php foreach ($errors as $e) echo "<li>".e($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>Select Club</label><br>
        <select name="club_id">
            <?php foreach ($clubs as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select><br><br>

        <label>User Roll Number</label><br>
        <input type="text" name="roll_no" required><br><br>

        <label>Role</label><br>
        <select name="role" required>
            <option value="club_secretary">Secretary</option>
            <option value="club_joint_secretary">Joint Secretary</option>
            <option value="club_member">Member</option>
        </select><br><br>

        <label><input type="checkbox" checked name="can_post"> Can Post Questions</label><br><br>

        <button type="submit">Update Role</button>
    </form>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
