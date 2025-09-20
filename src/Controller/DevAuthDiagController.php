<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DevAuthDiagController extends AbstractController
{
    private const TOKEN_ENV = 'SEED_TOKEN';

    private function checkToken(Request $request): ?Response
    {
        $got = (string) $request->query->get('token', '');
        $exp = (string) ($_ENV[self::TOKEN_ENV] ?? '');
        if ($exp === '' || !hash_equals($exp, $got)) {
            return new Response('Forbidden', 403);
        }
        return null;
    }

    #[Route('/dev/check-pass', name: 'dev_check_pass', methods: ['GET'])]
    public function checkPass(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        if ($resp = $this->checkToken($request)) return $resp;

        $email = (string) $request->query->get('email', '');
        $pass  = (string) $request->query->get('pass', '');

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            return new Response("NO | user not found", 404);
        }

        $ok = $hasher->isPasswordValid($user, $pass);
        $roles = implode(',', $user->getRoles());
        $status = method_exists($user, 'getStatus') ? $user->getStatus() : 'N/A';
        $expires = method_exists($user, 'getExpiresAt') && $user->getExpiresAt() ? $user->getExpiresAt()->format(\DateTimeInterface::ATOM) : 'null';

        return new Response(($ok ? 'OK' : 'NO') . " | email={$user->getEmail()} | roles=[{$roles}] | status={$status} | expires_at={$expires}");
    }

    #[Route('/dev/whoami', name: 'dev_whoami', methods: ['GET'])]
    public function whoAmI(Request $request): Response
    {
        if ($resp = $this->checkToken($request)) return $resp;

        if (!$this->getUser()) {
            return new Response('ANON');
        }

        /** @var User $u */
        $u = $this->getUser();
        $roles = implode(',', $u->getRoles());
        return new Response('AUTH | email='.$u->getEmail().' | roles=['.$roles.']');
    }
}
