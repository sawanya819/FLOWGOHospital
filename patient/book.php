<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$p = require_patient();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('patient/index.php');
csrf_check();

try {
    if (($_POST['action'] ?? '') === 'cancel') {
        $q = Queue::find((int)($_POST['id'] ?? 0));
        if (!$q || $q['hn'] !== $p['hn']) throw new RuntimeException('ไม่พบคิว');
        Queue::transition((int)$q['id'], 'cancel');
        flash('ยกเลิกคิว ' . $q['queue_code'] . ' แล้ว');
        redirect('patient/index.php');
    }

    $dept = Queue::department((int)($_POST['department_id'] ?? 0));
    if (!$dept || !(int)$dept['allow_online']) throw new RuntimeException('แผนกนี้ไม่เปิดให้จองออนไลน์');
    $date = (string)($_POST['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('วันที่ไม่ถูกต้อง');
    $q = Queue::issue($p['hn'], (int)$dept['id'], 'online', $date);
    flash('จองคิวสำเร็จ หมายเลข ' . $q['queue_code'] . ' แผนก' . $q['dept_name'] . ' วันที่ ' . thai_date($q['queue_date']));
    redirect('patient/index.php?id=' . $q['id']);
} catch (RuntimeException $ex) {
    flash($ex->getMessage(), 'err');
    redirect('patient/index.php');
}
