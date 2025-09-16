<?php
namespace App\Service;

use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ExcelImportService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function import(string $filepath): int
    {
        $sheet = IOFactory::load($filepath)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) {
            throw new \RuntimeException("Мөр алга.");
        }

        // Толгой мөрийг map болгох
        $map = [];
        foreach ($rows[1] as $k => $v) {
            if ($v === null) continue;
            $h = mb_strtolower(trim((string)$v));
            $h = str_replace(['ё','ө','ү'], ['е','o','u'], $h); // normalize
            if (in_array($h, ['date','огноо'])) $map['date']=$k;
            if (in_array($h, ['description','гүйлгээ','утга'])) $map['desc']=$k;
            if (in_array($h, ['amount','дүн','дvн'])) $map['amount']=$k;
            if (in_array($h, ['direction','чиглэл'])) $map['dir']=$k;
            if (in_array($h, ['category','зориулалт','ангилал'])) $map['cat']=$k;
            if (in_array($h, ['currency','валют'])) $map['cur']=$k;
        }
        foreach (['date','desc','amount'] as $req) {
            if (!isset($map[$req])) {
                throw new \RuntimeException("Header '$req' not found");
            }
        }

        $count = 0;
        $total = count($rows);
        for ($i = 2; $i <= $total; $i++) {
            $r = $rows[$i] ?? null;
            if (!$r) continue;

            // хоосон мөр алгас
            if (($r[$map['desc']] ?? null) === null && ($r[$map['amount']] ?? null) === null) {
                continue;
            }

            $t = new Transaction();

            // Огноо (Excel serial эсвэл string-г дэмжинэ)
            $rawDate = $r[$map['date']];
            if (is_numeric($rawDate)) {
                $dt = ExcelDate::excelToDateTimeObject((float)$rawDate);
                $t->setDate(\DateTimeImmutable::createFromMutable($dt));
            } else {
                $t->setDate(new \DateTimeImmutable((string)$rawDate));
            }

            // Тайлбар
            $t->setDescription((string)$r[$map['desc']]);

            // Дүн — parseAmount ашиглана
            $rawAmount   = (string)($r[$map['amount']] ?? '0');
            $amountFloat = $this->parseAmount($rawAmount);

            // Чиглэл — OUT бол сөрөг болгох (хэрэв Excel дээр дандаа эерэгээр өгдөг бол хэрэгтэй)
            if (isset($map['dir'])) {
                $dir = strtoupper(trim((string)$r[$map['dir']]));
                $isIncome = in_array($dir, ['IN','ORLOGO','INCOME'], true);
                if (!$isIncome && $amountFloat > 0) {
                    $amountFloat = -$amountFloat;
                }
                $t->setIsIncome($isIncome);
            } else {
                $t->setIsIncome($amountFloat >= 0);
            }

            // Хадгалах формат (string decimal 2 орон)
            $val = number_format($amountFloat, 2, '.', '');
            $t->setAmount($val);

            // Ангилал, Валют
            $t->setCategory(isset($map['cat']) ? (string)$r[$map['cat']] : null);
            $t->setCurrency(isset($map['cur']) ? (string)$r[$map['cur']] : 'MNT');

            $this->em->persist($t);
            $count++;
        }

        $this->em->flush();
        return $count;
    }

    private function parseAmount(string $s): float
    {
        $s = trim($s);

        // Аль хэдийн энгийн 1234 эсвэл 1234.56 бол шууд
        if ($s === '' || preg_match('/^\s*[+-]?\d+(\.\d+)?\s*$/', $s)) {
            return (float) $s;
        }

        $hasComma = str_contains($s, ',');
        $hasDot   = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // Хамгийн сүүлчийн тэмдэг нь ихэвчлэн аравтын тусгаарлагч
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
            return (float) $s;
        }

        if ($hasComma) {
            if (substr_count($s, ',') > 1) {
                // Олон comma = мянгтын тусгаарлагч
                $s = str_replace(',', '', $s);
                return (float) $s;
            }
            // Нэг comma байвал баруун тал нь 3 оронтой эсэхээр шийдье
            [$left, $right] = array_pad(explode(',', $s, 2), 2, '');
            if (preg_match('/^\d{3}$/', $right)) {
                $s = $left . $right; // мянгтын comma-г авч байна
            } else {
                $s = $left . '.' . $right; // аравтын comma-г '.' болголоо
            }
            return (float) $s;
        }

        if ($hasDot) {
            if (substr_count($s, '.') > 1) {
                // Олон dot = ихэнх нь мянгтын, бүгдийг авч, бүхэл тоо/сүүлийнх үлдэнэ
                $parts = explode('.', $s);
                $last  = array_pop($parts);
                $s = implode('', $parts) . $last;
            }
            return (float) $s;
        }

        // Зайтай мянгтын тэмдэг: "1 500 000"
        $s = str_replace(' ', '', $s);
        return (float) $s;
    }
}
