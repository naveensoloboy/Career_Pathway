<?php
// attendee/dashboard.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user = current_user($pdo);
$roll = $user['roll_no'] ?? $_SESSION['user_roll'];

// fetch recent attempts (last 10)
$stmt = $pdo->prepare("
    SELECT a.id, a.test_id, a.score, a.total_marks, a.submitted_at, t.test_type, t.test_date, COALESCE(c.name, 'Global') AS club_name
    FROM attempts a
    LEFT JOIN tests t ON t.id = a.test_id
    LEFT JOIN clubs c ON c.id = t.club_id
    WHERE a.user_roll = :r
    ORDER BY a.submitted_at DESC
    LIMIT 10
");
$stmt->execute([':r' => $roll]);
$attempts = $stmt->fetchAll();

// fetch clubs where this user has a role
$clubRolesStmt = $pdo->prepare("
    SELECT cr.club_id, cr.role, cr.can_post_questions, c.name AS club_name
    FROM club_roles cr
    JOIN clubs c ON c.id = cr.club_id
    WHERE cr.user_roll = :r
    ORDER BY c.name
");
$clubRolesStmt->execute([':r' => $roll]);
$clubRoles = $clubRolesStmt->fetchAll();

// if admin, also fetch all clubs for admin controls
$adminAllClubs = [];
if (is_admin($pdo)) {
    $cstmt = $pdo->query("SELECT id, name FROM clubs ORDER BY name");
    $adminAllClubs = $cstmt->fetchAll();
}

// helper to escape
function esc($v) { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// ---------------------
// Admin overall grouped report (aggregated sums) - PHP-side aggregation
// ---------------------

$showOverall = false;
$dailyGrouped = $weeklyGrouped = [];

if (is_admin($pdo) && (isset($_GET['overall']) && $_GET['overall'] == '1')) {
    $showOverall = true;

    // Step A: try to read detailed rows from attempts_tests (preferred)
    $sql = "
        SELECT
            at.attempt_id,
            a.user_roll,
            u.full_name,
            u.class,
            t.test_type,
            t.test_date,
            at.test_id AS mapped_test_id,
            at.score,
            at.total_marks
        FROM attempts_tests at
        JOIN attempts a ON a.id = at.attempt_id
        JOIN users u ON u.roll_no = a.user_roll
        JOIN tests t ON t.id = at.test_id
        ORDER BY t.test_date DESC, t.test_type, u.full_name
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // If no rows in attempts_tests, fallback to attempts (legacy)
    if (empty($rows)) {
        $sql2 = "
            SELECT
                a.id AS attempt_id,
                a.user_roll,
                u.full_name,
                u.class,
                t.test_type,
                t.test_date,
                t.id AS mapped_test_id,
                a.score,
                a.total_marks
            FROM attempts a
            JOIN users u ON u.roll_no = a.user_roll
            JOIN tests t ON t.id = a.test_id
            ORDER BY t.test_date DESC, t.test_type, u.full_name
        ";
        $rows = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC);
    }

    // Programmatic aggregation:
    // dailyGrouped keyed by test_date => items list (each item aggregated by roll)
    // weeklyGrouped keyed by "test_type|test_date" => items list (each item aggregated by roll)
    $dailyTmp = [];   // [date][roll] => ['roll_no','full_name','class','score_sum','total_sum']
    $weeklyTmp = [];  // [type|date][roll] => same structure

    foreach ($rows as $r) {
        $roll = (string)$r['user_roll'];
        $name = $r['full_name'] ?? '';
        $cls  = $r['class'] ?? '';
        $type = $r['test_type'] ?? 'daily';
        $date = $r['test_date'] ?? '';

        $score = isset($r['score']) ? (int)$r['score'] : 0;
        $total = isset($r['total_marks']) ? (int)$r['total_marks'] : 0;

        // DAILY grouping (only for type === 'daily')
        if ($type === 'daily') {
            if (!isset($dailyTmp[$date])) $dailyTmp[$date] = [];
            if (!isset($dailyTmp[$date][$roll])) {
                $dailyTmp[$date][$roll] = [
                    'roll_no' => $roll,
                    'full_name' => $name,
                    'class' => $cls,
                    'score_sum' => 0,
                    'total_sum' => 0
                ];
            }
            $dailyTmp[$date][$roll]['score_sum'] += $score;
            $dailyTmp[$date][$roll]['total_sum'] += $total;
        } else {
            // WEEKLY/OTHER grouping by type + date
            $key = $type . '|' . $date;
            if (!isset($weeklyTmp[$key])) $weeklyTmp[$key] = [];
            if (!isset($weeklyTmp[$key][$roll])) {
                $weeklyTmp[$key][$roll] = [
                    'roll_no' => $roll,
                    'full_name' => $name,
                    'class' => $cls,
                    'score_sum' => 0,
                    'total_sum' => 0
                ];
            }
            $weeklyTmp[$key][$roll]['score_sum'] += $score;
            $weeklyTmp[$key][$roll]['total_sum'] += $total;
        }
    }

    // Convert tmp arrays into the shape used by the renderer ($dailyGrouped / $weeklyGrouped)
    foreach ($dailyTmp as $date => $byRoll) {
        $items = array_values($byRoll); // list of rows
        usort($items, function($a, $b){ return strcasecmp($a['full_name'], $b['full_name']); });
        $dailyGrouped[$date] = ['label' => $date, 'items' => $items];
    }

    foreach ($weeklyTmp as $key => $byRoll) {
        $parts = explode('|', $key, 2);
        $typeLabel = ucfirst($parts[0] ?? 'weekly');
        $dateLabel = $parts[1] ?? '';
        $label = $typeLabel . ' • ' . $dateLabel;

        $items = array_values($byRoll);
        usort($items, function($a, $b){ return strcasecmp($a['full_name'], $b['full_name']); });
        $weeklyGrouped[$key] = ['label' => $label, 'items' => $items];
    }

    // sort groups by date desc
    uksort($dailyGrouped, function($a, $b){ return strcmp($b, $a); });
    uksort($weeklyGrouped, function($ka, $kb){
        $da = explode('|', $ka, 2)[1] ?? '';
        $db = explode('|', $kb, 2)[1] ?? '';
        return strcmp($db, $da);
    });
}
// ---------------------
// end aggregation block


?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dashboard — Attendee</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <link rel="stylesheet" href="/public/css/dashboard.css">
    <style>
        /* small enhancements for club action cards */
        .club-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .club-card { background:#fff; border:1px solid #e6eefb; border-radius:12px; padding:14px; box-shadow:0 6px 18px rgba(37,99,235,0.04); }
        .club-card h4 { margin:0 0 8px 0; font-size:1.05rem; color:#0f172a; }
        .club-meta { color:#64748b; font-size:0.9rem; margin-bottom:12px; }
        .club-actions-row { display:flex; gap:8px; flex-wrap:wrap; }
        .btn-sm { padding:0.5rem 0.75rem; border-radius:8px; font-weight:600; text-decoration:none; display:inline-block; }
        .btn-primary { background:linear-gradient(90deg,#2563eb,#1d4ed8); color:#fff; }
        .btn-ghost { background:transparent; border:1px solid #c7defb; color:#2563eb; }
        .btn-warning { background:#f59e0b; color:#fff; }
        .btn-danger { background:#ef4444; color:#fff; }
        .admin-badge { display:inline-block; font-size:12px; padding:4px 8px; background:#eef2ff; color:#1e3a8a; border-radius:6px; margin-left:8px; }
        @media(max-width:640px){ .club-actions { grid-template-columns:1fr; } }
        .section-heading { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }

        /* Overall report layout */
        .overall-wrap { display:flex; gap:16px; margin-top:12px; flex-wrap:wrap; }
        .panel { flex:1 1 360px; background:#fff; border:1px solid #eef2ff; border-radius:8px; padding:12px; box-shadow:0 6px 18px rgba(37,99,235,0.04); min-width:280px; }
        .panel h4 { margin:0 0 8px 0; font-size:1.05rem; }
        .table-small { width:100%; border-collapse:collapse; font-size:13px; }
        .table-small th, .table-small td { padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:left; }
        .table-small thead th { font-weight:700; color:#475569; font-size:12px; text-transform:uppercase; }
        .muted { color:#64748b; font-size:0.95rem; }
        .pill { display:inline-block; background:#f1f5f9; padding:6px 10px; border-radius:999px; font-weight:700; color:#0f172a; }
        .small-note { font-size:12px; color:#64748b; margin-top:8px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <h2>Welcome, <?= esc($user['full_name'] ?? $roll) ?></h2>
        <div style="display:flex; gap:8px; align-items:center;">
            <?php if (is_admin($pdo)): ?>
                <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/admin/dashboard.php">Admin Dashboard</a>  
            <?php endif; ?>

            <!-- && (!is_admin($pdo)) -->
             
            <?php if (!empty($clubRoles) ): ?>  
                <?php $firstClubId = (int)$clubRoles[0]['club_id']; ?>
                <a class="btn btn-primary"
                   href="<?= e(BASE_URL) ?>/club/dashboard.php?club_id=<?= $firstClubId ?>">
                    Club Dashboard
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="cards" style="margin-top:1rem;">
        <div class="card">
            <h3>Daily Test</h3>
            <p>Pick a date up to today.</p>
            <a href="<?= e(BASE_URL) ?>/attendee/daily_test.php">Open</a>
        </div>

        <div class="card">
            <h3>Weekly Test</h3>
            <p>Pick a Saturday (up to last Saturday).</p>
            <a href="<?= e(BASE_URL) ?>/attendee/weekly_test.php">Open</a>
        </div>
    </div>

    <hr style="margin:1.5rem 0; border:0; border-bottom:1px solid #e6eefb;">

    <h3>Your recent attempts</h3>
    <br>

    <?php if (empty($attempts)): ?>
        <p>No attempts yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto; background:#fff; border:1px solid #eef2ff; border-radius:8px; padding:8px;">
            <table style="width:100%; border-collapse:collapse; min-width:640px;">
                <thead>
                    <tr style="text-align:left; color:#64748b; font-weight:700;">
                        <th style="padding:10px;">Type</th>
                        <th style="padding:10px;">Date</th>
                        <th style="padding:10px;">Score</th>
                        <th style="padding:10px;">When</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attempts as $a): ?>
                        <tr>
                            <td style="padding:10px;"><?= esc($a['test_type'] ?? '') ?></td>
                            <td style="padding:10px;"><?= esc($a['test_date'] ?? '') ?></td>
                            <td style="padding:10px;">
                                <strong style="color:#2563eb;"><?= (int)$a['score'] ?></strong>
                                <span style="color:#64748b;">/ <?= (int)$a['total_marks'] ?></span>
                            </td>
                            <td style="padding:10px;"><?= esc($a['submitted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <br><br>

    <!-- <div class="section-heading" style="margin-bottom:0.75rem;">
        <h3 style="margin:0;">Your Club Actions</h3>
        <div style="color:#64748b; font-size:0.95rem;">Quick access to tasks for clubs you belong to</div>
    </div><BR>

    <?php if (empty($clubRoles)): ?>
        <p style="color:#64748b;">You are not a member of any club yet. Join a club to see club actions here.</p>
    <?php else: ?>
        <div class="club-actions" role="list">
            <?php foreach ($clubRoles as $cr):
                $clubId = (int)$cr['club_id'];
                $roleName = $cr['role'];
                $canPost = (int)$cr['can_post_questions'] === 1;
            ?>
                <div class="club-card" role="listitem">
                    <h4><?= esc($cr['club_name']) ?> <small style="color:#64748b; font-size:0.9rem;">(<?= esc($roleName) ?>)</small></h4>
                    <div class="club-meta"><?= $canPost ? 'Posting enabled' : 'Posting disabled' ?></div>

                    <div class="club-actions-row">
                        <?php if (in_array($roleName, ['club_secretary','club_joint_secretary'], true)): ?>
                            <a class="btn-sm btn-primary" href="<?= e(BASE_URL) ?>/club/results.php?club_id=<?= $clubId ?>">View Club Results</a>
                            <a class="btn-sm btn-ghost" href="<?= e(BASE_URL) ?>/club/create_test.php?club_id=<?= $clubId ?>">Create Test</a>
                            <a class="btn-sm btn-ghost" href="<?= e(BASE_URL) ?>/club/add_questions.php?club_id=<?= $clubId ?>">Add Questions</a>
                            <a class="btn-sm btn-ghost" href="<?= e(BASE_URL) ?>/club/test_status.php?club_id=<?= $clubId ?>">Test Status</a>
                        <?php elseif ($roleName === 'club_member'): ?>
                            <?php if ($canPost): ?>
                                <a class="btn-sm btn-primary" href="<?= e(BASE_URL) ?>/club/add_questions.php?club_id=<?= $clubId ?>">Add Questions</a>
                            <?php else: ?>
                                <a class="btn-sm btn-warning" href="<?= e(BASE_URL) ?>/admin/manage_roles.php">Request Posting Rights</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="btn-sm btn-ghost" href="<?= e(BASE_URL) ?>/club/test_status.php?club_id=<?= $clubId ?>">View Status</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?> -->

    <br>

    

 

    

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
