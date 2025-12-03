<?php
// club/dashboard.php
require_once __DIR__ . '/../config/config.php';
require_login();

$user_roll = $_SESSION['user_roll'];
$club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : 0;
if ($club_id <= 0) {
    die('Club not specified.');
}

// load club
$club = $pdo->prepare('SELECT * FROM clubs WHERE id = :id LIMIT 1');
$club->execute([':id' => $club_id]);
$clubRow = $club->fetch();
if (!$clubRow) die('Club not found.');

// detect alumni club (handle both Alumni / Alumini names)
$clubNameRaw   = trim($clubRow['name'] ?? '');
$isAlumniClub  = in_array(strtolower($clubNameRaw), ['alumni', 'alumini'], true);

// get user's role in club
$roleInfo = get_club_role($pdo, $user_roll, $club_id);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= e($clubRow['name']) ?> — Club Dashboard</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <link rel="stylesheet" href="/public/css/dashboard.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <h2><?= e($clubRow['name'] ?? '') ?> Club — Dashboard</h2>
    <p><?= e($clubRow['description'] ?? '') ?></p>

    <?php if (!$roleInfo): ?>
        <p>You are not a member of this club. Contact admin to add you.</p>
    <?php else: ?>
        <br><br>

        <?php if ($isAlumniClub): ?>
            <!-- =========================
                 ALUMNI CLUB VIEW
                 ========================= -->
            <!-- <h3 style="text-align:center;">Your role: <strong><?= e($roleInfo['role']) ?></strong></h3><br> -->
            <!-- <p>
                This club manages alumni connections: collecting details (name, batch, phone, LinkedIn, Naukri, Instagram),
                coordinating meetings, mock interviews, and internship / opportunity referrals.
            </p> -->

            <div class="cards">
                <div class="card">
                    <h3>Register Alumni</h3>
                    <p>Collect and store alumni details such as name, batch, phone, LinkedIn, Naukri, Instagram, etc.</p>
                    <!-- TODO: create this page -->
                    <a href="<?= e(BASE_URL) ?>/club/alumni_register.php?club_id=<?= (int)$club_id ?>">Open</a>
                </div>

                <div class="card">
                    <h3>Alumni Directory</h3>
                    <p>View list of alumni, filter by batch / department / field and get their contact links.</p>
                    <!-- TODO: create this page -->
                    <a href="<?= e(BASE_URL) ?>/club/alumni_directory.php?club_id=<?= (int)$club_id ?>">Open</a>
                </div>

                <div class="card">
                    <h3>Interaction & Meetings</h3>
                    <p>Plan alumni talks, mentoring sessions, and schedule meetings with current students.</p>
                    <!-- TODO: create this page -->
                    <a href="<?= e(BASE_URL) ?>/club/alumni_meetings.php?club_id=<?= (int)$club_id ?>">Open</a>
                </div>

                <div class="card">
                    <h3>Mock Interviews</h3>
                    <p>Arrange mock interviews by alumni or club officers and track feedback for students.</p>
                    <!-- TODO: create this page -->
                    <!-- <a href="<?= e(BASE_URL) ?>/club/alumni_mock_interviews.php?club_id=<?= (int)$club_id ?>">Open</a> -->
                    <a href="/public/under_construction.php">Open</a>
                </div>

                <div class="card">
                    <h3>Opportunities & Internships</h3>
                    <p>Record internships, job referrals and opportunities shared by alumni.</p>
                    <!-- TODO: create this page -->
                    <!-- <a href="<?= e(BASE_URL) ?>/club/alumni_opportunities.php?club_id=<?= (int)$club_id ?>">Open</a> -->
                    <a href="/public/under_construction.php">Open</a>
                </div>
            </div>

        <?php else: ?>
            <!-- =========================
                 NORMAL (NON-ALUMNI) CLUB VIEW
                 ========================= -->
            <!-- <h3 style="text-align:center;">Your role: <strong><?= e($roleInfo['role']) ?></strong></h3><br> -->

            <div class="cards">
                <?php if (in_array($roleInfo['role'], ['club_secretary','club_joint_secretary'], true) && $roleInfo['can_post_questions']): ?>
                    <div class="card">
                        <h3>Create Test</h3>
                        <p>Create a daily or weekly test.</p>
                        <a href="<?= e(BASE_URL) ?>/club/create_test.php?club_id=<?= (int)$club_id ?>">Open</a>
                    </div>

                    <div class="card">
                        <h3>Test Status</h3>
                        <p>View who attended a test and download results.</p>
                        <a href="<?= e(BASE_URL) ?>/club/test_status.php?club_id=<?= (int)$club_id ?>">Open</a>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <h3>Add Questions (pending)</h3>
                    <p>Submit MCQs for admin approval.</p>
                    <a href="<?= e(BASE_URL) ?>/club/add_questions.php?club_id=<?= (int)$club_id ?>">Open</a>
                </div>
                
                <?php if (in_array($roleInfo['role'], ['club_secretary','club_joint_secretary'], true)): ?>
                    <div class="card">
                        <h3>Add Club Members</h3>
                        <p>Add members, set role and posting rights.</p>
                        <a href="<?= e(BASE_URL) ?>/club/manage_role.php?club_id=<?= (int)$club_id ?>">Open</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>




