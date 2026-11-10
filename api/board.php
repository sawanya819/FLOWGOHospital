<?php
/** ข้อมูลสาธารณะสำหรับจอแสดงคิว (ไม่เปิดเผยชื่อผู้ป่วย) */
require dirname(__DIR__) . '/inc/bootstrap.php';
session_write_close();

$depIds = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['dept'] ?? '')))));
$depWhere = $depIds ? ' AND q.department_id IN (' . implode(',', $depIds) . ')' : '';

$summary = db()->query("SELECT d.id, d.name, d.prefix,
        SUM(q.status = 'waiting') AS waiting, SUM(q.status = 'serving') AS serving,
        SUM(q.status = 'done') AS done, COUNT(q.id) AS total
    FROM departments d
    LEFT JOIN queues q ON q.department_id = d.id AND q.queue_date = CURDATE() AND q.status <> 'cancelled'
    WHERE d.active = 1 " . ($depIds ? ' AND d.id IN (' . implode(',', $depIds) . ')' : '') . "
    GROUP BY d.id ORDER BY d.sort, d.id")->fetchAll();
foreach ($summary as &$s) {
    foreach (['id', 'waiting', 'serving', 'done', 'total'] as $k) $s[$k] = (int)$s[$k];
}
unset($s);

$out = ['ok' => true, 'server_time' => (int)round(microtime(true) * 1000), 'departments' => $summary];

if (empty($_GET['summary'])) {
    $out['serving'] = db()->query("SELECT q.id, q.queue_code, q.room, d.name AS dept_name, q.recall_count,
            UNIX_TIMESTAMP(q.announced_at) AS announced, q.patient_name
        FROM queues q JOIN departments d ON d.id = q.department_id
        WHERE q.queue_date = CURDATE() AND q.status = 'serving' $depWhere
        ORDER BY q.announced_at DESC LIMIT 12")->fetchAll();
    // ส่งเฉพาะเพศ (ใช้เลือกเสียงประกาศ) ไม่ส่งชื่อผู้ป่วยออกไปที่จอสาธารณะ
    foreach ($out['serving'] as &$s) {
        $s['gender'] = His::genderFromName((string)$s['patient_name']);
        unset($s['patient_name']);
    }
    unset($s);

    $out['waiting'] = db()->query("SELECT q.queue_code, q.department_id FROM queues q
        WHERE q.queue_date = CURDATE() AND q.status = 'waiting' $depWhere
        ORDER BY q.checkin_at, q.queue_no LIMIT 40")->fetchAll();

    $out['pharmacy'] = db()->query("SELECT q.queue_code FROM queues q
        WHERE q.queue_date = CURDATE() AND q.status = 'pharmacy' $depWhere
        ORDER BY q.pharmacy_at LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
}
json_out($out);
