<?php
require __DIR__ . '/inc/bootstrap.php';
$depts = Queue::departments();
$sel = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['dept'] ?? '')))));

page_header('จอแสดงคิว', ['bare' => true, 'body' => 'board']);
?>
<header class="board-head">
  <div>
    <h1><?= e(setting('system_name')) ?></h1>
    <div style="color:#cde8f0"><?= e(setting('hospital_name')) ?><?php if ($sel): ?> · <?= e(implode(', ', array_column(array_filter($depts, fn($d) => in_array((int)$d['id'], $sel, true)), 'name'))) ?><?php endif; ?></div>
  </div>
  <div class="clock"><div class="t" id="clock-t"></div><div class="d" id="clock-d"></div></div>
</header>

<div class="board-body">
  <section class="board-panel">
    <h2>กำลังเรียก</h2>
    <div class="call-row" style="font-size:1.1rem;color:#9fd3e3;padding-top:0"><span>หมายเลข</span><span>ห้อง</span><span>แผนก</span></div>
    <div id="calls"></div>
  </section>
  <div style="display:grid;gap:20px;grid-template-rows:2fr 1fr;min-height:0">
    <section class="board-panel">
      <h2>คิวถัดไป</h2>
      <div class="wait-list" id="waiting"></div>
    </section>
    <section class="board-panel">
      <h2>รอรับยา/ชำระเงิน</h2>
      <div class="wait-list" id="pharmacy"></div>
    </section>
  </div>
</div>

<button class="btn sound-btn" id="btn-sound">เปิดเสียงประกาศ</button>
<div class="board-foot"><span>กรุณารอเรียกคิวตามหมายเลข หากไม่อยู่เมื่อถูกเรียกเกิน 3 ครั้ง กรุณาติดต่อเคาน์เตอร์ต้อนรับ · ติดตามสถานะคิวบนมือถือได้โดยเข้าสู่ระบบด้วย HN</span></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const H = window.HPQ, $ = (id) => document.getElementById(id);
  const DEPT = <?= json_encode(implode(',', $sel)) ?>;
  H.startClock($('clock-t'), $('clock-d'));
  let sound = false, first = true;
  const seen = new Set();

  $('btn-sound').addEventListener('click', () => {
    sound = !sound;
    $('btn-sound').textContent = sound ? 'ปิดเสียงประกาศ' : 'เปิดเสียงประกาศ';
    if (sound) { H.beep(1); H.speak('เปิดเสียงประกาศเรียกคิว'); }
  });

  async function refresh() {
    const j = await H.api('api/board.php?dept=' + DEPT);
    const fresh = [];
    $('calls').innerHTML = j.serving.map(s => {
      const key = s.id + '-' + s.announced;
      const isNew = !seen.has(key);
      if (isNew) { seen.add(key); if (!first) fresh.push(s); }
      return `<div class="call-row ${isNew && !first ? 'flash' : ''}"><span class="code">${H.esc(s.queue_code)}</span><span>${H.esc(s.room || '-')}</span><span>${H.esc(s.dept_name)}</span></div>`;
    }).join('') || '<p style="font-size:1.4rem;color:#9fd3e3">ยังไม่มีการเรียกคิว</p>';
    $('waiting').innerHTML = j.waiting.map(w => `<span>${H.esc(w.queue_code)}</span>`).join('') || '<p style="color:#9fd3e3">ไม่มีคิวรอ</p>';
    $('pharmacy').innerHTML = j.pharmacy.map(c => `<span>${H.esc(c)}</span>`).join('') || '<p style="color:#9fd3e3">-</p>';
    first = false;
    if (sound) fresh.reverse().forEach(s => {
      H.beep(2);
      // เลือกเสียงตามเพศผู้ป่วย คำลงท้ายตามเพศของเสียงที่ได้จริง (ถ้าไม่ทราบใช้ตามเพศผู้ป่วย)
      const { voice, gender } = H.pickVoice(s.gender);
      const ending = (gender || s.gender) === 'f' ? 'ค่ะ' : 'ครับ';
      H.speak(`ขอเชิญหมายเลข ${H.spellQueue(s.queue_code)} ที่ ${s.room || 'ห้องตรวจ'} แผนก${s.dept_name} ${ending}`, voice);
    });
  }
  H.poll(refresh, 3000);
});
</script>
<?php page_footer();
