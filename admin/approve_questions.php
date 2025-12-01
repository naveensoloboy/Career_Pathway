<?php
// admin/approve_questions.php
require_once __DIR__ . '/../config/config.php';
require_admin($pdo);

$errors = [];
$success = null;

// helper: approve single pending question (transaction caller handles commit/rollBack)
function approve_single_pending(PDO $pdo, int $pendingId): void {
    $q = $pdo->prepare("SELECT * FROM questions_pending WHERE id = :id FOR UPDATE");
    $q->execute([':id' => $pendingId]);
    $row = $q->fetch();
    if (!$row) throw new Exception("Pending question #{$pendingId} not found.");

    // if attached to a test ensure it's active
    if (!empty($row['test_id'])) {
        $tst = $pdo->prepare("SELECT id, active FROM tests WHERE id = :id LIMIT 1");
        $tst->execute([':id' => $row['test_id']]);
        $trow = $tst->fetch();
        if (!$trow) throw new Exception("Linked test not found for pending #{$pendingId}.");
        if ((int)$trow['active'] !== 1) {
            throw new Exception("Cannot approve pending #{$pendingId}: linked test is inactive.");
        }
    }

    $insQ = $pdo->prepare("
        INSERT INTO questions (club_id, test_id, question_text)
        VALUES (:cid, :tid, :qt)
    ");
    $insQ->execute([
        ':cid' => $row['club_id'],
        ':tid' => $row['test_id'] ?: null,
        ':qt'  => $row['question_text']
    ]);
    $newQid = (int)$pdo->lastInsertId();

    $o = $pdo->prepare("SELECT * FROM questions_pending_options WHERE pending_question_id = :id");
    $o->execute([':id' => $pendingId]);
    $opt = $o->fetch();
    if (!$opt) throw new Exception("Options for pending #{$pendingId} not found.");

    $insOpt = $pdo->prepare("
        INSERT INTO options_four (question_id, option_a, option_b, option_c, option_d, correct_option)
        VALUES (:qid, :a, :b, :c, :d, :co)
    ");
    $insOpt->execute([
        ':qid' => $newQid,
        ':a'   => $opt['option_a'],
        ':b'   => $opt['option_b'],
        ':c'   => $opt['option_c'],
        ':d'   => $opt['option_d'],
        ':co'  => $opt['correct_option']
    ]);

    // delete pending rows
    $pdo->prepare("DELETE FROM questions_pending WHERE id = :id")->execute([':id' => $pendingId]);
    $pdo->prepare("DELETE FROM questions_pending_options WHERE pending_question_id = :id")->execute([':id' => $pendingId]);
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request.";
    } else {
        // Approve single question
        if (isset($_POST['approve'])) {
            $qid = intval($_POST['pending_id']);
            try {
                $pdo->beginTransaction();
                approve_single_pending($pdo, $qid);
                $pdo->commit();
                $success = "Question approved.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }

        // Reject single question
        if (isset($_POST['reject'])) {
            $qid = intval($_POST['pending_id']);
            try {
                $pdo->beginTransaction();
                $q = $pdo->prepare("SELECT * FROM questions_pending WHERE id = :id FOR UPDATE");
                $q->execute([':id' => $qid]);
                $row = $q->fetch();
                if (!$row) throw new Exception("Pending question not found.");

                if (!empty($row['id'])) {
                    // deactivate linked test (but keep pending rows)
                    $upd = $pdo->prepare("UPDATE questions_pending SET active = 0 WHERE id = :id");
                    $upd->execute([':id' => $row['id']]);
                    $success = "Linked test (ID {$row['id']}) deactivated.";
                } else {
                    // delete pending question and its options
                    // $pdo->prepare("DELETE FROM questions_pending_options WHERE pending_question_id = :id")->execute([':id' => $qid]);
                    // $pdo->prepare("DELETE FROM questions_pending WHERE id = :id")->execute([':id' => $qid]);
                    // $success = "Pending question removed.";
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error rejecting question: " . $e->getMessage();
            }
        }

        // Approve group (grouped by test_type + test_date OR general)
        if (isset($_POST['approve_group'])) {
            $group_type = $_POST['group_test_type'] ?? '';
            $group_date = $_POST['group_test_date'] ?? '';
            try {
                $pdo->beginTransaction();

                if ($group_type === 'GENERAL') {
                    // select general pending (test_id IS NULL)
                    $rows = $pdo->query("SELECT id FROM questions_pending WHERE test_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    
                    // select by test_type + test_date via join (only active tests, only active pending rows)
                    $stmt = $pdo->prepare("
                        SELECT q.id
                        FROM questions_pending q
                        JOIN tests t ON t.id = q.test_id
                        WHERE t.test_type = :tt
                          AND t.test_date = :td
                          AND t.active = 1
                          AND q.active = 2
                    ");
                    $stmt->execute([':tt' => $group_type, ':td' => $group_date]);
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

                }

                if (empty($rows)) throw new Exception("No pending questions found in this group.");

                foreach ($rows as $pid) {
                    approve_single_pending($pdo, (int)$pid);
                }

                $pdo->commit();
                $success = "Group approved (" . count($rows) . " question(s)).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Approve group failed: " . $e->getMessage();
            }
        }

        // Reject group
        if (isset($_POST['reject_group'])) {
            $group_type = $_POST['group_test_type'] ?? '';
            $group_date = $_POST['group_test_date'] ?? '';
            try {
                $pdo->beginTransaction();
                if ($group_type === 'GENERAL') {
                    // delete all general pending entries
                    $pdo->prepare("DELETE qopt FROM questions_pending_options qopt JOIN questions_pending qp ON qopt.pending_question_id = qp.id WHERE qp.test_id IS NULL")->execute();
                    $pdo->prepare("DELETE FROM questions_pending WHERE test_id IS NULL")->execute();
                    $success = "General pending questions deleted.";
                } else {
                    // deactivate all tests with that type+date
                    $upd = $pdo->prepare("UPDATE questions_pending SET active = 0 WHERE test_type = :tt AND test_date = :td");
                    $upd->execute([':tt' => $group_type, ':td' => $group_date]);
                    $success = "All tests on {$group_type} • {$group_date} have been deactivated.";
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Reject group failed: " . $e->getMessage();
            }
        }
    }
}

// VIEW controls
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : null; // 'GENERAL' or test_type value
$view_date = isset($_GET['view_date']) ? $_GET['view_date'] : null; // date string when viewing a typed group

// Build grouped summary by test_type + test_date (general group included).
// We group across clubs: all pending rows that map to the same test_type+test_date are one group.
$groupsSql = "
    SELECT
        COALESCE(t.test_type, 'GENERAL') AS group_type,
        COALESCE(t.test_date, '') AS group_date,
        COUNT(q.id) AS pending_count,
        GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS clubs
    FROM questions_pending q
    JOIN clubs c ON c.id = q.club_id
    LEFT JOIN tests t ON t.id = q.test_id
    WHERE (q.test_id IS NULL)
       OR (t.active = 1 AND q.active = 2)
    GROUP BY COALESCE(t.test_type, 'GENERAL'), COALESCE(t.test_date, '')
    ORDER BY COALESCE(t.test_date, '0000-00-00') DESC, GROUP_CONCAT(DISTINCT c.name) ASC
";

$groupsStmt = $pdo->query($groupsSql);
$groups = $groupsStmt->fetchAll();

// If viewing a specific group, fetch its pending questions.
$groupPending = [];
if ($view_type !== null) {
    if ($view_type === 'GENERAL') {
        $gStmt = $pdo->prepare("
            SELECT q.id, q.question_text, c.name AS club_name, q.test_id
            FROM questions_pending q
            JOIN clubs c ON c.id = q.club_id
            WHERE q.test_id IS NULL AND q.active = 2
            ORDER BY q.id ASC
        ");
        $gStmt->execute();
    } else {
        // view by concrete test_type + test_date pair
        $gStmt = $pdo->prepare("
            SELECT q.id, q.question_text, c.name AS club_name, q.test_id, t.id AS t_id, t.test_type, t.test_date
            FROM questions_pending q
            JOIN clubs c ON c.id = q.club_id
            JOIN tests t ON t.id = q.test_id
            WHERE t.test_type = :tt AND t.test_date = :td AND t.active = 1 AND q.active = 2
            ORDER BY q.id ASC
        ");
        $gStmt->execute([':tt' => $view_type, ':td' => $view_date]);
    }
    $groupPending = $gStmt->fetchAll();
}

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Approve Questions (grouped by type+date)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/footer.css">
    <style>
        .groups { display:grid; gap:12px; }
        .group-card { background:#fff;border:1px solid #e6eefb;padding:12px;border-radius:10px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
        .g-left { display:flex; gap:12px; align-items:center; }
        .g-meta { font-weight:700; }
        .g-sub { color:#64748b; font-size:0.9rem; }
        .actions { display:flex; gap:8px; }
        .btn { padding:8px 10px; border-radius:8px; text-decoration:none; font-weight:700; cursor:pointer; border:0; }
        .btn-approve { background:linear-gradient(90deg,#10b981,#059669); color:#fff; }
        .btn-reject { background:#ef4444;color:#fff; }
        .btn-view { background:#2563eb;color:#fff; }
        table { width:100%; border-collapse: collapse; margin-top:12px; }
        th,td { padding:15px 8px; border-bottom:1px solid #eef2ff; font-size: medium; font-weight: 500;}
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2>Pending Questions - Grouped by Test Type & Test Date</h2><br>

    <?php if ($errors): ?>
        <script>alert(<?= json_encode(implode("\\n", $errors)) ?>);</script>
    <?php endif; ?>

    <?php if ($success): ?>
        <script>alert(<?= json_encode($success) ?>); window.location = "approve_questions.php";</script>
    <?php endif; ?>

    <?php if ($view_type !== null): ?>
        <p style="text-align: right;"><a href="approve_questions.php">&larr; Back to groups</a></p><br>
        <h3>Viewing group:
            <?php if ($view_type === 'GENERAL'): ?>
                <em>General (not attached)</em>
            <?php else: ?>
                <?= e($view_type) ?> • <?= e($view_date) ?>
            <?php endif; ?>
        </h3><br>

        <?php if (empty($groupPending)): ?>
            <div class="empty">No pending questions in this group.</div>
        <?php else: ?>
            <table style="border-spacing: 10px;">
                <thead><tr><th>S. No.</th><th>Club Name</th><th>Question Id</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($groupPending as $idx => $p): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= e($p['club_name']) ?></td>
                            <td><?= e($p['id']) ?></td>
                            <td>
                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="pending_id" value="<?= (int)$p['id'] ?>">
                                    <button name="approve" class="btn btn-approve" onclick="return confirm('Approve this question?')">Approve</button>
                                </form>

                                <form method="post" style="display:inline-block;margin-left:8px;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="pending_id" value="<?= (int)$p['id'] ?>">
                                    <button name="reject" class="btn btn-reject" onclick="return confirm('Reject this question? This may deactivate the test if attached.')">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php else: ?>

        <?php if (empty($groups)): ?>
            <div class="empty">No pending questions found.</div>
        <?php else: ?>
            <div class="groups">
                <?php foreach ($groups as $g):
                    $groupType = $g['group_type'];
                    $groupDate = $g['group_date'];
                    $displayLabel = ($groupType === 'GENERAL') ? 'General (not attached)' : (e($groupType) . ' • ' . e($groupDate));
                ?>
                    <div class="group-card">
                        <div class="g-left">
                            <div>
                                <div class="g-meta"><?= $displayLabel ?></div>
                                <div class="g-sub"><?= (int)$g['pending_count'] ?> pending question(s) — Clubs: <?= e($g['clubs']) ?></div>
                            </div>
                        </div>

                        <div class="actions">
                            <form method="get" style="display:inline-block;">
                                <input type="hidden" name="view_type" value="<?= $groupType ?>">
                                <input type="hidden" name="view_date" value="<?= $groupDate ?>">
                                <button type="submit" class="btn btn-view">View</button>
                            </form>

                            <form method="post" style="display:inline-block;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <?php if ($groupType === 'GENERAL'): ?>
                                    <input type="hidden" name="group_test_type" value="GENERAL">
                                    <input type="hidden" name="group_test_date" value="">
                                <?php else: ?>
                                    <input type="hidden" name="group_test_type" value="<?= e($groupType) ?>">
                                    <input type="hidden" name="group_test_date" value="<?= e($groupDate) ?>">
                                <?php endif; ?>
                                <button name="approve_group" class="btn btn-approve" onclick="return confirm('Approve all questions in this group?')">Approve All</button>
                            </form>

                            <form method="post" style="display:inline-block;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <?php if ($groupType === 'GENERAL'): ?>
                                    <input type="hidden" name="group_test_type" value="GENERAL">
                                    <input type="hidden" name="group_test_date" value="">
                                <?php else: ?>
                                    <input type="hidden" name="group_test_type" value="<?= e($groupType) ?>">
                                    <input type="hidden" name="group_test_date" value="<?= e($groupDate) ?>">
                                <?php endif; ?>
                                <button name="reject_group" class="btn btn-reject" onclick="return confirm('Reject this group? (if attached to tests they will be deactivated)')">Reject Group</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>
</body>
</html>
