<?php
/** API ตู้รับบัตรคิว (ไม่ต้องเข้าสู่ระบบ — ใช้ที่จุดบริการภายในโรงพยาบาล) */
require dirname(__DIR__) . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);
csrf_check();

function ticket(array $q): array
{
    $info = Queue::statusInfo($q);
    return [
        'id' => (int)$q['id'], 'queue_code' => $q['queue_code'], 'dept_name' => $q['dept_name'],
        'hn' => $q['hn'], 'ahead' => $info['ahead'], 'eta_min' => $info['step_ends_at']
            ? max(0, (int)round(($info['step_ends_at'] / 1000 - time()) / 60)) : null,
        'issued' => thai_date($q['created_at'], true),
    ];
}

try {
    $action = $_POST['action'] ?? '';
    $hn = His::normalizeHn((string)($_POST['hn'] ?? ''));
    $patient = $hn !== '' ? His::findPatient($hn) : null;
    if (!$patient) json_out(['ok' => false, 'error' => 'ไม่พบ HN นี้ กรุณาตรวจสอบหรือติดต่อเคาน์เตอร์ต้อนรับ']);

    switch ($action) {
        case 'lookup':
            $active = array_values(array_filter(Queue::activeForHn($patient['hn']),
                fn($q) => $q['queue_date'] === date('Y-m-d') && in_array($q['status'], ['registered', 'waiting', 'serving', 'pharmacy'], true)));
            json_out(['ok' => true,
                'name' => mask_name(($patient['prename'] ?? '') . $patient['fname'], (string)$patient['lname']),
                'hn' => $patient['hn'],
                'queues' => array_map(fn($q) => [
                    'id' => (int)$q['id'], 'queue_code' => $q['queue_code'], 'dept_name' => $q['dept_name'],
                    'department_id' => (int)$q['department_id'], 'status' => $q['status'],
                    'status_label' => Queue::STATUS_LABEL[$q['status']],
                ], $active),
            ]);

        case 'issue':
            $q = Queue::issue($patient['hn'], (int)($_POST['department_id'] ?? 0), 'kiosk');
            json_out(['ok' => true, 'ticket' => ticket($q)]);

        case 'checkin':
        case 'reprint':
            $q = Queue::find((int)($_POST['id'] ?? 0));
            if (!$q || $q['hn'] !== $patient['hn']) throw new RuntimeException('ไม่พบคิว');
            if ($action === 'checkin') $q = Queue::checkin((int)$q['id']);
            json_out(['ok' => true, 'ticket' => ticket($q)]);
    }
    json_out(['ok' => false, 'error' => 'คำสั่งไม่ถูกต้อง'], 400);
} catch (RuntimeException $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()]);
}
