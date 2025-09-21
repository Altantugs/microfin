<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Attribute\IsGranted;

final class AppController extends AbstractController
{
    /**
     * Аппын дашбоард — зөвхөн нэвтэрсэн хэрэглэгчдэд.
     * /app дээрх хуучин redirect-тай мөргөлдөхгүйн тулд /app/dashboard болгосон.
     */
    #[Route('/app/dashboard', name: 'app_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        return $this->render('app/dashboard.html.twig');
    }
}
