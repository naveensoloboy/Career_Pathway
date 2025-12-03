<?php
// attendee/take_test.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'];

$test_type = $_REQUEST['test_type'] ?? '';
$test_date = $_REQUEST['test_date'] ?? '';

if (!in_array($test_type, ['daily','weekly'], true)) {
    die('Invalid test type.');
}

$dt = DateTime::createFromFormat('Y-m-d', $test_date);
if (!$dt) die('Invalid date format.');

// weekly must be Saturday
if ($test_type === 'weekly' && (int)$dt->format('N') !== 6) {
    die('Weekly tests must be taken only on Saturdays.');
}

// Block ONLY tomorrow
$today = new DateTime('today');
$tomorrow = (clone $today)->modify('+1 day');
if ($dt == $tomorrow) {
    die("You cannot attempt tomorrow's test today.");
}

// Fetch ALL tests of that date (multiple clubs)
$stmt = $pdo->prepare("
    SELECT id, club_id, title
    FROM tests
    WHERE test_type = :tt
      AND test_date = :td
      AND active = 1
");
$stmt->execute([':tt' => $test_type, ':td' => $test_date]);
$testsForDate = $stmt->fetchAll();

if (!$testsForDate) {
    die("No test found for the selected date.");
}

// Get all test_ids from all clubs
$testIds = array_column($testsForDate, 'id');
$testCount = count($testIds);
if ($testCount === 0) {
    die("No active tests found for that date.");
}

// Check if user already attempted ANY test of this date
// Use positional placeholders only to avoid mixing param styles
$placeholders = implode(',', array_fill(0, $testCount, '?'));
// build values: first is user_roll then the test ids
$execParams = array_merge([$user_roll], $testIds);

$sqlCheck = "SELECT a.id
    FROM attempts a
    JOIN tests t ON a.test_id = t.id
    WHERE a.user_roll = ?
      AND a.test_id IN ($placeholders)
    LIMIT 1";

$chk = $pdo->prepare($sqlCheck);
$chk->execute($execParams);

if ($chk->fetch()) {
    die("You already attempted the test for " . e($test_date));
}

// Fetch ALL questions from ALL clubs for this test-date
$placeholders2 = implode(',', array_fill(0, $testCount, '?'));
$sqlQ = "
    SELECT 
        q.id AS qid,
        q.question_text,
        o.option_a, o.option_b, o.option_c, o.option_d,
        c.name AS club_name,
        t.title AS test_title,
        q.test_id
    FROM questions q
    JOIN options_four o ON o.question_id = q.id
    JOIN tests t ON t.id = q.test_id
    JOIN clubs c ON c.id = t.club_id
    WHERE q.test_id IN ($placeholders2)
    ORDER BY c.name, q.id ASC
";
$qstmt = $pdo->prepare($sqlQ);
$qstmt->execute($testIds);
$questions = $qstmt->fetchAll();

if (!$questions) {
    die("No questions available yet for this test.");
}

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Take Test</title>
<link rel="stylesheet" href="/public/css/main.css">
<link rel="stylesheet" href="/public/css/footer.css">
<link rel="stylesheet" href="/public/css/header.css">
<style>
    .club-header {
        margin-top:25px;
        font-size:18px;
        color:#1e40af;
        border-bottom:2px solid #cbd5e1;
        padding-bottom:6px;
    }
    .question-block { margin:14px 0; }
    .club-tag { display:inline-block; font-size:12px; color:#475569; margin-left:8px; }
    .question-block p { margin:6px 0; }
    .question-block label { display:block; margin:6px 0; }
    button.submit-btn { background:linear-gradient(90deg,#16a34a,#10b981); color:#fff; padding:10px 14px; border:0; border-radius:8px; font-weight:700; cursor:pointer; }
</style>
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">

    <h2><?= e(ucfirst($test_type) . ' Test') ?> — <?= e($test_date) ?></h2>

    <form method="post" action="<?= e(BASE_URL) ?>/attendee/submit_test.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <!-- send ALL test IDs (server can choose how to associate) -->
        <?php foreach ($testIds as $tid): ?>
            <input type="hidden" name="test_ids[]" value="<?= (int)$tid ?>">
        <?php endforeach; ?>

        <?php
        $currentClub = null;
        foreach ($questions as $i => $qq):
            if ($currentClub !== $qq['club_name']):
                $currentClub = $qq['club_name'];
        ?>
            <div class="club-header">
                <?= e($qq['club_name']) ?>
                <span class="club-tag"><?= e($qq['test_title']) ?></span>
            </div>
        <?php endif; ?>

        <div class="question-block">
            <p><strong>Q<?= $i + 1 ?>.</strong> <?= nl2br(e($qq['question_text'])) ?></p>

            <label><input type="radio" name="answer[<?= (int)$qq['qid'] ?>]" value="1" required> <?= e($qq['option_a']) ?></label>
            <label><input type="radio" name="answer[<?= (int)$qq['qid'] ?>]" value="2"> <?= e($qq['option_b']) ?></label>
            <label><input type="radio" name="answer[<?= (int)$qq['qid'] ?>]" value="3"> <?= e($qq['option_c']) ?></label>
            <label><input type="radio" name="answer[<?= (int)$qq['qid'] ?>]" value="4"> <?= e($qq['option_d']) ?></label>
        </div>
        <hr>

        <?php endforeach; ?>

        <div style="margin-top:14px;">
            <button type="submit" class="submit-btn">Submit Test</button>
        </div>
    </form>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
