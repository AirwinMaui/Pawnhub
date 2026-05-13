<?php
require 'mailer.php';
$result = sendMail('mendozakiaro@gmail.com', 'Test', 'Test Email', '<p>Test lang</p>');
echo $result ? 'EMAIL SENT!' : 'FAILED: ' . error_get_last()['message'];