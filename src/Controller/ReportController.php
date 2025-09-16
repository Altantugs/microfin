<?php

namespace App\Controller;

use App\Service\ExcelImportService;
use App\Repository\TransactionRepository;
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

    #[Route('/report', name: 'report_summary')]
    public function summary(TransactionRepository $repo): Response
    {
        // Нийт орлого, зарлага, тоо ширхэг
        $qb = $repo->createQueryBuilder('t');
        $qb->select(
            'SUM(CASE WHEN t.isIncome = true THEN t.amount ELSE 0 END) AS income',
            'SUM(CASE WHEN t.isIncome = false THEN t.amount ELSE 0 END) AS expense',
            'COUNT(t.id) AS cnt'
        );
        $row = $qb->getQuery()->getSingleResult();

        $income  = (float) ($row['income'] ?? 0);
        $expense = (float) ($row['expense'] ?? 0);
        // Зарлагын дүн маань сөрөг байж болох тул үлдэгдэл = орлого + зарлага
        $balance = $income + $expense;

        // Сүүлийн 10 гүйлгээ
        $latest = $repo->createQueryBuilder('t2')
            ->orderBy('t2.date', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('report/summary.html.twig', [
            'income'  => $income,
            'expense' => $expense,
            'balance' => $balance,
            'latest'  => $latest,
        ]);
    }
}
