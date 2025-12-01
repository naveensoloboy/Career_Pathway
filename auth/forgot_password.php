<!-- request_otp.php -->
<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/phpmailer_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Step 2: Validate Email and Staff ID
    $email = $_POST['email'];
    $roll_no = $_POST['roll_no'];

    // Check if the email and staff ID match in the database
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e AND roll_no = :r limit 1');
    $stmt->execute([':e'=> $email, ':r' => $roll_no]);
    
    

    if ($stmt->fetch()) {
        // Step 3: Generate and Send OTP
        $otp = rand(100000, 999999); // Generate a 6-digit OTP
        $_SESSION['otp'] = $otp;
        $_SESSION['otp_expiry'] = time() + 60; // Set OTP expiry to 1 minute
        $_SESSION['email'] = $email; // Store email for later use

        // Send OTP using PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'naveen9222777@gmail.com'; // Your email address
            $mail->Password = 'dvxb snoo mosq ntwn'; // Your email password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('your_email@example.com', 'Your App');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'Your OTP Code';
            $mail->Body = "Your OTP is <b>$otp</b>. It is valid for 1 minute.";

            $mail->send();
            $_SESSION['step'] = 'verify_otp'; // Move to next step
            header('Location: f_verify_otp.php');
            exit();
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        echo "<script>alert('Invalid email or roll no.');</script>";
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
    input[type="email"] {
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
</style>

<div class="container">
    <h2>Request OTP</h2>
    <form method="POST">
        <label>Roll No.:</label>
        <input type="text" name="roll_no" required>
        
        <label>Email:</label>
        <input type="email" name="email" required>
        
        <button type="submit">Request OTP</button>
    </form>
</div>
