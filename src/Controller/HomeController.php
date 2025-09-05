<?php
namespace App\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

class HomeController {
    #[Route('/', name: 'app_home')]
    public function __invoke(Environment $twig): Response {
        return new Response($twig->render('home/index.html.twig', ['title'=>'MicroFin']));
    }
}
