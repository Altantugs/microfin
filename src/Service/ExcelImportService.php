<?php
namespace App\Service;

use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ExcelImportService
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Дэмжих толгойнууд:
     * 1) Хуучин: Огноо | Тайлбар | Дүн | Чиглэл | Ангилал | Валют
     * 2) Банкны хуулга: Огноо | Гүйлгээний утга | Дүн | Орлого | Зарлага | Үлдэгдэл | Төрөл
     */
    public function import(string $filepath): int
    {
        $sheet = IOFactory::load($filepath)->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) {
            throw new \RuntimeException("Мөр алга.");
        }

        // --- Толгой мөрийг map болгох
        $map = [];
        foreach ($rows[1] as $k => $v) {
            if ($v === null) continue;
            $h = $this->normHeader((string)$v);

            // Ерөнхий
            if (in_array($h, ['date','огноо'])) $map['date'] = $k;

            // Тайлбар / Гүйлгээний утга
            if (in_array($h, [
                'description','тайлбар','гуилгээ','гүйлгээ','утга','гуилгээнии утга','гүйлгээний утга',
                'transaction details','details','detail'
            ])) { $map['desc'] = $k; }

            // Дүн (signed)
            if (in_array($h, ['amount','дун','дvн','дүн'])) $map['amount'] = $k;

            // Чиглэл (хуучин IN/OUT)
            if (in_array($h, ['direction','чиглэл'])) $map['dir'] = $k;

            // Ангилал / Төрөл
            if (in_array($h, ['category','зориулалт','ангилал'])) $map['cat'] = $k;
            if (in_array($h, ['turul','төрөл','type'])) $map['type'] = $k;

            // Валют
            if (in_array($h, ['currency','валют'])) $map['cur'] = $k;

            // Банкны хуулга: Орлого / Зарлага / Үлдэгдэл
            if (in_array($h, ['orlogo','орлого','income'])) $map['inc'] = $k;
            if (in_array($h, ['zaralga','зарлага','expense'])) $map['exp'] = $k;
            if (in_array($h, ['uldegdel','үлдэгдэл','улдэгдэл','balance'])) $map['bal'] = $k; // импортод заавал биш
        }

        // Заавал: огноо + тайлбар + (дүн эсвэл (орлого/зарлага))
        foreach (['date','desc'] as $req) {
            if (!isset($map[$req])) {
                throw new \RuntimeException("Header '$req' not found");
            }
        }
        if (!isset($map['amount']) && !isset($map['inc']) && !isset($map['exp'])) {
            throw new \RuntimeException("Дүн эсвэл (Орлого/Зарлага) багана шаардлагатай.");
        }

        $count = 0;
        $total = count($rows);

        for ($i = 2; $i <= $total; $i++) {
            $r = $rows[$i] ?? null;
            if (!$r) continue;

            // Хоосон мөр алгас: тайлбар хоосон + дүн/орлого/зарлага бүгд хоосон бол
            $descCell = $r[$map['desc']] ?? null;
            $hasAnyAmountCell =
                (isset($map['amount']) && trim((string)($r[$map['amount']] ?? '')) !== '') ||
                (isset($map['inc'])    && trim((string)($r[$map['inc']] ?? ''))    !== '') ||
                (isset($map['exp'])    && trim((string)($r[$map['exp']] ?? ''))    !== '');
            if ((trim((string)$descCell) === '') && !$hasAnyAmountCell) {
                continue;
            }

            // Огноо (заавал)
            $rawDate = $r[$map['date']] ?? null;
            if ($rawDate === null || $rawDate === '') {
                continue; // огноогүй мөр алгас
            }

            $t = new Transaction();

            if (is_numeric($rawDate)) {
                $dt = ExcelDate::excelToDateTimeObject((float)$rawDate);
                $t->setDate(\DateTimeImmutable::createFromMutable($dt));
            } else {
                $t->setDate(new \DateTimeImmutable((string)$rawDate));
            }

            // Тайлбар
            $t->setDescription((string)$descCell);

            // === Дүн/чиглэл тогтоох
            $hasSignedAmount = isset($map['amount']) && trim((string)($r[$map['amount']] ?? '')) !== '';
            $hasInc = isset($map['inc']) && trim((string)($r[$map['inc']] ?? '')) !== '';
            $hasExp = isset($map['exp']) && trim((string)($r[$map['exp']] ?? '')) !== '';

            $signedVal = $hasSignedAmount ? $this->parseAmount((string)$r[$map['amount']]) : null;
            $incVal    = $hasInc ? $this->parseAmount((string)$r[$map['inc']]) : 0.0; // Орлого (эерэг)
            $expVal    = $hasExp ? $this->parseAmount((string)$r[$map['exp']]) : 0.0; // Зарлага (эерэг)

            $amountFloat = 0.0;
            $isIncome = null;

            if ($hasInc || $hasExp) {
                // Банкны хуулга горим
                if ($hasInc && !$hasExp) {
                    $amountFloat = +abs($incVal);
                    $isIncome = true;
                } elseif (!$hasInc && $hasExp) {
                    $amountFloat = -abs($expVal);
                    $isIncome = false;
                } else {
                    // Хоёулаа бөглөгдвөл нет
                    $net = abs($incVal) - abs($expVal);
                    $amountFloat = $net;
                    $isIncome = ($net >= 0);
                }

                // Хэрэв signed "Дүн" мөн ирсэн бол давуу эрх
                if ($signedVal !== null && $signedVal != 0.0) {
                    $amountFloat = (float)$signedVal;
                    $isIncome = ($amountFloat >= 0);
                }
            } else {
                // Хуучин загвар: зөвхөн "Дүн" (+магадгүй "Чиглэл")
                $amountFloat = (float)$signedVal;
                if (isset($map['dir'])) {
                    $dir = strtoupper(trim((string)($r[$map['dir']] ?? '')));
                    $isIncome = in_array($dir, ['IN','ORLOGO','INCOME','ОРЛОГО'], true);
                    if (!$isIncome && $amountFloat > 0) {
                        $amountFloat = -$amountFloat; // OUT → сөрөг
                    }
                } else {
                    $isIncome = ($amountFloat >= 0);
                }
            }

            if ($isIncome === null) {
                $isIncome = ($amountFloat >= 0);
            }

            // Хадгалах формат (2 орон)
            $val = number_format($amountFloat, 2, '.', '');
            $t->setAmount($val);
            $t->setIsIncome((bool)$isIncome);

            // Ангилал / Төрөл
            if (isset($map['type'])) {
                $t->setCategory((string)($r[$map['type']] ?? null));
            } elseif (isset($map['cat'])) {
                $t->setCategory((string)($r[$map['cat']] ?? null));
            } else {
                $t->setCategory(null);
            }

            // Валют
            $t->setCurrency(isset($map['cur']) ? (string)$r[$map['cur']] : 'MNT');

            $this->em->persist($t);
            $count++;
        }

        $this->em->flush();
        return $count;
    }

    /** Header-ийг normalize: lower, NBSP→space, олон space-ийг нэг, тусгай үсэг сольж, цэг таслалыг авч хаяна */
    private function normHeader(string $h): string
    {
        $h = str_replace("\xC2\xA0", ' ', $h);        // NBSP → space
        $h = preg_replace('/\s+/u', ' ', $h);         // олон space → 1
        $h = trim($h);
        $h = mb_strtolower($h);
        // Монгол үсгийн normalize
        $h = str_replace(['ё','ө','ү'], ['е','o','u'], $h);
        // Таслал, цэг, двоеточие гэх мэт тэмдэгтийг авч, дахин trim
        $h = preg_replace('/[,:;()\[\]{}]+/u', '', $h);
        $h = trim($h);
        return $h;
    }

    /**
     * Тоон утгыг уян хатан хөрвүүлнэ:
     * - 1234, 1234.56
     * - 1,234,567.89 (US), 1.234.567,89 (EU)
     * - "1 500 000", NBSP
     * - "(15000)" → -15000, "25000-" → -25000
     * - "₮1,500.25", "MNT 1,500.25" → 1500.25
     */
    private function parseAmount(string $s): float
    {
        $s = trim($s);
        if ($s === '') return 0.0;

        // Валют/текстийн цэвэрлэгээ
        $s = str_replace("\xC2\xA0", ' ', $s);        // NBSP
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_ireplace(['mnt', 'төгрөг', 'togrog', 'mnt.', 'mnt:'], '', $s);
        $s = str_replace(['₮', '¥', '$', '€'], '', $s);
        $s = trim($s);

        // Хаалттай эсэх
        $negByParen = false;
        if (preg_match('/^\(\s*.+\s*\)$/', $s)) {
            $negByParen = true;
            $s = trim($s, " ()");
        }

        // Арын минус
        $negByTrailing = false;
        if (preg_match('/^-?\s*[\d.,\s]+\s-\s*$/', $s) || str_ends_with($s, '-')) {
            $negByTrailing = true;
            $s = rtrim($s, "- \t\n\r\0\x0B");
        }

        // Шууд стандарт хэлбэр
        if (preg_match('/^\s*[+-]?\d+(?:\.\d+)?\s*$/', $s)) {
            $val = (float)$s;
            if ($negByParen || $negByTrailing) $val = -abs($val);
            return $val;
        }

        $hasComma = str_contains($s, ',');
        $hasDot   = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // Сүүлийн тусгаарлагчыг аравтын гэж үзэх
            $lastComma = strrpos($s, ',');
            $lastDot   = strrpos($s, '.');
            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    // ЕХ: 1.500.000,25
                    $s = str_replace('.', '', $s);
                    $s = str_replace(',', '.', $s);
                } else {
                    // АНУ: 1,500,000.25
                    $s = str_replace(',', '', $s);
                }
            }
        } elseif ($hasComma) {
            if (substr_count($s, ',') > 1) {
                $s = str_replace(',', '', $s); // олон comma → мянгтын
            } else {
                [$left, $right] = array_pad(explode(',', $s, 2), 2, '');
                if (preg_match('/^\d{3}$/', $right)) {
                    $s = $left . $right; // 1,234 → 1234
                } else {
                    $s = $left . '.' . $right; // 12,34 → 12.34
                }
            }
        } elseif ($hasDot) {
            if (substr_count($s, '.') > 1) {
                $parts = explode('.', $s);
                $last  = array_pop($parts);
                $s = implode('', $parts) . (ctype_digit($last) ? $last : ('.'.$last));
            }
        } else {
            // Зайтай мянгтын тэмдэг
            $s = str_replace(' ', '', $s);
        }

        $val = (float)$s;
        if ($negByParen || $negByTrailing) $val = -abs($val);
        return $val;
    }
}
