<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Domain\Mail\MailTransport;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class PHPMailerMailTransport implements MailTransport
{
    public function requiresSecret(): bool
    {
        return true;
    }

    public function send(array $message, array $account): array
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->SMTPDebug = 0;
            $mailer->isSMTP();
            $mailer->Host = (string) $account['smtp_host'];
            $mailer->Port = (int) $account['smtp_port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = (string) $account['smtp_username'];
            $mailer->Password = (string) $account['smtp_password'];
            $mailer->Timeout = 20;

            $encryption = (string) $account['smtp_encryption'];
            if ($encryption === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->setFrom((string) $account['from_email'], (string) $account['from_name']);
            if (($account['reply_to_email'] ?? null) !== null && $account['reply_to_email'] !== '') {
                $mailer->addReplyTo((string) $account['reply_to_email']);
            }
            foreach ($message['to'] as $email) {
                $mailer->addAddress($email);
            }
            foreach ($message['cc'] as $email) {
                $mailer->addCC($email);
            }
            foreach ($message['bcc'] as $email) {
                $mailer->addBCC($email);
            }

            $mailer->Subject = $message['subject'];
            $mailer->isHTML(true);
            $mailer->Body = $message['html'];
            $mailer->AltBody = $message['text'];
            $mailer->send();

            return ['sent' => true, 'error_type' => null];
        } catch (PHPMailerException $exception) {
            return ['sent' => false, 'error_type' => $this->classify($exception)];
        } catch (Throwable) {
            return ['sent' => false, 'error_type' => 'unexpected'];
        }
    }

    private function classify(PHPMailerException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'authenticate')) {
            return 'authentication';
        }
        if (str_contains($message, 'recipient') || str_contains($message, 'address')) {
            return 'recipient';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'connect') || str_contains($message, 'smtp')) {
            return 'connection';
        }

        return 'unexpected';
    }
}
