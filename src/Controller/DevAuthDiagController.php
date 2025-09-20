<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class DevAuthDiagController extends AbstractController
{
    #[Route('/dev/check-pass', name: 'dev_check_pass', methods: ['GET'])]
    public function check(
        Request $req,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $seed = $_ENV['SEED_TOKEN'] ?? null;
        if (!$seed || $req->query->get('token') !== $seed) {
            return new Response('Forbidden', 403);
        }

        $email = (string) $req->query->get('email', '');
        $pass  = (string) $req->query->get('pass', '');

        if ($email === '' || $pass === '') {
            return new Response("Usage: /dev/check-pass?token=SEED_TOKEN&email=...&pass=...", 400);
        }

        /** @var User|null $user */
        $user = $users->findOneBy(['email' => $email]);

        if (!$user) {
            return new Response("NOT FOUND: $email", 404);
        }

        $ok = $hasher->isPasswordValid($user, $pass);

        $roles = implode(',', $user->getRoles());
        $status = $user->getStatus();
        $expires = $user->getExpiresAt()?->format('c') ?? 'null';

        return new Response(
            ($ok ? 'OK' : 'NO') . " | email={$email} | roles=[{$roles}] | status={$status} | expires_at={$expires}\n",
            $ok ? 200 : 401,
            ['Content-Type' => 'text/plain']
        );
    }
}
