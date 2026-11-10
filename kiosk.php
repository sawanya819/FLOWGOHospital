<?php
require __DIR__ . '/inc/bootstrap.php';
$depts = Queue::departments();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$loginUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url('patient/login.php');

page_header('ตู้รับบัตรคิว', ['bare' => true, 'body' => 'kiosk']);
?>
<main class="kiosk-wrap">
  <div class="row between" style="margin-bottom:8px">
    <div><b style="font-size:1.3rem"><?= e(setting('system_name')) ?></b><div class="muted"><?= e(setting('hospital_name')) ?></div></div>
    <div class="clock"><div class="t" id="clock-t"></div><div class="d" id="clock-d"></div></div>
  </div>

  <!-- ขั้นที่ 1: กรอก HN -->
  <section class="card" id="sc-hn">
    <h1>รับบัตรคิว</h1>
    <p style="text-align:center" class="muted">กรอกเลข HN บนบัตรโรงพยาบาล แล้วกด "ถัดไป"</p>
    <div class="hn-display num" id="hn-view" aria-live="polite"></div>
    <div class="keypad" id="keypad">
      <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?><button type="button" data-k="<?= $n ?>"><?= $n ?></button><?php endforeach; ?>
      <button type="button" data-k="clear" aria-label="ล้าง">ล้าง</button>
      <button type="button" data-k="0">0</button>
      <button type="button" data-k="back" aria-label="ลบ">⌫</button>
    </div>
    <div style="max-width:360px;margin:0 auto"><button class="btn primary lg block" id="btn-next">ถัดไป</button></div>
    <p class="small muted" style="text-align:center;margin-top:16px">ผู้ป่วยใหม่ที่ยังไม่มี HN กรุณาติดต่อเคาน์เตอร์ต้อนรับ</p>
  </section>

  <!-- ขั้นที่ 2: เลือกแผนก / ยืนยันตัว -->
  <section class="card" id="sc-dept" hidden>
    <div class="row between"><h1 style="text-align:left;margin:0" id="pt-name"></h1><button class="btn" id="btn-back">ย้อนกลับ</button></div>
    <div id="my-queues" style="margin:16px 0"></div>
    <h2>เลือกแผนกที่ต้องการรับบริการ</h2>
    <div class="dept-grid">
      <?php foreach ($depts as $d): ?>
        <button class="dept-btn" data-dept="<?= (int)$d['id'] ?>"><?= e($d['name']) ?><small>คิว <?= e($d['prefix']) ?> · <span data-wait="<?= (int)$d['id'] ?>">-</span></small></button>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ขั้นที่ 3: บัตรคิว -->
  <section class="card" id="sc-ticket" hidden style="text-align:center">
    <p class="muted">หมายเลขคิวของท่าน</p>
    <div style="font-size:5rem;font-weight:700;color:var(--primary);line-height:1" id="t-code"></div>
    <h2 id="t-dept"></h2>
    <p id="t-ahead" style="font-size:1.2rem"></p>
    <p class="muted">สแกน QR บนบัตรคิวเพื่อติดตามสถานะคิวบนมือถือ</p>
    <div class="row" style="justify-content:center">
      <button class="btn lg" id="btn-print">พิมพ์บัตรคิวอีกครั้ง</button>
      <button class="btn primary lg" id="btn-done">เสร็จสิ้น</button>
    </div>
    <p class="small muted">หน้าจอจะกลับสู่หน้าแรกใน <span id="t-reset">20</span> วินาที</p>
  </section>
</main>

<div id="print-area"><div class="ticket">
  <div class="hosp"><?= e(setting('hospital_name')) ?></div>
  <div><?= e(setting('system_name')) ?></div><hr>
  <div>หมายเลขคิว</div>
  <div class="code" id="p-code"></div>
  <div id="p-dept" style="font-size:16px;font-weight:700"></div>
  <div id="p-ahead"></div><hr>
  <div class="qr" id="p-qr"></div>
  <div style="font-size:11px">สแกนเพื่อดูสถานะคิว (เข้าสู่ระบบด้วย HN)</div>
  <div style="font-size:11px" id="p-time"></div>
</div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const H = window.HPQ, $ = (id) => document.getElementById(id);
  const LOGIN_URL = <?= json_encode($loginUrl) ?>;
  H.startClock($('clock-t'), $('clock-d'));
  let hn = '', idle, resetTimer;

  const show = (id) => ['sc-hn', 'sc-dept', 'sc-ticket'].forEach(s => $(s).hidden = s !== id);
  const view = () => $('hn-view').textContent = hn || ' ';
  function reset() { hn = ''; view(); show('sc-hn'); clearInterval(resetTimer); }
  function bumpIdle() { clearTimeout(idle); idle = setTimeout(reset, 60000); }
  document.addEventListener('pointerdown', bumpIdle);
  view();

  $('keypad').addEventListener('click', (e) => {
    const k = e.target.closest('button')?.dataset.k; if (!k) return;
    if (k === 'clear') hn = ''; else if (k === 'back') hn = hn.slice(0, -1); else if (hn.length < 12) hn += k;
    view();
  });
  document.addEventListener('keydown', (e) => {
    if ($('sc-hn').hidden) return;
    if (/^\d$/.test(e.key) && hn.length < 12) hn += e.key;
    else if (e.key === 'Backspace') hn = hn.slice(0, -1);
    else if (e.key === 'Enter') $('btn-next').click();
    view();
  });

  $('btn-next').addEventListener('click', async () => {
    if (!hn) return H.toast('กรุณากรอก HN', 'warn');
    try {
      const j = await H.api('api/kiosk.php', { action: 'lookup', hn });
      hn = j.hn;
      $('pt-name').textContent = 'คุณ' + j.name;
      const box = $('my-queues');
      box.innerHTML = j.queues.map(q => q.status === 'registered'
        ? `<div class="alert info row between"><span>ท่านจองคิว <b>${H.esc(q.queue_code)}</b> แผนก${H.esc(q.dept_name)} ไว้แล้ว</span>
             <button class="btn primary lg" data-checkin="${q.id}">ยืนยันตัว / รับบัตรคิว</button></div>`
        : `<div class="alert warn row between"><span>ท่านมีคิว <b>${H.esc(q.queue_code)}</b> แผนก${H.esc(q.dept_name)} (${H.esc(q.status_label)})</span>
             <button class="btn" data-reprint="${q.id}">พิมพ์บัตรคิวซ้ำ</button></div>`).join('');
      loadWaiting();
      show('sc-dept');
    } catch (e) { H.toast(e.message, 'err', 5000); }
  });
  $('btn-back').addEventListener('click', reset);

  $('my-queues').addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    const id = b.dataset.checkin || b.dataset.reprint;
    try {
      const j = await H.api('api/kiosk.php', { action: b.dataset.checkin ? 'checkin' : 'reprint', hn, id });
      ticket(j.ticket);
    } catch (e) { H.toast(e.message, 'err', 5000); }
  });

  document.querySelectorAll('.dept-btn').forEach(b => b.addEventListener('click', async () => {
    b.disabled = true;
    try {
      const j = await H.api('api/kiosk.php', { action: 'issue', hn, department_id: b.dataset.dept });
      ticket(j.ticket);
    } catch (e) { H.toast(e.message, 'err', 5000); }
    b.disabled = false;
  }));

  async function loadWaiting() {
    try {
      const j = await H.api('api/board.php?summary=1');
      j.departments.forEach(d => { const el = document.querySelector(`[data-wait="${d.id}"]`); if (el) el.textContent = `รอ ${d.waiting} คิว`; });
    } catch (e) { /* ไม่สำคัญ */ }
  }

  function ticket(t) {
    $('t-code').textContent = t.queue_code; $('p-code').textContent = t.queue_code;
    $('t-dept').textContent = 'แผนก' + t.dept_name; $('p-dept').textContent = 'แผนก' + t.dept_name;
    const a = `รอก่อนหน้า ${t.ahead} คิว` + (t.eta_min != null ? ` · ประมาณ ${t.eta_min} นาที` : '');
    $('t-ahead').textContent = a; $('p-ahead').textContent = a;
    $('p-time').textContent = 'ออกบัตร ' + t.issued + ' · HN ' + t.hn;
    $('p-qr').innerHTML = '';
    if (window.QRCode) new QRCode($('p-qr'), { text: LOGIN_URL + '?hn=' + encodeURIComponent(t.hn), width: 110, height: 110 });
    show('sc-ticket');
    setTimeout(() => window.print(), 300);
    let s = 20; $('t-reset').textContent = s;
    clearInterval(resetTimer);
    resetTimer = setInterval(() => { s--; $('t-reset').textContent = s; if (s <= 0) reset(); }, 1000);
  }
  $('btn-print').addEventListener('click', () => window.print());
  $('btn-done').addEventListener('click', reset);
});
</script>
<?php page_footer();
