<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

class MailService {
    public static function send(string $to, string $subject, string $body): bool {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$_ENV['SMTP_PORT'];

        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        return (bool)$mail->send();
    }
}