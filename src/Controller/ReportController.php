<?php

namespace App\Controller;

use App\Service\ExcelImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class ReportController extends AbstractController
{
    #[Route('/upload', name: 'upload_excel')]
    public function upload(Request $request, ExcelImportService $excelImportService): Response
    {
        if ($request->isMethod('POST')) {
            $file = $request->files->get('excel_file');

            if ($file) {
                try {
                    $excelImportService->import($file->getPathname());
                    $this->addFlash('success', 'Excel амжилттай импортлогдлоо!');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Алдаа гарлаа: '.$e->getMessage());
                }
            }
        }

        return $this->render('report/upload.html.twig');
    }
}
