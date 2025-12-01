<?php
require_once __DIR__ . '/config/phpmailer_config.php';

$to      = 'careerpathway2k25@gmail.com';
$subject = 'PHPMailer test';
$html    = '<h2>Hello from PHPMailer</h2><p>This is a test email.</p>';

$result = sendMail($to, $subject, $html);

if ($result['success']) {
    echo '<p style="color:green;">Mail sent successfully</p>';
} else {
    echo '<p style="color:red;">Error: ' . htmlspecialchars($result['error']) . '</p>';
}
