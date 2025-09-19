<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/app/reports/bank')]
final class BankReportsController extends AbstractController
{
    #[Route('', name: 'bank_reports_home', methods: ['GET'])]
    public function index(): Response
    {
        // Одоогийн тайлангийн үндсэн дэлгэц
        return $this->render('reports.html.twig');
    }

    #[Route('/import', name: 'bank_reports_import', methods: ['GET','POST'])]
    public function import(): Response
    {
        // Одоогийн Excel upload дэлгэц
        return $this->render('upload.html.twig');
    }

    #[Route('/prepare', name: 'bank_reports_prepare', methods: ['GET','POST'])]
    public function prepare(): Response
    {
        // Одоогийн тайлан боловсруулах/нийтлэлтийн дэлгэц
        return $this->render('summary.html.twig');
    }
}
