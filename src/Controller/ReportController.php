<?php

namespace App\Controller;

use App\Service\ExcelImportService;
use App\Repository\TransactionRepository;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface; // ← нэмнэ
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReportController extends AbstractController
{
    #[Route('/upload', name: 'upload_excel')]
    public function upload(
        Request $request,
        ExcelImportService $excelImportService,
        EntityManagerInterface $em // ← нэмэв
    ): Response
    {
        if ($request->isMethod('POST')) {
            // Хэрэв "Өмнөхийг устгах" сонгосон бол — бүх Transaction-ийг цэвэрлэнэ
            if ($request->request->get('clear')) {
                // DQL delete (RESTART IDENTITY хийхгүй, зөвхөн мөрийг устгана)
                $em->createQuery('DELETE FROM App\Entity\Transaction t')->execute();

                // Хэрэв Postgres дээр ID-г тэглэмээр байвал (сонголтоор):
                // АНХААР: шууд SQL тул Postgres-д л ажиллана
                // $meta = $em->getClassMetadata(Transaction::class);
                // $table = $meta->getTableName(); // ихэнхдээ "transaction"
                // $em->getConnection()->executeStatement(sprintf('TRUNCATE TABLE "%s" RESTART IDENTITY CASCADE', $table));
            }

            $file = $request->files->get('excel');

            if (!$file) {
                $this->addFlash('error', 'Файл сонгогдоогүй.');
                return $this->redirectToRoute('upload_excel');
            }

            try {
                $count = $excelImportService->import($file->getPathname());
                $this->addFlash('success', sprintf('Excel амжилттай импортлогдлоо! (%d мөр)', $count));
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Алдаа: '.$e->getMessage());
            }

            return $this->redirectToRoute('upload_excel');
        }

        return $this->render('report/upload.html.twig');
    }

    #[Route('/report', name: 'report_summary')]
    public function summary(TransactionRepository $repo): Response
    {
        $qb = $repo->createQueryBuilder('t');
        $qb->select(
            'SUM(CASE WHEN t.isIncome = true THEN t.amount ELSE 0 END) AS income',
            'SUM(CASE WHEN t.isIncome = false THEN t.amount ELSE 0 END) AS expense',
            'COUNT(t.id) AS cnt'
        );
        $row = $qb->getQuery()->getSingleResult();

        $income  = (float) ($row['income'] ?? 0);
        $expense = (float) ($row['expense'] ?? 0);
        $balance = $income + $expense;

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
