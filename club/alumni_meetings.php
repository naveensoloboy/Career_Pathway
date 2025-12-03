<?php
// club/alumni_meetings.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/phpmailer_config.php';
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

// Check it is alumni club
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

// Only secretary / joint secretary can create meetings
$canCreate  = in_array($roleInfo['role'], ['club_secretary', 'club_joint_secretary'], true);
// Only secretary can cancel meetings
$canCancel  = ($roleInfo['role'] === 'club_secretary');

$errors  = [];
$success = null;
$today   = date('Y-m-d'); // used for queries + cancel check

// Handle POST (create meeting / cancel meeting)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {

        /**
         * CANCEL MEETING + SEND CANCELLATION MAIL
         */
        if (isset($_POST['cancel_meeting']) && $canCancel) {
            $meetingId = (int)($_POST['meeting_id'] ?? 0);

            if ($meetingId <= 0) {
                $errors[] = 'Invalid meeting id.';
            } else {
                try {
                    // Get meeting details first (and ensure it's upcoming + same club)
                    $stmtMeet = $pdo->prepare("
                        SELECT *
                        FROM alumni_meetings
                        WHERE id = :id
                          AND club_id = :cid
                          AND meeting_date >= :today
                        LIMIT 1
                    ");
                    $stmtMeet->execute([
                        ':id'    => $meetingId,
                        ':cid'   => $club_id,
                        ':today' => $today
                    ]);
                    $meetingRow = $stmtMeet->fetch();

                    if (!$meetingRow) {
                        $errors[] = 'Unable to cancel this meeting. It may already be past or not belong to this club.';
                    } else {
                        // Delete the meeting
                        $delStmt = $pdo->prepare("
                            DELETE FROM alumni_meetings
                            WHERE id = :id
                              AND club_id = :cid
                              AND meeting_date >= :today
                        ");
                        $delStmt->execute([
                            ':id'    => $meetingId,
                            ':cid'   => $club_id,
                            ':today' => $today
                        ]);

                        if ($delStmt->rowCount() > 0) {
                            $success = 'Meeting cancelled successfully.';

                            // If meeting had a target batch, email those users
                            $targetBatch = $meetingRow['target_batches'] ?? '';
                            if ($targetBatch !== '') {
                                // Fetch all active users in that batch with email
                                $uStmt = $pdo->prepare("
                                    SELECT full_name, email
                                    FROM users
                                    WHERE batch = :batch
                                      AND email IS NOT NULL
                                      AND email <> ''
                                      AND is_active = 1
                                ");
                                $uStmt->execute([':batch' => $targetBatch]);
                                $recipients = $uStmt->fetchAll();

                                $clubName  = $clubRow['name'] ?? 'Alumni Club';
                                $title     = $meetingRow['title'] ?? 'Alumni Meeting';
                                $mDate     = $meetingRow['meeting_date'] ?? '';
                                $mTime     = $meetingRow['meeting_time'] ?? '';
                                $whenLine  = $mDate . ($mTime ? ' at ' . substr($mTime, 0, 5) : '');
                                $modeLabel = ucfirst($meetingRow['mode'] ?? 'offline');

                                foreach ($recipients as $r) {
                                    $toName  = $r['full_name'] ?: 'Student';
                                    $toEmail = $r['email'];

                                    $subject = "Alumni Meeting Cancelled: {$title} ({$clubName})";

                                    $html  = "<p>Hi " . esc($toName) . ",</p>";
                                    $html .= "<p>The following alumni meeting has been <strong>cancelled</strong>:</p>";
                                    $html .= "<p><strong>Title:</strong> " . esc($title) . "<br>";
                                    $html .= "<strong>Date & Time:</strong> " . esc($whenLine) . "<br>";
                                    $html .= "<strong>Mode:</strong> " . esc($modeLabel) . "<br>";
                                    if (!empty($meetingRow['location'])) {
                                        $html .= "<strong>Location / Link:</strong> " . esc($meetingRow['location']) . "<br>";
                                    }
                                    if ($targetBatch !== '') {
                                        $html .= "<strong>Target Batch:</strong> " . esc($targetBatch) . "</p>";
                                    } else {
                                        $html .= "</p>";
                                    }

                                    if (!empty($meetingRow['description'])) {
                                        $html .= "<p><strong>Original Agenda:</strong><br>" . nl2br(esc($meetingRow['description'])) . "</p>";
                                    }

                                    $html .= "<p>We regret any inconvenience caused.<br>";
                                    $html .= "Regards,<br>" . esc($clubName) . " Team</p>";

                                    @sendMail($toEmail, $subject, $html);
                                }
                            }

                        } else {
                            $errors[] = 'Unable to cancel this meeting. It may already be past or not belong to this club.';
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = 'Error cancelling meeting: ' . $e->getMessage();
                }
            }
        }

        /**
         * CREATE MEETING + SEND INVITES
         */
        if (isset($_POST['create_meeting']) && $canCreate && empty($errors)) {
            $title          = trim($_POST['title'] ?? '');
            $meeting_date   = trim($_POST['meeting_date'] ?? '');
            $meeting_time   = trim($_POST['meeting_time'] ?? '');
            $mode           = trim($_POST['mode'] ?? 'offline');
            $location       = trim($_POST['location'] ?? '');
            $description    = trim($_POST['description'] ?? '');
            $target_batches = trim($_POST['target_batches'] ?? '');

            if ($title === '' || $meeting_date === '') {
                $errors[] = 'Title and meeting date are required.';
            }

            $allowedModes = ['online','offline','hybrid'];
            if (!in_array($mode, $allowedModes, true)) {
                $mode = 'offline';
            }

            if ($target_batches === '') {
                $errors[] = 'Target batch is required.';
            }

            if (empty($errors)) {
                try {
                    // 1) Insert meeting
                    $stmt = $pdo->prepare("
                        INSERT INTO alumni_meetings
                            (club_id, title, meeting_date, meeting_time, mode, location, description, target_batches, created_by)
                        VALUES
                            (:cid, :title, :mdate, :mtime, :mode, :loc, :descr, :tb, :creator)
                    ");
                    $stmt->execute([
                        ':cid'     => $club_id,
                        ':title'   => $title,
                        ':mdate'   => $meeting_date,
                        ':mtime'   => $meeting_time !== '' ? $meeting_time : null,
                        ':mode'    => $mode,
                        ':loc'     => $location !== '' ? $location : null,
                        ':descr'   => $description !== '' ? $description : null,
                        ':tb'      => $target_batches !== '' ? $target_batches : null,
                        ':creator' => $user_roll,
                    ]);

                    // 2) Fetch users whose batch matches target batch
                    $uStmt = $pdo->prepare("
                        SELECT full_name, email
                        FROM users
                        WHERE batch = :batch
                          AND email IS NOT NULL
                          AND email <> ''
                          AND is_active = 1
                    ");
                    $uStmt->execute([':batch' => $target_batches]);
                    $recipients = $uStmt->fetchAll();

                    // 3) Prepare email details
                    $clubName   = $clubRow['name'] ?? 'Alumni Club';
                    $niceDate   = $meeting_date;
                    $niceTime   = $meeting_time ? substr($meeting_time, 0, 5) : '';
                    $whenLine   = $niceDate . ($niceTime ? ' at ' . $niceTime : '');
                    $modeLabel  = ucfirst($mode);

                    foreach ($recipients as $r) {
                        $toName  = $r['full_name'] ?: 'Student';
                        $toEmail = $r['email'];

                        $subject = "Alumni Meeting: {$title} ({$clubName})";

                        $html  = "<p>Hi " . esc($toName) . ",</p>";
                        $html .= "<p>You are invited to an alumni interaction arranged by <strong>" . esc($clubName) . "</strong>.</p>";
                        $html .= "<p><strong>Title:</strong> " . esc($title) . "<br>";
                        $html .= "<strong>Date & Time:</strong> " . esc($whenLine) . "<br>";
                        $html .= "<strong>Mode:</strong> " . esc($modeLabel) . "<br>";
                        if ($location !== '') {
                            $html .= "<strong>Location / Link:</strong> " . esc($location) . "<br>";
                        }
                        if ($target_batches !== '') {
                            $html .= "<strong>Target Batch:</strong> " . esc($target_batches) . "</p>";
                        } else {
                            $html .= "</p>";
                        }

                        if ($description !== '') {
                            $html .= "<p><strong>Agenda / Description:</strong><br>" . nl2br(esc($description)) . "</p>";
                        }

                        $html .= "<p>Regards,<br>" . esc($clubName) . " Team</p>";

                        @sendMail($toEmail, $subject, $html);
                    }

                    $success = 'Meeting created successfully. Invitation emails have been sent to the selected batch (if any).';
                } catch (Exception $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch upcoming & past meetings
$upStmt = $pdo->prepare("
    SELECT *
    FROM alumni_meetings
    WHERE club_id = :cid AND meeting_date >= :today
    ORDER BY meeting_date ASC, meeting_time ASC
");
$upStmt->execute([':cid' => $club_id, ':today' => $today]);
$upcoming = $upStmt->fetchAll();

$pastStmt = $pdo->prepare("
    SELECT *
    FROM alumni_meetings
    WHERE club_id = :cid AND meeting_date < :today
    ORDER BY meeting_date DESC, meeting_time DESC
");
$pastStmt->execute([':cid' => $club_id, ':today' => $today]);
$pastMeetings = $pastStmt->fetchAll();

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= esc($clubRow['name']) ?> — Alumni Meetings</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap {
            max-width: 1100px;
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
            min-height:70px;
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
        .btn-danger {
            background:#ef4444;
            color:#fff;
        }
        .meet-list {
            margin-top:10px;
        }
        .meet {
            border-bottom:1px solid #e5e7eb;
            padding:8px 0;
        }
        .meet:last-child {
            border-bottom:none;
        }
        .meet-title {
            font-weight:600;
        }
        .badge {
            display:inline-block;
            padding:3px 7px;
            border-radius:999px;
            font-size:0.75rem;
            font-weight:600;
            margin-right:6px;
        }
        .badge-upcoming { background:#dcfce7; color:#166534; }
        .badge-past { background:#fee2e2; color:#b91c1c; }
        .badge-mode { background:#eff6ff; color:#1d4ed8; }
        .small {
            font-size:0.8rem;
            color:#6b7280;
        }
        .meet-actions {
            margin-top:6px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div class="wrap">
        <h2><?= esc($clubRow['name']) ?> Club — Alumni Meetings</h2>
        <p class="muted">
            Plan and track alumni interactions, networking sessions and mock interviews.
        </p><br><br>

        <?php if (!empty($errors)): ?>
            <div class="card" style="border-color:#fecaca; background:#fee2e2;">
                <ul style="margin:0; padding-left:18px; color:#b91c1c; font-size:0.9rem;">
                    <?php foreach ($errors as $e): ?>
                        <li><?= esc($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div><br>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="card" style="border-color:#bbf7d0; background:#dcfce7; color:#166534; font-size:0.9rem; font-weight:600;">
                <?= esc($success) ?>
            </div><br>
        <?php endif; ?>

        <div class="grid-2">
            <section class="card">
                <h3>Upcoming Meetings</h3>
                <?php if (empty($upcoming)): ?>
                    <p class="muted">No upcoming meetings scheduled.</p>
                <?php else: ?>
                    <div class="meet-list">
                        <?php foreach ($upcoming as $m): ?>
                            <div class="meet">
                                <div class="meet-title">
                                    <?= esc($m['title']) ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-upcoming">Upcoming</span>
                                    <?php
                                        $dtLabel = esc($m['meeting_date']);
                                        if (!empty($m['meeting_time'])) {
                                            $dtLabel .= ' • ' . esc(substr($m['meeting_time'], 0, 5));
                                        }
                                        echo $dtLabel;
                                    ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-mode">
                                        <?= esc(ucfirst($m['mode'])) ?>
                                    </span>
                                    <?php if ($m['location']): ?>
                                        at <?= esc($m['location']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($m['target_batches']): ?>
                                    <div class="small">
                                        Target: <?= esc($m['target_batches']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($m['description']): ?>
                                    <div class="small" style="margin-top:4px;">
                                        <?= nl2br(esc($m['description'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canCancel): ?>
                                    <div class="meet-actions">
                                        <form method="post" style="display:inline-block;"
                                              onsubmit="return confirm('Are you sure you want to cancel this meeting?');">
                                            <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                            <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                                            <button type="submit" name="cancel_meeting" class="btn btn-danger">
                                                Cancel Meeting
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h3 style="margin-top:18px;">Past Meetings</h3>
                <?php if (empty($pastMeetings)): ?>
                    <p class="muted">No past meetings recorded yet.</p>
                <?php else: ?>
                    <div class="meet-list" style="max-height:260px; overflow:auto;">
                        <?php foreach ($pastMeetings as $m): ?>
                            <div class="meet">
                                <div class="meet-title">
                                    <?= esc($m['title']) ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-past">Completed</span>
                                    <?php
                                        $dtLabel = esc($m['meeting_date']);
                                        if (!empty($m['meeting_time'])) {
                                            $dtLabel .= ' • ' . esc(substr($m['meeting_time'], 0, 5));
                                        }
                                        echo $dtLabel;
                                    ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-mode">
                                        <?= esc(ucfirst($m['mode'])) ?>
                                    </span>
                                    <?php if ($m['location']): ?>
                                        at <?= esc($m['location']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Create Meeting</h3>
                <?php if (!$canCreate): ?>
                    <p class="muted">
                        Only club secretary / joint secretary can create meetings.
                    </p>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">

                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" required
                               value="<?= esc($_POST['title'] ?? '') ?>"
                               placeholder="e.g. Alumni Tech Talk with 2018 Batch">

                        <label for="meeting_date">Date *</label>
                        <input type="date" id="meeting_date" name="meeting_date"
                               required value="<?= esc($_POST['meeting_date'] ?? '') ?>">

                        <label for="meeting_time">Time *</label>
                        <input type="time" id="meeting_time" name="meeting_time" required
                               value="<?= esc($_POST['meeting_time'] ?? '') ?>">

                        <label for="mode">Mode *</label>
                        <select id="mode" name="mode" required>
                            <?php
                                $currMode = $_POST['mode'] ?? 'offline';
                            ?>
                            <option value="offline" <?= $currMode === 'offline' ? 'selected' : '' ?>>Offline</option>
                            <option value="online"  <?= $currMode === 'online'  ? 'selected' : '' ?>>Online</option>
                            <option value="hybrid"  <?= $currMode === 'hybrid'  ? 'selected' : '' ?>>Hybrid</option>
                        </select>

                        <label for="location">Location / Link *</label>
                        <input type="text" id="location" name="location"
                               value="<?= esc($_POST['location'] ?? '') ?>"
                               placeholder="e.g. Seminar Hall / Google Meet link" required>

                        <label for="target_batches">Target Batches * (Example : 2025-2027 or 2025-2028)</label>
                        <input type="text" id="target_batches" name="target_batches"
                               value="<?= esc($_POST['target_batches'] ?? '') ?>"
                               placeholder="Enter Batch" pattern="\d{4}-\d{4}" required>

                        <label for="description">Description / Agenda</label>
                        <textarea id="description" name="description"
                                  placeholder="Short agenda, purpose, speakers, etc."><?= esc($_POST['description'] ?? '') ?></textarea>

                        <button type="submit" name="create_meeting" class="btn btn-primary">Create Meeting</button>
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
