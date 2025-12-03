<?php
// club/alumni_register.php
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

// check it is alumni club (handles Alumni / Alumini)
$clubNameRaw  = trim($clubRow['name'] ?? '');
$isAlumniClub = in_array(strtolower($clubNameRaw), ['alumni', 'alumini'], true);
if (!$isAlumniClub) {
    http_response_code(403);
    echo "This page is only for the Alumni club.";
    exit;
}

// get user's club role
$roleInfo = get_club_role($pdo, $user_roll, $club_id);
if (!$roleInfo || !in_array($roleInfo['role'], ['club_secretary','club_joint_secretary'], true)) {
    http_response_code(403);
    echo "Access denied. Only club secretary / joint secretary can register alumni.";
    exit;
}

$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $name          = trim($_POST['name'] ?? '');
        $batch         = trim($_POST['batch'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $linkedin_url  = trim($_POST['linkedin_url'] ?? '');
        $naukri_url    = trim($_POST['naukri_url'] ?? '');
        $instagram_url = trim($_POST['instagram_url'] ?? '');
        $current_role  = trim($_POST['current_role'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');

        // basic validation
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($batch === '') {
            $errors[] = 'Batch is required (e.g., 2018–2021).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        // optional: basic URL format check
        $urlFields = [
            'linkedin_url'  => $linkedin_url,
            'naukri_url'    => $naukri_url,
            'instagram_url' => $instagram_url,
        ];
        foreach ($urlFields as $label => $val) {
            if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                $errors[] = ucfirst(str_replace('_', ' ', $label)) . ' must be a valid URL.';
            }
        }

        if (empty($errors)) {
            try {
                $ins = $pdo->prepare("
                    INSERT INTO alumni_contacts
                        (club_id, name, batch, phone, email, linkedin_url, naukri_url, instagram_url, current_role, notes)
                    VALUES
                        (:cid, :name, :batch, :phone, :email, :linkedin_url, :naukri_url, :instagram_url, :current_role, :notes)
                ");
                $ins->execute([
                    ':cid'           => $club_id,
                    ':name'          => $name,
                    ':batch'         => $batch,
                    ':phone'         => $phone ?: null,
                    ':email'         => $email ?: null,
                    ':linkedin_url'  => $linkedin_url ?: null,
                    ':naukri_url'    => $naukri_url ?: null,
                    ':instagram_url' => $instagram_url ?: null,
                    ':current_role'  => $current_role ?: null,
                    ':notes'         => $notes ?: null,
                ]);

                $success = 'Alumni details saved successfully.';
                // clear POST values after success
                $_POST = [];
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$csrf = csrf_token();

function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= esc($clubRow['name']) ?> Club — Register Alumni</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .wrap {
            max-width: 800px;
            margin: 20px auto;
        }
        .card {
            background:#fff;
            border-radius:12px;
            border:1px solid #e5e7eb;
            padding:18px;
            box-shadow:0 8px 24px rgba(15,23,42,0.04);
        }
        .form-row {
            margin-bottom:12px;
        }
        label {
            display:block;
            font-weight:600;
            margin-bottom:4px;
        }
        input[type="text"],
        input[type="email"],
        textarea {
            width:100%;
            box-sizing:border-box;
            padding:8px 10px;
            border-radius:8px;
            border:1px solid #e5e7eb;
        }
        textarea {
            min-height:80px;
            resize:vertical;
        }
        .btn {
            padding:8px 14px;
            border-radius:8px;
            border:0;
            font-weight:600;
            cursor:pointer;
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
        .errors ul {
            margin:0;
            padding-left:18px;
            color:#b91c1c;
            font-weight:500;
        }
        .success {
            color:#065f46;
            font-weight:600;
            margin-bottom:10px;
        }
        .muted {
            color:#6b7280;
            font-size:0.9rem;
        }
        @media (min-width: 720px) {
            .grid-2 {
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:12px;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container">
    <div class="wrap">
        <h2><?= esc($clubRow['name']) ?> Club — Register Alumni</h2>
        <p class="muted">
            Use this form to record alumni details such as contact info and current role.
        </p>
        <br>

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

        <div class="card">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">

                <div class="form-row">
                    <label for="name">Alumni Name *</label>
                    <input type="text" id="name" name="name"
                           value="<?= esc($_POST['name'] ?? '') ?>" required>
                </div>

                <div class="grid-2">
                    <div class="form-row">
                        <label for="batch">Batch (e.g., 2018–2021) *</label>
                        <input type="text" id="batch" name="batch"
                               value="<?= esc($_POST['batch'] ?? '') ?>" required>
                    </div>
                    <div class="form-row">
                        <label for="phone">Phone</label>
                        <input type="text" id="phone" name="phone"
                               value="<?= esc($_POST['phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-row">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?= esc($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="current_role">Current Role / Company</label>
                        <input type="text" id="current_role" name="current_role"
                               value="<?= esc($_POST['current_role'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <label for="linkedin_url">LinkedIn Profile URL</label>
                    <input type="text" id="linkedin_url" name="linkedin_url"
                           placeholder="https://www.linkedin.com/in/..."
                           value="<?= esc($_POST['linkedin_url'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <label for="naukri_url">Naukri Profile URL</label>
                    <input type="text" id="naukri_url" name="naukri_url"
                           value="<?= esc($_POST['naukri_url'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <label for="instagram_url">Instagram Profile URL</label>
                    <input type="text" id="instagram_url" name="instagram_url"
                           value="<?= esc($_POST['instagram_url'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <label for="notes">Notes (interests, domain, comments)</label>
                    <textarea id="notes" name="notes"><?= esc($_POST['notes'] ?? '') ?></textarea>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                    <button type="submit" class="btn btn-primary">Save Alumni</button>
                    <a href="<?= esc(BASE_URL) ?>/club/dashboard.php?club_id=<?= (int)$club_id ?>"
                       class="btn btn-ghost">Back to Club Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
