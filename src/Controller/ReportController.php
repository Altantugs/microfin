<?php

namespace App\Controller;

use App\Service\ExcelImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReportController extends AbstractController
{
    #[Route('/upload', name: 'upload_excel')]
    public function upload(Request $request, ExcelImportService $excelImportService): Response
    {
        if ($request->isMethod('POST')) {
            $file = $request->files->get('excel_file');

            if (!$file) {
                $this->addFlash('error', 'Файл сонгогдоогүй.');
                return $this->redirectToRoute('upload_excel');
            }

            try {
                // Импортын сервис мөрийн тоо буцаана
                $count = $excelImportService->import($file->getPathname());
                $this->addFlash('success', sprintf('Excel амжилттай импортлогдлоо! (%d мөр)', $count));
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Алдаа: '.$e->getMessage());
            }

            // PRG (Post/Redirect/Get)
            return $this->redirectToRoute('upload_excel');
        }

        return $this->render('report/upload.html.twig');
    }
}
