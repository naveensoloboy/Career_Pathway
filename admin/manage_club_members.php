<?php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

$errors = [];
$success = null;

// Fetch clubs
$clubs = $pdo->query("SELECT id, name FROM clubs ORDER BY name")->fetchAll();

// Add user to club
if (isset($_POST['add_member'])) {
    if (!validate_csrf($_POST['csrf_token'])) {
        $errors[] = "Invalid request.";
    } else {
        $club_id = intval($_POST['club_id']);
        $roll = trim($_POST['roll_no']);

        if ($club_id <= 0 || $roll === '') {
            $errors[] = "Club and roll number are required.";
        } else {
            // check if user exists
            $u = $pdo->prepare("SELECT roll_no, role FROM users WHERE roll_no = :r LIMIT 1");
            $u->execute([':r' => $roll]);
            $userRow = $u->fetch();
            if (!$userRow) {
                $errors[] = "User does not exist.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // add member with default role = club_member
                    $ins = $pdo->prepare("INSERT INTO club_roles (club_id, user_roll, role, can_post_questions)
                                          VALUES (:cid, :r, 'club_member', 0)");
                    $ins->execute([':cid' => $club_id, ':r' => $roll]);

                    // synchronize users.role only when current role is attendee
                    if (isset($userRow['role']) && $userRow['role'] === 'attendee') {
                        $updUser = $pdo->prepare("UPDATE users SET role = 'club_member' WHERE roll_no = :r");
                        $updUser->execute([':r' => $roll]);
                    }

                    $pdo->commit();
                    $success = "User added as club member.";
                } catch (Exception $e) {
                    $pdo->rollBack();

                    if ($e->getCode() === '23000') {
                        $errors[] = "User already in this club.";
                    } else {
                        $errors[] = "Database error: " . $e->getMessage();
                    }
                }
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
    <title>Manage Club Members</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/create_club.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/footer.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<?php if ($errors): ?>
<script>
    alert("<?= e(implode('\n', $errors)) ?>");
</script>
<?php endif; ?>

<?php if ($success): ?>
<script>
    alert("<?= e($success) ?>");
    window.location.href = "manage_club_members.php";  // reload the page
</script>
<?php endif; ?>

<main class="container">
    <h2>Manage Club Members</h2>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>Choose Club</label><br>
        <select name="club_id" required>
            <?php foreach ($clubs as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select><br><br>

        <label>User Roll Number</label><br>
        <input type="text" name="roll_no" required><br><br>

        <button type="submit" name="add_member">Add Member</button>
    </form>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
