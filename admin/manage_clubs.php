<?php
// admin/manage_clubs.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

$csrf = csrf_token();

// fetch clubs
$stmt = $pdo->query('SELECT id, name, description FROM clubs ORDER BY name');
$clubs = $stmt->fetchAll();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manage Clubs</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap { max-width:1100px; margin:24px auto; padding:12px; }
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); gap:12px; }
        .card { background:#fff; padding:14px; border-radius:10px; border:1px solid #eef6ff; }
        .card h4 { margin:0 0 6px 0; }
        .meta { color:#64748b; font-size:0.95rem; margin-bottom:10px; }
        .actions { display:flex; gap:8px; }
        .btn { padding:8px 10px; border-radius:8px; text-decoration:none; font-weight:700; border:0; cursor:pointer; }
        .btn-edit { background:#2563eb;padding:8px 18px;color:#fff; }
        .btn-delete { background:#ef4444;padding: 15px 18px;color:#fff; }
        .btn-back { background:transparent; color:#2563eb; border:1px solid #cfe3ff; padding:8px 10px; border-radius:8px; text-decoration:none; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="wrap">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px;">
        <h2>Manage Clubs</h2>
        <a class="btn-back" href="<?= e(BASE_URL) ?>/admin/dashboard.php">← Back to Admin</a>
    </div>

    <?php if (empty($clubs)): ?>
        <div class="card">No clubs found. <a href="<?= e(BASE_URL) ?>/admin/create_club.php">Create one</a>.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($clubs as $c): ?>
                <div class="card" role="region" aria-label="<?= e($c['name']) ?>">
                    <h4><?= e($c['name']) ?></h4>
                    <?php if (!empty($c['description'])): ?>
                        <div class="meta"><?= e($c['description']) ?></div>
                    <?php else: ?>
                        <div class="meta" style="color:#94a3b8;">(no description)</div>
                    <?php endif; ?>

                    <div class="actions" style="margin-top:8px;">
                        <a class="btn btn-edit" href="<?= e(BASE_URL) ?>/admin/edit_club.php?id=<?= (int)$c['id'] ?>">Edit</a>

                        <!-- delete posts to delete_club.php (POST) -->
                        <form method="post" action="<?= e(BASE_URL) ?>/admin/delete_club.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="club_id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-delete" type="submit" onclick="return confirm('Delete club <?= addslashes($c['name']) ?>? This is irreversible.')">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
