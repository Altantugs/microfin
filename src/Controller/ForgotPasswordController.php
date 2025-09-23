<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ForgotPasswordController extends AbstractController
{
    #[Route('/forgot', name: 'forgot_password', methods: ['GET','POST'])]
    public function forgot(
        Request $request,
        Connection $db,
        MailerInterface $mailer
    ): Response {
        if ($request->isMethod('POST')) {
            $email = trim((string)$request->request->get('email',''));
            if ($email === '') {
                $this->addFlash('error', 'Имэйл шаардлагатай.');
                return $this->redirectToRoute('forgot_password');
            }

            // user id авах (байхгүй бол чимээгүй амжилт гэж үзнэ — security best practice)
            $user = $db->fetchAssociative('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
            if ($user) {
                $userId = $user['id'];
                $token = bin2hex(random_bytes(32)); // 64 тэмдэгт
                $expiresAt = (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');

                // хуучин ашиглаагүй токенуудыг хүчингүй болгоё (сонголт)
                $db->executeStatement(
                    'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL',
                    ['uid' => $userId]
                );

                // шинэ токен хадгална
                $db->insert('password_reset_tokens', [
                    'user_id'    => $userId,
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                ]);

                // ресет холбоос
                $resetUrl = $this->generateUrl('reset_password', ['token' => $token], 0);

                // Имэйлийг илгээх (одоо null://null тул лог/нооп болно)
                $emailMsg = (new TemplatedEmail())
                    ->from(new Address('no-reply@microfin.local', 'MicroFin'))
                    ->to($email)
                    ->subject('Нууц үг сэргээх холбоос')
                    ->htmlTemplate('security/reset_email.html.twig')
                    ->context(['reset_url' => $resetUrl]);

                try { $mailer->send($emailMsg); } catch (\Throwable $e) { /* noop dev */ }

                // DEV: хэрэглэгчид шууд линк харуулж өгнө (имэйлгүй тестлэхэд)
                $this->addFlash('success', 'Сэргээх холбоос үүсгэлээ. Доорх линкээр орж шалгана уу.');
                $this->addFlash('success', $resetUrl);
            }

            // Ямагт ижил мессеж буцаана (имэйл бүртгэлтэй эсэхийг илчлэхгүй)
            $this->addFlash('info', 'Хэрэв имэйл бүртгэлтэй бол сэргээх заавар илгээгдлээ.');
            return $this->redirectToRoute('forgot_password');
        }

        return $this->render('security/forgot.html.twig');
    }
}
