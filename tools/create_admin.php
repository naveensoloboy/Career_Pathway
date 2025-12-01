<?php
// tools/create_admin.php
require_once __DIR__ . '/../config/config.php';

// -----------------------------
// CLI MODE
// -----------------------------
if (php_sapi_name() === 'cli') {
    echo "Enter Admin Roll Number: ";
    $roll = trim(fgets(STDIN));

    echo "Enter Admin Email: ";
    $email = trim(fgets(STDIN));

    echo "Enter Full Name: ";
    $full = trim(fgets(STDIN));

    echo "Enter Password: ";
    $password = trim(fgets(STDIN));

    if ($roll === '' || $email === '' || $password === '') {
        echo "All fields are required.\n";
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // create or update user table
    $stmt = $pdo->prepare('SELECT roll_no FROM users WHERE roll_no = :roll LIMIT 1');
    $stmt->execute([':roll' => $roll]);

    if ($stmt->fetch()) {
        $upd = $pdo->prepare('UPDATE users SET email = :email, password_hash = :pw, full_name = :full, role = :role, is_active = 1 WHERE roll_no = :roll');
        $upd->execute([':email' => $email, ':pw' => $hash, ':full' => $full, ':role' => 'admin', ':roll' => $roll]);
        echo "Updated existing user {$roll} as admin.\n";
    } else {
        $ins = $pdo->prepare('INSERT INTO users (roll_no, email, password_hash, full_name, role, class, is_active)
                              VALUES (:roll, :email, :pw, :full, :role, NULL, 1)');
        $ins->execute([':roll' => $roll, ':email' => $email, ':pw' => $hash, ':full' => $full, ':role' => 'admin']);
        echo "Created admin user {$roll}.\n";
    }

    // create or update admin table
    $stmt2 = $pdo->prepare('SELECT roll_no FROM admin WHERE roll_no = :roll LIMIT 1');
    $stmt2->execute([':roll' => $roll]);

    if ($stmt2->fetch()) {
        $updA = $pdo->prepare('UPDATE admin SET email = :email, full_name = :full WHERE roll_no = :roll');
        $updA->execute([':email' => $email, ':full' => $full, ':roll' => $roll]);
        echo "Updated admin table entry.\n";
    } else {
        $insA = $pdo->prepare('INSERT INTO admin (roll_no, email, full_name) VALUES (:roll, :email, :full)');
        $insA->execute([':roll' => $roll, ':email' => $email, ':full' => $full]);
        echo "Inserted into admin table.\n";
    }

    echo "Done.\n";
    exit;
}

// -----------------------------
// BROWSER MODE
// -----------------------------
$errors = [];
$success = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $roll  = trim($_POST['roll_no'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full  = trim($_POST['full_name'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($roll === '' || $email === '' || $password === '') {
        $errors[] = "Roll number, email, and password are required.";
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Check users table
        $stmt = $pdo->prepare('SELECT roll_no FROM users WHERE roll_no = :roll LIMIT 1');
        $stmt->execute([':roll' => $roll]);

        if ($stmt->fetch()) {
            $upd = $pdo->prepare('UPDATE users SET email = :email, password_hash = :pw, full_name = :full, role = :role, is_active = 1 WHERE roll_no = :roll');
            $upd->execute([':email' => $email, ':pw' => $hash, ':full' => $full, ':role' => 'admin', ':roll' => $roll]);
            $msg = "Updated existing user {$roll} as admin.";
        } else {
            $ins = $pdo->prepare('INSERT INTO users (roll_no, email, password_hash, full_name, role, class, is_active)
                                  VALUES (:roll, :email, :pw, :full, :role, NULL, 1)');
            $ins->execute([':roll' => $roll, ':email' => $email, ':pw' => $hash, ':full' => $full, ':role' => 'admin']);
            $msg = "Created admin user {$roll}.";
        }

        // Check admin table
        $stmt2 = $pdo->prepare('SELECT roll_no FROM admin WHERE roll_no = :roll LIMIT 1');
        $stmt2->execute([':roll' => $roll]);

        if ($stmt2->fetch()) {
            $updA = $pdo->prepare('UPDATE admin SET email = :email, full_name = :full WHERE roll_no = :roll');
            $updA->execute([':email' => $email, ':full' => $full, ':roll' => $roll]);
        } else {
            $insA = $pdo->prepare('INSERT INTO admin (roll_no, email, full_name) VALUES (:roll, :email, :full)');
            $insA->execute([':roll' => $roll, ':email' => $email, ':full' => $full]);
        }

        $success = $msg . " Also saved to admin table.";
    }
}
?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Create Admin</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <style>
        :root{
            --bg: #f6f8fb;
            --card: #ffffff;
            --muted: #64748b;
            --accent: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --radius: 12px;
            --maxw: 760px;
            font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }

        body{
            margin:0;
            background: linear-gradient(180deg,var(--bg), #ffffff);
            color: #0b1220;
            -webkit-font-smoothing:antialiased;
            -moz-osx-font-smoothing:grayscale;
            padding: 24px 12px;
        }

        .wrap{ max-width:var(--maxw); margin:0 auto; }

        header.app-header{
            display:flex;
            align-items:center;
            gap:12px;
            margin-bottom:18px;
        }
        header.app-header h1{ font-size:1.15rem; margin:0; }

        .card{
            background:var(--card);
            border-radius:var(--radius);
            padding:18px;
            box-shadow: 0 8px 30px rgba(15,23,42,0.05);
            border: 1px solid rgba(15,23,42,0.04);
        }

        .grid{ display:grid; gap:12px; }

        .form-row{ display:flex; flex-direction:column; gap:6px; margin-bottom:6px; }
        label{ font-weight:600; font-size:0.95rem; color:#0b1220; }
        input[type="text"], input[type="email"], input[type="password"]{
            padding:10px 12px;
            border-radius:8px;
            border:1px solid #e6eef8;
            background:#fbfdff;
            font-size:0.95rem;
            width:100%;
            box-sizing:border-box;
        }

        .actions{
            display:flex;
            gap:8px;
            margin-top:8px;
            flex-wrap:wrap;
        }

        .btn{
            display:inline-block;
            padding:10px 14px;
            border-radius:8px;
            font-weight:700;
            text-decoration:none;
            border:0;
            cursor:pointer;
            font-size:0.95rem;
        }

        .btn-primary{ background: linear-gradient(90deg,var(--accent), #1d4ed8); color:#fff; box-shadow:0 8px 20px rgba(37,99,235,0.08); }
        .btn-ghost{ background:transparent; border:1px solid #cfe3ff; color:var(--accent); }
        .note{ font-size:0.9rem; color:var(--muted); }

        .msg {
            padding:10px 12px;
            border-radius:8px;
            font-weight:600;
            display:flex;
            gap:10px;
            align-items:center;
        }
        .msg.error { background:#fff5f5; color:var(--danger); border:1px solid rgba(239,68,68,0.08); }
        .msg.success { background:#f0fdf4; color:var(--success); border:1px solid rgba(16,185,129,0.08); }

        ul.errors { margin:0; padding-left:18px; color:var(--danger); font-weight:600;}
        ul.errors li { margin:6px 0; font-weight:500; }

        .footer-note{ margin-top:12px; font-size:0.9rem; color:var(--muted); }

        @media (min-width:720px){
            .two-col { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
            .form-row.inline { flex-direction:row; gap:10px; align-items:center; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="app-header">
        <h1>Create Admin User</h1>
    </header>

    <div class="card grid">
        <?php if (!empty($errors)): ?>
            <div class="msg error">
                <div>
                    <strong><?= htmlspecialchars(count($errors)) ?> error(s)</strong>
                    <div style="margin-top:6px;">
                        <ul class="errors">
                            <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="msg success">
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <form method="post" class="grid" novalidate>
            <div class="form-row">
                <label for="roll_no">Roll Number *</label>
                <input id="roll_no" name="roll_no" type="text" required value="<?= htmlspecialchars($_POST['roll_no'] ?? '') ?>">
            </div>

            <div class="form-row">
                <label for="email">Email *</label>
                <input id="email" name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="two-col">
                <div class="form-row">
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" type="text" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <label for="password">Password *</label>
                    <input id="password" name="password" type="password" required>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Create / Update Admin</button>
                <a href="<?= htmlspecialchars(BASE_URL) ?>" class="btn btn-ghost" style="display:inline-flex;align-items:center;justify-content:center;">Back to site</a>
            </div>

            <div class="footer-note">
                <div class="note">This creates or updates a user row and an admin table entry. Passwords are hashed using PHP's password_hash().</div>
            </div>
        </form>
    </div>
</div>
</body>
</html>
