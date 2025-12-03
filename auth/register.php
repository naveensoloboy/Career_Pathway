<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/phpmailer_config.php';

$errors = [];
$success = null;
$otpSuccess = null;
$otpError = null;
$showOtpForm = false;
$pendingRollNo = null;

// if logged in, redirect
if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/attendee/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {

        /**
         * STEP 2: OTP VERIFICATION FLOW
         * --------------------------------
         * If user submitted OTP form, verify and activate account.
         */
        if (isset($_POST['verify_otp'])) {
            $roll_no = trim($_POST['roll_no'] ?? '');
            $otp     = trim($_POST['otp'] ?? '');

            if ($roll_no === '' || $otp === '') {
                $otpError = 'Roll number or OTP missing.';
            } else {

                // Find OTP entry for this roll_no that has not expired
                $stmt = $pdo->prepare('SELECT token_hash, expires_at FROM email_verifications WHERE roll_no = :roll LIMIT 1');
                $stmt->execute([':roll' => $roll_no]);
                $row = $stmt->fetch();

                if (!$row) {
                    $otpError = 'No active OTP found or it has expired. Please register again or ask for a new verification.';
                } else {
                    $now = new DateTime();
                    $exp = new DateTime($row['expires_at']);

                    if ($now > $exp) {
                        $otpError = 'OTP has expired. Please register again.';
                    } else {
                        $otpHash = hash('sha256', $otp);

                        if (!hash_equals($row['token_hash'], $otpHash)) {
                            $otpError = 'Invalid OTP. Please try again.';
                            $showOtpForm = true;
                            $pendingRollNo = $roll_no;
                        } else {
                            // OTP is valid: activate user and remove verification row
                            $pdo->beginTransaction();

                            $upd = $pdo->prepare('UPDATE users SET is_active = 1 WHERE roll_no = :roll');
                            $upd->execute([':roll' => $roll_no]);

                            $del = $pdo->prepare('DELETE FROM email_verifications WHERE roll_no = :roll');
                            $del->execute([':roll' => $roll_no]);

                            $pdo->commit();

                            $otpSuccess = 'Your email has been verified and your account is now active. You can log in.';
                        }
                    }
                }
            }

        /**
         * STEP 1: REGISTRATION + OTP SEND FLOW
         * ------------------------------------
         * First submit: create user, create OTP, email OTP.
         */
        } else {
            $roll_no   = trim($_POST['roll_no'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $password  = $_POST['password']  ?? '';
            $password2 = $_POST['password2'] ?? '';
            $full_name = trim($_POST['full_name'] ?? '');
            $class     = trim($_POST['class'] ?? '');
            $batch     = trim($_POST['batch'] ?? '');

            if ($roll_no === '')           $errors[] = 'Roll number is required.';
            if ($full_name === '')         $errors[] = 'Full name is required.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
            if (strlen($password) < 6)     $errors[] = 'Password must be at least 6 characters.';
            if ($password !== $password2)  $errors[] = 'Passwords do not match.';

            if (empty($errors)) {
                // check if roll or email exists
                $stmt = $pdo->prepare('SELECT roll_no FROM users WHERE roll_no = :roll OR email = :email LIMIT 1');
                $stmt->execute([':roll' => $roll_no, ':email' => $email]);
                if ($stmt->fetch()) {
                    $errors[] = 'Roll number or email already registered.';
                } else {
                    $pwHash = password_hash($password, PASSWORD_DEFAULT);

                    try {
                        $pdo->beginTransaction();

                        // create user as inactive until OTP verification
                        $ins = $pdo->prepare('INSERT INTO users (roll_no, email, password_hash, full_name, role, class, batch, is_active) 
                                              VALUES (:roll, :email, :pw, :full, :role, :class, :batch, 0)');
                        $ins->execute([
                            ':roll'  => $roll_no,
                            ':email' => $email,
                            ':pw'    => $pwHash,
                            ':full'  => $full_name,
                            ':role'  => 'attendee',
                            ':class' => $class,
                            ':batch' => $batch
                        ]);

                        // create 6-digit OTP and store its hash in email_verifications
                        $otp = random_int(100000, 999999);
                        $otpHash = hash('sha256', (string)$otp);
                        $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

                        // remove any previous tokens for this roll and insert new
                        $del = $pdo->prepare('DELETE FROM email_verifications WHERE roll_no = :roll');
                        $del->execute([':roll' => $roll_no]);

                        $insToken = $pdo->prepare('INSERT INTO email_verifications (roll_no, token_hash, expires_at) 
                                                   VALUES (:roll, :th, :exp)');
                        $insToken->execute([
                            ':roll' => $roll_no,
                            ':th'   => $otpHash,
                            ':exp'  => $expiresAt
                        ]);

                        $pdo->commit();

                        // build OTP email (no link, just code)
                        $subject = 'Career pathway verification code';
                        $html  = "<p>Hello " . e($full_name) . ",</p>";
                        $html .= "<p>Your verification code for career pathway is:</p>";
                        $html .= "<p><strong>" . e($otp) . "</strong></p>";
                        $html .= "<p>This code will expire in 24 hours. If you didn't register, you can ignore this email.</p>";

                        // use the simple sendMail() that returns true/false
                        $mailOk = sendMail($email, $subject, $html);

                        // Force OTP form display after registration
                        $showOtpForm = true;
                        $pendingRollNo = $roll_no;

                        if ($mailOk) {
                            $success = 'Registration successful. An OTP has been sent to your email. Enter it below to activate your account.';
                        } else {
                            $success = 'Registration successful, but we could not send the OTP email. Please contact admin or try again later.';
                        }

                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'Database error: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// prepare csrf token
$token = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Register — Career Pathway</title>
    <link rel="stylesheet" href="/public/css/main.css">
    <link rel="stylesheet" href="/public/css/header.css">
    <link rel="stylesheet" href="/public/css/footer.css">
    <style>
        .container1 {
            max-width: 580px;
            margin: 25px auto;
            background: #ffffff;
            padding: 20px 22px;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            font-family: "Inter", "Segoe UI", Arial, sans-serif;
        }
        .container1 h2 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.6rem;
            color: #1e293b;
            text-align: center;
        }
        .container1 label {
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
            color: #374151;
        }
        .container1 input[type="text"],
        .container1 input[type="email"],
        .container1 input[type="password"],
        .container1 input[type="number"] {
            width: 100%;
            padding: 12px 14px;
            font-size: 0.95rem;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            transition: 0.2s ease;
        }
        .container1 input:focus {
            border-color: #2563eb;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.18);
            outline: none;
        }
        .form-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
        }
        .container1 button {
            width: 100%;
            padding: 12px 14px;
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(90deg, #2563eb, #1d4ed8);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            margin-top: 5px;
            transition: 0.2s ease;
        }
        .container1 button:hover { opacity: 0.95; }
        .container1 button:active { transform: scale(0.98); }
        .errors {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 12px 14px;
            margin-bottom: 18px;
            border-radius: 8px;
            color: #b91c1c;
        }
        .errors ul {
            margin: 0;
            padding-left: 18px;
        }
        .success {
            background: #dcfce7;
            border-left: 4px solid #22c55e;
            padding: 12px 14px;
            margin-bottom: 15px;
            border-radius: 8px;
            color: #166534;
            font-weight: 600;
        }
        .container1 a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
        .container1 a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            .container1 {
                margin: 12px;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container1">
    <h2>Register</h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e) echo '<li>' . e($e) . '</li>'; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($otpError): ?>
        <div class="errors">
            <ul><li><?= e($otpError) ?></li></ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($otpSuccess): ?>
        <div class="success"><?= e($otpSuccess) ?></div>
        <p><a href="<?= e(BASE_URL) ?>/auth/login.php">Go to Login</a></p>
    <?php endif; ?>

    <?php if (!$otpSuccess): ?>
        <div class="form-card">
            <?php if ($showOtpForm): ?>
                <!-- OTP Verification Form -->
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="roll_no" value="<?= e($pendingRollNo ?? ($_POST['roll_no'] ?? '')) ?>">

                    <label>Enter the OTP sent to your email</label><br>
                    <input type="number" name="otp" required><br><br>

                    <button type="submit" name="verify_otp">Verify OTP</button>
                </form>
            <?php else: ?>
                <!-- Registration Form -->
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

                    <label>Roll Number *</label><br>
                    <input type="text" name="roll_no" value="<?= e($_POST['roll_no'] ?? '') ?>" required><br><br>

                    <label>Full Name *</label><br>
                    <input type="text" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" required><br><br>

                    <label>Class</label><br>
                    <select name="class">
                        <option value="">Select your class</option>
                        <option value="I - MCA"            <?= (($_POST['class'] ?? '') === 'I - MCA') ? 'selected' : '' ?>>I - MCA</option>
                        <option value="II - MCA"           <?= (($_POST['class'] ?? '') === 'II - MCA') ? 'selected' : '' ?>>II - MCA</option>
                        <option value="I - B.Sc (CS)"      <?= (($_POST['class'] ?? '') === 'I - B.Sc (CS)') ? 'selected' : '' ?>>I - B.Sc (CS)</option>
                        <option value="II - B.Sc (CS)"     <?= (($_POST['class'] ?? '') === 'II - B.Sc (CS)') ? 'selected' : '' ?>>II - B.Sc (CS)</option>
                        <option value="III - B.Sc (CS)"    <?= (($_POST['class'] ?? '') === 'III - B.Sc (CS)') ? 'selected' : '' ?>>III - B.Sc (CS)</option>
                    </select><br><br>

                    <label>Batch * (Example : 2025-2027 or 2025-2028)</label>
                    <input type="text" name="batch" value="<?= e($_POST['batch'] ?? '') ?>" pattern="\d{4}-\d{4}"><br><br>

                    <label>Email *</label><br>
                    <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required><br><br>

                    <label>Password *</label><br>
                    <input type="password"
                           name="password"
                           pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[!@#$%^&*]).{8,}"
                           title="Password must have at least 1 capital letter, 1 number, 1 special character and minimum 8 characters."
                           required><br><br>

                    <label>Confirm Password *</label><br>
                    <input type="password"
                           name="password2"
                           pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[!@#$%^&*]).{8,}"
                           title="Password must have at least 1 capital letter, 1 number, 1 special character and minimum 8 characters."
                           required><br><br>

                    <button type="submit">Register</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
