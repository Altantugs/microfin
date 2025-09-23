<?php
namespace App\Controller\Auth;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Annotation\Route;

final class ForgotPasswordController extends AbstractController
{
    #[Route('/forgot', name: 'forgot_password', methods: ['GET','POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('security/forgot.html.twig');
        }

        $email = trim((string) $request->request->get('email', ''));
        if ($email === '') {
            $this->addFlash('error', 'Имэйлээ оруулна уу.');
            return $this->redirectToRoute('forgot_password');
        }

        $user = $users->findOneBy(['email' => $email]);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new \DateTimeImmutable('+30 minutes'));

            $conn = $em->getConnection();
            $conn->executeStatement(
                'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (:uid, :token, :exp)',
                [
                    'uid'   => $user->getId(),
                    'token' => $token,
                    'exp'   => $expiresAt->format('Y-m-d H:i:s'),
                ]
            );

            $base = rtrim($request->getSchemeAndHttpHost(), '/');
            $resetUrl = $base . '/reset/' . $token;

            $message = (new TemplatedEmail())
                ->to($email)
                ->subject('Нууц үг сэргээх линк')
                ->htmlTemplate('security/reset_email.html.twig')
                ->context(['reset_url' => $resetUrl]);

            try {
                $mailer->send($message);
            } catch (\Throwable $e) {
                // dev/null mailer-тэй үед энд ирэхгүй, бодит SMTP үед алдаа гарвал логгүйгээр чимээгүй өнгөрөөе
            }
        }

        $this->addFlash('success', 'Хэрэв ийм имэйл бүртгэлтэй бол сэргээх линк илгээлээ.');
        return $this->redirectToRoute('app_login');
    }
}
