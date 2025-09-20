<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class DevResetPassController extends AbstractController
{
    #[Route('/dev/reset-pass', name: 'dev_reset_pass', methods: ['GET'])]
    public function reset(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $tokenNeed = $_ENV['SEED_TOKEN'] ?? '';
        $tokenGot  = (string)($_GET['token'] ?? '');
        if ($tokenNeed !== '' && $tokenGot !== $tokenNeed) {
            return new Response('Forbidden', 403);
        }

        $email = trim((string)($_GET['email'] ?? ''));
        $pass  = (string)($_GET['pass']  ?? 'Admin123!');
        if ($email === '') return new Response('Missing email', 400);

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) return new Response('User not found', 404);

        $user->setPassword($hasher->hashPassword($user, $pass));

        if (method_exists($user, 'setEnabled'))    { $user->setEnabled(true); }
        if (method_exists($user, 'setIsVerified')) { $user->setIsVerified(true); }

        $em->flush();

        return new Response("OK: {$email} => {$pass}");
    }
}
