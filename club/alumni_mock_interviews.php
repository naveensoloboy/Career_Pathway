<?php
// club/alumni_mock_interviews.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'] ?? null;
$club_id   = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;

if ($club_id <= 0) {
    die('Club not specified.');
}

// Load club
$stmtClub = $pdo->prepare("SELECT * FROM clubs WHERE id = :id LIMIT 1");
$stmtClub->execute([':id' => $club_id]);
$clubRow = $stmtClub->fetch();
if (!$clubRow) {
    die('Club not found.');
}

// Ensure this is Alumni club
$clubNameRaw  = trim($clubRow['name'] ?? '');
$isAlumniClub = in_array(strtolower($clubNameRaw), ['alumni', 'alumini'], true);
if (!$isAlumniClub) {
    http_response_code(403);
    echo "This page is only for the Alumni club.";
    exit;
}

// Get user's club role
$roleInfo = get_club_role($pdo, $user_roll, $club_id);
if (!$roleInfo) {
    http_response_code(403);
    echo "Access denied. You are not a member of this club.";
    exit;
}

function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Only secretary / joint secretary can create & update
$canManage = in_array($roleInfo['role'], ['club_secretary', 'club_joint_secretary'], true);

$errors = [];
$success = null;

// Fetch active alumni list for dropdown
$alumniStmt = $pdo->prepare("
    SELECT id, name, batch, current_role
    FROM alumni_contacts
    WHERE club_id = :cid ORDER BY name
");
$alumniStmt->execute([':cid' => $club_id]);
$alumniList = $alumniStmt->fetchAll();

// Handle create mock interview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create' && $canManage) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $alumni_id     = isset($_POST['alumni_id']) && $_POST['alumni_id'] !== '' ? (int)$_POST['alumni_id'] : null;
        $student_roll  = trim($_POST['student_roll'] ?? '');
        $student_name  = trim($_POST['student_name'] ?? '');
        $domain        = trim($_POST['domain'] ?? '');
        $mode          = trim($_POST['mode'] ?? 'offline');
        $scheduled_date= trim($_POST['scheduled_date'] ?? '');
        $scheduled_time= trim($_POST['scheduled_time'] ?? '');

        if ($student_roll === '' || $scheduled_date === '') {
            $errors[] = 'Student roll number and date are required.';
        }

        $allowedModes = ['online','offline','hybrid'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'offline';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO alumni_mock_interviews
                        (club_id, alumni_id, student_roll, student_name, domain, mode,
                         scheduled_date, scheduled_time, created_by)
                    VALUES
                        (:cid, :aid, :sroll, :sname, :domain, :mode, :sdate, :stime, :creator)
                ");
                $stmt->execute([
                    ':cid'    => $club_id,
                    ':aid'    => $alumni_id,
                    ':sroll'  => $student_roll,
                    ':sname'  => $student_name !== '' ? $student_name : null,
                    ':domain' => $domain !== '' ? $domain : null,
                    ':mode'   => $mode,
                    ':sdate'  => $scheduled_date,
                    ':stime'  => $scheduled_time !== '' ? $scheduled_time : null,
                    ':creator'=> $user_roll,
                ]);
                $success = 'Mock interview scheduled successfully.';
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle update status / feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update' && $canManage) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $mid      = (int)($_POST['mock_id'] ?? 0);
        $status   = trim($_POST['status'] ?? 'scheduled');
        $feedback = trim($_POST['feedback'] ?? '');
        $rating   = trim($_POST['rating'] ?? '');

        $allowedStatus = ['scheduled','completed','cancelled'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'scheduled';
        }

        $ratingVal = null;
        if ($rating !== '' && is_numeric($rating)) {
            $ratingVal = max(1, min(5, (int)$rating)); // clamp 1-5
        }

        if ($mid <= 0) {
            $errors[] = 'Invalid mock interview id.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE alumni_mock_interviews
                    SET status = :st,
                        feedback = :fb,
                        rating = :rt
                    WHERE id = :id AND club_id = :cid
                ");
                $stmt->execute([
                    ':st'  => $status,
                    ':fb'  => $feedback !== '' ? $feedback : null,
                    ':rt'  => $ratingVal,
                    ':id'  => $mid,
                    ':cid' => $club_id,
                ]);
                $success = 'Mock interview updated.';
            } catch (Exception $e) {
                $errors[] = 'Update error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch upcoming & recent completed
$today = date('Y-m-d');

$upStmt = $pdo->prepare("
    SELECT m.*, a.name AS alumni_name, a.current_role AS role
    FROM alumni_mock_interviews m
    LEFT JOIN alumni_contacts a ON a.id = m.alumni_id
    WHERE m.club_id = :cid
      AND m.scheduled_date >= :today
    ORDER BY m.scheduled_date ASC, m.scheduled_time ASC
");
$upStmt->execute([':cid' => $club_id, ':today' => $today]);
$upcoming = $upStmt->fetchAll();

$pastStmt = $pdo->prepare("
    SELECT m.*, a.name AS alumni_name, a.current_role AS role
    FROM alumni_mock_interviews m
    LEFT JOIN alumni_contacts a ON a.id = m.alumni_id
    WHERE m.club_id = :cid
      AND m.scheduled_date < :today
    ORDER BY m.scheduled_date DESC, m.scheduled_time DESC
    LIMIT 30
");
$pastStmt->execute([':cid' => $club_id, ':today' => $today]);
$recent = $pastStmt->fetchAll();

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= esc($clubRow['name']) ?> — Mock Interviews</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap {
            max-width: 1150px;
            margin: 20px auto;
        }
        .muted {
            color:#6b7280;
            font-size:0.9rem;
        }
        .grid-2 {
            display:grid;
            grid-template-columns: 1fr;
            gap:18px;
            align-items:flex-start;
        }
        @media (max-width: 900px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
        .card {
            background:#fff;
            border-radius:10px;
            border:1px solid #e5e7eb;
            padding:14px 16px;
            box-shadow:0 6px 18px rgba(15,23,42,0.03);
        }
        .card h3 {
            margin-top:0;
        }
        .errors ul {
            margin:0;
            padding-left:18px;
            color:#b91c1c;
            font-size:0.9rem;
        }
        .success {
            color:#065f46;
            font-size:0.9rem;
            margin-bottom:8px;
        }
        label {
            display:block;
            font-weight:600;
            font-size:0.9rem;
            margin-bottom:4px;
        }
        input[type="text"],
        input[type="date"],
        input[type="time"],
        select,
        textarea {
            width:100%;
            box-sizing:border-box;
            padding:8px 10px;
            border-radius:8px;
            border:1px solid #e5e7eb;
            font-size:0.9rem;
            margin-bottom:8px;
        }
        textarea {
            min-height:60px;
            resize:vertical;
        }
        .btn {
            padding:9px 13px;
            border-radius:8px;
            border:0;
            cursor:pointer;
            font-weight:600;
            font-size:0.9rem;
        }
        .btn-primary {
            background:linear-gradient(90deg,#2563eb,#1d4ed8);
            color:#fff;
        }
        .btn-ghost {
            background:transparent;
            border:1px solid #cbd5f5;
            color:#2563eb;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }
        .mock-list {
            margin-top:10px;
        }
        .mock {
            border-bottom:1px solid #e5e7eb;
            padding:8px 0;
        }
        .mock:last-child {
            border-bottom:none;
        }
        .mock-title {
            font-weight:600;
            font-size:0.95rem;
        }
        .small {
            font-size:0.8rem;
            color:#6b7280;
        }
        .badge {
            display:inline-block;
            padding:3px 7px;
            border-radius:999px;
            font-size:0.75rem;
            font-weight:600;
            margin-right:6px;
        }
        .badge-scheduled { background:#eef2ff; color:#4338ca; }
        .badge-completed { background:#dcfce7; color:#166534; }
        .badge-cancelled { background:#fee2e2; color:#b91c1c; }
        .badge-mode { background:#f1f5f9; color:#0f172a; }
        .update-box {
            margin-top:6px;
            padding:6px 8px;
            border-radius:8px;
            background:#f9fafb;
        }
        .rating-input {
            width:70px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div class="wrap">
        <h2><?= esc($clubRow['name']) ?> Club — Mock Interviews</h2>
        <p class="muted">
            Schedule and track alumni-led mock interviews to help students prepare for placements and higher studies.
        </p>

        <div class="grid-2">
            <section class="card">
                <h3>Upcoming Mock Interviews</h3>
                <?php if (empty($upcoming)): ?>
                    <p class="muted">No upcoming mock interviews scheduled.</p>
                <?php else: ?>
                    <div class="mock-list">
                        <?php foreach ($upcoming as $m): ?>
                            <div class="mock">
                                <div class="mock-title">
                                    Student: <?= esc($m['student_name'] ?: $m['student_roll']) ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-scheduled">Scheduled</span>
                                    <?php
                                        $dtLabel = esc($m['scheduled_date']);
                                        if (!empty($m['scheduled_time'])) {
                                            $dtLabel .= ' • ' . esc(substr($m['scheduled_time'], 0, 5));
                                        }
                                        echo $dtLabel;
                                    ?>
                                    <?php if ($m['domain']): ?>
                                        &nbsp; | Domain: <?= esc($m['domain']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small">
                                    <?php if ($m['alumni_name']): ?>
                                        Alumni: <?= esc($m['alumni_name']) ?>
                                        <?php if ($m['alumni_company']): ?>
                                            (<?= esc($m['alumni_company']) ?>)
                                        <?php endif; ?>
                                        <?php if ($m['alumni_domain']): ?>
                                            — <?= esc($m['alumni_domain']) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Alumni: <em>Not linked</em>
                                    <?php endif; ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-mode"><?= esc(ucfirst($m['mode'])) ?></span>
                                </div>

                                <?php if ($canManage): ?>
                                    <div class="update-box">
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="mock_id" value="<?= (int)$m['id'] ?>">

                                            <label>Status</label>
                                            <select name="status">
                                                <option value="scheduled" <?= $m['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                                <option value="completed" <?= $m['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="cancelled" <?= $m['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            </select>

                                            <label>Feedback (optional)</label>
                                            <textarea name="feedback" placeholder="Notes, strengths, areas to improve"><?= esc($m['feedback'] ?? '') ?></textarea>

                                            <label>Rating (1–5)</label>
                                            <input type="number" class="rating-input" name="rating" min="1" max="5"
                                                   value="<?= esc($m['rating'] ?? '') ?>">

                                            <button type="submit" class="btn btn-primary" style="margin-top:4px;">Update</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h3 style="margin-top:18px;">Recent Completed / Cancelled</h3>
                <?php if (empty($recent)): ?>
                    <p class="muted">No recent records.</p>
                <?php else: ?>
                    <div class="mock-list" style="max-height:260px; overflow:auto;">
                        <?php foreach ($recent as $m): ?>
                            <div class="mock">
                                <div class="mock-title">
                                    Student: <?= esc($m['student_name'] ?: $m['student_roll']) ?>
                                </div>
                                <div class="small">
                                    <?php
                                        $badgeClass = $m['status'] === 'completed' ? 'badge-completed'
                                                     : ($m['status'] === 'cancelled' ? 'badge-cancelled' : 'badge-scheduled');
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= esc(ucfirst($m['status'])) ?></span>
                                    <?= esc($m['scheduled_date']) ?>
                                    <?php if (!empty($m['scheduled_time'])): ?>
                                        • <?= esc(substr($m['scheduled_time'], 0, 5)) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small">
                                    <?php if ($m['alumni_name']): ?>
                                        Alumni: <?= esc($m['alumni_name']) ?>
                                        <?php if ($m['alumni_company']): ?>
                                            (<?= esc($m['alumni_company']) ?>)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($m['rating']): ?>
                                        &nbsp; | Rating: <?= (int)$m['rating'] ?>/5
                                    <?php endif; ?>
                                </div>
                                <?php if ($m['feedback']): ?>
                                    <div class="small" style="margin-top:4px;">
                                        <?= nl2br(esc($m['feedback'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Schedule Mock Interview</h3>
                <?php if (!$canManage): ?>
                    <p class="muted">
                        Only club secretary / joint secretary can schedule or update mock interviews.
                    </p>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <div class="errors">
                            <ul>
                                <?php foreach ($errors as $e): ?>
                                    <li><?= esc($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="success"><?= esc($success) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                        <input type="hidden" name="action" value="create">

                        <label for="alumni_id">Alumni (optional)</label>
                        <select id="alumni_id" name="alumni_id">
                            <option value="">Select alumni</option>
                            <?php foreach ($alumniList as $al): ?>
                                <option value="<?= (int)$al['id'] ?>"
                                    <?= (isset($_POST['alumni_id']) && (int)$_POST['alumni_id'] === (int)$al['id']) ? 'selected' : '' ?>>
                                    <?= esc($al['name']) ?>
                                    <?php if ($al['batch']): ?>
                                        (<?= esc($al['batch']) ?>)
                                    <?php endif; ?>
                                    <?php if ($al['current_role']): ?>
                                        — <?= esc($al['current_role']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="student_roll">Student Roll Number *</label>
                        <input type="text" id="student_roll" name="student_roll"
                               value="<?= esc($_POST['student_roll'] ?? '') ?>">

                        <label for="student_name">Student Name</label>
                        <input type="text" id="student_name" name="student_name"
                               value="<?= esc($_POST['student_name'] ?? '') ?>">

                        <label for="domain">Domain / Role</label>
                        <input type="text" id="domain" name="domain"
                               placeholder="e.g. Full Stack, Data Science, Testing"
                               value="<?= esc($_POST['domain'] ?? '') ?>">

                        <label for="scheduled_date">Date *</label>
                        <input type="date" id="scheduled_date" name="scheduled_date"
                               value="<?= esc($_POST['scheduled_date'] ?? '') ?>">

                        <label for="scheduled_time">Time</label>
                        <input type="time" id="scheduled_time" name="scheduled_time"
                               value="<?= esc($_POST['scheduled_time'] ?? '') ?>">

                        <label for="mode">Mode</label>
                        <?php $currMode = $_POST['mode'] ?? 'offline'; ?>
                        <select id="mode" name="mode">
                            <option value="offline" <?= $currMode === 'offline' ? 'selected' : '' ?>>Offline</option>
                            <option value="online"  <?= $currMode === 'online'  ? 'selected' : '' ?>>Online</option>
                            <option value="hybrid"  <?= $currMode === 'hybrid'  ? 'selected' : '' ?>>Hybrid</option>
                        </select>

                        <button type="submit" class="btn btn-primary">Schedule</button>
                    </form>
                <?php endif; ?>

                <div style="margin-top:14px;">
                    <a href="<?= esc(BASE_URL) ?>/club/dashboard.php?club_id=<?= (int)$club_id ?>"
                       class="btn btn-ghost">Back to Club Dashboard</a>
                </div>
            </section>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
