<?php
/**
 * ตัวเชื่อมต่อฐานข้อมูล HN ของโรงพยาบาล (HIS)
 * แก้ SQL ได้ที่ config.php ส่วน 'his' โดยไม่ต้องแก้ไฟล์นี้
 */
final class His
{
    private static ?PDO $pdo = null;

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = $GLOBALS['CONFIG']['his'];
            if (empty($c['dsn'])) {
                self::$pdo = db();
            } else {
                self::$pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
            }
        }
        return self::$pdo;
    }

    /** ทำ HN ให้อยู่ในรูปแบบมาตรฐาน (ตัดช่องว่าง/ขีด, เติม 0 ด้านหน้า) */
    public static function normalizeHn(string $hn): string
    {
        $hn = preg_replace('/[^0-9A-Za-z]/', '', trim($hn)) ?? '';
        $pad = (int)($GLOBALS['CONFIG']['his']['hn_pad'] ?? 0);
        if ($pad > 0 && ctype_digit($hn) && strlen($hn) < $pad) {
            $hn = str_pad($hn, $pad, '0', STR_PAD_LEFT);
        }
        return $hn;
    }

    public static function findPatient(string $hn): ?array
    {
        $hn = self::normalizeHn($hn);
        if ($hn === '') return null;
        $st = self::pdo()->prepare($GLOBALS['CONFIG']['his']['sql_patient']);
        $st->execute([':hn' => $hn]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['fullname'] = trim(($r['prename'] ?? '') . ($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
        return $r;
    }

    /** เดาเพศจากคำนำหน้าชื่อ: 'm' ชาย, 'f' หญิง, null ไม่ทราบ (เช่น ยศ/ตำแหน่งที่ไม่ระบุเพศ) */
    public static function genderFromName(string $name): ?string
    {
        $name = ltrim($name);
        foreach (['นาย', 'ด.ช.', 'เด็กชาย', 'พระ', 'สามเณร', 'Mr.', 'Mr ', 'Master'] as $p) {
            if (str_starts_with($name, $p)) return 'm';
        }
        foreach (['นาง', 'น.ส.', 'ด.ญ.', 'เด็กหญิง', 'แม่ชี', 'Mrs', 'Miss', 'Ms'] as $p) {
            if (str_starts_with($name, $p)) return 'f';
        }
        return null;
    }

    public static function history(string $hn): array
    {
        $st = self::pdo()->prepare($GLOBALS['CONFIG']['his']['sql_history']);
        $st->execute([':hn' => self::normalizeHn($hn)]);
        return $st->fetchAll();
    }
}
