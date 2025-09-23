<?php

namespace App\Controller;
use Symfony\Component\Mime\Address;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class PasswordResetController extends AbstractController
{
    #[Route('/forgot', name: 'forgot_password', methods: ['GET','POST'])]
    public function forgot(
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        if ($request->isMethod('POST')) {
            $emailStr = trim((string)$request->request->get('email',''));
            if ($emailStr !== '') {
                $user = $em->getRepository(User::class)->findOneBy(['email' => $emailStr]);
                if ($user) {
                    $token = bin2hex(random_bytes(16));
                    $conn = $em->getConnection();
                    $conn->insert('password_reset_tokens', [
                        'user_id'    => $user->getId(),
                        'token'      => $token,
                        'expires_at' => (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s'),
                        'used_at'    => null,
                    ]);

                    $resetUrl = $this->generateUrl('reset_password', ['token' => $token], 0);

                    $mailer->send(
                        (new Email())
            ->from(new Address($_ENV["MAILER_FROM"] ?? "no-reply@microfin.local", "Microfin"))
            ->to($emailStr)
                            ->subject('Нууц үг сэргээх линк')
                            ->text("Доорх линкээр 30 минутын дотор шинээр нууц үгээ тохируулна уу:\n\n".$resetUrl)
                    );
                }

                $this->addFlash('success', 'Хэрэв ийм имэйл бүртгэлтэй бол сэргээх заавар очсон.');
                return $this->redirectToRoute('forgot_password');
            }

            $this->addFlash('error', 'И-мэйл оруулна уу.');
        }

        return $this->render('security/forgot.html.twig');
    }

    #[Route('/reset/{token}', name: 'reset_password', methods: ['GET','POST'])]
    public function reset(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $conn = $em->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT t.*, u.email FROM password_reset_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = :t',
            ['t' => $token]
        );

        if (!$row || $row['used_at'] !== null || new \DateTimeImmutable($row['expires_at']) < new \DateTimeImmutable()) {
            return new Response('Токен хүчингүй.', 410);
        }

        if ($request->isMethod('POST')) {
            $p1 = (string)$request->request->get('password','');
            $p2 = (string)$request->request->get('password_confirm','');

            if ($p1 === '' || $p1 !== $p2) {
                $this->addFlash('error', 'Нууц үг хоорондоо таарахгүй байна.');
                return $this->redirectToRoute('reset_password', ['token' => $token]);
            }

            $user = $em->getRepository(User::class)->find($row['user_id']);
            if (!$user) {
                return new Response('Хэрэглэгч олдсонгүй.', 404);
            }

            $user->setPassword($hasher->hashPassword($user, $p1));
            $em->flush();

            $conn->update('password_reset_tokens', ['used_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $row['id']]);

            $this->addFlash('success', 'Шинэ нууц үг тохирлоо. Одоо нэвтэрнэ үү.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset.html.twig', [
            'token' => $token,
            'email' => $row['email'],
        ]);
    }
}
