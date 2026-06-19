<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/PHPMailer-master/src/SMTP.php';

function sendOTP($email, $name, $otp)
{
    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

        $mail->Username   = 'kamaudennis833@gmail.com';

        // Gmail App Password
        $mail->Password   = 'sfnf fixe kews irdk';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(
            'kamaudennis833@gmail.com',
            'Learning Management System'
        );

        $mail->addAddress($email, $name);

        $mail->isHTML(true);

        $mail->Subject = "Your OTP Verification Code";

        $mail->Body = "
        <div style='font-family:Arial,sans-serif'>
            <h2>Learning Management System</h2>

            <p>Hello <b>{$name}</b>,</p>

            <p>Your OTP code is:</p>

            <h1 style='color:#28a745'>{$otp}</h1>

            <p>This code expires in 10 minutes.</p>

            <p><b>Do not share this OTP with anyone.</b></p>
        </div>
        ";

        $mail->AltBody =
            "Hello {$name}, Your OTP code is {$otp}. This code expires in 10 minutes.";

        return $mail->send();

    } catch (Exception $e) {

        error_log($mail->ErrorInfo);
        return false;
    }
}