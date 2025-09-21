<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class AppController extends AbstractController
{
    #[Route('/app', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('app/dashboard.html.twig');
    }
}
