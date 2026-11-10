<?php
declare(strict_types=1);
/**
 * ติดตั้งระบบ: สร้างฐานข้อมูล ตาราง และข้อมูลเริ่มต้น
 * เมื่อติดตั้งเสร็จจะสร้างไฟล์ install/install.lock — ลบไฟล์นี้หากต้องการติดตั้งใหม่
 */
$config = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Bangkok');
$lock = __DIR__ . '/install.lock';
$cli = PHP_SAPI === 'cli';
$msg = [];
$error = null;

function out_page(array $msg, ?string $error, bool $done, bool $locked): void
{
    ?><!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ติดตั้งระบบคิว</title><link rel="stylesheet" href="../assets/style.css"></head>
    <body class="page"><main class="container narrow"><div class="card">
    <h1>ติดตั้งระบบคิวโรงพยาบาล</h1>
    <?php if ($locked): ?>
      <p class="alert warn">ระบบติดตั้งแล้ว หากต้องการติดตั้งใหม่ให้ลบไฟล์ <code>install/install.lock</code></p>
      <p><a class="btn" href="../index.php">ไปหน้าแรก</a></p>
    <?php elseif ($done): ?>
      <div class="alert ok"><?= implode('<br>', array_map('htmlspecialchars', $msg)) ?></div>
      <p>บัญชีทดสอบ (รหัสผ่านตามที่ตั้ง สำหรับ admin / รหัส <b>1234</b> สำหรับบัญชีอื่น):</p>
      <ul><li>admin — ผู้ดูแลระบบ</li><li>counter1 — เจ้าหน้าที่ต้อนรับ</li><li>doc_med / doc_ped / doc_den — แพทย์/พยาบาล</li></ul>
      <p>HN ทดสอบ: <b>000000001</b> – <b>000000010</b> (วันเกิดดูได้ในตาราง his_patient)</p>
      <p><a class="btn primary" href="../index.php">เริ่มใช้งาน</a></p>
    <?php else: ?>
      <?php if ($error): ?><p class="alert err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="post" class="form">
        <label>ชื่อโรงพยาบาล<input name="hospital" required value="โรงพยาบาลตัวอย่าง"></label>
        <label>รหัสผ่านผู้ดูแลระบบ (admin)<input type="password" name="admin_pass" minlength="6" required></label>
        <label class="check"><input type="checkbox" name="demo" value="1" checked> ใส่ข้อมูลตัวอย่าง (HN จำลอง, บัญชีทดสอบ, คิวย้อนหลัง 14 วันสำหรับรายงาน)</label>
        <button class="btn primary">ติดตั้ง</button>
      </form>
    <?php endif; ?>
    </div></main></body></html><?php
}

if (file_exists($lock)) {
    if ($cli) exit("Already installed. Delete install/install.lock to reinstall.\n");
    out_page([], null, false, true);
    exit;
}

$run = $cli || $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$run) {
    out_page([], null, false, false);
    exit;
}

$hospital = $cli ? ($argv[1] ?? 'โรงพยาบาลตัวอย่าง') : trim($_POST['hospital'] ?? '');
$adminPass = $cli ? ($argv[2] ?? 'admin1234') : (string)($_POST['admin_pass'] ?? '');
$demo = $cli ? true : !empty($_POST['demo']);

try {
    if (strlen($adminPass) < 6) throw new RuntimeException('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');

    // เชื่อมต่อโดยไม่ระบุฐานข้อมูล เพื่อสร้างฐานข้อมูลถ้ายังไม่มี
    preg_match('/dbname=([^;]+)/', $config['db']['dsn'], $m);
    $dbName = $m[1] ?? 'hp_queue';
    $dsnNoDb = preg_replace('/dbname=[^;]+;?/', '', $config['db']['dsn']);
    $pdo = new PDO($dsnNoDb, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    $msg[] = "สร้างฐานข้อมูล $dbName";

    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/schema.sql'))))) as $sql) {
        $pdo->exec($sql);
    }
    $msg[] = 'สร้างตารางเรียบร้อย';

    $settings = [
        'system_name' => 'ระบบคิวผู้ป่วยนอก',
        'hospital_name' => $hospital ?: 'โรงพยาบาล',
        'notify_ahead' => '3',
        'pharmacy_avg_min' => '10',
        'booking_days_ahead' => '7',
        'patient_verify' => 'birthdate',
        'open_time' => '07:00',
        'close_time' => '16:00',
    ];
    $st = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = svalue');
    foreach ($settings as $k => $v) $st->execute([$k, $v]);

    $depts = [
        ['MED', 'อายุรกรรม', 'A', 3, 10, 1],
        ['PED', 'กุมารเวชกรรม', 'B', 2, 12, 2],
        ['DEN', 'ทันตกรรม', 'C', 2, 20, 3],
        ['ORT', 'ศัลยกรรมกระดูก', 'D', 1, 15, 4],
        ['GP',  'ตรวจโรคทั่วไป', 'E', 4, 8, 5],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO departments (code, name, prefix, rooms, avg_service_min, sort) VALUES (?,?,?,?,?,?)');
    foreach ($depts as $d) $st->execute($d);
    $deptId = $pdo->query('SELECT code, id FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);
    $msg[] = 'เพิ่มแผนกเริ่มต้น ' . count($depts) . ' แผนก';

    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES ('admin', ?, 'ผู้ดูแลระบบ', 'admin')
                   ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)")
        ->execute([password_hash($adminPass, PASSWORD_DEFAULT)]);
    $msg[] = 'สร้างบัญชี admin';

    if ($demo) {
        $pw = password_hash('1234', PASSWORD_DEFAULT);
        $st = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash, full_name, role, department_id, room) VALUES (?,?,?,?,?,?)');
        $st->execute(['counter1', $pw, 'สมศรี ใจดี', 'staff', null, null]);
        $st->execute(['doc_med', $pw, 'นพ.วิชัย รักษาดี', 'doctor', $deptId['MED'], 'ห้องตรวจ 1']);
        $st->execute(['doc_ped', $pw, 'พญ.สุดา ห่วงใย', 'doctor', $deptId['PED'], 'ห้องตรวจ 1']);
        $st->execute(['doc_den', $pw, 'ทพ.ธนา ฟันดี', 'doctor', $deptId['DEN'], 'ห้องตรวจ 1']);

        $patients = [
            ['000000001', '1100100000011', 'นาย', 'สมชาย', 'มั่นคง', '1975-03-12', '0812345601'],
            ['000000002', '1100100000022', 'นาง', 'สมหญิง', 'ศรีสุข', '1982-07-25', '0812345602'],
            ['000000003', '1100100000033', 'ด.ช.', 'ภูมิ', 'แสงทอง', '2016-11-02', '0812345603'],
            ['000000004', '1100100000044', 'นางสาว', 'กมลชนก', 'วงศ์ใหญ่', '1995-01-30', '0812345604'],
            ['000000005', '1100100000055', 'นาย', 'ประเสริฐ', 'บุญมา', '1950-09-09', '0812345605'],
            ['000000006', '1100100000066', 'นาง', 'บุญเรือน', 'ทองดี', '1958-04-18', '0812345606'],
            ['000000007', '1100100000077', 'นาย', 'อนุชา', 'พรหมวงศ์', '1988-12-05', '0812345607'],
            ['000000008', '1100100000088', 'ด.ญ.', 'ปิยะดา', 'สุขสันต์', '2019-06-21', '0812345608'],
            ['000000009', '1100100000099', 'นางสาว', 'วรรณา', 'จันทร์เพ็ญ', '2000-02-14', '0812345609'],
            ['000000010', '1100100000100', 'นาย', 'ธีรพงษ์', 'ศักดิ์ดี', '1969-10-01', '0812345610'],
        ];
        $st = $pdo->prepare('INSERT IGNORE INTO his_patient VALUES (?,?,?,?,?,?,?)');
        foreach ($patients as $p) $st->execute($p);

        if (!(int)$pdo->query('SELECT COUNT(*) FROM his_visit')->fetchColumn()) {
            $dx = [
                ['อายุรกรรม', 'นพ.วิชัย รักษาดี', 'ความดันโลหิตสูง (I10)', 'ตรวจวัดความดัน ให้คำแนะนำการปรับพฤติกรรม', 'Amlodipine 5 mg 1x1 pc'],
                ['อายุรกรรม', 'นพ.วิชัย รักษาดี', 'เบาหวานชนิดที่ 2 (E11.9)', 'เจาะ FBS, HbA1c', 'Metformin 500 mg 1x2 pc'],
                ['ตรวจโรคทั่วไป', 'พญ.อรุณี ใสสะอาด', 'ไข้หวัด (J00)', 'ตรวจร่างกาย ให้ยาตามอาการ', 'Paracetamol 500 mg prn'],
                ['ทันตกรรม', 'ทพ.ธนา ฟันดี', 'ฟันผุ (K02.1)', 'อุดฟันซี่ 36', '-'],
                ['ศัลยกรรมกระดูก', 'นพ.ชาตรี แข็งแรง', 'ปวดหลังส่วนล่าง (M54.5)', 'X-ray L-spine, กายภาพบำบัด', 'Naproxen 250 mg 1x2 pc'],
                ['กุมารเวชกรรม', 'พญ.สุดา ห่วงใย', 'ฉีดวัคซีนตามนัด', 'วัคซีน MMR เข็ม 2', '-'],
            ];
            $st = $pdo->prepare('INSERT INTO his_visit (hn, visit_date, department, doctor, diagnosis, treatment, medication) VALUES (?,?,?,?,?,?,?)');
            foreach ($patients as $i => $p) {
                $n = 2 + $i % 4;
                for ($k = 0; $k < $n; $k++) {
                    $row = $dx[($i + $k) % count($dx)];
                    if (str_starts_with($p[2], 'ด.') ) $row = $dx[$k % 2 ? 5 : 2];
                    $st->execute([$p[0], date('Y-m-d', strtotime('-' . (20 + 45 * $k + $i * 3) . ' days')), ...$row]);
                }
            }
        }

        // คิวย้อนหลัง 14 วัน สำหรับรายงานสถิติ
        if (!(int)$pdo->query('SELECT COUNT(*) FROM queues')->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO queues (queue_date, department_id, queue_no, queue_code, hn, patient_name, source, status,
                room, created_at, checkin_at, called_at, announced_at, pharmacy_at, finished_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $cnt = $pdo->prepare('INSERT INTO queue_counters VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_no = VALUES(last_no)');
            $sources = ['kiosk', 'kiosk', 'counter', 'online'];
            mt_srand(2569);
            foreach ($depts as $d) {
                $did = $deptId[$d[0]];
                for ($day = 14; $day >= 1; $day--) {
                    $date = date('Y-m-d', strtotime("-$day days"));
                    if (date('N', strtotime($date)) == 7) continue; // ปิดวันอาทิตย์
                    $total = mt_rand(12, 40);
                    $t = strtotime("$date 07:30");
                    $docFree = $t;
                    for ($no = 1; $no <= $total; $no++) {
                        $t += mt_rand(2, 12) * 60;
                        $p = $patients[mt_rand(0, 9)];
                        $r = mt_rand(1, 100);
                        $status = $r <= 4 ? 'cancelled' : ($r <= 8 ? 'skipped' : 'done');
                        $call = max($t, $docFree) + mt_rand(1, 5) * 60;
                        $svc = (int)($d[4] * 60 * (0.6 + mt_rand(0, 80) / 100));
                        $docFree = $status === 'done' ? $call + (int)($svc / $d[3]) : $docFree;
                        $ph = $status === 'done' ? $call + $svc : null;
                        $fin = $status === 'done' ? $ph + mt_rand(4, 18) * 60 : ($status === 'cancelled' ? $t + 600 : null);
                        $f = fn($x) => $x ? date('Y-m-d H:i:s', $x) : null;
                        $ins->execute([$date, $did, $no, $d[2] . str_pad((string)$no, 3, '0', STR_PAD_LEFT), $p[0], $p[2] . $p[3] . ' ' . $p[4],
                            $sources[mt_rand(0, 3)], $status, $status === 'cancelled' ? null : 'ห้องตรวจ ' . mt_rand(1, $d[3]),
                            $f($t), $f($t), $status === 'cancelled' ? null : $f($call), $status === 'cancelled' ? null : $f($call), $f($ph), $f($fin)]);
                    }
                    $cnt->execute([$date, $did, $total]);
                }
            }
            $msg[] = 'สร้างข้อมูลคิวย้อนหลังสำหรับรายงาน';
        }
        $msg[] = 'เพิ่มข้อมูลตัวอย่าง (HN จำลอง 10 ราย, บัญชีทดสอบ 4 บัญชี)';
    }

    file_put_contents($lock, date('c'));
    if ($cli) {
        echo implode("\n", $msg), "\n";
        exit;
    }
    out_page($msg, null, true, false);
} catch (Throwable $ex) {
    if ($cli) {
        fwrite(STDERR, $ex->getMessage() . "\n");
        exit(1);
    }
    out_page([], $ex->getMessage(), false, false);
}
