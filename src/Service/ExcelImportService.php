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
     * Дараах толгойнуудыг танина:
     * 1) Хуучин загвар:
     *    Огноо | Тайлбар | Дүн | Чиглэл | Ангилал | Валют
     * 2) Банкны хуулга загвар:
     *    Огноо | Гүйлгээний утга | Дүн | Орлого | Зарлага | Үлдэгдэл | Төрөл
     *
     * Тайлбар:
     *  - "Дүн" (signed) ирвэл тэмдэгтэй дүнд ДАВУУ ЭРХ өгнө.
     *  - "Орлого"/"Зарлага" ирвэл тэдгээрээс чиглэл+дүнг тогтооно (хоёул бөглөгдвөл нетээр).
     *  - "Чиглэл" (IN/OUT) ирвэл хуучин загварт хэрэглэнэ.
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
            $h = mb_strtolower(trim((string)$v));

            // Монгол үсгийн normalize
            $h = str_replace(['ё','ө','ү'], ['е','o','u'], $h);

            // Ерөнхий талбарууд
            if (in_array($h, ['date','огноо'])) $map['date'] = $k;

            if (in_array($h, [
                'description','гуилгээ','гүйлгээ','утга','гуилгээнии утга','гүйлгээний утга','тайлбар'
            ])) $map['desc'] = $k;

            if (in_array($h, ['amount','дун','дvн','дүн'])) $map['amount'] = $k;

            if (in_array($h, ['direction','чиглэл'])) $map['dir'] = $k;

            if (in_array($h, ['category','зориулалт','ангилал'])) $map['cat'] = $k;

            if (in_array($h, ['currency','валют'])) $map['cur'] = $k;

            // Банкны хуулгын нэмэлтүүд
            if (in_array($h, ['orlogo','орлого'])) $map['inc'] = $k;
            if (in_array($h, ['zaralga','зарлага'])) $map['exp'] = $k;
            if (in_array($h, ['uldegdel','үлдэгдэл','улдэгдэл'])) $map['bal'] = $k; // (одоохондоо хадгалахгүй)
            if (in_array($h, ['turul','төрөл'])) $map['type'] = $k; // = category
        }

        // Заавал байх: огноо + тайлбар + (дун эсвэл (орлого/зарлага))
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
            $incVal    = $hasInc ? $this->parseAmount((string)$r[$map['inc']]) : 0.0; // Орлого (эерэг гэж үзнэ)
            $expVal    = $hasExp ? $this->parseAmount((string)$r[$map['exp']]) : 0.0; // Зарлага (эерэг гэж үзнэ)

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

                // Хэрэв signed "Дүн" мөн ирсэн бол түүнд давуу эрх өгнө
                if ($signedVal !== null && $signedVal != 0.0) {
                    $amountFloat = (float)$signedVal; // тэмдэгтэй дүн
                    $isIncome = ($amountFloat >= 0);
                }
            } else {
                // Хуучин загвар: зөвхөн "Дүн" (+ магадгүй "Чиглэл")
                $amountFloat = (float)$signedVal;
                if (isset($map['dir'])) {
                    $dir = strtoupper(trim((string)($r[$map['dir']] ?? '')));
                    $isIncome = in_array($dir, ['IN','ORLOGO','INCOME'], true);
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

            // Ангилал/Төрөл
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

    /**
     * Тоон утгыг уян хатан хөрвүүлнэ.
     * Дэмжлэг:
     *  - Энгийн: 1234, 1234.56
     *  - АНУ: 1,234,567.89
     *  - ЕХ: 1.234.567,89
     *  - Зайтай: "1 500 000", NBSP "\xC2\xA0"
     *  - Хаалт: "(15000)" → -15000
     *  - Арын тэмдэг: "25000-" → -25000
     *  - Валютын тэмдэг/текст: "₮1,500.25", "MNT 1,500.25" → 1500.25
     */
    private function parseAmount(string $s): float
    {
        $s = trim($s);
        if ($s === '') return 0.0;

        // Валют/текстийн ерөнхий цэвэрлэгээ
        // MNT, ₮, төгрөг гэх мэтийг авч хаяна (үсгийн хоорондох олон зайг нэг болгоно)
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_replace(["\xC2\xA0"], ' ', $s); // NBSP → space
        $s = str_ireplace(['mnt', 'төгрөг', 'togrog', 'mnt.', 'mnt:'], '', $s);
        $s = str_replace(['₮', '¥', '$', '€'], '', $s);
        $s = trim($s);

        // Хаалттай бол сөрөг гэж үзнэ: (1234.56)
        $negByParen = false;
        if (preg_match('/^\(\s*.+\s*\)$/', $s)) {
            $negByParen = true;
            $s = trim($s, " ()");
        }

        // Арын минус: 25000-
        $negByTrailing = false;
        if (preg_match('/^-?\s*[\d.,\s]+\s-\s*$/', $s) || str_ends_with($s, '-')) {
            $negByTrailing = true;
            $s = rtrim($s, "- \t\n\r\0\x0B");
        }

        // Хэрэв шууд стандарт хэлбэр бол
        if (preg_match('/^\s*[+-]?\d+(?:\.\d+)?\s*$/', $s)) {
            $val = (float)$s;
            if ($negByParen || $negByTrailing) $val = -abs($val);
            return $val;
        }

        $hasComma = str_contains($s, ',');
        $hasDot   = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // Сүүлийн тэмдэгийг аравтын гэж үзэх
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
                // олон comma → мянгтын
                $s = str_replace(',', '', $s);
            } else {
                // нэг comma: баруун тал 3 оронтой бол мянгтын, бусад тохиолдолд аравтын
                [$left, $right] = array_pad(explode(',', $s, 2), 2, '');
                if (preg_match('/^\d{3}$/', $right)) {
                    $s = $left . $right;
                } else {
                    $s = $left . '.' . $right;
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

        // Эцсийн хөрвүүлэлт
        $val = (float)$s;
        if ($negByParen || $negByTrailing) $val = -abs($val);
        return $val;
    }
}
