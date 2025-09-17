<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class SecurityController extends AbstractController
{
    // /login нэртэй ROUTE (name="login") — Security формын redirect-ыг барина
    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        return new Response('<h1>Login</h1><p>Түр placeholder. Дараагийн алхмаар жинхэнэ форм нэмнэ.</p>', 200);
    }

    // /logout — Symfony өөрөө интерсептэлдэг, энд логик бичих шаардлагагүй
    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(): void
    {
        throw new \LogicException('Logout is handled by Symfony firewall.');
    }
}
