<?php
/** API สำหรับเจ้าหน้าที่ต้อนรับ และ แพทย์/พยาบาล */
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_role('staff', 'doctor');

function row(array $q): array
{
    return [
        'id' => (int)$q['id'], 'queue_code' => $q['queue_code'], 'hn' => $q['hn'], 'patient_name' => $q['patient_name'],
        'department_id' => (int)$q['department_id'], 'dept_name' => $q['dept_name'], 'status' => $q['status'],
        'status_label' => Queue::STATUS_LABEL[$q['status']] ?? $q['status'], 'source' => Queue::SOURCE_LABEL[$q['source']] ?? $q['source'],
        'room' => $q['room'], 'recall_count' => (int)$q['recall_count'],
        'created' => $q['created_at'] ? date('H:i', strtotime($q['created_at'])) : null,
        'checkin' => $q['checkin_at'] ? date('H:i', strtotime($q['checkin_at'])) : null,
        'called' => $q['called_at'] ? date('H:i', strtotime($q['called_at'])) : null,
        'called_ts' => $q['called_at'] ? strtotime($q['called_at']) * 1000 : null,
        'checkin_ts' => $q['checkin_at'] ? strtotime($q['checkin_at']) * 1000 : null,
        'pharmacy_ts' => $q['pharmacy_at'] ? strtotime($q['pharmacy_at']) * 1000 : null,
        'finished' => $q['finished_at'] ? date('H:i', strtotime($q['finished_at'])) : null,
    ];
}

$now = fn() => (int)round(microtime(true) * 1000);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $dept = (int)($_GET['department_id'] ?? 0) ?: null;
    $list = array_map('row', Queue::listToday($dept));
    $avg = $dept ? round(Queue::avgServiceSec($dept) / 60, 1) : null;
    json_out(['ok' => true, 'server_time' => $now(), 'queues' => $list, 'avg_service_min' => $avg]);
}

csrf_check();
$action = (string)($_POST['action'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$isStaff = in_array($u['role'], ['staff', 'admin'], true);

try {
    switch ($action) {
        case 'lookup':
            $p = His::findPatient((string)($_POST['hn'] ?? ''));
            if (!$p) throw new RuntimeException('ไม่พบ HN นี้ในฐานข้อมูลโรงพยาบาล');
            $active = array_map('row', array_filter(Queue::activeForHn($p['hn']), fn($q) => $q['queue_date'] === date('Y-m-d')));
            json_out(['ok' => true, 'patient' => [
                'hn' => $p['hn'], 'name' => $p['fullname'], 'birthdate' => thai_date($p['birthdate'] ?? null), 'phone' => $p['phone'] ?? '',
            ], 'queues' => array_values($active)]);

        case 'issue':
            if (!$isStaff) throw new RuntimeException('เฉพาะเจ้าหน้าที่ต้อนรับ');
            $q = Queue::issue((string)($_POST['hn'] ?? ''), (int)($_POST['department_id'] ?? 0), 'counter', null, $u['id']);
            $info = Queue::statusInfo($q);
            counter_screen_push((int)$u['id'], (int)$q['id']);
            json_out(['ok' => true, 'queue' => row($q), 'ahead' => $info['ahead'], 'message' => 'ออกบัตรคิว ' . $q['queue_code'] . ' แล้ว']);

        case 'checkin':
            if (!$isStaff) throw new RuntimeException('เฉพาะเจ้าหน้าที่ต้อนรับ');
            $q = Queue::checkin($id);
            counter_screen_push((int)$u['id'], (int)$q['id']);
            json_out(['ok' => true, 'queue' => row($q), 'message' => 'ยืนยันลงทะเบียนคิว ' . $q['queue_code'] . ' แล้ว']);

        case 'screen_show':
            if (!$isStaff) throw new RuntimeException('เฉพาะเจ้าหน้าที่ต้อนรับ');
            $q = Queue::find($id);
            if (!$q) throw new RuntimeException('ไม่พบคิว');
            counter_screen_push((int)$u['id'], (int)$q['id']);
            json_out(['ok' => true, 'message' => 'แสดงคิว ' . $q['queue_code'] . ' บนจอผู้ป่วยแล้ว']);

        case 'screen_clear':
            if (!$isStaff) throw new RuntimeException('เฉพาะเจ้าหน้าที่ต้อนรับ');
            counter_screen_push((int)$u['id'], null);
            json_out(['ok' => true, 'message' => 'ล้างจอผู้ป่วยแล้ว']);

        case 'set_room':
            $room = trim((string)($_POST['room'] ?? ''));
            $dept = (int)($_POST['department_id'] ?? 0);
            if ($room === '' || mb_strlen($room) > 30) throw new RuntimeException('กรุณาระบุห้องตรวจ');
            $_SESSION['user']['room'] = $room;
            if ($u['role'] === 'admin' && $dept) $_SESSION['user']['department_id'] = $dept;
            db()->prepare('UPDATE users SET room = ? WHERE id = ?')->execute([$room, $u['id']]);
            json_out(['ok' => true, 'message' => 'ตั้งค่าห้องตรวจเป็น ' . $room]);

        case 'call_next':
            $dept = (int)($_POST['department_id'] ?? 0);
            if ($u['role'] === 'doctor') $dept = (int)$u['department_id'];
            $room = trim((string)($_POST['room'] ?? $u['room'] ?? '')) ?: 'ห้องตรวจ';
            $q = Queue::callNext($dept, $room, $u['id']);
            if (!$q) json_out(['ok' => true, 'queue' => null, 'message' => 'ไม่มีคิวรอเรียก']);
            json_out(['ok' => true, 'queue' => row($q), 'message' => 'เรียกคิว ' . $q['queue_code']]);

        case 'call':
            $room = trim((string)($_POST['room'] ?? $u['room'] ?? '')) ?: 'ห้องตรวจ';
            $q = Queue::find($id);
            if ($q && $u['role'] === 'doctor' && (int)$q['department_id'] !== (int)$u['department_id']) throw new RuntimeException('คิวนี้ไม่ใช่แผนกของท่าน');
            $q = Queue::callSpecific($id, $room, $u['id']);
            json_out(['ok' => true, 'queue' => row($q), 'message' => 'เรียกคิว ' . $q['queue_code']]);

        case 'recall': case 'skip': case 'restore': case 'pharmacy': case 'finish': case 'cancel': case 'back':
            $q = Queue::find($id);
            if (!$q) throw new RuntimeException('ไม่พบคิว');
            if ($u['role'] === 'doctor' && (int)$q['department_id'] !== (int)$u['department_id']) throw new RuntimeException('คิวนี้ไม่ใช่แผนกของท่าน');
            if ($action === 'cancel' && !$isStaff) throw new RuntimeException('เฉพาะเจ้าหน้าที่ต้อนรับ');
            $q = Queue::transition($id, $action);
            $labels = ['recall' => 'เรียกซ้ำ', 'skip' => 'ข้ามคิว', 'restore' => 'นำกลับเข้าคิว', 'pharmacy' => 'ส่งรับยา/ชำระเงิน',
                'finish' => 'เสร็จสิ้น', 'cancel' => 'ยกเลิก', 'back' => 'คืนเข้าคิวรอ'];
            json_out(['ok' => true, 'queue' => row($q), 'message' => $labels[$action] . ' ' . $q['queue_code'] . ' แล้ว']);
    }
    json_out(['ok' => false, 'error' => 'คำสั่งไม่ถูกต้อง'], 400);
} catch (RuntimeException $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 422);
}
