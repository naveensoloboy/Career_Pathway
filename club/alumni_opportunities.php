<?php
// club/alumni_opportunities.php
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

$clubNameRaw  = trim($clubRow['name'] ?? '');
$isAlumniClub = in_array(strtolower($clubNameRaw), ['alumni','alumini'], true);
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

$canManage = in_array($roleInfo['role'], ['club_secretary','club_joint_secretary'], true);

$errors = [];
$success = null;

// Fetch active alumni for dropdown
$alumniStmt = $pdo->prepare("
    SELECT id, name, batch, current_role
    FROM alumni_contacts
    WHERE club_id = :cid
    ORDER BY name
");
$alumniStmt->execute([':cid' => $club_id]);
$alumniList = $alumniStmt->fetchAll();

// Handle add opportunity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create' && $canManage) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $alumni_id   = isset($_POST['alumni_id']) && $_POST['alumni_id'] !== '' ? (int)$_POST['alumni_id'] : null;
        $title       = trim($_POST['title'] ?? '');
        $company     = trim($_POST['company'] ?? '');
        $location    = trim($_POST['location'] ?? '');
        $type        = trim($_POST['type'] ?? 'internship');
        $mode        = trim($_POST['mode'] ?? 'online');
        $apply_link  = trim($_POST['apply_link'] ?? '');
        $last_date   = trim($_POST['last_date'] ?? '');
        $details     = trim($_POST['details'] ?? '');

        if ($title === '') {
            $errors[] = 'Title is required.';
        }

        if ($apply_link !== '' && !filter_var($apply_link, FILTER_VALIDATE_URL)) {
            $errors[] = 'Apply link is not a valid URL.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO alumni_opportunities
                        (club_id, alumni_id, title, company, location, type, mode,
                         apply_link, last_date, details, status, created_by)
                    VALUES
                        (:cid, :aid, :title, :company, :location, :type, :mode,
                         :alink, :ldate, :details, 'open', :creator)
                ");
                $stmt->execute([
                    ':cid'     => $club_id,
                    ':aid'     => $alumni_id,
                    ':title'   => $title,
                    ':company' => $company !== '' ? $company : null,
                    ':location'=> $location !== '' ? $location : null,
                    ':type'    => $type !== '' ? $type : 'internship',
                    ':mode'    => $mode !== '' ? $mode : 'online',
                    ':alink'   => $apply_link !== '' ? $apply_link : null,
                    ':ldate'   => $last_date !== '' ? $last_date : null,
                    ':details' => $details !== '' ? $details : null,
                    ':creator' => $user_roll,
                ]);
                $success = 'Opportunity added.';
                // Clear POST so form becomes empty
                $_POST = [];
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle status change (open / closed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'status' && $canManage) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $oppId  = (int)($_POST['opp_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'open');

        if ($oppId <= 0) {
            $errors[] = 'Invalid opportunity id.';
        } else {
            if (!in_array($status, ['open','closed'], true)) {
                $status = 'open';
            }
            try {
                $stmt = $pdo->prepare("
                    UPDATE alumni_opportunities
                    SET status = :st
                    WHERE id = :id AND club_id = :cid
                ");
                $stmt->execute([
                    ':st'  => $status,
                    ':id'  => $oppId,
                    ':cid' => $club_id,
                ]);
                $success = 'Opportunity status updated.';
            } catch (Exception $e) {
                $errors[] = 'Status update failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch lists
$openStmt = $pdo->prepare("
    SELECT o.*, a.name AS alumni_name, a.current_role AS role
    FROM alumni_opportunities o
    LEFT JOIN alumni_contacts a ON a.id = o.alumni_id
    WHERE o.club_id = :cid AND o.status = 'open'
    ORDER BY o.last_date IS NULL, o.last_date ASC, o.created_at DESC
");
$openStmt->execute([':cid' => $club_id]);
$openList = $openStmt->fetchAll();

$closedStmt = $pdo->prepare("
    SELECT o.*, a.name AS alumni_name, a.current_role AS role
    FROM alumni_opportunities o
    LEFT JOIN alumni_contacts a ON a.id = o.alumni_id
    WHERE o.club_id = :cid AND o.status = 'closed'
    ORDER BY o.last_date DESC, o.created_at DESC
    LIMIT 30
");
$closedStmt->execute([':cid' => $club_id]);
$closedList = $closedStmt->fetchAll();

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= esc($clubRow['name']) ?> — Alumni Opportunities</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap {
            max-width: 1150px;
            margin: 20px auto;
        }
        .muted { color:#6b7280; font-size:0.9rem; }
        .grid-2 {
            display:grid;
            grid-template-columns: 1fr;
            gap:18px;
            align-items:flex-start;
        }
        @media (max-width: 900px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        .card {
            background:#fff;
            border-radius:10px;
            border:1px solid #e5e7eb;
            padding:14px 16px;
            box-shadow:0 6px 18px rgba(15,23,42,0.03);
        }
        .card h3{ margin-top:0; }
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
        .opp-list {
            margin-top:8px;
        }
        .opp {
            border-bottom:1px solid #e5e7eb;
            padding:10px 0;
        }
        .opp:last-child { border-bottom:none; }
        .opp-title {
            font-weight:600;
            font-size:0.95rem;
        }
        .small { font-size:0.8rem; color:#6b7280; }
        .badge {
            display:inline-block;
            padding:3px 7px;
            border-radius:999px;
            font-size:0.75rem;
            font-weight:600;
            margin-right:6px;
        }
        .badge-open { background:#dcfce7; color:#166534; }
        .badge-closed { background:#fee2e2; color:#b91c1c; }
        .badge-type { background:#eef2ff; color:#4338ca; }
        .badge-mode { background:#f1f5f9; color:#0f172a; }
        .tag-company { font-weight:500; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div class="wrap">
        <h2><?= esc($clubRow['name']) ?> Club — Opportunities</h2>
        <p class="muted">
            Track internships, jobs, referrals and other opportunities shared by alumni for current students.
        </p>

        <div class="grid-2">
            <section class="card">
                <h3>Open Opportunities</h3>
                <?php if (empty($openList)): ?>
                    <p class="muted">No open opportunities right now.</p>
                <?php else: ?>
                    <div class="opp-list">
                        <?php foreach ($openList as $o): ?>
                            <div class="opp">
                                <div class="opp-title">
                                    <?= esc($o['title']) ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-open">Open</span>
                                    <span class="badge badge-type"><?= esc(ucfirst($o['type'] ?? 'internship')) ?></span>
                                    <span class="badge badge-mode"><?= esc(ucfirst($o['mode'] ?? 'online')) ?></span>
                                </div>
                                <div class="small">
                                    <?php if ($o['company']): ?>
                                        <span class="tag-company"><?= esc($o['company']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($o['location']): ?>
                                        • <?= esc($o['location']) ?>
                                    <?php endif; ?>
                                    <?php if ($o['last_date']): ?>
                                        • Last date: <?= esc($o['last_date']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small">
                                    <?php if ($o['alumni_name']): ?>
                                        Shared by: <?= esc($o['alumni_name']) ?>
                                        <?php if ($o['alumni_company']): ?>
                                            (<?= esc($o['alumni_company']) ?>)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Shared by club
                                    <?php endif; ?>
                                </div>
                                <?php if ($o['apply_link']): ?>
                                    <div class="small">
                                        Apply: <a href="<?= esc($o['apply_link']) ?>" target="_blank" rel="noopener">
                                            <?= esc($o['apply_link']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($o['details']): ?>
                                    <div class="small" style="margin-top:4px;">
                                        <?= nl2br(esc($o['details'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canManage): ?>
                                    <form method="post" style="margin-top:6px;">
                                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="opp_id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn" name="status" value="closed"
                                            onclick="return confirm('Mark this opportunity as closed?');">
                                            Mark as Closed
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h3 style="margin-top:18px;">Recently Closed</h3>
                <?php if (empty($closedList)): ?>
                    <p class="muted">No closed opportunities yet.</p>
                <?php else: ?>
                    <div class="opp-list" style="max-height:260px; overflow:auto;">
                        <?php foreach ($closedList as $o): ?>
                            <div class="opp">
                                <div class="opp-title">
                                    <?= esc($o['title']) ?>
                                </div>
                                <div class="small">
                                    <span class="badge badge-closed">Closed</span>
                                    <span class="badge badge-type"><?= esc(ucfirst($o['type'] ?? 'internship')) ?></span>
                                    <?php if ($o['last_date']): ?>
                                        • Last date: <?= esc($o['last_date']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small">
                                    <?php if ($o['company']): ?>
                                        <span class="tag-company"><?= esc($o['company']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($o['location']): ?>
                                        • <?= esc($o['location']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small">
                                    <?php if ($o['alumni_name']): ?>
                                        Shared by: <?= esc($o['alumni_name']) ?>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canManage): ?>
                                    <form method="post" style="margin-top:6px;">
                                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="opp_id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn" name="status" value="open">
                                            Reopen
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Add New Opportunity</h3>

                <?php if (!$canManage): ?>
                    <p class="muted">
                        Only club secretary / joint secretary can add or update opportunities.
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

                        <label for="alumni_id">Shared by Alumni (optional)</label>
                        <select id="alumni_id" name="alumni_id">
                            <option value="">Select alumni</option>
                            <?php foreach ($alumniList as $al): ?>
                                <option value="<?= (int)$al['id'] ?>"
                                    <?= (isset($_POST['alumni_id']) && (int)($_POST['alumni_id']) === (int)$al['id']) ? 'selected' : '' ?>>
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

                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title"
                               value="<?= esc($_POST['title'] ?? '') ?>"
                               placeholder="e.g. Software Intern, Backend Developer, Data Analyst">

                        <label for="company">Company</label>
                        <input type="text" id="company" name="company"
                               value="<?= esc($_POST['company'] ?? '') ?>">

                        <label for="location">Location</label>
                        <input type="text" id="location" name="location"
                               value="<?= esc($_POST['location'] ?? '') ?>"
                               placeholder="e.g. Bangalore / Remote">

                        <label for="type">Type</label>
                        <?php $currType = $_POST['type'] ?? 'internship'; ?>
                        <select id="type" name="type">
                            <option value="internship" <?= $currType === 'internship' ? 'selected' : '' ?>>Internship</option>
                            <option value="job"        <?= $currType === 'job'        ? 'selected' : '' ?>>Job</option>
                            <option value="referral"   <?= $currType === 'referral'   ? 'selected' : '' ?>>Referral</option>
                            <option value="webinar"    <?= $currType === 'webinar'    ? 'selected' : '' ?>>Webinar / Session</option>
                            <option value="other"      <?= $currType === 'other'      ? 'selected' : '' ?>>Other</option>
                        </select>

                        <label for="mode">Mode</label>
                        <?php $currMode = $_POST['mode'] ?? 'online'; ?>
                        <select id="mode" name="mode">
                            <option value="online"  <?= $currMode === 'online'  ? 'selected' : '' ?>>Online</option>
                            <option value="offline" <?= $currMode === 'offline' ? 'selected' : '' ?>>Offline</option>
                            <option value="hybrid"  <?= $currMode === 'hybrid'  ? 'selected' : '' ?>>Hybrid</option>
                        </select>

                        <label for="last_date">Last Date to Apply</label>
                        <input type="date" id="last_date" name="last_date"
                               value="<?= esc($_POST['last_date'] ?? '') ?>">

                        <label for="apply_link">Apply / Info Link</label>
                        <input type="text" id="apply_link" name="apply_link"
                               value="<?= esc($_POST['apply_link'] ?? '') ?>"
                               placeholder="https://...">

                        <label for="details">Details</label>
                        <textarea id="details" name="details"
                                  placeholder="Role description, eligibility, how to apply, contact details, etc."><?= esc($_POST['details'] ?? '') ?></textarea>

                        <button type="submit" class="btn btn-primary">Add Opportunity</button>
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
