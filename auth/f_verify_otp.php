<!-- f_verify_otp.php -->
<?php
session_start();

require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['step']) || $_SESSION['step'] !== 'verify_otp') {
    header('Location: f_request_otp.php'); // Redirect to index if accessed directly
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Step 4: Verify OTP
    if ($_POST['otp'] == $_SESSION['otp'] && time() <= $_SESSION['otp_expiry']) {
        $_SESSION['otp_verified'] = true;
        $_SESSION['step'] = 'reset_password'; // Proceed to reset password
    } else {
        $error_message = "<script>alert('OTP Expired or Invalid.'); window.location.href='forgot_password.php';</script>";
    }
}

?>

<style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f9f9f9;
        color: #333;
        margin: 0;
        padding: 0;
    }

    .container {
        max-width: 400px;
        margin: 50px auto;
        padding: 20px;
        background: white;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
    }

    h2 {
        text-align: center;
        color: #007bff;
    }

    label {
        display: block;
        margin: 10px 0 5px;
    }

    input[type="text"],
    input[type="password"] {
        width: 100%;
        padding: 10px;
        margin: 10px 0;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
    }

    button {
        width: 100%;
        padding: 10px;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        font-size: 16px;
        cursor: pointer;
    }

    button:hover {
        background-color: #0056b3;
    }

    .error {
        color: red;
        font-size: 14px;
        text-align: center;
    }

    #timer {
        text-align: center;
        font-size: 14px;
        margin-top: 10px;
        color: #888;
    }
</style>

<div class="container">
    <?php if (isset($error_message)): ?>
        <div class="error"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['otp_verified'])): ?>
        <!-- Password Reset Form -->
        <h2>Reset Password</h2>
        <form method="POST" action="f_reset_password.php">
            <label>New Password:</label>
            <input type="password" name="password" required>
            <label>Confirm Password:</label>
            <input type="password" name="confirm_password" required>
            <button type="submit">Reset Password</button>
        </form>
    <?php else: ?>
        <!-- OTP Verification Form -->
        <h2>Verify OTP</h2>
        <form method="POST">
            <label>Enter OTP:</label>
            <input type="text" name="otp" required>
            <p id="timer">Time left: 60 seconds</p>
            <button type="submit">Verify OTP</button>
        </form>
        <script>
            // Timer for OTP expiry
            let timer = 60;
            const timerElement = document.getElementById("timer");
            setInterval(function() {
                if (timer > 0) {
                    timer--;
                    timerElement.textContent = `Time left: ${timer} seconds`;
                } else {
                    timerElement.textContent = "OTP expired.";
                    // Optionally, redirect or disable form submission here
                }
            }, 1000);
        </script>
    <?php endif; ?>
</div>
