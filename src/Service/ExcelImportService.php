<?php
namespace App\Service;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Bundle\SecurityBundle\Security;

class ExcelImportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security, // нэвтэрсэн хэрэглэгчийг авах
    ) {}

    // origin (CASH|BANK) дамжуулдаг
    public function import(string $filepath, string $origin): int
    {
        // 0) Workbook
        $spreadsheet = IOFactory::load($filepath);
        $sheet = $spreadsheet->getSheetByName('BankStatement') ?? $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) {
            throw new \RuntimeException("Мөр алга.");
        }

        // 1) Header map
        $map = [];
        $rawHeaders = $rows[1];
        foreach ($rawHeaders as $k => $v) {
            if ($v === null) continue;
            $h = $this->normHeader((string)$v);

            if (in_array($h, ['date','огноо'])) $map['date'] = $k;

            if (in_array($h, [
                'description','тайлбар','гуилгээ','гүйлгээ','утга',
                'гуилгээнии утга','гүйлгээний утга','гуйлгээний утга','гуйлгээнии утга',
                'transaction details','details','detail','гүйлгээнийутга','гуйлгээнийутга'
            ])) { $map['desc'] = $k; }

            if (in_array($h, ['amount','дун','дvн','дүн'])) $map['amount'] = $k;

            if (in_array($h, ['direction','чиглэл'])) $map['dir'] = $k;

            if (in_array($h, ['category','зориулалт','ангилал'])) $map['cat'] = $k;
            if (in_array($h, ['turul','төрөл','type'])) $map['type'] = $k;

            if (in_array($h, ['currency','валют'])) $map['cur'] = $k;

            if (in_array($h, ['orlogo','орлого','income'])) $map['inc'] = $k;
            if (in_array($h, ['zaralga','зарлага','expense'])) $map['exp'] = $k;
            if (in_array($h, ['uldegdel','үлдэгдэл','улдэгдэл','balance'])) $map['bal'] = $k;

            // --- ШИНЭ: Харилцагч алиасууд ---
            if (in_array($h, ['customer','харилцагч','supplier','vendor','client','поставщик'])) {
                $map['customer'] = $k;
            }
        }

        if (!isset($map['desc'])) {
            $detected = [];
            foreach ($rawHeaders as $k => $v) {
                $detected[] = sprintf('%s:%s→%s', $k, (string)$v, $this->normHeader((string)$v));
            }
            throw new \RuntimeException(
                "Header 'desc' not found. Detected: " . implode(' | ', $detected)
            );
        }
        if (!isset($map['date'])) {
            throw new \RuntimeException("Header 'date' not found");
        }
        if (!isset($map['amount']) && !isset($map['inc']) && !isset($map['exp'])) {
            throw new \RuntimeException("Дүн эсвэл (Орлого/Зарлага) багана шаардлагатай.");
        }

        // 2) Импорт
        $count = 0;
        $total = count($rows);

        for ($i = 2; $i <= $total; $i++) {
            $r = $rows[$i] ?? null;
            if (!$r) continue;

            $descCell = $r[$map['desc']] ?? null;
            $hasAnyAmountCell =
                (isset($map['amount']) && trim((string)($r[$map['amount']] ?? '')) !== '') ||
                (isset($map['inc'])    && trim((string)($r[$map['inc']] ?? ''))    !== '') ||
                (isset($map['exp'])    && trim((string)($r[$map['exp']] ?? ''))    !== '');

            if ((trim((string)$descCell) === '') && !$hasAnyAmountCell) {
                continue;
            }

            // Огноо
            $rawDate = $r[$map['date']] ?? null;
            if ($rawDate === null || $rawDate === '') {
                continue;
            }

            $t = new Transaction();

            // origin
            if (method_exists($t, 'setOrigin')) {
                $t->setOrigin($origin); // 'CASH' / 'BANK'
            }

            // Нэвтэрсэн хэрэглэгчийг Transaction-д шивж өгөх
            $user = $this->security->getUser();
            if ($user instanceof User) {
                $t->setUser($user);
            }

            if (is_numeric($rawDate)) {
                $dt = ExcelDate::excelToDateTimeObject((float)$rawDate);
                $t->setDate(\DateTimeImmutable::createFromMutable($dt));
            } else {
                $t->setDate(new \DateTimeImmutable((string)$rawDate));
            }

            // Тайлбар
            $t->setDescription((string)$descCell);

            // Дүн/чиглэл
            $hasSignedAmount = isset($map['amount']) && trim((string)($r[$map['amount']] ?? '')) !== '';
            $hasInc = isset($map['inc']) && trim((string)($r[$map['inc']] ?? '')) !== '';
            $hasExp = isset($map['exp']) && trim((string)($r[$map['exp']] ?? '')) !== '';

            $signedVal = $hasSignedAmount ? $this->parseAmount((string)$r[$map['amount']]) : null;
            $incVal    = $hasInc ? $this->parseAmount((string)$r[$map['inc']]) : 0.0;
            $expVal    = $hasExp ? $this->parseAmount((string)$r[$map['exp']]) : 0.0;

            $amountFloat = 0.0;
            $isIncome = null;

            if ($hasInc || $hasExp) {
                if ($hasInc && !$hasExp) { $amountFloat = +abs($incVal); $isIncome = true; }
                elseif (!$hasInc && $hasExp) { $amountFloat = -abs($expVal); $isIncome = false; }
                else {
                    $net = abs($incVal) - abs($expVal);
                    $amountFloat = $net;
                    $isIncome = ($net >= 0);
                }
                if ($signedVal !== null && $signedVal != 0.0) {
                    $amountFloat = (float)$signedVal;
                    $isIncome = ($amountFloat >= 0);
                }
            } else {
                $amountFloat = (float)$signedVal;
                if (isset($map['dir'])) {
                    $dir = strtoupper(trim((string)($r[$map['dir']] ?? '')));
                    $isIncome = in_array($dir, ['IN','ORLOGO','INCOME','ОРЛОГО'], true);
                    if (!$isIncome && $amountFloat > 0) $amountFloat = -$amountFloat;
                } else {
                    $isIncome = ($amountFloat >= 0);
                }
            }

            if ($isIncome === null) $isIncome = ($amountFloat >= 0);

            $t->setAmount(number_format($amountFloat, 2, '.', ''));
            $t->setIsIncome((bool)$isIncome);

            // Ангилал / Төрөл
            if (isset($map['type']))      { $t->setCategory((string)($r[$map['type']] ?? null)); }
            elseif (isset($map['cat']))   { $t->setCategory((string)($r[$map['cat']] ?? null)); }
            else                          { $t->setCategory(null); }

            // Валют
            $t->setCurrency(isset($map['cur']) ? (string)$r[$map['cur']] : 'MNT');

            // --- ШИНЭ: Харилцагч
            if (isset($map['customer'])) {
                $t->setCustomer((string)($r[$map['customer']] ?? null));
            }

            $this->em->persist($t);
            $count++;
        }

        $this->em->flush();
        return $count;
    }

    private function normHeader(string $h): string
    {
        $h = str_replace("\xC2\xA0", ' ', $h);
        $h = preg_replace('/\s+/u', ' ', $h);
        $h = trim($h);
        $h = mb_strtolower($h);
        $h = str_replace(['ё'], ['е'], $h);
        $h = preg_replace('/[,:;()\[\]{}]+/u', '', $h);
        return trim($h);
    }

    private function parseAmount(string $s): float
    {
        $s = trim($s);
        if ($s === '') return 0.0;
        $s = str_replace("\xC2\xA0", ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_ireplace(['mnt', 'төгрөг', 'togrog', 'mnt.', 'mnt:'], '', $s);
        $s = str_replace(['₮', '¥', '$', '€'], '', $s);
        $s = trim($s);

        $negByParen = false;
        if (preg_match('/^\(\s*.+\s*\)$/', $s)) { $negByParen = true; $s = trim($s, " ()"); }

        $negByTrailing = false;
        if (preg_match('/^-?\s*[\d.,\s]+\s-\s*$/', $s) || str_ends_with($s, '-')) {
            $negByTrailing = true;
            $s = rtrim($s, "- \t\n\r\0\x0B");
        }

        if (preg_match('/^\s*[+-]?\d+(?:\.\d+)?\s*$/', $s)) {
            $val = (float)$s;
            if ($negByParen || $negByTrailing) $val = -abs($val);
            return $val;
        }

        $hasComma = str_contains($s, ',');
        $hasDot   = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($s, ','); $lastDot = strrpos($s, '.');
            if ($lastComma > $lastDot) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
            else { $s = str_replace(',', '', $s); }
        } elseif ($hasComma) {
            if (substr_count($s, ',') > 1) { $s = str_replace(',', '', $s); }
            else {
                [$left, $right] = array_pad(explode(',', $s, 2), 2, '');
                if (preg_match('/^\d{3}$/', $right)) { $s = $left . $right; }
                else { $s = $left . '.' . $right; }
            }
        } elseif ($hasDot) {
            if (substr_count($s, '.') > 1) {
                $parts = explode('.', $s); $last = array_pop($parts);
                $s = implode('', $parts) . (ctype_digit($last) ? $last : ('.'.$last));
            }
        } else {
            $s = str_replace(' ', '', $s);
        }

        $val = (float)$s;
        if ($negByParen || $negByTrailing) $val = -abs($val);
        return $val;
    }
}
