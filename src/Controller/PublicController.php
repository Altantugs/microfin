<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PublicController extends AbstractController
{
    #[Route('/', name: 'public_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('public/home.html.twig');
    }

    #[Route('/intro', name: 'public_intro', methods: ['GET'])]
    public function intro(): Response
    {
        return $this->render('public/intro.html.twig');
    }

    #[Route('/guide', name: 'public_guide', methods: ['GET'])]
    public function guide(): Response
    {
        return $this->render('public/guide.html.twig');
    }

    #[Route('/pricing', name: 'public_pricing', methods: ['GET'])]
    public function pricing(): Response
    {
        return $this->render('public/pricing.html.twig');
    }

    #[Route('/contact', name: 'public_contact', methods: ['GET'])]
    public function contact(): Response
    {
        return $this->render('public/contact.html.twig');
    }
}
