<?php
require 'mailer.php';
$result = sendMail('mendozalesterjames7@gmail.com', 'Test', 'Test Email', '<p>Test lang</p>');
echo $result ? 'EMAIL SENT!' : 'FAILED: ' . error_get_last()['message'] ?? 'unknown error';

// Show PHPMailer error directly
use PHPMailer\PHPMailer\PHPMailer;
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'mendozalesterjames7@gmail.com';
    $mail->Password   = 'kwrussfymluo wcfe';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;
    $mail->setFrom('mendozalesterjames7@gmail.com', 'Test');
    $mail->addAddress('mendozalesterjames7@gmail.com');
    $mail->Subject = 'Test';
    $mail->Body    = 'Test';
    $mail->send();
    echo '<br>DIRECT TEST: SENT!';
} catch (Exception $e) {
    echo '<br>DIRECT ERROR: ' . $mail->ErrorInfo;
}