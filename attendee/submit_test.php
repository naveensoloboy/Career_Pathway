<?php
// attendee/submit_test.php
// Writes attempts + answers and inserts attempts_tests mapping with per-test score & total_marks
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    die('Invalid request method.');
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Invalid request.');
}

// read incoming test identifiers or date/type
$test_type = trim($_POST['test_type'] ?? '');
$test_date = trim($_POST['test_date'] ?? '');

// get explicit test_ids[] if provided
$testIds = [];
if (!empty($_POST['test_ids']) && is_array($_POST['test_ids'])) {
    foreach ($_POST['test_ids'] as $t) {
        $t = intval($t);
        if ($t > 0) $testIds[] = $t;
    }
}

// if missing type/date but test_ids present, fetch from first test
$firstTestId = $testIds[0] ?? null;
if (($test_type === '' || $test_date === '') && $firstTestId) {
    $stmt = $pdo->prepare("SELECT test_type, test_date FROM tests WHERE id = ? LIMIT 1");
    $stmt->execute([$firstTestId]);
    $row = $stmt->fetch();
    if ($row) {
        $test_type = $test_type ?: $row['test_type'];
        $test_date = $test_date ?: $row['test_date'];
    }
}

// if still missing, but user supplied type+date, discover tests for that date/type
if ((empty($testIds)) && $test_type !== '' && $test_date !== '') {
    $stmt = $pdo->prepare("SELECT id FROM tests WHERE test_type = :tt AND test_date = :td AND active = 1");
    $stmt->execute([':tt' => $test_type, ':td' => $test_date]);
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($found) {
        $testIds = array_map('intval', $found);
        $firstTestId = $firstTestId ?: ($testIds[0] ?? null);
    }
}

// basic validation
if (!in_array($test_type, ['daily','weekly'], true) || $test_date === '') {
    http_response_code(400);
    die('Invalid test_type or test_date.');
}

$dt = DateTime::createFromFormat('Y-m-d', $test_date);
if (!$dt) {
    http_response_code(400);
    die('Invalid date.');
}

// block only tomorrow attempts
$today = new DateTime('today');
$tomorrow = (clone $today)->modify('+1 day');
if ($dt == $tomorrow) {
    die("You cannot attempt tomorrow's test today.");
}

// must have at least one test id to proceed
if (empty($testIds)) {
    http_response_code(400);
    die('No associated tests found for this date/type.');
}

// Check user hasn't already attempted any of these tests
$placeholders = implode(',', array_fill(0, count($testIds), '?'));
$params = array_merge([$user_roll], $testIds);

$sql = "SELECT at.attempt_id
        FROM attempts_tests at
        JOIN attempts a ON a.id = at.attempt_id
        WHERE a.user_roll = ? AND at.test_id IN ($placeholders) LIMIT 1";
$chk = $pdo->prepare($sql);
$chk->execute($params);
if ($chk->fetch()) {
    die('You have already attempted this test/date.');
}

// backward check in attempts.test_id
$sql2 = "SELECT id FROM attempts WHERE user_roll = ? AND test_id IN ($placeholders) LIMIT 1";
$chk2 = $pdo->prepare($sql2);
$chk2->execute($params);
if ($chk2->fetch()) {
    die('You have already attempted this test/date.');
}

// collect answers
$answers = $_POST['answer'] ?? [];
if (!is_array($answers) || empty($answers)) {
    http_response_code(400);
    die('No answers submitted.');
}

// normalize qids
$qids = array_map('intval', array_keys($answers));
if (empty($qids)) {
    http_response_code(400);
    die('No question IDs found.');
}

// fetch correct options for submitted questions
$inQ = implode(',', array_fill(0, count($qids), '?'));
$optStmt = $pdo->prepare("SELECT question_id, correct_option FROM options_four WHERE question_id IN ($inQ)");
$optStmt->execute($qids);
$correctRows = $optStmt->fetchAll();
$correctMap = [];
foreach ($correctRows as $r) $correctMap[(int)$r['question_id']] = (int)$r['correct_option'];

// fetch question -> test_id mapping for submitted questions
$qmapStmt = $pdo->prepare("SELECT id, test_id FROM questions WHERE id IN ($inQ)");
$qmapStmt->execute($qids);
$qRows = $qmapStmt->fetchAll();
$questionToTest = [];
foreach ($qRows as $qr) {
    // if test_id is null treat as 0 (or ignore); we'll only count when test_id is in $testIds
    $questionToTest[(int)$qr['id']] = $qr['test_id'] !== null ? (int)$qr['test_id'] : 0;
}

// compute overall score and per-test correct counts
$totalSubmitted = count($qids);
$overallScore = 0;
$perTestCorrect = []; // [test_id => correct_count]
foreach ($testIds as $tid) $perTestCorrect[(int)$tid] = 0;

foreach ($qids as $qid) {
    $sel = intval($answers[$qid] ?? 0);
    $isCorrect = (isset($correctMap[$qid]) && (int)$correctMap[$qid] === $sel);
    if ($isCorrect) $overallScore++;
    $qidTest = $questionToTest[$qid] ?? 0;
    // only count this question towards a test if the question's test_id matches one of the testIds
    if ($qidTest && in_array($qidTest, $testIds, true)) {
        if (!isset($perTestCorrect[$qidTest])) $perTestCorrect[$qidTest] = 0;
        if ($isCorrect) $perTestCorrect[$qidTest]++;
    }
}

// compute per-test possible question counts (total questions for that test)
// fallback to number of submitted questions mapped to that test if DB count returns 0
$perTestPossible = []; // [test_id => possible_count]
$countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM questions WHERE test_id = ?");
foreach ($testIds as $tid) {
    $countStmt->execute([(int)$tid]);
    $cRow = $countStmt->fetch();
    $cnt = $cRow ? (int)$cRow['cnt'] : 0;

    // defensive fallback: number of submitted questions that belong to this test
    if ($cnt <= 0) {
        $cnt = 0;
        foreach ($qids as $qid) {
            $qidTest = $questionToTest[$qid] ?? 0;
            if ($qidTest === (int)$tid) $cnt++;
        }
        // if still zero, fallback to overall submitted count (defensive)
        if ($cnt <= 0) $cnt = $totalSubmitted;
    }
    $perTestPossible[$tid] = $cnt;
}

// decide attempt.test_id association: use firstTestId (or NULL)
$attemptTestId = $firstTestId ? (int)$firstTestId : null;

// insert attempt, answers, and attempts_tests rows
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO attempts (user_roll, test_id, score, total_marks, submitted_at) VALUES (:u, :tid, :score, :total, NOW())");
    $ins->execute([
        ':u' => $user_roll,
        ':tid' => $attemptTestId,
        ':score' => $overallScore,
        ':total' => $totalSubmitted
    ]);
    $attemptId = (int)$pdo->lastInsertId();

    // insert answers
    $insA = $pdo->prepare("INSERT INTO answers (attempt_id, question_id, selected_option) VALUES (:aid, :qid, :sel)");
    foreach ($answers as $qid => $sel) {
        $insA->execute([
            ':aid' => $attemptId,
            ':qid' => (int)$qid,
            ':sel' => (int)$sel
        ]);
    }

    // insert mapping rows with per-test score and per-test total_marks
    $mapStmt = $pdo->prepare("INSERT INTO attempts_tests (attempt_id, test_id, score, total_marks, submitted_at) VALUES (:aid, :tid, :score, :total, NOW())");
    foreach ($testIds as $tid) {
        $tid = (int)$tid;
        $mapStmt->execute([
            ':aid' => $attemptId,
            ':tid' => $tid,
            ':score'=> (int)($perTestCorrect[$tid] ?? 0),
            ':total' => (int)($perTestPossible[$tid] ?? $totalSubmitted)
        ]);
    }

    $pdo->commit();

    // redirect with message
    header('Location: ' . BASE_URL . '/attendee/dashboard.php?msg=' . urlencode("Test submitted. Score: $overallScore/$totalSubmitted"));
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    die('DB error: ' . $e->getMessage());
}
