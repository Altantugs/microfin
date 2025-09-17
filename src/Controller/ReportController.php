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
            // 1) Clear сонгосон бол бүх бичлэгийг устгана
            if ($request->request->get('clear')) {
                $em->createQuery('DELETE FROM App\Entity\Transaction t')->execute();
            }

            // 2) DB-д origin багана заавал байхыг баталгаажуулна (Postgres/SQLite)
            try {
                $this->ensureOriginColumn($em);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'DB migrate error: '.$e->getMessage());
                return $this->redirectToRoute('upload_excel');
            }

            // 3) Upload төрлийг авах (КАСС / ХАРИЛЦАХ)
            $origin = strtoupper(trim((string)$request->request->get('origin', '')));
            if (!in_array($origin, ['CASH', 'BANK'], true)) {
                $this->addFlash('error', 'Төрөл сонгоно уу: КАСС эсвэл ХАРИЛЦАХ ДАНСЫН ХУУЛГА.');
                return $this->redirectToRoute('upload_excel');
            }

            // 4) Файл шалгах
            $file = $request->files->get('excel');
            if (!$file) {
                $this->addFlash('error', 'Файл сонгогдоогүй.');
                return $this->redirectToRoute('upload_excel');
            }

            try {
                // 5) Импорт (origin дамжуулна)
                $count = $excelImportService->import($file->getPathname(), $origin);

                $this->addFlash('success', sprintf(
                    'Excel амжилттай импортлогдлоо! (%d мөр) • Төрөл: %s',
                    $count,
                    $origin === 'CASH' ? 'КАСС' : 'ХАРИЛЦАХ'
                ));
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Алдаа: ' . $e->getMessage());
            }

            return $this->redirectToRoute('upload_excel');
        }

        return $this->render('report/upload.html.twig');
    }

    #[Route('/report', name: 'report_summary', methods: ['GET'])]
    public function summary(Request $request, TransactionRepository $repo): Response
    {
        try {
            // --- Шүүлтүүд
            $opening = $request->query->get('opening', $_ENV['OPENING_BALANCE'] ?? 0);
            $opening = is_numeric($opening) ? (float)$opening : 0.0;

            $fromStr = trim((string) $request->query->get('from', ''));
            $toStr   = trim((string) $request->query->get('to', ''));
            $type    = trim((string) $request->query->get('type', ''));
            $dir     = trim((string) $request->query->get('dir', ''));
            $originQ = strtoupper(trim((string)$request->query->get('origin', '')));

            // --- Хуудаслалт
            $allowedPer = [30, 40, 50, 100, 200];
            $perPage = (int) ($request->query->get('perPage', 30));
            if (!in_array($perPage, $allowedPer, true)) $perPage = 30;

            $page = (int) ($request->query->get('page', 1));
            if ($page < 1) $page = 1;

            // --- Төрлийн жагсаалт
            $typesRows = $repo->createQueryBuilder('t')
                ->select('DISTINCT t.category')
                ->where('t.category IS NOT NULL AND t.category <> \'\'')
                ->orderBy('t.category', 'ASC')
                ->getQuery()->getArrayResult();
            $types = [];
            foreach ($typesRows as $row) {
                $val = $row['category'] ?? null;
                if ($val !== null && $val !== '') $types[] = $val;
            }

            // --- Нийт шүүлттэй QB
            $baseQb = $repo->createQueryBuilder('t')
                ->orderBy('t.date', 'ASC')
                ->addOrderBy('t.id', 'ASC');

            if ($fromStr !== '') {
                $from = new \DateTimeImmutable($fromStr.' 00:00:00');
                $baseQb->andWhere('t.date >= :from')->setParameter('from', $from);
            }
            if ($toStr !== '') {
                $to = new \DateTimeImmutable($toStr.' 23:59:59');
                $baseQb->andWhere('t.date <= :to')->setParameter('to', $to);
            }
            if ($type !== '') {
                $baseQb->andWhere('t.category = :cat')->setParameter('cat', $type);
            }
            if ($dir === 'in') {
                $baseQb->andWhere('t.isIncome = true');
            } elseif ($dir === 'out') {
                $baseQb->andWhere('t.isIncome = false');
            }
            if (in_array($originQ, ['CASH','BANK'], true)) {
                $baseQb->andWhere('t.origin = :o')->setParameter('o', $originQ);
            }

            // --- Нийт тоо
            $countQb = clone $baseQb;
            $totalCount = (int) $countQb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

            $totalPages = (int) max(1, (int)ceil($totalCount / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            // --- Нийт дүн (бүх шүүлттэй мөр)
            $sumQb = clone $baseQb;
            $sumQb->resetDQLPart('orderBy');
            $sumQb->select(
                'SUM(CASE WHEN t.isIncome = true  THEN ABS(t.amount) ELSE 0 END) AS incomeSum',
                'SUM(CASE WHEN t.isIncome = false THEN ABS(t.amount) ELSE 0 END) AS expenseSum'
            );
            $sums = $sumQb->getQuery()->getSingleResult();
            $income = (float)($sums['incomeSum'] ?? 0);
            $expense = (float)($sums['expenseSum'] ?? 0);
            $balance = $opening + ($income - $expense);

            // --- Энэ хуудсын мөрүүд
            $pageQb = clone $baseQb;
            $rows = $pageQb
                ->setFirstResult($offset)
                ->setMaxResults($perPage)
                ->getQuery()->getResult();

            // --- Энэ хуудсын эхлэх үлдэгдэл = opening + өмнөх offset мөрийн нет
            $startBalance = $opening;
            if ($offset > 0) {
                $prevQb = clone $baseQb;
                $prevRows = $prevQb
                    ->select('t.amount')
                    ->setFirstResult(0)
                    ->setMaxResults($offset)
                    ->getQuery()->getArrayResult();
                $netBefore = 0.0;
                foreach ($prevRows as $r) {
                    $netBefore += (float)$r['amount']; // amount sign-тай хадгалагддаг
                }
                $startBalance += $netBefore;
            }

            return $this->render('report/summary.html.twig', [
                'income'         => $income,
                'expense'        => $expense,
                'balance'        => $balance,
                'rows'           => $rows,             // ← pagination-той мөрүүд
                'openingBalance' => $opening,
                'startBalance'   => $startBalance,     // ← тухайн хуудсын running эхлэл
                'filters'        => [
                    'from'   => $fromStr,
                    'to'     => $toStr,
                    'type'   => $type,
                    'dir'    => $dir,
                    'origin' => $originQ,
                ],
                'types'          => $types,
                'totalCount'     => $totalCount,
                'pagination'     => [
                    'page'        => $page,
                    'perPage'     => $perPage,
                    'allowed'     => $allowedPer,
                    'totalPages'  => $totalPages,
                    'offset'      => $offset,
                ],
            ]);
        } catch (\Throwable $e) {
            return new Response(
                'DEBUG /report ERROR: '.$e->getMessage().' ['.$e->getFile().':'.$e->getLine().']',
                500,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }
    }

    /**
     * Prod/Postgres болон локал/SQLite аль алинд нь 'origin' багана
     * байхгүй бол үүсгэж баталгаажуулна. (DBAL 3-safe)
     */
    private function ensureOriginColumn(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $platform = $conn->getDatabasePlatform()->getName(); // 'postgresql', 'sqlite', ...

        if ($platform === 'postgresql') {
            // Postgres — IF NOT EXISTS
            $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN IF NOT EXISTS origin VARCHAR(16) NULL');
        } elseif ($platform === 'sqlite') {
            // SQLite — introspect хийж байж нэмнэ
            $sm = $conn->createSchemaManager();
            $table = $sm->introspectTable('transaction');
            $has = false;
            foreach ($table->getColumns() as $col) {
                if (strcasecmp($col->getName(), 'origin') === 0) { $has = true; break; }
            }
            if (!$has) {
                $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN origin VARCHAR(16) NULL');
            }
        } else {
            // Бусад платформ: эхлээд шалгаж үзээд, байхгүй бол нэм
            try {
                $conn->executeStatement('SELECT origin FROM "transaction" WHERE 1=0');
            } catch (\Throwable $e) {
                $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN origin VARCHAR(16) NULL');
            }
        }
    }
}
