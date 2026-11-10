<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$p = require_patient();

$queues = Queue::activeForHn($p['hn']);
$selId = (int)($_GET['id'] ?? 0);
$sel = null;
foreach ($queues as $q) {
    if ($selId ? (int)$q['id'] === $selId : true) { $sel = $q; break; }
}
$info = $sel ? Queue::statusInfo($sel) : null;
$depts = array_filter(Queue::departments(), fn($d) => (int)$d['allow_online'] === 1);
$maxDays = (int)setting('booking_days_ahead', 7);
$notifyAhead = (int)setting('notify_ahead', 3);

page_header('สถานะคิว');
?>
<main class="container mid">
  <!-- 1. ชื่อระบบ + 5. เวลาปัจจุบัน -->
  <div class="qs-head">
    <div>
      <h1 class="qs-sys"><?= e(setting('system_name')) ?></h1>
      <div class="muted">สวัสดี คุณ<?= e($p['name']) ?> · HN <?= e($p['hn']) ?></div>
    </div>
    <div class="clock" aria-label="เวลาปัจจุบัน"><div class="t" id="clock-t">--:--:--</div><div class="d" id="clock-d"></div></div>
  </div>

  <?php if (count($queues) > 1): ?>
    <div class="row" style="margin-bottom:12px">
      <span class="muted small">คิวของท่าน:</span>
      <?php foreach ($queues as $q): ?>
        <a class="btn sm <?= $sel && $q['id'] === $sel['id'] ? 'primary' : '' ?>" href="?id=<?= (int)$q['id'] ?>">
          <?= e($q['queue_code']) ?> · <?= e($q['dept_name']) ?><?= $q['queue_date'] !== date('Y-m-d') ? ' · ' . e(thai_date($q['queue_date'])) : '' ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($info): ?>
  <div id="near-banner" class="alert warn notice-banner" role="alert"></div>

  <!-- 2. ขั้นตอนการดำเนินการ (สถานะภาพรวม) -->
  <div class="phase" id="phase" aria-label="ขั้นตอนการดำเนินการ">
    <span data-k="wait">รอเรียก</span>
    <span data-k="serve">กำลังรับบริการ</span>
    <span data-k="done">เสร็จสิ้น</span>
  </div>

  <!-- 3. กรอบบอกสถานะการดำเนินการ -->
  <section class="status-box" id="status-box" aria-live="polite">
    <!-- 8. คิวที่ / เหลืออีกกี่คิว -->
    <div class="q-main">
      <div class="q-cell"><div class="label">คิวที่</div><div class="big" id="q-code">-</div><div class="sub" id="q-dept"></div></div>
      <div class="q-cell"><div class="label">เหลืออีก</div><div class="big ahead" id="q-ahead">-</div><div class="sub">คิว ก่อนถึงคิวของท่าน</div></div>
    </div>

    <!-- 4. สถานะขั้นตอนที่ (progress bar 1-2-3-4) -->
    <ol class="steps" id="steps">
      <?php foreach (Queue::STEPS as $n => $name): ?>
        <li data-step="<?= $n ?>"><span class="dot"><?= $n ?></span><?= e($name) ?><span class="tm"></span></li>
      <?php endforeach; ?>
    </ol>
    <div class="step-of" id="step-of"></div>

    <!-- 6. เวลานับถอยหลังของขั้นตอนปัจจุบัน -->
    <div class="countdown" id="countdown">
      <div class="label" id="cd-label">เวลาโดยประมาณ</div>
      <div class="v" id="cd-v">--:--</div>
      <div class="bar"><i id="cd-bar"></i></div>
      <div class="small muted" id="cd-sub"></div>
    </div>

    <!-- 7. สถานะปัจจุบัน -->
    <div class="current-status"><span class="muted">สถานะปัจจุบัน:</span> <b id="cur-status">-</b></div>
    <p class="status-msg" id="status-msg"></p>
  </section>

  <div class="row between" style="margin-top:14px">
    <button class="btn sm" id="btn-notify" type="button">เปิดการแจ้งเตือน</button>
    <span class="small muted">อัปเดตอัตโนมัติทุก 5 วินาที · แจ้งเตือนเมื่อเหลือ <?= $notifyAhead ?> คิว</span>
    <?php if (in_array($sel['status'], ['registered', 'waiting'], true)): ?>
      <form method="post" action="<?= e(base_url('patient/book.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
        <button class="btn sm danger" data-confirm="ยืนยันยกเลิกคิว <?= e($sel['queue_code']) ?>?">ยกเลิกคิวนี้</button>
      </form>
    <?php endif; ?>
  </div>
  <!-- <?php else: ?>
    <div class="card"><p>ท่านยังไม่มีคิวสำหรับวันนี้ จองคิวได้จากแบบฟอร์มด้านล่าง หรือรับบัตรคิวที่ตู้บริการในโรงพยาบาล</p></div>
  <?php endif; ?> -->

  <section class="card" style="margin-top:20px">
    <!-- <h2>จองคิว</h2>
    <?php if (!$depts): ?>
      <p class="muted">ขณะนี้ไม่มีแผนกที่เปิดให้จองออนไลน์</p>
    <?php else: ?> -->
    <form method="post" action="<?= e(base_url('patient/book.php')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="book">
      <div class="grid c2">
        <label>แผนก
          <select name="department_id" required>
            <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>วันที่
          <select name="date">
            <?php for ($i = 0; $i <= $maxDays; $i++): $dt = date('Y-m-d', strtotime("+$i days")); ?>
              <option value="<?= $dt ?>"><?= $i === 0 ? 'วันนี้ · ' : '' ?><?= e(thai_date($dt)) ?></option>
            <?php endfor; ?>
          </select>
        </label>
      </div>
      <p class="small muted" style="margin:0">เมื่อมาถึงโรงพยาบาล กรุณายืนยันตัวที่ตู้รับบัตรคิวหรือเคาน์เตอร์ เพื่อเข้าสู่คิวรอพบแพทย์</p>
      <div><button class="btn primary">จองคิว</button></div>
    </form>
    <?php endif; ?>
  </section>
</main>

<?php if ($info): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const H = window.HPQ;
  H.startClock(document.getElementById('clock-t'), document.getElementById('clock-d'));
  const qid = <?= (int)$info['id'] ?>;
  const NOTIFY_AHEAD = <?= $notifyAhead ?>;
  const $ = (id) => document.getElementById(id);
  let data = <?= json_encode($info, JSON_UNESCAPED_UNICODE) ?>;
  data.server_time = <?= time() * 1000 ?>;

  const CD_LABEL = {
    1: 'กรุณายืนยันตัวที่โรงพยาบาล',
    2: 'เวลาโดยประมาณก่อนถึงคิวของท่าน',
    3: 'เวลาตรวจโดยประมาณที่เหลือ',
    4: 'เวลารับยา/ชำระเงินโดยประมาณที่เหลือ',
  };

  const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* ignore */ } },
  };

  function render() {
    const d = data;
    document.querySelectorAll('#phase span').forEach(s => s.className = s.dataset.k === d.phase ? 'on ' + d.phase : '');
    const box = $('status-box');
    box.className = 'status-box ' + d.phase + (d.status === 'serving' ? ' alerting' : '');
    $('q-code').textContent = d.queue_code;
    $('q-dept').textContent = d.dept_name + (d.is_today ? '' : ' · ' + d.queue_date_th);
    $('q-ahead').textContent = ['waiting', 'registered'].includes(d.status) ? d.ahead : '0';
    document.querySelectorAll('#steps li').forEach(li => {
      const n = +li.dataset.step;
      li.className = n < d.step || d.step === 5 ? 'done' : (n === d.step ? 'current' : '');
      li.querySelector('.tm').textContent = d.times[n] ? d.times[n] + ' น.' : '';
    });
    $('step-of').textContent = d.step >= 1 && d.step <= 4
      ? `ขั้นตอนที่ ${d.step} จาก 4 · ${d.step_name}`
      : (d.step === 5 ? 'ครบทุกขั้นตอนแล้ว' + (d.times[5] ? ' เวลา ' + d.times[5] + ' น.' : '') : '');
    $('cur-status').textContent = d.status_label + (d.status === 'serving' && d.room ? ' · ' + d.room : '');
    $('status-msg').textContent = d.message;
    tick();
  }

  function tick() {
    const d = data, cd = $('countdown');
    $('cd-label').textContent = CD_LABEL[d.step] || 'สถานะ';
    if (!d.step_ends_at) {
      $('cd-v').textContent = d.step === 5 ? '00:00' : '--:--';
      $('cd-bar').style.width = d.step === 5 ? '100%' : '0';
      $('cd-sub').textContent = d.step === 1 && d.ahead ? `ขณะนี้มีผู้รอ ${d.ahead} คิว` : '';
      cd.classList.remove('over');
      return;
    }
    const left = d.step_ends_at - H.now();
    const total = Math.max(1, d.step_ends_at - (d.step_started_at || H.now()));
    $('cd-v').textContent = left >= 0 ? H.fmtDuration(left) : H.fmtDuration(left);
    cd.classList.toggle('over', left < 0);
    $('cd-bar').style.width = Math.min(100, Math.max(0, 100 - (left / total) * 100)) + '%';
    $('cd-sub').textContent = left < 0
      ? 'เกินเวลาประมาณการแล้ว กรุณารอสักครู่'
      : 'ประมาณเวลา ' + new Date(d.step_ends_at).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) + ' น. (เฉลี่ย ' + d.avg_service_min + ' นาที/คิว)';
  }

  function alerts() {
    const d = data, banner = $('near-banner');
    let text = '';
    if (d.status === 'waiting' && d.ahead <= NOTIFY_AHEAD) {
      text = d.ahead === 0 ? 'ท่านเป็นคิวถัดไป กรุณารอหน้าห้องตรวจ' : `ใกล้ถึงคิวของท่านแล้ว เหลืออีก ${d.ahead} คิว กรุณามารอหน้าห้องตรวจ ${d.dept_name}`;
      const key = `hpq-near-${qid}-${d.ahead}`;
      if (!store.get(key)) { store.set(key, 1); H.beep(2); H.notify('ใกล้ถึงคิว ' + d.queue_code, text); }
    } else if (d.status === 'serving') {
      text = `ถึงคิวของท่านแล้ว! เชิญที่ ${d.room || 'ห้องตรวจ'} แผนก${d.dept_name}`;
      const key = `hpq-call-${qid}-${d.recall_count}`;
      if (!store.get(key)) { store.set(key, 1); H.beep(4); H.notify('ถึงคิว ' + d.queue_code + ' แล้ว', text); }
    } else if (d.status === 'pharmacy') {
      const key = `hpq-ph-${qid}`;
      if (!store.get(key)) { store.set(key, 1); H.beep(2); H.notify('คิว ' + d.queue_code, 'กรุณาติดต่อห้องจ่ายยา/ชำระเงิน'); }
    }
    banner.textContent = text;
    banner.classList.toggle('show', !!text);
  }

  async function refresh() {
    const j = await H.api('api/patient_status.php?id=' + qid);
    const prev = data.status;
    data = j.queue;
    render(); alerts();
    if (prev !== data.status && ['done', 'cancelled'].includes(data.status)) H.toast(data.message);
  }

  const nb = $('btn-notify');
  const syncBtn = () => {
    if (!('Notification' in window)) { nb.textContent = 'อุปกรณ์นี้ไม่รองรับการแจ้งเตือน (จะใช้เสียงแทน)'; nb.disabled = true; return; }
    if (Notification.permission === 'granted') { nb.textContent = 'เปิดการแจ้งเตือนแล้ว'; nb.disabled = true; }
    if (Notification.permission === 'denied') { nb.textContent = 'การแจ้งเตือนถูกปิดในเบราว์เซอร์'; nb.disabled = true; }
  };
  nb.addEventListener('click', async () => { H.beep(1); if ('Notification' in window) await Notification.requestPermission(); syncBtn(); });
  syncBtn();

  render(); alerts();
  setInterval(tick, 1000);
  H.poll(refresh, 5000);
});
</script>
<?php endif; ?>
<?php page_footer();
