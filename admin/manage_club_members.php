<?php
// admin/manage_club_members.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

$errors = [];
$success = null;
$csrf = csrf_token();

// fetch clubs for the select
$clubs = $pdo->query("SELECT id, name FROM clubs ORDER BY name")->fetchAll();

// Handle removal (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_member'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request.";
    } else {
        $club_id = intval($_POST['club_id'] ?? 0);
        $user_roll = trim($_POST['user_roll'] ?? '');

        if ($club_id <= 0 || $user_roll === '') {
            $errors[] = "Missing parameters for removal.";
        } else {
            try {
                $pdo->beginTransaction();

                // delete role mapping
                $del = $pdo->prepare("DELETE FROM club_roles WHERE club_id = :cid AND user_roll = :r LIMIT 1");
                $del->execute([':cid' => $club_id, ':r' => $user_roll]);

                // If deleted, check if the user still has any other club_roles
                $chk = $pdo->prepare("SELECT COUNT(*) FROM club_roles WHERE user_roll = :r");
                $chk->execute([':r' => $user_roll]);
                $left = (int)$chk->fetchColumn();

                if ($left === 0) {
                    // revert users.role to attendee (only if user's role is not admin)
                    $uRow = $pdo->prepare("SELECT role FROM users WHERE roll_no = :r LIMIT 1");
                    $uRow->execute([':r' => $user_roll]);
                    $u = $uRow->fetch();
                    if ($u && ($u['role'] ?? '') !== 'admin') {
                        $upd = $pdo->prepare("UPDATE users SET role = 'attendee' WHERE roll_no = :r");
                        $upd->execute([':r' => $user_roll]);
                    }
                }

                $pdo->commit();
                $success = "Member {$user_roll} removed from club.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "Failed to remove member: " . $e->getMessage();
            }
        }
    }
}

// When a club is chosen via GET, show its members
$selectedClubId = isset($_GET['club_id']) ? intval($_GET['club_id']) : 0;
$members = [];
$clubName = null;
if ($selectedClubId > 0) {
    // fetch club name
    $stmt = $pdo->prepare("SELECT id, name FROM clubs WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $selectedClubId]);
    $c = $stmt->fetch();
    if ($c) {
        $clubName = $c['name'];
        // fetch members grouped with role ordering
        $mstmt = $pdo->prepare("
            SELECT cr.user_roll, cr.role, cr.can_post_questions, u.full_name, u.class
            FROM club_roles cr
            LEFT JOIN users u ON u.roll_no = cr.user_roll
            WHERE cr.club_id = :cid
            ORDER BY FIELD(cr.role,'club_secretary','club_joint_secretary','club_member'), u.full_name
        ");
        $mstmt->execute([':cid' => $selectedClubId]);
        $members = $mstmt->fetchAll();
    } else {
        $errors[] = "Selected club not found.";
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manage Club Members</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap { max-width:1100px; margin:20px auto; padding:12px; }
        .card { background:#fff; border:1px solid #eef6ff; padding:14px; border-radius:10px; margin-bottom:12px; }
        label { font-weight:700; display:block; margin-bottom:6px; }
        select, input[type="text"] { padding:10px; border-radius:8px; border:1px solid #e6eef8; width:100%; box-sizing:border-box; }
        .grid { display:grid; gap:12px; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { padding:10px 8px; border-bottom:1px solid #eef6ff; text-align:left; vertical-align:middle; }
        th { background:#fbfdff; color:#334155; font-weight:700; font-size:0.95rem; }
        .role-badge { display:inline-block; padding:4px 8px; font-size:0.85rem; border-radius:8px; background:#eef2ff; color:#1e3a8a; }
        .btn { padding:8px 10px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
        .btn-delete { background:#ef4444; color:#fff; }
        .note { color:#64748b; font-size:0.95rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="wrap">
    <div class="card">
        <h2>Manage Club Members</h2>
        <?php if (!empty($errors)): ?>
            <div style="color:#b91c1c; margin-bottom:10px;"><?= e(implode(' | ', $errors)) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div style="color:#065f46; margin-bottom:10px;"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="get" class="grid" style="grid-template-columns: 1fr auto; gap:12px; align-items:end;">
            <div style="margin: auto 0;">
                <label for="club_id">Choose Club</label>
                <select id="club_id" name="club_id" required>
                    <option value="">-- select club --</option>
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selectedClubId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin: 15px;">
                <button type="submit" class="btn" style="background:linear-gradient(90deg,#2563eb,#1d4ed8); padding: 10px; color:#fff;">Load Members</button>
            </div>
        </form>
    </div>

    <?php if ($selectedClubId > 0): ?>
        <div class="card">
            <h3>Members of <?= e($clubName) ?></h3>
            <?php if (empty($members)): ?>
                <p class="note">No members in this club yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Role</th>
                            <th>Roll No</th>
                            <th>Name</th>
                            <th>Class</th>
                            <th>Posting</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $idx => $m): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td><span class="role-badge"><?= e($m['role']) ?></span></td>
                                <td><?= e($m['user_roll']) ?></td>
                                <td><?= e($m['full_name'] ?? '') ?></td>
                                <td><?= e($m['class'] ?? '') ?></td>
                                <td><?= (int)($m['can_post_questions'] ?? 0) ? 'Yes' : 'No' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remove <?= addslashes($m['user_roll']) ?> from <?= addslashes($clubName) ?>?');" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="club_id" value="<?= (int)$selectedClubId ?>">
                                        <input type="hidden" name="user_roll" value="<?= e($m['user_roll']) ?>">
                                        <button type="submit" name="remove_member" class="btn btn-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
