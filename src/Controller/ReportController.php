<?php

namespace App\Controller;

use App\Service\ExcelImportService;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    #[Route('/upload', name: 'upload_excel', methods: ['GET','POST'])]
    public function upload(
        Request $request,
        ExcelImportService $excelImportService,
        EntityManagerInterface $em
    ): Response {
        // Хамгаалалт — заавал нэвтэрсэн байх
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('login');
        }

        if ($request->isMethod('POST')) {
            // 1) Зөвхөн тухайн хэрэглэгчийн дата-г цэвэрлэх
            if ($request->request->get('clear')) {
                $em->createQuery('DELETE FROM App\Entity\Transaction t WHERE t.user = :u')
                   ->setParameter('u', $user)
                   ->execute();
                $this->addFlash('success', 'Таны бүх гүйлгээг цэвэрлэж устгалаа.');
            }

            // 2) Баганууд (origin, customer) байгаа эсэхийг баталгаажуулах
            try {
                $this->ensureColumns($em);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'DB migrate error: '.$e->getMessage());
                return $this->redirectToRoute('upload_excel');
            }

            // 3) Upload төрлийг авах
            $origin = strtoupper(trim((string)$request->request->get('origin', '')));
            if (!in_array($origin, ['CASH', 'BANK'], true)) {
                $this->addFlash('error', 'Төрөл сонгоно уу: КАСС эсвэл ХАРИЛЦАХ ДАНСНЫ ХУУЛГА.');
                return $this->redirectToRoute('upload_excel');
            }

            // 4) Файл шалгах
            $file = $request->files->get('excel');
            if (!$file) {
                $this->addFlash('error', 'Файл сонгогдоогүй.');
                return $this->redirectToRoute('upload_excel');
            }

            try {
                // 5) Импорт — ExcelImportService нь таний user-г Transaction-д тохируулж хадгална
                $count = $excelImportService->import($file->getPathname(), $origin);

                // Fallback (хэрвээ хуучин мөрүүдэд user=null үлдвэл, бүгдийг таны user-р тэмдэглэнэ)
                $em->getConnection()->executeStatement(
                    'UPDATE "transaction" SET user_id = :uid WHERE user_id IS NULL',
                    ['uid' => $user->getId()]
                );

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
    public function summary(Request $request, TransactionRepository $repo, EntityManagerInterface $em): Response
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return $this->redirectToRoute('login');
            }

            // prod дээр ч багана байна уу гэдгийг баталгаажуулъя
            $this->ensureColumns($em);

            // --- Шүүлтүүд
            $opening = $request->query->get('opening', $_ENV['OPENING_BALANCE'] ?? 0);
            $opening = is_numeric($opening) ? (float)$opening : 0.0;

            $fromStr   = trim((string) $request->query->get('from', ''));
            $toStr     = trim((string) $request->query->get('to', ''));
            $type      = trim((string) $request->query->get('type', ''));
            $dir       = trim((string) $request->query->get('dir', ''));
            $originQ   = strtoupper(trim((string)$request->query->get('origin', '')));
            $q         = trim((string)$request->query->get('q', ''));
            $customerQ = trim((string)$request->query->get('customer', ''));

            // --- Хуудаслалт
            $allowedPer = [30, 40, 50, 100, 200];
            $perPage = (int) ($request->query->get('perPage', 30));
            if (!in_array($perPage, $allowedPer, true)) $perPage = 30;

            $page = (int) ($request->query->get('page', 1));
            if ($page < 1) $page = 1;

            // --- Төрлийн жагсаалт (зөвхөн тухайн хэрэглэгчийн датагаас)
            $typesRows = $repo->createQueryBuilder('t')
                ->select('DISTINCT t.category')
                ->where('t.user = :u')
                ->andWhere('t.category IS NOT NULL AND t.category <> \'\'')
                ->setParameter('u', $user)
                ->orderBy('t.category', 'ASC')
                ->getQuery()->getArrayResult();
            $types = [];
            foreach ($typesRows as $row) {
                $val = $row['category'] ?? null;
                if ($val !== null && $val !== '') $types[] = $val;
            }

            // --- Харилцагчийн жагсаалт (distinct, зөвхөн тухайн хэрэглэгчийнх)
            $customersRows = $repo->createQueryBuilder('t2')
                ->select('DISTINCT t2.customer')
                ->where('t2.user = :u')
                ->andWhere('t2.customer IS NOT NULL AND t2.customer <> \'\'')
                ->setParameter('u', $user)
                ->orderBy('t2.customer', 'ASC')
                ->getQuery()->getArrayResult();
            $customers = [];
            foreach ($customersRows as $row) {
                $val = $row['customer'] ?? null;
                if ($val !== null && $val !== '') $customers[] = $val;
            }

            // --- Нийт шүүлттэй QB (зөвхөн тухайн хэрэглэгчийн мөрүүд)
            $baseQb = $repo->createQueryBuilder('t')
                ->andWhere('t.user = :u')->setParameter('u', $user)
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
            if ($q !== '') {
                $baseQb->andWhere('LOWER(t.description) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%');
            }
            if ($customerQ !== '') {
                $baseQb->andWhere('t.customer = :cust')->setParameter('cust', $customerQ);
            }

            // --- Нийт тоо
            $countQb = clone $baseQb;
            $countQb->resetDQLPart('orderBy');
            $totalCount = (int) $countQb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

            $totalPages = (int) max(1, (int)ceil($totalCount / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            // --- Нийт дүн
            $sumQb = clone $baseQb;
            $sumQb->resetDQLPart('orderBy');
            $sumQb->select(
                'SUM(CASE WHEN t.isIncome = true  THEN ABS(t.amount) ELSE 0 END) AS incomeSum',
                'SUM(CASE WHEN t.isIncome = false THEN ABS(t.amount) ELSE 0 END) AS expenseSum'
            );
            $sums = $sumQb->getQuery()->getSingleResult();
            $income  = (float)($sums['incomeSum'] ?? 0);
            $expense = (float)($sums['expenseSum'] ?? 0);
            $balance = $opening + ($income - $expense);

            // --- КАСС vs ХАРИЛЦАХ нийлбэр
            $sumOriginQb = clone $baseQb;
            $sumOriginQb->resetDQLPart('orderBy');
            $sumOriginQb->select(
                "SUM(CASE WHEN t.origin = 'CASH' AND t.isIncome = true  THEN ABS(t.amount) ELSE 0 END) AS cashIncome",
                "SUM(CASE WHEN t.origin = 'CASH' AND t.isIncome = false THEN ABS(t.amount) ELSE 0 END) AS cashExpense",
                "SUM(CASE WHEN t.origin = 'BANK' AND t.isIncome = true  THEN ABS(t.amount) ELSE 0 END) AS bankIncome",
                "SUM(CASE WHEN t.origin = 'BANK' AND t.isIncome = false THEN ABS(t.amount) ELSE 0 END) AS bankExpense"
            );
            $by = $sumOriginQb->getQuery()->getSingleResult();
            $byOrigin = [
                'cash' => [
                    'income'  => (float)($by['cashIncome']  ?? 0),
                    'expense' => (float)($by['cashExpense'] ?? 0),
                    'balance' => (float)($by['cashIncome']  ?? 0) - (float)($by['cashExpense'] ?? 0),
                ],
                'bank' => [
                    'income'  => (float)($by['bankIncome']  ?? 0),
                    'expense' => (float)($by['bankExpense'] ?? 0),
                    'balance' => (float)($by['bankIncome']  ?? 0) - (float)($by['bankExpense'] ?? 0),
                ],
            ];

            // --- Энэ хуудсын мөрүүд
            $pageQb = clone $baseQb;
            $rows = $pageQb
                ->setFirstResult($offset)
                ->setMaxResults($perPage)
                ->getQuery()->getResult();

            // --- Энэ хуудсын эхлэх үлдэгдэл
            $startBalance = $opening;
            if ($offset > 0) {
                $prevQb = clone $baseQb;
                $prevRows = $prevQb
                    ->select('t.amount')
                    ->setFirstResult(0)
                    ->setMaxResults($offset)
                    ->getQuery()->getArrayResult();
                $netBefore = 0.0;
                foreach ($prevRows as $r) { $netBefore += (float)$r['amount']; }
                $startBalance += $netBefore;
            }

            return $this->render('report/summary.html.twig', [
                'income'         => $income,
                'expense'        => $expense,
                'balance'        => $balance,
                'rows'           => $rows,
                'openingBalance' => $opening,
                'startBalance'   => $startBalance,
                'filters'        => [
                    'from'     => $fromStr,
                    'to'       => $toStr,
                    'type'     => $type,
                    'dir'      => $dir,
                    'origin'   => $originQ,
                    'q'        => $q,
                    'customer' => $customerQ,
                ],
                'types'          => $types,
                'customers'      => $customers,
                'totalCount'     => $totalCount,
                'byOrigin'       => $byOrigin,
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
     * 'origin' болон 'customer' баганууд байхгүй бол үүсгэнэ (Postgres/SQLite-д аюулгүй).
     */
    private function ensureColumns(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $platform = $conn->getDatabasePlatform()->getName(); // 'postgresql' | 'sqlite' | ...

        if ($platform === 'postgresql') {
            $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN IF NOT EXISTS origin VARCHAR(16) NULL');
            $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN IF NOT EXISTS customer VARCHAR(120) NULL');
        } elseif ($platform === 'sqlite') {
            $sm = $conn->createSchemaManager();
            $table = $sm->introspectTable('transaction');

            $cols = [];
            foreach ($table->getColumns() as $col) {
                $cols[strtolower($col->getName())] = true;
            }

            if (!isset($cols['origin'])) {
                $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN origin VARCHAR(16) NULL');
            }
            if (!isset($cols['customer'])) {
                $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN customer VARCHAR(120) NULL');
            }
        } else {
            // Бусад платформ – энгийн шалгалт
            foreach (['origin' => 'VARCHAR(16)', 'customer' => 'VARCHAR(120)'] as $name => $type) {
                try {
                    $conn->executeStatement('SELECT '.$name.' FROM "transaction" WHERE 1=0');
                } catch (\Throwable $e) {
                    $conn->executeStatement('ALTER TABLE "transaction" ADD COLUMN '.$name.' '.$type.' NULL');
                }
            }
        }
    }
}
