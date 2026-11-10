<?php
/**
 * ตรรกะหลักของระบบคิว
 *
 * สถานะในฐานข้อมูล -> ขั้นตอน (progress bar)
 *   registered  ขั้นตอนที่ 1 ลงทะเบียน        (จองออนไลน์ รอยืนยันตัวที่จุดบริการ)
 *   waiting     ขั้นตอนที่ 2 รอพบแพทย์
 *   serving     ขั้นตอนที่ 3 พบแพทย์
 *   pharmacy    ขั้นตอนที่ 4 รับยา/ชำระเงิน
 *   done        เสร็จสิ้นครบทุกขั้นตอน
 *   skipped     เรียกแล้วไม่มา (เลยคิว) — เจ้าหน้าที่นำกลับเข้าคิวได้
 *   cancelled   ยกเลิก
 */
final class Queue
{
    public const STEPS = [1 => 'ลงทะเบียน', 2 => 'รอพบแพทย์', 3 => 'พบแพทย์', 4 => 'รับยา/ชำระเงิน'];

    public const STATUS_STEP = [
        'registered' => 1, 'waiting' => 2, 'serving' => 3, 'pharmacy' => 4,
        'done' => 5, 'skipped' => 2, 'cancelled' => 0,
    ];

    public const STATUS_LABEL = [
        'registered' => 'รอยืนยันลงทะเบียน', 'waiting' => 'รอเรียก', 'serving' => 'กำลังพบแพทย์',
        'pharmacy' => 'รอรับยา/ชำระเงิน', 'done' => 'เสร็จสิ้น', 'skipped' => 'เลยคิว', 'cancelled' => 'ยกเลิก',
    ];

    public const SOURCE_LABEL = ['kiosk' => 'ตู้รับบัตรคิว', 'counter' => 'เคาน์เตอร์', 'online' => 'จองออนไลน์'];

    /** สถานะภาพรวม 3 แบบ: รอเรียก / กำลังรับบริการ / เสร็จสิ้น */
    public static function phase(string $status): array
    {
        return match ($status) {
            'registered', 'waiting' => ['key' => 'wait', 'label' => 'รอเรียก'],
            'serving', 'pharmacy'   => ['key' => 'serve', 'label' => 'กำลังรับบริการ'],
            'done'                  => ['key' => 'done', 'label' => 'เสร็จสิ้น'],
            'skipped'               => ['key' => 'skip', 'label' => 'เลยคิว'],
            default                 => ['key' => 'cancel', 'label' => 'ยกเลิกแล้ว'],
        };
    }

    public static function departments(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM departments' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id';
        return db()->query($sql)->fetchAll();
    }

    public static function department(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM departments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT q.*, d.name AS dept_name, d.code AS dept_code
                             FROM queues q JOIN departments d ON d.id = q.department_id WHERE q.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** คิวที่ยังไม่จบของ HN (วันนี้และวันที่จองล่วงหน้า) */
    public static function activeForHn(string $hn): array
    {
        $st = db()->prepare("SELECT q.*, d.name AS dept_name, d.code AS dept_code
            FROM queues q JOIN departments d ON d.id = q.department_id
            WHERE q.hn = ? AND (q.queue_date > CURDATE()
                  OR (q.queue_date = CURDATE() AND q.status NOT IN ('cancelled')))
            ORDER BY q.queue_date, FIELD(q.status,'serving','pharmacy','waiting','registered','skipped','done'), q.id DESC");
        $st->execute([$hn]);
        return $st->fetchAll();
    }

    /**
     * ออกบัตรคิว
     * @param string $source kiosk|counter|online
     */
    public static function issue(string $hn, int $deptId, string $source, ?string $date = null, ?int $userId = null): array
    {
        $date ??= date('Y-m-d');
        $patient = His::findPatient($hn);
        if (!$patient) {
            throw new RuntimeException('ไม่พบ HN นี้ในฐานข้อมูลโรงพยาบาล');
        }
        $hn = $patient['hn'];
        $dept = self::department($deptId);
        if (!$dept || !$dept['active']) {
            throw new RuntimeException('แผนกนี้ปิดให้บริการ');
        }
        if ($date < date('Y-m-d')) {
            throw new RuntimeException('ไม่สามารถจองย้อนหลังได้');
        }
        $maxDays = (int)setting('booking_days_ahead', 7);
        if ($date > date('Y-m-d', strtotime("+{$maxDays} days"))) {
            throw new RuntimeException("จองล่วงหน้าได้ไม่เกิน {$maxDays} วัน");
        }
        if ((int)$dept['daily_limit'] > 0) {
            $st = db()->prepare("SELECT COUNT(*) FROM queues WHERE queue_date = ? AND department_id = ? AND status <> 'cancelled'");
            $st->execute([$date, $deptId]);
            if ((int)$st->fetchColumn() >= (int)$dept['daily_limit']) {
                throw new RuntimeException('คิวของแผนกนี้เต็มแล้วสำหรับวันที่เลือก');
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, queue_code FROM queues WHERE hn = ? AND department_id = ? AND queue_date = ?
                                 AND status IN ('registered','waiting','serving','pharmacy','skipped') FOR UPDATE");
            $st->execute([$hn, $deptId, $date]);
            if ($dup = $st->fetch()) {
                throw new RuntimeException('HN นี้มีคิวของแผนกนี้อยู่แล้ว (คิว ' . $dup['queue_code'] . ')');
            }

            // ขอเลขคิวแบบ atomic
            $pdo->prepare('INSERT INTO queue_counters (queue_date, department_id, last_no) VALUES (?, ?, LAST_INSERT_ID(1))
                           ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')
                ->execute([$date, $deptId]);
            $no = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            $code = $dept['prefix'] . str_pad((string)$no, 3, '0', STR_PAD_LEFT);

            $isBooking = ($source === 'online') || $date > date('Y-m-d');
            $status = $isBooking ? 'registered' : 'waiting';
            $pdo->prepare('INSERT INTO queues (queue_date, department_id, queue_no, queue_code, hn, patient_name,
                              source, status, created_at, checkin_at, created_by)
                           VALUES (?,?,?,?,?,?,?,?,NOW(),?,?)')
                ->execute([$date, $deptId, $no, $code, $hn, $patient['fullname'], $source, $status,
                    $isBooking ? null : date('Y-m-d H:i:s'), $userId]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        return self::find($id);
    }

    /** ยืนยันลงทะเบียน (ผู้ป่วยที่จองออนไลน์มาถึงโรงพยาบาล) */
    public static function checkin(int $id): array
    {
        $q = self::find($id);
        if (!$q || $q['status'] !== 'registered') throw new RuntimeException('คิวนี้ไม่อยู่ในสถานะรอยืนยันลงทะเบียน');
        if ($q['queue_date'] !== date('Y-m-d')) throw new RuntimeException('ยืนยันได้เฉพาะคิวของวันนี้ (คิวนี้วันที่ ' . thai_date($q['queue_date']) . ')');
        db()->prepare("UPDATE queues SET status='waiting', checkin_at=NOW() WHERE id=? AND status='registered'")->execute([$id]);
        return self::find($id);
    }

    /** เรียกคิวถัดไปของแผนก เข้าห้องตรวจ */
    public static function callNext(int $deptId, string $room, ?int $userId): ?array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id FROM queues WHERE queue_date = CURDATE() AND department_id = ? AND status = 'waiting'
                                 ORDER BY checkin_at, queue_no LIMIT 1 FOR UPDATE");
            $st->execute([$deptId]);
            $id = $st->fetchColumn();
            if (!$id) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare("UPDATE queues SET status='serving', room=?, called_at=NOW(), announced_at=NOW(), called_by=?, recall_count=0 WHERE id=?")
                ->execute([$room, $userId, $id]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        return self::find((int)$id);
    }

    /** เรียกคิวที่ระบุ (ข้ามลำดับ เช่น ผู้ป่วยฉุกเฉิน/ผู้สูงอายุ) */
    public static function callSpecific(int $id, string $room, ?int $userId): array
    {
        $st = db()->prepare("UPDATE queues SET status='serving', room=?, called_at=NOW(), announced_at=NOW(), called_by=?, recall_count=0
                             WHERE id=? AND status IN ('waiting','skipped') AND queue_date = CURDATE()");
        $st->execute([$room, $userId, $id]);
        if (!$st->rowCount()) throw new RuntimeException('ไม่สามารถเรียกคิวนี้ได้');
        return self::find($id);
    }

    public static function transition(int $id, string $action): array
    {
        [$from, $sql] = match ($action) {
            'recall'   => [['serving'], "recall_count = recall_count + 1, announced_at = NOW()"],
            'skip'     => [['serving', 'waiting'], "status='skipped'"],
            'restore'  => [['skipped'], "status='waiting', room=NULL"],
            'pharmacy' => [['serving'], "status='pharmacy', pharmacy_at=NOW()"],
            'finish'   => [['serving', 'pharmacy'], "status='done', finished_at=NOW()"],
            'cancel'   => [['registered', 'waiting', 'skipped'], "status='cancelled', finished_at=NOW()"],
            'back'     => [['serving'], "status='waiting', room=NULL, called_at=NULL, announced_at=NULL"],
            default    => throw new RuntimeException('คำสั่งไม่ถูกต้อง'),
        };
        $in = implode(',', array_fill(0, count($from), '?'));
        $st = db()->prepare("UPDATE queues SET $sql WHERE id = ? AND status IN ($in)");
        $st->execute([$id, ...$from]);
        if (!$st->rowCount()) throw new RuntimeException('สถานะคิวเปลี่ยนไปแล้ว กรุณารีเฟรช');
        return self::find($id);
    }

    /** เวลาเฉลี่ยในการตรวจ (วินาที) — ใช้ค่าจริงของวันนี้ถ้ามีอย่างน้อย 3 ราย ไม่เช่นนั้นใช้ค่าที่ตั้งไว้ในแผนก */
    public static function avgServiceSec(int $deptId): int
    {
        static $cache = [];
        if (isset($cache[$deptId])) return $cache[$deptId];
        $st = db()->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, called_at, COALESCE(pharmacy_at, finished_at))) a, COUNT(*) n
            FROM (SELECT called_at, pharmacy_at, finished_at FROM queues
                  WHERE department_id = ? AND queue_date = CURDATE() AND called_at IS NOT NULL
                    AND (pharmacy_at IS NOT NULL OR (status='done' AND finished_at IS NOT NULL))
                  ORDER BY id DESC LIMIT 10) t");
        $st->execute([$deptId]);
        $r = $st->fetch();
        $dept = self::department($deptId);
        $fallback = max(1, (int)($dept['avg_service_min'] ?? 10)) * 60;
        $avg = ((int)$r['n'] >= 3 && $r['a'] > 30) ? (int)round((float)$r['a']) : $fallback;
        return $cache[$deptId] = $avg;
    }

    /** จำนวนคิวที่รออยู่ก่อนหน้า */
    public static function ahead(array $q): int
    {
        if ($q['status'] === 'registered') {
            // ยังไม่ยืนยันตัว: นับทุกคิวที่รออยู่ตอนนี้
            $st = db()->prepare("SELECT COUNT(*) FROM queues WHERE queue_date=? AND department_id=? AND status='waiting'");
            $st->execute([$q['queue_date'], $q['department_id']]);
            return (int)$st->fetchColumn();
        }
        if ($q['status'] !== 'waiting') return 0;
        $st = db()->prepare("SELECT COUNT(*) FROM queues WHERE queue_date=? AND department_id=? AND status='waiting'
                             AND (checkin_at < ? OR (checkin_at = ? AND queue_no < ?))");
        $st->execute([$q['queue_date'], $q['department_id'], $q['checkin_at'], $q['checkin_at'], $q['queue_no']]);
        return (int)$st->fetchColumn();
    }

    /** ข้อมูลสถานะทั้งหมดสำหรับหน้าผู้ป่วย */
    public static function statusInfo(array $q): array
    {
        $now = time();
        $status = $q['status'];
        $step = self::STATUS_STEP[$status] ?? 0;
        $ahead = self::ahead($q);
        $avg = self::avgServiceSec((int)$q['department_id']);
        $startedAt = null;
        $endsAt = null;
        $message = '';
        $isToday = $q['queue_date'] === date('Y-m-d');

        switch ($status) {
            case 'registered':
                $startedAt = strtotime($q['created_at']);
                $message = $isToday
                    ? 'กรุณายืนยันตัวที่ตู้รับบัตรคิวหรือเคาน์เตอร์ต้อนรับเมื่อมาถึงโรงพยาบาล'
                    : 'จองคิวสำเร็จ สำหรับวันที่ ' . thai_date($q['queue_date']) . ' กรุณายืนยันตัวที่โรงพยาบาลในวันนัด';
                break;
            case 'waiting':
                $startedAt = strtotime($q['checkin_at']);
                $st = db()->prepare("SELECT called_at FROM queues WHERE queue_date=CURDATE() AND department_id=? AND status='serving'");
                $st->execute([$q['department_id']]);
                $serving = $st->fetchAll(PDO::FETCH_COLUMN);
                $parallel = max(1, count($serving));
                $minRemain = 0;
                if ($serving) {
                    $minRemain = PHP_INT_MAX;
                    foreach ($serving as $c) {
                        $minRemain = min($minRemain, max(0, strtotime($c) + $avg - $now));
                    }
                }
                $eta = (int)(floor($ahead / $parallel) * $avg + ($serving ? $minRemain : 0));
                $endsAt = $now + $eta;
                $message = $ahead === 0
                    ? 'ท่านเป็นคิวถัดไป กรุณารอหน้าห้องตรวจ ' . $q['dept_name']
                    : 'กรุณารอเรียกคิวบริเวณหน้าห้องตรวจ ' . $q['dept_name'];
                break;
            case 'serving':
                $startedAt = strtotime($q['called_at']);
                $endsAt = $startedAt + $avg;
                $message = 'ถึงคิวของท่านแล้ว เชิญที่ ' . ($q['room'] ?: 'ห้องตรวจ') . ' แผนก' . $q['dept_name'];
                break;
            case 'pharmacy':
                $startedAt = strtotime($q['pharmacy_at']);
                $endsAt = $startedAt + max(1, (int)setting('pharmacy_avg_min', 10)) * 60;
                $message = 'กรุณาติดต่อห้องจ่ายยา/ชำระเงิน';
                break;
            case 'done':
                $message = 'ดำเนินการเสร็จสิ้นทุกขั้นตอน ขอบคุณที่ใช้บริการ';
                break;
            case 'skipped':
                $message = 'ท่านไม่อยู่เมื่อถูกเรียกคิว กรุณาติดต่อเคาน์เตอร์ต้อนรับเพื่อนำกลับเข้าคิว';
                break;
            default:
                $message = 'คิวนี้ถูกยกเลิกแล้ว';
        }

        $phase = self::phase($status);
        return [
            'id' => (int)$q['id'],
            'queue_code' => $q['queue_code'],
            'queue_date' => $q['queue_date'],
            'queue_date_th' => thai_date($q['queue_date']),
            'is_today' => $isToday,
            'dept_name' => $q['dept_name'],
            'room' => $q['room'],
            'status' => $status,
            'status_label' => self::STATUS_LABEL[$status] ?? $status,
            'phase' => $phase['key'],
            'phase_label' => $phase['label'],
            'step' => $step,
            'step_name' => self::STEPS[min(4, max(1, $step))] ?? '',
            'ahead' => $ahead,
            'avg_service_min' => round($avg / 60, 1),
            'step_started_at' => $startedAt ? $startedAt * 1000 : null,
            'step_ends_at' => $endsAt ? $endsAt * 1000 : null,
            'recall_count' => (int)$q['recall_count'],
            'called_at' => $q['called_at'] ? strtotime($q['called_at']) * 1000 : null,
            'message' => $message,
            'times' => [
                1 => $q['created_at'] ? date('H:i', strtotime($q['created_at'])) : null,
                2 => $q['checkin_at'] ? date('H:i', strtotime($q['checkin_at'])) : null,
                3 => $q['called_at'] ? date('H:i', strtotime($q['called_at'])) : null,
                4 => $q['pharmacy_at'] ? date('H:i', strtotime($q['pharmacy_at'])) : null,
                5 => $q['finished_at'] ? date('H:i', strtotime($q['finished_at'])) : null,
            ],
        ];
    }

    /** รายการคิววันนี้ของแผนก (ใช้ในหน้าเจ้าหน้าที่/แพทย์/จอแสดงผล) */
    public static function listToday(?int $deptId = null, ?array $statuses = null): array
    {
        $where = ['q.queue_date = CURDATE()'];
        $args = [];
        if ($deptId) {
            $where[] = 'q.department_id = ?';
            $args[] = $deptId;
        }
        if ($statuses) {
            $where[] = 'q.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($args, ...$statuses);
        }
        $st = db()->prepare('SELECT q.id, q.queue_code, q.queue_no, q.hn, q.patient_name, q.source, q.status, q.room,
                q.department_id, d.name AS dept_name, q.created_at, q.checkin_at, q.called_at, q.pharmacy_at,
                q.finished_at, q.recall_count
            FROM queues q JOIN departments d ON d.id = q.department_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY FIELD(q.status,\'serving\',\'waiting\',\'registered\',\'pharmacy\',\'skipped\',\'done\',\'cancelled\'),
                     q.checkin_at IS NULL, q.checkin_at, q.queue_no');
        $st->execute($args);
        return $st->fetchAll();
    }
}
