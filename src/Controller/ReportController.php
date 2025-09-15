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
    #[Route('/upload', name: 'app_upload')]
    public function upload(Request $request, ExcelImportService $importer): Response
    {
        $message = null;

        if ($request->isMethod('POST')) {
            $file = $request->files->get('excel');
            if ($file) {
                $uploadsDir = $this->getParameter('kernel.project_dir').'/var/uploads';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0777, true);
                }

                $filepath = $uploadsDir.'/'.$file->getClientOriginalName();
                try {
                    $file->move($uploadsDir, $file->getClientOriginalName());
                    $count = $importer->import($filepath);
                    $message = "Амжилттай: $count мөр импортлогдлоо ✅";
                } catch (FileException $e) {
                    $message = "Файл хадгалах үед алдаа: ".$e->getMessage();
                } catch (\Throwable $e) {
                    $message = "Импорт алдаа: ".$e->getMessage();
                }
            } else {
                $message = "Excel файл сонгоно уу!";
            }
        }

        return $this->render('report/upload.html.twig', [
            'message' => $message,
        ]);
    }
}
