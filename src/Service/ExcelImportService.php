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
     * Excel header жишээ:
     * Date, Description, Amount, Direction(IN/OUT), Category, Currency
     * Мөн монгол хэлний: Огноо, Гүйлгээ/Утга, Дүн, Чиглэл, Зориулалт/Ангилал, Валют
     */
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
        for ($i=2; $i <= $total; $i++) {
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

            // Дүн ("," → "." гэх мэт normalize)
            $rawAmount = (string)($r[$map['amount']] ?? '0');
            $norm = str_replace([' ', ','], ['', '.'], $rawAmount);
            $val = number_format((float)$norm, 2, '.', '');
            $t->setAmount($val);

            // Орлого/Зарлага (Direction байхгүй бол дүн > 0-г орлого гэж үзье)
            if (isset($map['dir'])) {
                $dir = strtoupper((string)$r[$map['dir']]);
                $t->setIsIncome($dir === 'IN' || $dir === 'ORLOGO' || $dir === 'INCOME');
            } else {
                $t->setIsIncome(((float)$val) >= 0);
            }

            // Ангилал, Валют
            $t->setCategory(isset($map['cat']) ? (string)$r[$map['cat']] : null);
            $t->setCurrency(isset($map['cur']) ? (string)$r[$map['cur']] : 'MNT');

            $this->em->persist($t);
            $count++;
        }

        $this->em->flush();
        return $count;
    }
}
