<?php
// club/create_test.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'];
$club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : intval($_POST['club_id'] ?? 0);
if ($club_id <= 0) die('Club not specified.');

// ensure user is member of club
$role = get_club_role($pdo, $user_roll, $club_id);
if (!$role) die('You are not a member of this club.');

// posting privilege required
if (!$role['can_post_questions'] && !is_admin($pdo)) {
    die('You do not have permission to create tests. Request posting rights from admin.');
}

$errors = [];
$success = null;
$createdId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) $errors[] = 'Invalid request.';

    $title = trim($_POST['title'] ?? '');
    $test_type = $_POST['test_type'] ?? '';
    $test_date = $_POST['test_date'] ?? '';

    if ($title === '') $errors[] = 'Title required.';
    if (!in_array($test_type, ['daily','weekly'], true)) $errors[] = 'Select test type.';
    if (!$test_date || !DateTime::createFromFormat('Y-m-d', $test_date)) $errors[] = 'Select a valid date.';

    // weekly must be saturday
    if (empty($errors) && $test_type === 'weekly') {
        $dt = new DateTime($test_date);
        if ((int)$dt->format('N') !== 6) $errors[] = 'Weekly test date must be a Saturday.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO tests (club_id, title, test_type, test_date, created_by_roll, active) VALUES
                                   (:cid, :title, :tt, :td, :creator, 1)");
            $stmt->execute([
                ':cid' => $club_id,
                ':title' => $title,
                ':tt' => $test_type,
                ':td' => $test_date,
                ':creator' => $user_roll
            ]);
            $success = 'Test created. Redirected to Questions Page.';
            $createdId = $pdo->lastInsertId();
        } catch (Exception $e) {
            if ($e->getCode() == 23000) {
                $errors[] = 'A test for this date already exists.';
            } else {
                $errors[] = 'DB error: ' . $e->getMessage();
            }
        }
    }
}

$csrf = csrf_token();

// simple fetch of club
$club = $pdo->prepare('SELECT id, name FROM clubs WHERE id = :id LIMIT 1');
$club->execute([':id' => $club_id]);
$clubRow = $club->fetch();
if (!$clubRow) die('Club not found.');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Create Test — <?= e($clubRow['name']) ?></title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/main.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/header.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/footer.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/public/css/create_club.css">
    <script>
function enforceSaturday() {
    const type = document.querySelector('select[name="test_type"]').value;
    const dateInput = document.querySelector('input[name="test_date"]');

    if (type === 'weekly') {
        dateInput.addEventListener('change', function () {
            const chosen = new Date(this.value);
            const day = chosen.getUTCDay(); // 6 = Saturday

            if (day !== 6) {
                alert("Weekly test date must be a Saturday.");
                this.value = ""; // reset value
            }
        });
    } 
}

// Run after page loads
document.addEventListener('DOMContentLoaded', enforceSaturday);

// Re-run when type changes
document.addEventListener('change', function (e) {
    if (e.target.name === "test_type") {
        enforceSaturday(); 
    }
});
</script>

</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<?php
// Show alerts (errors or success). Use json_encode to safely escape text for JS.
if (!empty($errors)) {
    $jsMsg = implode("\n", $errors);
    echo '<script>alert(' . json_encode($jsMsg) . ');</script>';
}

if ($success) {
    $msg = $success;
    // If createdId is present, redirect to add_questions for that test after alert
    if ($createdId) {
        $redirectUrl = e(BASE_URL) . '/club/add_questions.php?club_id=' . (int)$club_id . '&test_id=' . (int)$createdId;
        echo '<script>';
        echo 'alert(' . json_encode($msg) . ');';
        echo 'window.location.href = ' . json_encode($redirectUrl) . ';';
        echo '</script>';
        // stop further rendering to avoid duplicate UI; exit after redirect script
        exit;
    } else {
        echo '<script>alert(' . json_encode($msg) . ');</script>';
    }
}
?>

<main class="container">
    <h2>Create Test for <?= e($clubRow['name']) ?></h2>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">

        <label>Test Title</label>
        <input type="text" name="title" required value="<?= e($_POST['title'] ?? '') ?>">

        <label>Type</label>
        <select name="test_type" required>
            <option value="daily" <?= (($_POST['test_type'] ?? '') === 'daily') ? 'selected' : '' ?>>Daily</option>
            <option value="weekly" <?= (($_POST['test_type'] ?? '') === 'weekly') ? 'selected' : '' ?>>Weekly (Saturday)</option>
        </select>

        <label>Date</label>
        <input type="date" name="test_date" required value="<?= e($_POST['test_date'] ?? '') ?>">

        <div style="margin-top:12px;">
            <button type="submit" class="btn">Create Test</button>
        </div>
    </form>

    <?php if ($success && $createdId): ?>
        <p style="margin-top:12px;">Test ID: <?= (int)$createdId ?></p>
        <p><a class="btn secondary" href="<?= e(BASE_URL) ?>/club/add_questions.php?club_id=<?= (int)$club_id ?>&test_id=<?= (int)$createdId ?>">Add questions to this test</a></p>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
