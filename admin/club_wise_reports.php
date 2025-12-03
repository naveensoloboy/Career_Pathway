<?php
// admin/club_wise_reports.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

// fetch all clubs for admin controls
$adminAllClubs = $pdo->query("SELECT id, name FROM clubs ORDER BY name")->fetchAll();

// small helper if you want it
function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Club-wise Reports</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .club-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
            margin-top: 12px;
            margin-bottom: 1.5rem;
        }
        .club-card {
            background:#fff;
            border:1px solid #e6eefb;
            border-radius:12px;
            padding:14px;
            box-shadow:0 6px 18px rgba(37,99,235,0.04);
        }
        .club-card h4 {
            margin:0 0 8px 0;
            font-size:1.05rem;
            color:#0f172a;
        }
        .admin-badge {
            display:inline-block;
            font-size:12px;
            padding:4px 8px;
            background:#eef2ff;
            color:#1e3a8a;
            border-radius:6px;
            margin-left:8px;
        }
        .club-actions-row {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }
        .btn-sm {
            padding:0.5rem 0.75rem;
            border-radius:8px;
            font-weight:600;
            text-decoration:none;
            display:inline-block;
        }
        .btn-primary {
            background:linear-gradient(90deg,#2563eb,#1d4ed8);
            color:#fff;
        }
        .muted {
            color:#64748b;
            font-size:0.95rem;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2>Club-wise Reports</h2>
    <p class="muted">Choose a club to view its test results and performance.</p>

    <?php if (is_admin($pdo) && !empty($adminAllClubs)): ?>
        <hr style="margin:1.25rem 0; border:0; border-bottom:1px solid #e6eefb;">
        <!-- <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <h3 style="margin:0;">Admin — All Clubs</h3>
            <div style="color:#64748b; font-size:0.95rem;">Admin-level controls for each club</div>
        </div> -->

        <br>

        <div class="club-actions" role="list" style="margin-top:12px;">
            <?php foreach ($adminAllClubs as $ac):
                $clubId = (int)$ac['id'];
            ?>
                <div class="club-card" role="listitem">
                    <h4><?= esc($ac['name']) ?> <span class="admin-badge">admin</span></h4>

                    <br><br>

                    <div class="club-actions-row">
                        <!-- Admin-level club actions -->
                        <a class="btn-sm btn-primary" href="<?= e(BASE_URL) ?>/club/results.php?club_id=<?= $clubId ?>">
                            View Results
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">No clubs found.</p>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
