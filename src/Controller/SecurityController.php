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
        // Хэрэв нэвтэрсэн бол аппын нүүр рүү буцаана
        if ($this->getUser()) {
            // таны аппын дотоод нүүр: /app  (route: app_home)
            return $this->redirectToRoute('app_home', [], 302);
            // эсвэл шууд URL хэрэглэмээр бол:
            // return $this->redirect('/app');
        }

        // Нэвтрээгүй бол login формыг үзүүлнэ
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): void
    {
        // firewall барьдаг тул энд хүрэх ёсгүй
        throw new \LogicException('This should never be reached.');
    }
}
