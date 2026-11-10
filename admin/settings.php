<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_role('admin');

$fields = [
    'system_name' => ['ชื่อระบบ', 'text'],
    'hospital_name' => ['ชื่อโรงพยาบาล', 'text'],
    'open_time' => ['เวลาเปิดบริการ', 'time'],
    'close_time' => ['เวลาปิดบริการ', 'time'],
    'notify_ahead' => ['แจ้งเตือนผู้ป่วยเมื่อเหลือ (คิว)', 'number'],
    'pharmacy_avg_min' => ['เวลารับยา/ชำระเงินเฉลี่ย (นาที)', 'number'],
    'booking_days_ahead' => ['จองคิวล่วงหน้าได้ (วัน)', 'number'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    foreach ($fields as $k => [$label, $type]) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($type === 'number') $v = (string)max(0, (int)$v);
        $st->execute([$k, $v]);
    }
    $st->execute(['patient_verify', ($_POST['patient_verify'] ?? '') === 'none' ? 'none' : 'birthdate']);
    flash('บันทึกการตั้งค่าแล้ว');
    redirect('admin/settings.php');
}

$his = $GLOBALS['CONFIG']['his'];
$hisOk = true;
try {
    His::findPatient('0');
} catch (Throwable $ex) {
    $hisOk = false;
    $hisErr = $ex->getMessage();
}

page_header('ตั้งค่าระบบ');
?>
<main class="container mid">
  <h1>ตั้งค่าระบบ</h1>
  <section class="card">
    <form method="post" class="form">
      <?= csrf_field() ?>
      <div class="grid c2">
        <?php foreach ($fields as $k => [$label, $type]): ?>
          <label><?= e($label) ?><input type="<?= $type ?>" name="<?= $k ?>" value="<?= e(setting($k, '')) ?>" <?= $type === 'number' ? 'min="0"' : '' ?>></label>
        <?php endforeach; ?>
        <label>การยืนยันตัวตนผู้ป่วย
          <select name="patient_verify">
            <option value="birthdate" <?= setting('patient_verify') !== 'none' ? 'selected' : '' ?>>HN + วันเกิด (แนะนำ)</option>
            <option value="none" <?= setting('patient_verify') === 'none' ? 'selected' : '' ?>>HN อย่างเดียว</option>
          </select>
        </label>
      </div>
      <p class="small muted" style="margin:0">หน้าประวัติการรักษาเป็นข้อมูลส่วนบุคคล การใช้ HN อย่างเดียวทำให้ผู้ที่รู้ HN ดูข้อมูลได้ จึงแนะนำให้ยืนยันด้วยวันเกิด</p>
      <div><button class="btn primary">บันทึก</button></div>
    </form>
  </section>

  <section class="card" style="margin-top:16px">
    <h2>การเชื่อมต่อฐานข้อมูล HN (HIS)</h2>
    <p>แหล่งข้อมูล: <b><?= empty($his['dsn']) ? 'ตารางจำลองในฐานข้อมูลระบบคิว (his_patient / his_visit)' : e(preg_replace('/password=[^;]*/i', 'password=***', $his['dsn'])) ?></b></p>
    <p>สถานะ: <?= $hisOk ? '<span class="badge s-done">เชื่อมต่อได้</span>' : '<span class="badge s-cancelled">เชื่อมต่อไม่ได้</span> <span class="small">' . e($hisErr ?? '') . '</span>' ?></p>
    <p class="small muted">แก้ไขการเชื่อมต่อและคำสั่ง SQL ได้ที่ไฟล์ <code>config.php</code> ส่วน <code>'his'</code> (มีตัวอย่างสำหรับ HOSxP)</p>
  </section>
</main>
<?php page_footer();
