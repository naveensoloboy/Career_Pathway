<?php

// club/test_status.php
require_once __DIR__ . '/../config/config.php';
require_login();

$club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : 0;
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

if ($club_id <= 0) die('Club not specified.');

// ensure user is club member
$role = get_club_role($pdo, $_SESSION['user_roll'], $club_id);
if (!$role && !is_admin($pdo)) die('Access denied.');

// Shared CSS Styles for both views
$custom_css = "
<style>
    :root {
        --primary-color: #2563eb;
        --primary-hover: #1d4ed8;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
    }

    * { box-sizing: border-box; }

    body {
        font-family: system-ui, -apple-system, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-main);
        line-height: 1.6;
        margin: 0;
        padding: 0;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem 1rem;
    }

    h2 {
        font-size: 1.8rem;
        margin-bottom: 1.5rem;
        color: var(--text-main);
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0.5rem;
    }

    /* --- List View (Cards) --- */
    .test-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        list-style: none;
        padding: 0;
    }

    .test-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .test-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .test-info {
        margin-bottom: 1rem;
    }

    .test-title {
        font-weight: 700;
        font-size: 1.1rem;
        display: block;
        margin-bottom: 0.5rem;
    }

    .test-meta {
        font-size: 0.9rem;
        color: var(--text-muted);
        background: #f1f5f9;
        padding: 4px 8px;
        border-radius: 4px;
        display: inline-block;
    }

    /* --- Table View --- */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        background: var(--card-bg);
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-color);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px; /* Forces scroll on small screens */
    }

    th, td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    th {
        background-color: #f8fafc;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.05em;
    }

    tr:last-child td { border-bottom: none; }
    tr:hover td { background-color: #f8fafc; }

    /* --- Buttons and Links --- */
    .btn {
        display: inline-block;
        background-color: var(--primary-color);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 500;
        font-size: 0.9rem;
        transition: background-color 0.2s;
        text-align: center;
    }

    .btn:hover { background-color: var(--primary-hover); }
    
    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--primary-color);
        color: var(--primary-color);
        width: 100%; /* Full width on card */
    }
    
    .btn-outline:hover { background-color: #eff6ff; }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: var(--text-muted);
        background: var(--card-bg);
        border-radius: 8px;
    }
    
    .action-bar { margin-top: 1.5rem; }

    /* small helper for test header */
    .test-header {
        display:flex;
        gap:12px;
        align-items:baseline;
        flex-wrap:wrap;
    }
    .test-header .label {
        font-size: 0.95rem;
        color: var(--text-muted);
        background:#f1f5f9;
        padding:6px 10px;
        border-radius:8px;
    }
</style>
";

// --------------------------------------------------------------------------
// SCENARIO 1: List of Tests (No test_id provided)
// --------------------------------------------------------------------------

if ($test_id <= 0) {
    $tests = $pdo->prepare('SELECT id, title, test_type, test_date FROM tests WHERE club_id = :cid ORDER BY test_date DESC LIMIT 20');
    $tests->execute([':cid' => $club_id]);
    $testsList = $tests->fetchAll();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Tests — Status</title>
        <link rel="stylesheet" href="/public/css/main.css">
        <link rel="stylesheet" href="/public/css/header.css">
        <link rel="stylesheet" href="/public/css/footer.css">
        <?= $custom_css ?>
    </head>
    <body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <main class="container">
        <h2>Tests for Club</h2>
        
        <?php if (empty($testsList)): ?>
            <div class="empty-state">
                <p>No tests found for this club.</p>
            </div>
        <?php else: ?>
            <div class="test-grid">
                <?php foreach ($testsList as $t): ?>
                    <div class="test-card">
                        <div class="test-info">
                            <span class="test-title"><?= e($t['title']) ?></span>
                            <div class="test-meta">
                                <?= e($t['test_type']) ?> • <?= e($t['test_date']) ?>
                            </div>
                        </div>
                        <a href="?club_id=<?= (int)$club_id ?>&test_id=<?= (int)$t['id'] ?>" class="btn btn-outline">
                            View Status
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

// --------------------------------------------------------------------------
// SCENARIO 2: Specific Test Status (test_id provided)
// --------------------------------------------------------------------------

// Fetch test meta (title, type, date) for header
$testStmt = $pdo->prepare('SELECT id, title, test_type, test_date FROM tests WHERE id = :id AND club_id = :cid LIMIT 1');
$testStmt->execute([':id' => $test_id, ':cid' => $club_id]);
$testInfo = $testStmt->fetch();

// If test not found for this club, still proceed but show fallback message
if (!$testInfo) {
    $displayTestTitle = 'Test #' . (int)$test_id;
    $displayTestType = '';
    $displayTestDate = '';
} else {
    $displayTestTitle = $testInfo['title'];
    $displayTestType = ucfirst($testInfo['test_type']);
    $displayTestDate = $testInfo['test_date'];
}

// fetch attempts for this test (using attempts_tests mapping, fallback to attempts.test_id)
$stm = $pdo->prepare('
    SELECT DISTINCT 
        a.id AS attempt_id,
        a.user_roll,
        COALESCE(at.score, a.score) AS score,
        COALESCE(at.total_marks, a.total_marks) AS total_marks,
        COALESCE(at.submitted_at, a.submitted_at) AS submitted_at,
        u.full_name,
        u.class
    FROM attempts a
    LEFT JOIN attempts_tests at ON at.attempt_id = a.id
    JOIN users u ON u.roll_no = a.user_roll
    WHERE (at.test_id = :tid)
    ORDER BY COALESCE(at.submitted_at, a.submitted_at) ASC
');
$stm->execute([':tid' => $test_id]);
$attendees = $stm->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Status</title>
    <link rel="stylesheet" href="/public/css/main.css">
        <link rel="stylesheet" href="/public/css/header.css">
        <link rel="stylesheet" href="/public/css/footer.css">
    <?= $custom_css ?>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 style="border:none; margin:0;"><?= e($displayTestTitle) ?></h2>
            <div class="test-header" style="margin-top:6px;">
                <?php if ($displayTestType !== ''): ?>
                    <div class="label"><?= e($displayTestType) ?></div>
                <?php endif; ?>
                <?php if ($displayTestDate !== ''): ?>
                    <div class="label"><?= e($displayTestDate) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <a href="?club_id=<?= (int)$club_id ?>" style="color: var(--text-muted); text-decoration: none;">&larr; Back to Tests</a>
    </div>
    <hr style="border: 0; border-bottom: 2px solid var(--border-color); margin-bottom: 1.5rem;">

    <?php if (empty($attendees)): ?>
        <div class="empty-state">
            <p>No attendees yet.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Roll No</th>
                        <th>Name</th>
                        <th>Class</th>
                        
                        <th>Score</th>
                        <th>Submitted At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendees as $r): ?>
                        <tr>
                            <td><?= e($r['user_roll']) ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e($r['class']) ?></td>
                            <td>
                                <span style="font-weight: bold; color: var(--primary-color);">
                                    <?= (int)$r['score'] ?>
                                </span> 
                                <span style="color: var(--text-muted); font-size: 0.9em;">
                                    / <?= (int)$r['total_marks'] ?>
                                </span>
                            </td>
                            <td><?= e($r['submitted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="action-bar">
            <?php if ($role && in_array($role['role'], ['club_secretary'], true)): ?>
                <a href="<?= e(BASE_URL) ?>/club/results.php?club_id=<?= (int)$club_id ?>&test_id=<?= (int)$test_id ?>" class="btn">
                    Download Report
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
