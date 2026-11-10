<?php
/** จอฝั่งผู้ป่วยที่เคาน์เตอร์ต้อนรับ — เปิดบนจอที่สอง/แท็บเล็ตที่หันหาผู้ป่วย (เข้าสู่ระบบด้วยบัญชีเดียวกับเคาน์เตอร์) */
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('staff');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$loginUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url('patient/login.php');
$verify = setting('patient_verify', 'birthdate') === 'birthdate';

page_header('จอผู้ป่วย', ['bare' => true, 'body' => 'kiosk cs']);
?>
<main class="cs-wrap">
  <div class="row between cs-top">
    <div><b style="font-size:1.4rem"><?= e(setting('hospital_name')) ?></b><div class="muted"><?= e(setting('system_name')) ?></div></div>
    <div class="clock"><div class="t" id="clock-t"></div><div class="d" id="clock-d"></div></div>
  </div>

  <!-- ไม่มีคิวแสดง -->
  <section class="card cs-idle" id="sc-idle">
    <div class="cs-hello">ยินดีต้อนรับ</div>
    <p class="muted" style="font-size:1.2rem">เจ้าหน้าที่กำลังลงทะเบียนให้ท่าน กรุณารอสักครู่</p>
  </section>

  <!-- แสดงคิว + QR -->
  <section class="card cs-show" id="sc-show" hidden>
    <div class="cs-ticket">
      <div class="muted">หมายเลขคิวของท่าน</div>
      <div class="cs-code" id="s-code"></div>
      <div class="cs-dept" id="s-dept"></div>
      <div class="cs-ahead" id="s-ahead"></div>
      <div class="cs-pt"><span id="s-name"></span><br>HN <b class="num" id="s-hn"></b></div>
    </div>
    <div class="cs-qr-box">
      <div class="cs-qr" id="s-qr" role="img" aria-label="QR code สำหรับติดตามสถานะคิว"></div>
      <div style="font-weight:600;font-size:1.15rem">สแกนเพื่อติดตามคิวบนมือถือ</div>
      <ol class="cs-howto">
        <li>เปิดกล้องมือถือ แล้วสแกน QR code นี้</li>
        <?php if ($verify): ?><li>เลือก <b>วันเกิด</b> ของท่านเพื่อยืนยันตัวตน แล้วกด "เข้าสู่ระบบ"</li><?php else: ?><li>กด "เข้าสู่ระบบ" (ระบบกรอก HN ให้แล้ว)</li><?php endif; ?>
        <li>ดูลำดับคิว เวลารอโดยประมาณ และรับการแจ้งเตือนเมื่อใกล้ถึงคิว</li>
      </ol>
      <p class="small muted" style="margin:0">กรุณาจดเลข HN ไว้ ใช้ทุกครั้งที่มาโรงพยาบาล</p>
    </div>
  </section>
</main>

<style>
  body.cs { min-height: 100vh; }
  .cs-wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px; }
  .cs-top { margin-bottom: 16px; }
  .cs-idle { text-align: center; padding: 64px 16px; }
  .cs-hello { font-size: clamp(2.2rem, 6vw, 3.6rem); font-weight: 700; color: var(--primary); }
  .cs-show { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: center; padding: 28px; }
  .cs-show[hidden] { display: none; }
  .cs-ticket { text-align: center; }
  .cs-code { font-size: clamp(4rem, 12vw, 7rem); font-weight: 700; color: var(--primary); line-height: 1; font-variant-numeric: tabular-nums; margin: 6px 0; }
  .cs-dept { font-size: 1.7rem; font-weight: 600; }
  .cs-ahead { font-size: 1.25rem; margin-top: 6px; }
  .cs-pt { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--line); font-size: 1.15rem; }
  .cs-qr-box { text-align: center; display: grid; gap: 10px; justify-items: center; }
  .cs-qr { background: #fff; padding: 14px; border-radius: 12px; line-height: 0; }
  .cs-qr img, .cs-qr canvas { width: 240px; height: 240px; }
  .cs-howto { text-align: left; margin: 0; padding-left: 1.4em; display: grid; gap: 4px; font-size: 1.05rem; }
  @media (max-width: 760px) { .cs-show { grid-template-columns: 1fr; padding: 20px 16px; } .cs-qr img, .cs-qr canvas { width: 200px; height: 200px; } }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const H = window.HPQ, $ = (id) => document.getElementById(id);
  const LOGIN_URL = <?= json_encode($loginUrl) ?>;
  H.startClock($('clock-t'), $('clock-d'));
  let key = null;

  function render(s) {
    if (!s) {
      key = null;
      $('sc-show').hidden = true; $('sc-idle').hidden = false;
      return;
    }
    $('s-ahead').textContent = s.status === 'registered' ? 'กรุณายืนยันตัวที่เคาน์เตอร์'
      : `รอก่อนหน้า ${s.ahead} คิว` + (s.eta_min != null ? ` · ประมาณ ${s.eta_min} นาที` : '');
    if (s.key === key) return; // คิวเดิม อัปเดตแค่จำนวนคิวที่รอ
    key = s.key;
    $('s-code').textContent = s.queue_code;
    $('s-dept').textContent = 'แผนก' + s.dept_name;
    $('s-name').textContent = 'คุณ' + s.patient_name;
    $('s-hn').textContent = s.hn;
    $('s-qr').innerHTML = '';
    if (window.QRCode) new QRCode($('s-qr'), { text: LOGIN_URL + '?hn=' + encodeURIComponent(s.hn), width: 240, height: 240, correctLevel: QRCode.CorrectLevel.M });
    else $('s-qr').textContent = 'โหลด QR ไม่สำเร็จ';
    $('sc-idle').hidden = true; $('sc-show').hidden = false;
    H.beep(1);
  }

  const refresh = async () => render((await H.api('api/counter_screen.php')).screen);
  H.poll(refresh, 2000);

  // ถ้าเปิดในเบราว์เซอร์เดียวกับหน้าเคาน์เตอร์ (จอที่สอง) จะอัปเดตทันทีโดยไม่ต้องรอรอบ poll
  if ('BroadcastChannel' in window) new BroadcastChannel('hpq-counter-screen').onmessage = () => refresh().catch(() => {});
});
</script>
<?php page_footer();
