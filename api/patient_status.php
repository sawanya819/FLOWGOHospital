<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$p = require_patient();
session_write_close();

$q = Queue::find((int)($_GET['id'] ?? 0));
if (!$q || $q['hn'] !== $p['hn']) json_out(['ok' => false, 'error' => 'ไม่พบคิว'], 404);

json_out(['ok' => true, 'server_time' => (int)round(microtime(true) * 1000), 'queue' => Queue::statusInfo($q)]);
