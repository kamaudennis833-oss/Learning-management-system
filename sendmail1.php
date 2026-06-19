<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__.'/PHPMailer/PHPMailer-master/src/Exception.php';
require __DIR__.'/PHPMailer/PHPMailer-master/src/PHPMailer.php';
require __DIR__.'/PHPMailer/PHPMailer-master/src/SMTP.php';

function sendOTP($email, $name, $otp)
{
    $mail = new PHPMailer(true);

    try {

        // SMTP CONFIG
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = 'kamaudennis833@gmail.com';
        $mail->Password = 'sfnf fixe kews irdk'; // ⚠️ use app password

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // SENDER
        $mail->setFrom('kamaudennis833@gmail.com', 'Learning Management System');

        // RECEIVER
        $mail->addAddress($email, $name);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = "Email Verification OTP - LMS";

        $mail->Body = "
            <div style='font-family:Arial;padding:20px'>
                <h2>Learning Management System</h2>

                <p>Hello <b>{$name}</b>,</p>

                <p>Your OTP verification code is:</p>

                <h1 style='color:green;font-size:32px;'>{$otp}</h1>

                <p>This code expires in <b>5–10 minutes</b>.</p>

                <hr>
                <p style='font-size:12px;color:gray'>
                    If you did not request this, ignore this email.
                </p>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}
?>