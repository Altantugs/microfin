<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET','POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // ✅ Хэрэв аль хэдийн нэвтэрсэн бол нүүр (/) буюу public_home руу буцаана
        return $this->getUser()
            ? $this->redirectToRoute('public_home', [], 302) // (= "/")
            : $this->render('security/login.html.twig', [     // ✅ зөв template зам
                'last_username' => $authenticationUtils->getLastUsername(),
                'error'         => $authenticationUtils->getLastAuthenticationError(),
            ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): void
    {
        // Firewall барьдаг, энд хүрэх ёсгүй
        throw new \LogicException('This should never be reached.');
    }
}
