<?php
// admin/edit_club.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

$errors = [];
$success = null;
$csrf = csrf_token();

// id param
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    die('Missing club id.');
}

// load club
$stmt = $pdo->prepare('SELECT id, name, description FROM clubs WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$club = $stmt->fetch();
if (!$club) {
    http_response_code(404);
    die('Club not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if ($name === '') $errors[] = 'Club name is required.';

        if (empty($errors)) {
            try {
                $upd = $pdo->prepare('UPDATE clubs SET name = :name, description = :desc WHERE id = :id');
                $upd->execute([':name' => $name, ':desc' => $desc, ':id' => $id]);
                $success = 'Club updated.';
                // refresh club data
                $club['name'] = $name;
                $club['description'] = $desc;
            } catch (Exception $e) {
                $errors[] = 'Update failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Edit Club</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap{ max-width:760px; margin:28px auto; padding:12px; }
        .card{ background:#fff; padding:16px; border-radius:10px; border:1px solid #eef6ff; }
        label{ display:block; margin-bottom:6px; font-weight:700;}
        input[type="text"], textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #e6eef8; box-sizing:border-box; }
        textarea { min-height:140px; resize:vertical; }
        .actions { margin-top:12px; display:flex; gap:8px; }
        .btn { padding:10px 14px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
        .btn-save { background:#10b981;color:#fff; }
        .btn-back { background:transparent;border:1px solid #cfe3ff;color:#2563eb;padding:10px 12px;border-radius:8px; }
        .msg { padding:10px;border-radius:8px;margin-bottom:12px; }
        .msg.success { background:#f0fdf4;color:#065f46;border:1px solid rgba(16,185,129,0.08); }
        .msg.error { background:#fff5f5;color:#991b1b;border:1px solid rgba(239,68,68,0.08); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="wrap">
    <a href="<?= e(BASE_URL) ?>/admin/manage_clubs.php" class="btn-back">← Back to Manage Clubs</a>
    <div style="height:14px"></div>

    <div class="card">
        <h2>Edit Club</h2>

        <?php if (!empty($errors)): ?>
            <div class="msg error"><?php foreach ($errors as $err) echo '<div>' . e($err) . '</div>'; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="msg success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <label for="name">Club Name</label>
            <input id="name" name="name" type="text" value="<?= e($club['name']) ?>" required>

            <label for="description" style="margin-top:10px;">Description</label>
            <textarea id="description" name="description"><?= e($club['description']) ?></textarea>

            <div class="actions">
                <button type="submit" class="btn btn-save">Update</button>
                <a href="<?= e(BASE_URL) ?>/admin/manage_clubs.php" class="btn btn-back">Cancel</a>
            </div>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
