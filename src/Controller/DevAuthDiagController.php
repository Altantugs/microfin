<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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
        if ($email === '' || $pass === '') {
            return new Response('email & pass are required', 400);
        }

        $qb = $em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = LOWER(:e)')
            ->setParameter('e', $email)
            ->setMaxResults(1);
        /** @var ?User $user */
        $user = $qb->getQuery()->getOneOrNullResult();
        if (!$user) {
            return new Response("NO | user not found", 404);
        }

        $ok = $hasher->isPasswordValid($user, $pass);
        $roles = implode(',', $user->getRoles());
        $status = method_exists($user, 'getStatus') ? $user->getStatus() : 'N/A';
        $expires = method_exists($user, 'getExpiresAt') && $user->getExpiresAt()
            ? $user->getExpiresAt()->format(\DateTimeInterface::ATOM)
            : 'null';

        return new Response(($ok ? 'OK' : 'NO') . " | email={$user->getEmail()} | roles=[{$roles}] | status={$status} | expires_at={$expires}");
    }

    #[Route('/dev/whoami', name: 'dev_whoami', methods: ['GET'])]
    public function whoAmI(Request $request): Response
    {
        if ($resp = $this->checkToken($request)) return $resp;

        $u = $this->getUser();
        if (!$u instanceof User) {
            return new Response('ANON');
        }
        $roles = implode(',', $u->getRoles());
        return new Response('AUTH | email=' . $u->getEmail() . ' | roles=[' . $roles . ']');
    }

    #[Route('/dev/user-dump', name: 'dev_user_dump', methods: ['GET'])]
    public function userDump(Request $request, UserRepository $repo): Response
    {
        if ($resp = $this->checkToken($request)) return $resp;

        $email = (string) $request->query->get('email', '');
        if ($email === '') {
            return new Response('email=? required', 400);
        }

        $rows = $repo->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = LOWER(:e)')
            ->setParameter('e', $email)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        /** @var User $u */
        foreach ($rows as $u) {
            $out[] = [
                'id'          => $u->getId(),
                'email'       => $u->getEmail(),
                'roles'       => $u->getRoles(),
                'status'      => method_exists($u, 'getStatus') ? $u->getStatus() : null,
                'createdAt'   => method_exists($u, 'getCreatedAt') && $u->getCreatedAt()
                                ? $u->getCreatedAt()->format('c') : null,
                'pass_prefix' => substr((string)$u->getPassword(), 0, 20),
            ];
        }

        return new JsonResponse(['count' => count($rows), 'items' => $out]);
    }

    /**
     * 🔎 LOGIN PROBE: сервер яг юу аваад, CSRF үнэн эсэхийг харуулна.
     * POST /dev/login-probe?token=...  (body: _username, _password, _csrf_token)
     */
    #[Route('/dev/login-probe', name: 'dev_login_probe', methods: ['POST'])]
    public function loginProbe(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        CsrfTokenManagerInterface $csrf
    ): Response {
        if ($resp = $this->checkToken($request)) return $resp;

        $uName = (string) $request->request->get('_username', '');
        $pRaw  = (string) $request->request->get('_password', '');
        $pLen  = strlen($pRaw);
        $csrfVal = (string) $request->request->get('_csrf_token', '');

        $csrfValid = $csrf->isTokenValid(new CsrfToken('authenticate', $csrfVal));

        $user = $em->getRepository(User::class)->findOneBy(['email' => $uName]);

        $passOk = null;
        if ($user) {
            $passOk = $hasher->isPasswordValid($user, $pRaw);
        }

        $payload = [
            'username_received' => $uName,
            'password_length'   => $pLen,
            'csrf_valid'        => $csrfValid,
            'user_found'        => (bool) $user,
            'pass_valid'        => $passOk,
        ];

        return new JsonResponse($payload);
    }
}
