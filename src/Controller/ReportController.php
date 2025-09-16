<?php

namespace App\Controller;

use App\Service\ExcelImportService;
use App\Repository\TransactionRepository;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
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
        EntityManagerInterface $em
    ): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->get('clear')) {
                $em->createQuery('DELETE FROM App\Entity\Transaction t')->execute();
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

    #[Route('/report', name: 'report_summary', methods: ['GET'])]
    public function summary(Request $request, TransactionRepository $repo): Response
    {
        // --- Фильтерүүд
        $opening = $request->query->get('opening', $_ENV['OPENING_BALANCE'] ?? 0);
        $opening = is_numeric($opening) ? (float)$opening : 0.0;

        $fromStr = trim((string) $request->query->get('from', ''));
        $toStr   = trim((string) $request->query->get('to', ''));
        $type    = trim((string) $request->query->get('type', ''));   // category
        $dir     = trim((string) $request->query->get('dir', ''));    // 'in' | 'out' | ''

        // --- Distinct төрлүүд (select-д үзүүлэх)
        $types = $repo->createQueryBuilder('t')
            ->select('DISTINCT t.category')
            ->where('t.category IS NOT NULL AND t.category <> \'\'')
            ->orderBy('t.category', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        // --- Шүүлттэй query
        $qb = $repo->createQueryBuilder('t')
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if ($fromStr !== '') {
            try {
                $from = new \DateTimeImmutable($fromStr.' 00:00:00');
                $qb->andWhere('t.date >= :from')->setParameter('from', $from);
            } catch (\Throwable $e) {}
        }
        if ($toStr !== '') {
            try {
                // өдрийн төгсгөл хүртэл
                $to = new \DateTimeImmutable($toStr.' 23:59:59');
                $qb->andWhere('t.date <= :to')->setParameter('to', $to);
            } catch (\Throwable $e) {}
        }
        if ($type !== '') {
            $qb->andWhere('t.category = :cat')->setParameter('cat', $type);
        }
        if ($dir === 'in') {
            $qb->andWhere('t.isIncome = true');
        } elseif ($dir === 'out') {
            $qb->andWhere('t.isIncome = false');
        }

        $filtered = $qb->getQuery()->getResult();

        // --- Нийт дүнгүүд (шүүлттэй өгөгдлөөс)
        $income = 0.0; $expense = 0.0;
        foreach ($filtered as $t) {
            $amt = (float)$t->getAmount();
            if ($t->isIsIncome()) $income += abs($amt);
            else                  $expense += abs($amt);
        }

        // --- Эцсийн үлдэгдэл = эхлэх үлдэгдэл + (орлого - зарлага)
        $balance = $opening + ($income - $expense);

        // --- Сүүлийн 10 мөр (ASC дарааллаас сүүлчийн 10-ыг авна)
        $latest = array_slice($filtered, max(0, count($filtered) - 10));

        return $this->render('report/summary.html.twig', [
            'income'         => $income,
            'expense'        => $expense,
            'balance'        => $balance,
            'latest'         => $latest,
            'openingBalance' => $opening,
            'filters'        => [
                'from' => $fromStr,
                'to'   => $toStr,
                'type' => $type,
                'dir'  => $dir,
            ],
            'types'          => $types,
            'totalCount'     => count($filtered),
        ]);
    }
}
