<?php
// club/manage_role.php
require_once __DIR__ . '/../config/config.php';

// must be logged in
require_login();

$errors = [];
$success = null;

// get club_id from query string
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
if ($club_id <= 0) {
    http_response_code(400);
    die('Club not specified.');
}

// ensure this club exists
$stmtClub = $pdo->prepare("SELECT id, name, description FROM clubs WHERE id = :id LIMIT 1");
$stmtClub->execute([':id' => $club_id]);
$club = $stmtClub->fetch();
if (!$club) {
    http_response_code(404);
    die('Club not found.');
}

// only club_secretary / club_joint_secretary of this club can access
require_club_role($pdo, $club_id, ['club_secretary','club_joint_secretary']);

$csrf = csrf_token();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request.";
    } else {
        $roll = trim($_POST['roll_no'] ?? '');
        $role = trim($_POST['role'] ?? 'club_member');
        $can_post = isset($_POST['can_post']) ? 1 : 0;

        if ($roll === '') {
            $errors[] = "Roll number is required.";
        }

        if (empty($errors)) {
            // check if user exists
            $u = $pdo->prepare("SELECT roll_no FROM users WHERE roll_no = :r LIMIT 1");
            $u->execute([':r' => $roll]);
            if (!$u->fetch()) {
                $errors[] = "User not found.";
            } else {
                try {
                    $iap = "waiting";
                    $pdo->beginTransaction();

                    // upsert into club_roles for THIS club only
                    $stmt = $pdo->prepare("
                        INSERT INTO club_roles_pending (club_id, user_roll, role, can_post_questions, is_approve)
                        VALUES (:cid, :r, :role, :cp, :iap)
                        ON DUPLICATE KEY UPDATE
                            role = VALUES(role),
                            can_post_questions = VALUES(can_post_questions)
                    ");
                    $stmt->execute([
                        ':cid'  => $club_id,
                        ':r'    => $roll,
                        ':role' => $role,
                        ':cp'   => $can_post,
                        ':iap' => $iap
                    ]);

                    $pdo->commit();
                    $success = "Adding Member for {$club['name']} club request is sent to admin.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manage Roles — <?= e($club['name']) ?> Club</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap {
            max-width: 640px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15,23,42,0.06);
            border: 1px solid #e5e7eb;
        }
        h2 {
            margin-top: 0;
        }
        label {
            display:block;
            margin-bottom:6px;
            font-weight:600;
        }
        input[type="text"], select {
            width:100%;
            padding:10px;
            border-radius:8px;
            border:1px solid #d1d5db;
            margin-bottom:12px;
            box-sizing:border-box;
        }
        .btn {
            display:inline-block;
            padding:10px 14px;
            border-radius:8px;
            border:0;
            cursor:pointer;
            font-weight:700;
        }
        .btn-primary {
            background: linear-gradient(90deg,#2563eb,#1d4ed8);
            color:#fff;
        }
        .btn-ghost {
            background:transparent;
            border:1px solid #cbd5f5;
            color:#2563eb;
        }
        .errors ul {
            margin:0;
            padding-left:18px;
            color:#b91c1c;
            font-weight:500;
        }
        .success {
            color:#065f46;
            font-weight:600;
            margin-bottom:10px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div class="wrap">
        <h2> Add Members to <?= e($club['name']) ?> Club</h2>
        <!-- <p style="color:#6b7280;font-size:0.95rem;">
            Add members for this club.
        </p> -->
        <br>

        <?php if ($errors): ?>
            <div class="errors">
                <ul>
                    <?php foreach ($errors as $e) echo "<li>".e($e)."</li>"; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <label>Club</label>
            <input type="text" value="<?= e($club['name']) ?>" disabled>

            <label>Member Roll Number</label>
            <input type="text" name="roll_no" required placeholder="Enter the roll number">

            <input type="hidden" name="role" value="club_member">

            <label>
                <input type="checkbox" name="can_post" checked>
                Can Post Questions
            </label>

            <br>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="<?= e(BASE_URL) ?>/club/dashboard.php?club_id=<?= (int)$club_id ?>" class="btn btn-ghost">Back to Club Dashboard</a>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
