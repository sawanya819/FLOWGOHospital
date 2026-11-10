<?php
require __DIR__ . '/inc/bootstrap.php';
if (!file_exists(__DIR__ . '/install/install.lock')) redirect('install/index.php');

page_header('หน้าแรก');
$ic = [
    'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    'ticket' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/></svg>',
    'tv' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
    'staff' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v18M3 12h18"/></svg>',
];
?>
<main class="container">
  <section class="hero">
    <h1><?= e(setting('system_name')) ?></h1>
    <p class="muted"><?= e(setting('hospital_name')) ?> · เปิดบริการ <?= e(setting('open_time')) ?>–<?= e(setting('close_time')) ?> น.</p>
  </section>
  <div class="grid c4">
    <a class="card tile" href="<?= e(base_url('patient/login.php')) ?>">
      <div class="ic"><?= $ic['user'] ?></div>
      <h2>ผู้ป่วย</h2>
      <p class="muted">เข้าสู่ระบบด้วย HN เพื่อจองคิว ดูสถานะคิว และประวัติการรักษา</p>
    </a>
    <!-- <a class="card tile" href="<?= e(base_url('kiosk.php')) ?>">
      <div class="ic"><?= $ic['ticket'] ?></div>
      <h2>ตู้รับบัตรคิว</h2>
      <p class="muted">รับบัตรคิว ณ จุดบริการ หรือยืนยันตัวสำหรับผู้ที่จองออนไลน์</p>
    </a> -->
    <a class="card tile" href="<?= e(base_url('display.php')) ?>">
      <div class="ic"><?= $ic['tv'] ?></div>
      <h2>จอแสดงคิว</h2>
      <p class="muted">แสดงคิวที่กำลังเรียกพร้อมเสียงประกาศ สำหรับติดตั้งหน้าห้องตรวจ</p>
    </a>
    <a class="card tile" href="<?= e(base_url('login.php')) ?>">
      <div class="ic"><?= $ic['staff'] ?></div>
      <h2>เจ้าหน้าที่</h2>
      <p class="muted">เคาน์เตอร์ต้อนรับ แพทย์/พยาบาล และผู้ดูแลระบบ</p>
    </a>
  </div>
</main>
<?php page_footer();
