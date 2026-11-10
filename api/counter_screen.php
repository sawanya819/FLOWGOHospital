<?php
/** ข้อมูลสำหรับจอฝั่งผู้ป่วยที่เคาน์เตอร์ — คืนคิวล่าสุดที่เจ้าหน้าที่คนนี้ออกบัตร/ลงทะเบียนให้ */
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = require_role('staff');
session_write_close();

const SCREEN_TTL = 180; // แสดงค้างไว้สูงสุด 3 นาที แล้วกลับสู่หน้าต้อนรับ

$now = (int)round(microtime(true) * 1000);
$s = counter_screen_get((int)$u['id']);
$q = ($s && time() - (int)$s['at'] <= SCREEN_TTL) ? Queue::find((int)$s['id']) : null;
if (!$q || in_array($q['status'], ['done', 'cancelled'], true)) {
    json_out(['ok' => true, 'server_time' => $now, 'screen' => null]);
}

$info = Queue::statusInfo($q);
$eta = $info['step_ends_at'] && $q['status'] === 'waiting' ? max(0, (int)round(($info['step_ends_at'] / 1000 - time()) / 60)) : null;
json_out(['ok' => true, 'server_time' => $now, 'screen' => [
    'key' => $s['id'] . '-' . $s['at'],
    'shown_at' => (int)$s['at'] * 1000,
    'queue_code' => $q['queue_code'],
    'dept_name' => $q['dept_name'],
    'hn' => $q['hn'],
    'patient_name' => $q['patient_name'],
    'status' => $q['status'],
    'ahead' => $info['ahead'],
    'eta_min' => $eta,
]]);
