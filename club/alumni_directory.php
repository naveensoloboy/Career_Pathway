<?php
// club/alumni_directory.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'] ?? null;
$club_id   = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;

if ($club_id <= 0) {
    die('Club not specified.');
}

// load club
$stmtClub = $pdo->prepare('SELECT * FROM clubs WHERE id = :id LIMIT 1');
$stmtClub->execute([':id' => $club_id]);
$clubRow = $stmtClub->fetch();
if (!$clubRow) {
    die('Club not found.');
}

// check it is alumni club
$clubNameRaw  = trim($clubRow['name'] ?? '');
$isAlumniClub = in_array(strtolower($clubNameRaw), ['alumni', 'alumini'], true);
if (!$isAlumniClub) {
    http_response_code(403);
    echo "This page is only for the Alumni club.";
    exit;
}

// get user's club role (any member can view)
$roleInfo = get_club_role($pdo, $user_roll, $club_id);
if (!$roleInfo) {
    http_response_code(403);
    echo "Access denied. You are not a member of this club.";
    exit;
}

function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- Filters / Search ----------
$search = trim($_GET['search'] ?? '');
$batch  = trim($_GET['batch'] ?? '');

// base query
$sql = "
    SELECT
        ac.id,
        ac.name,
        ac.batch,
        ac.phone,
        ac.email,
        ac.linkedin_url,
        ac.naukri_url,
        ac.instagram_url,
        ac.current_role,
        ac.notes,
        ac.created_at
    FROM alumni_contacts ac
    WHERE ac.club_id = :cid
";

$params = [':cid' => $club_id];

if ($search !== '') {
    $sql .= " AND (
        ac.name LIKE :q
        OR ac.batch LIKE :q
        OR ac.current_role LIKE :q
        OR ac.email LIKE :q
    )";
    $params[':q'] = '%' . $search . '%';
}

if ($batch !== '') {
    $sql .= " AND ac.batch = :batch";
    $params[':batch'] = $batch;
}

$sql .= " ORDER BY ac.batch DESC, ac.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alumni = $stmt->fetchAll();

// Fetch distinct batches for filter dropdown
$batchStmt = $pdo->prepare("
    SELECT DISTINCT batch 
    FROM alumni_contacts 
    WHERE club_id = :cid 
    ORDER BY batch DESC
");
$batchStmt->execute([':cid' => $club_id]);
$batches = $batchStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= esc($clubRow['name']) ?> — Alumni Directory</title>
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
        .filters {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin:12px 0 16px;
            align-items:flex-end;
        }
        .filters .field {
            display:flex;
            flex-direction:column;
            gap:4px;
        }
        .filters label {
            font-weight:600;
            font-size:0.9rem;
        }
        .filters input[type="text"],
        .filters select {
            padding:7px 9px;
            border-radius:8px;
            border:1px solid #e5e7eb;
            min-width:200px;
        }
        .btn {
            padding:8px 12px;
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
        table {
            width:100%;
            border-collapse:collapse;
            background:#fff;
            border-radius:10px;
            overflow:hidden;
            border:1px solid #e5e7eb;
        }
        th, td {
            padding:9px 8px;
            border-bottom:1px solid #eef2ff;
            font-size:0.9rem;
            text-align:left;
            vertical-align:top;
        }
        th {
            background:#f9fafb;
            font-weight:600;
            color:#4b5563;
            white-space:nowrap;
        }
        .chip {
            display:inline-block;
            padding:3px 8px;
            border-radius:999px;
            font-size:0.75rem;
            background:#eff6ff;
            color:#1d4ed8;
            font-weight:600;
        }
        .link-list a {
            display:inline-block;
            font-size:0.8rem;
            margin-right:6px;
            color:#2563eb;
            text-decoration:none;
        }
        .link-list a:hover {
            text-decoration:underline;
        }
        .notes {
            font-size:0.8rem;
            color:#4b5563;
        }
        @media (max-width: 768px) {
            table {
                font-size:0.8rem;
            }
            th, td {
                padding:7px 6px;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div class="wrap">
        <h2><?= esc($clubRow['name']) ?> Club — Alumni Directory</h2>
        <p class="muted">
            Search and filter alumni by name, batch, or current role.  
            Click on profile links to connect with them.
        </p>

        <form method="get" class="filters">
            <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">
            
            <div class="field">
                <label for="search">Search (name / role / email)</label>
                <input type="text" id="search" name="search"
                       value="<?= esc($search) ?>"
                       placeholder="e.g. Priya, Developer, gmail.com">
            </div>

            <div class="field">
                <label for="batch">Batch</label>
                <select id="batch" name="batch">
                    <option value="">All batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= esc($b) ?>" <?= $batch === $b ? 'selected' : '' ?>>
                            <?= esc($b) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" style="flex-direction:row; gap:8px; margin-top:18px;">
                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="<?= esc(BASE_URL) ?>/club/alumni_directory.php?club_id=<?= (int)$club_id ?>"
                   class="btn btn-ghost">Reset</a>
            </div>
        </form>

        <?php if (empty($alumni)): ?>
            <p class="muted">No alumni found for this filter.</p>
        <?php else: ?>
            <div style="margin-bottom:8px;" class="muted">
                Showing <?= count($alumni) ?> alumni.
            </div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name / Batch</th>
                            <th>Contact</th>
                            <th>Profiles</th>
                            <th>Current Role</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($alumni as $idx => $a): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <div><strong><?= esc($a['name']) ?></strong></div>
                                <div class="chip"><?= esc($a['batch']) ?></div>
                                <div style="font-size:0.75rem;color:#9ca3af;margin-top:2px;">
                                    Added: <?= esc($a['created_at']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($a['phone']): ?>
                                    <div>📞 <?= esc($a['phone']) ?></div>
                                <?php endif; ?>
                                <?php if ($a['email']): ?>
                                    <div>✉️ <?= esc($a['email']) ?></div>
                                <?php endif; ?>
                                <?php if (!$a['phone'] && !$a['email']): ?>
                                    <span class="muted">No direct contact added</span>
                                <?php endif; ?>
                            </td>
                            <td class="link-list">
                                <?php if ($a['linkedin_url']): ?>
                                    <a href="<?= esc($a['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer">LinkedIn</a>
                                <?php endif; ?>
                                <?php if ($a['naukri_url']): ?>
                                    <a href="<?= esc($a['naukri_url']) ?>" target="_blank" rel="noopener noreferrer">Naukri</a>
                                <?php endif; ?>
                                <?php if ($a['instagram_url']): ?>
                                    <a href="<?= esc($a['instagram_url']) ?>" target="_blank" rel="noopener noreferrer">Instagram</a>
                                <?php endif; ?>
                                <?php if (!$a['linkedin_url'] && !$a['naukri_url'] && !$a['instagram_url']): ?>
                                    <span class="muted">No profiles added</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $a['current_role'] ? esc($a['current_role']) : '<span class="muted">Not specified</span>' ?>
                            </td>
                            <td class="notes">
                                <?= $a['notes'] ? esc($a['notes']) : '<span class="muted">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="margin-top:16px;">
            <a href="<?= esc(BASE_URL) ?>/club/dashboard.php?club_id=<?= (int)$club_id ?>"
               class="btn btn-ghost">Back to Club Dashboard</a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
