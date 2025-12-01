<?php
// club/results.php
require_once __DIR__ . '/../config/config.php';
require_login();

$club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : 0;
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

if ($club_id <= 0) {
    http_response_code(400);
    die('Missing parameter: club_id.');
}

// permission: admin OR club secretary/joint_secretary
$role = get_club_role($pdo, $_SESSION['user_roll'], $club_id);
if (!is_admin($pdo) && (!$role || !in_array($role['role'], ['club_secretary','club_joint_secretary'], true))) {
    http_response_code(403);
    die('Access denied.');
}

/* -------------------------------------------------------------------------
   If only club_id provided -> show tests list (so dashboard can link here)
   ------------------------------------------------------------------------- */
if ($test_id <= 0) {
    $stmt = $pdo->prepare('SELECT id, title, test_type, test_date, active FROM tests WHERE club_id = :cid ORDER BY test_date DESC');
    $stmt->execute([':cid' => $club_id]);
    $tests = $stmt->fetchAll();
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Club Tests — Results</title>
        <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/main.css">
        <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/header.css">
        <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/footer.css">
        <style>
            .container { max-width:980px; margin:20px auto; padding:16px; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; }
            h2 { margin-bottom:12px; }
            .tests-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap:12px; }
            .test-card { background:#fff; border:1px solid #e6eefb; padding:12px; border-radius:10px; box-shadow:0 6px 18px rgba(37,99,235,0.04); }
            .test-title { font-weight:700; margin-bottom:6px; }
            .meta { color:#64748b; font-size:0.9rem; margin-bottom:10px; display:block; }
            .actions { display:flex; gap:8px; flex-wrap:wrap; }
            .btn { padding:8px 10px; border-radius:8px; text-decoration:none; font-weight:700; font-size:0.9rem; display:inline-block; }
            .btn-primary { background:linear-gradient(90deg,#2563eb,#1d4ed8); color:#fff; }
            .btn-ghost { background:transparent; border:1px solid #c7defb; color:#2563eb; }
            .inactive { color:#ef4444; font-weight:700; }
            .empty { padding:18px; text-align:center; color:#64748b; background:#fff; border:1px dashed #e6eefb; border-radius:8px; }
        </style>
    </head>
    <body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="container">
        <h2>Tests for Club — <?= e( get_club_name($pdo, $club_id) ?: "Club {$club_id}") ?></h2>

        <?php if (empty($tests)): ?>
            <div class="empty">No tests found for this club.</div>
        <?php else: ?>
            <div class="tests-grid">
                <?php foreach ($tests as $t): ?>
                    <div class="test-card">
                        <div class="test-title"><?= e($t['title']) ?></div>
                        <div class="meta"><?= e(ucfirst($t['test_type'])) ?> • <?= e($t['test_date']) ?>
                            <?php if (!$t['active']): ?><span class="inactive"> (inactive)</span><?php endif; ?>
                        </div>

                        <div class="actions">
                            <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/club/results.php?club_id=<?= (int)$club_id ?>&test_id=<?= (int)$t['id'] ?>">
                                Download PDF
                            </a>

                            <a class="btn btn-ghost" href="<?= e(BASE_URL) ?>/club/test_status.php?club_id=<?= (int)$club_id ?>&test_id=<?= (int)$t['id'] ?>">
                                View Attendees
                            </a>
                        </div>
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

/* -------------------------------------------------------------------------
   test_id provided -> generate PDF with answers + correct options
   ------------------------------------------------------------------------- */
$test_id = (int)$test_id;

// Verify test belongs to the club
$check = $pdo->prepare('SELECT id, title, test_type, test_date FROM tests WHERE id = :tid AND club_id = :cid LIMIT 1');
$check->execute([':tid' => $test_id, ':cid' => $club_id]);
$testInfo = $check->fetch();
if (!$testInfo) {
    http_response_code(400);
    die('Invalid test_id for this club.');
}

/* -------------------------------------------------------------------------
   Fetch attempts for this test.
   Use attempts_tests mapping table if present, and fall back to attempts.test_id
   (DISTINCT to avoid duplicates if both mapping and column exist).
   ------------------------------------------------------------------------- */
$attemptsSql = '
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
';
$attempts = $pdo->prepare($attemptsSql);
$attempts->execute([':tid' => $test_id]);
$rows = $attempts->fetchAll();

if (!$rows) {
    // No attempts - show friendly page
    ?>
    <!doctype html>
    <html>
    <head><meta charset="utf-8"><title>No Results</title></head>
    <body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main style="max-width:800px;margin:30px auto;padding:16px;">
      <h2><?= e($testInfo['title']) ?> — No results</h2>
      <p style="color:#64748b;">No attempts have been recorded for this test yet.</p>
      <p><a href="<?= e(BASE_URL) ?>/club/results.php?club_id=<?= (int)$club_id ?>">Back to tests</a></p>
    </main>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

/* -------------------------------------------------------------------------
   Fetch questions and correct options
   ------------------------------------------------------------------------- */
$qstmt = $pdo->prepare('SELECT q.id, q.question_text FROM questions q WHERE q.test_id = :tid ORDER BY q.id ASC');
$qstmt->execute([':tid' => $test_id]);
$qs = $qstmt->fetchAll();
$qids = array_column($qs, 'id');

if (empty($qids)) {
    die('No questions found for this test.');
}

// Fetch correct options
$placeholders = implode(',', array_fill(0, count($qids), '?'));
$optStmt = $pdo->prepare("SELECT question_id, correct_option FROM options_four WHERE question_id IN ($placeholders)");
$optStmt->execute($qids);
$optRows = $optStmt->fetchAll();

$correctMap = [];
foreach ($optRows as $o) {
    $correctMap[(int)$o['question_id']] = (int)$o['correct_option'];
}

/* -------------------------------------------------------------------------
   Prepare answers per attempt
   ------------------------------------------------------------------------- */
$ansStmt = $pdo->prepare('SELECT question_id, selected_option FROM answers WHERE attempt_id = :aid');

$attemptsData = [];
foreach ($rows as $r) {
    $ansStmt->execute([':aid' => $r['attempt_id']]);
    $ans = $ansStmt->fetchAll();
    $map = [];
    foreach ($ans as $a) $map[(int)$a['question_id']] = $a['selected_option'];

    $attemptsData[] = [
        'attempt_id' => $r['attempt_id'],
        'roll' => $r['user_roll'],
        'name' => $r['full_name'],
        'class' => $r['class'],
        'score' => $r['score'],
        'total' => $r['total_marks'],
        'submitted_at' => $r['submitted_at'],
        'answers' => $map
    ];
}

/* -------------------------------------------------------------------------
   Club name & helper
   ------------------------------------------------------------------------- */
$clubNameS = $pdo->prepare('SELECT name FROM clubs WHERE id = :id LIMIT 1');
$clubNameS->execute([':id' => $club_id]);
$clubRow = $clubNameS->fetch();
$clubLabel = $clubRow ? $clubRow['name'] : ('Club ' . $club_id);

$optionLetter = function($n) {
    $map = [1=>'A',2=>'B',3=>'C',4=>'D'];
    return isset($map[(int)$n]) ? $map[(int)$n] : '';
};

/* -------------------------------------------------------------------------
   Build HTML for PDF
   ------------------------------------------------------------------------- */
$titleText = e($testInfo['title']);
$testLabel = e(ucfirst($testInfo['test_type']) . ' - ' . $testInfo['test_date']);

$html = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size:12px; color:#111; margin:18px; }
  .header { text-align:center; margin-bottom:10px; }
  .header h1 { margin:0; font-size:18px; }
  .header p { margin:6px 0 0 0; color:#555; font-size:12px; }
  .meta { margin-top:8px; margin-bottom:12px; text-align:left; font-size:12px; color:#333; }
  table { width:100%; border-collapse: collapse; margin-top:8px; }
  table thead th { background:#f1f5f9; border:1px solid #e6eef8; padding:8px; font-weight:700; text-align:left; font-size:11px; }
  table tbody td { border:1px solid #e6eef8; padding:7px; vertical-align:top; font-size:11px; }
  .small { font-size:10px; color:#666; }
  .right { text-align:right; }
  .center { text-align:center; }
  .qcell { max-width:220px; word-wrap:break-word; }
  .correct { color: #0f9d58; font-weight:700; } /* green */
  .wrong { color: #d32f2f; font-weight:700; }   /* red */
  .neutral { color:#666; }
  @page { margin: 18mm; }
</style>
</head>
<body>
  <div class="header">
    <h1>' . e($clubLabel) . ' — ' . $titleText . '</h1>
    <p class="small">Generated: ' . date('Y-m-d H:i:s') . '</p>
  </div>

  <div class="meta"><strong>Test:</strong> ' . $testLabel . '</div>

  <table>
    <thead>
      <tr>
        <th style="width:80px;">Roll</th>
        <th style="width:160px;">Name</th>
        <th style="width:80px;">Class</th>
        <th style="width:70px;" class="center">Score</th>
        <th style="width:120px;">Submitted At</th>';

foreach ($qs as $q) {
    $qLabel = 'Q' . $q['id'];
    $html .= '<th class="center qcell">' . e($qLabel) . '</th>';
}

$html .= '    </tr>
    </thead>
    <tbody>';

foreach ($attemptsData as $a) {
    $html .= '<tr>';
    $html .= '<td>' . e($a['roll']) . '</td>';
    $html .= '<td>' . e($a['name']) . '</td>';
    $html .= '<td>' . e($a['class']) . '</td>';
    $html .= '<td class="center">' . (int)$a['score'] . ' / ' . (int)$a['total'] . '</td>';
    $html .= '<td>' . e($a['submitted_at']) . '</td>';

    foreach ($qids as $qid) {
        $sel = isset($a['answers'][$qid]) ? $a['answers'][$qid] : '';
        $correctNum = isset($correctMap[$qid]) ? (int)$correctMap[$qid] : null;
        $correctLetter = $correctNum ? $optionLetter($correctNum) : '';

        if ($sel === '' || $sel === null) {
            $cell = '<span class="neutral">—</span>';
            if ($correctLetter !== '') {
                $cell .= ' <span class="neutral">(Ans: ' . e($correctLetter) . ')</span>';
            }
        } else {
            $selLetter = $optionLetter($sel);
            if ($correctNum !== null && (int)$sel === $correctNum) {
                $cell = '<span class="correct">' . e($selLetter) . ' ✓</span>';
            } else {
                $cell = '<span class="wrong">' . e($selLetter) . ' ✗</span>';
                if ($correctLetter !== '') {
                    $cell .= ' <span class="neutral">(Ans: ' . e($correctLetter) . ')</span>';
                }
            }
        }

        $html .= '<td class="center qcell">' . $cell . '</td>';
    }

    $html .= '</tr>';
}

$html .= '
    </tbody>
  </table>

  <p class="small">Note: Cells show selected option and correctness (✓ correct, ✗ wrong). If blank, the attendee did not answer that question.</p>
</body>
</html>';

/* -------------------------------------------------------------------------
   Render PDF via Dompdf (composer). Provide clear instructions if missing.
   ------------------------------------------------------------------------- */
$autoloadPath1 = __DIR__ . '/../vendor/autoload.php';
$autoloadPath2 = __DIR__ . '/../../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} elseif (file_exists($autoloadPath2)) {
    require_once $autoloadPath2;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dompdf (pdf generator) is not installed. To enable PDF export install dompdf via composer:\n\n";
    echo "cd /path/to/project && composer require dompdf/dompdf\n\nThen try again.";
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $filename = 'results_test_' . $test_id . '_' . date('Ymd_His') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => 1]);
    exit;
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF generation failed: " . $e->getMessage();
    exit;
}

/* helper */
function get_club_name($pdo, $club_id) {
    $s = $pdo->prepare('SELECT name FROM clubs WHERE id = :id LIMIT 1');
    $s->execute([':id' => $club_id]);
    $r = $s->fetch();
    return $r ? $r['name'] : null;
}
