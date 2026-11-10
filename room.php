<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('doctor');
$depts = Queue::departments();
$deptId = (int)($u['department_id'] ?? 0);
if ($u['role'] === 'admin') $deptId = (int)($_GET['dept'] ?? $deptId) ?: (int)($depts[0]['id'] ?? 0);
$dept = $deptId ? Queue::department($deptId) : null;
$room = $u['room'] ?: 'ห้องตรวจ 1';

page_header('ห้องตรวจ');
?>
<main class="container">
  <?php if (!$dept): ?>
    <p class="alert warn">บัญชีของท่านยังไม่ได้กำหนดแผนก กรุณาติดต่อผู้ดูแลระบบ</p>
  <?php else: ?>
  <div class="row between" style="margin-bottom:12px">
    <div>
      <h1 style="margin:0">ห้องตรวจ · แผนก<?= e($dept['name']) ?></h1>
      <div class="muted small">เวลาตรวจเฉลี่ย <b id="avg">-</b> นาที/คิว</div>
    </div>
    <div class="inline-form">
      <?php if ($u['role'] === 'admin'): ?>
        <label>แผนก<select onchange="location='?dept='+this.value">
          <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $deptId ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <label>ห้อง<select id="room">
        <?php for ($i = 1; $i <= max(1, (int)$dept['rooms']); $i++): $r = 'ห้องตรวจ ' . $i; ?>
          <option <?= $r === $room ? 'selected' : '' ?>><?= e($r) ?></option>
        <?php endfor; ?>
        <?php if (!preg_match('/^ห้องตรวจ \d+$/u', $room)): ?><option selected><?= e($room) ?></option><?php endif; ?>
      </select></label>
      <div class="clock"><div class="t" id="clock-t"></div></div>
    </div>
  </div>

  <div class="grid c4" style="margin-bottom:16px">
    <div class="stat"><div class="label">รอเรียก</div><div class="value" id="st-wait">0</div></div>
    <div class="stat"><div class="label">กำลังตรวจ (ทุกห้อง)</div><div class="value" id="st-serve">0</div></div>
    <div class="stat"><div class="label">ตรวจแล้ววันนี้</div><div class="value" id="st-done">0</div></div>
    <div class="stat"><div class="label">เลยคิว</div><div class="value" id="st-skip">0</div></div>
  </div>

  <div class="grid c2" style="align-items:start">
    <section class="card">
      <h2>กำลังตรวจในห้องนี้</h2>
      <div id="current"></div>
      <div class="btn-bar">
        <button class="btn primary lg" id="btn-next">เรียกคิวถัดไป</button>
      </div>
    </section>

    <section class="card">
      <h2>คิวรอเรียก</h2>
      <div class="table-wrap"><table>
        <thead><tr><th>คิว</th><th>ผู้ป่วย</th><th>ลงทะเบียน</th><th>รอ</th><th></th></tr></thead>
        <tbody id="wait-rows"></tbody>
      </table></div>
      <h3 style="margin-top:20px">เลยคิว</h3>
      <div class="table-wrap"><table><tbody id="skip-rows"></tbody></table></div>
    </section>
  </div>
  <?php endif; ?>
</main>

<?php if ($dept): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const H = window.HPQ, $ = (id) => document.getElementById(id);
  const DEPT = <?= $deptId ?>;
  H.startClock($('clock-t'));
  let data = [];
  const room = () => $('room').value;

  $('room').addEventListener('change', async () => {
    try { const j = await H.api('api/action.php', { action: 'set_room', room: room(), department_id: DEPT }); H.toast(j.message); render(); }
    catch (e) { H.toast(e.message, 'err'); }
  });

  function render() {
    const by = (s) => data.filter(q => q.status === s);
    $('st-wait').textContent = by('waiting').length;
    $('st-serve').textContent = by('serving').length;
    $('st-done').textContent = data.filter(q => ['pharmacy', 'done'].includes(q.status)).length;
    $('st-skip').textContent = by('skipped').length;

    const mine = by('serving').filter(q => q.room === room());
    $('current').innerHTML = mine.length ? mine.map(q => `
      <div class="now-serving">
        <div class="code">${H.esc(q.queue_code)}</div>
        <div class="name">${H.esc(q.patient_name)} <span class="muted">· HN ${H.esc(q.hn)}</span></div>
        <div class="small muted">เรียกเมื่อ ${q.called} น. · ใช้เวลา <span class="num" data-since="${q.called_ts}"></span>${q.recall_count ? ' · เรียกซ้ำ ' + q.recall_count + ' ครั้ง' : ''}</div>
        <div class="btn-bar">
          <button class="btn" data-act="recall" data-id="${q.id}">เรียกซ้ำ</button>
          <button class="btn primary" data-act="pharmacy" data-id="${q.id}">ตรวจเสร็จ → รับยา/ชำระเงิน</button>
          <button class="btn ok" data-act="finish" data-id="${q.id}">เสร็จสิ้น (ไม่มียา)</button>
          <button class="btn danger" data-act="skip" data-id="${q.id}">ไม่มา (ข้ามคิว)</button>
          <button class="btn" data-act="back" data-id="${q.id}">คืนเข้าคิวรอ</button>
        </div>
      </div>`).join('<hr>') : '<p class="muted" style="text-align:center;padding:24px 0">ไม่มีผู้ป่วยในห้องนี้ กด "เรียกคิวถัดไป"</p>';

    $('wait-rows').innerHTML = by('waiting').map((q, i) => `<tr>
      <td><b>${H.esc(q.queue_code)}</b></td><td>${H.esc(q.patient_name)}<div class="small muted">HN ${H.esc(q.hn)}</div></td>
      <td class="num small">${q.checkin}</td><td class="num small" data-since="${q.checkin_ts}"></td>
      <td class="actions"><button class="btn sm" data-act="call" data-id="${q.id}">เรียก</button></td></tr>`).join('')
      || '<tr><td colspan="5" class="muted" style="text-align:center;padding:20px">ไม่มีคิวรอ</td></tr>';
    $('skip-rows').innerHTML = by('skipped').map(q => `<tr>
      <td><b>${H.esc(q.queue_code)}</b></td><td>${H.esc(q.patient_name)}</td>
      <td class="actions"><button class="btn sm" data-act="restore" data-id="${q.id}">นำกลับเข้าคิว</button>
      <button class="btn sm" data-act="call" data-id="${q.id}">เรียกเข้าห้อง</button></td></tr>`).join('')
      || '<tr><td class="muted" style="text-align:center">-</td></tr>';
    ticks();
  }

  function ticks() {
    document.querySelectorAll('[data-since]').forEach(el => {
      const t = +el.dataset.since; el.textContent = t ? H.fmtDuration(H.now() - t) : '';
    });
  }
  setInterval(ticks, 1000);

  async function load() {
    const j = await H.api('api/action.php?department_id=' + DEPT);
    data = j.queues; $('avg').textContent = j.avg_service_min; render();
  }

  async function act(payload, btn) {
    if (btn) btn.disabled = true;
    try { const j = await H.api('api/action.php', payload); H.toast(j.message, j.queue ? 'ok' : 'warn'); await load(); }
    catch (e) { H.toast(e.message, 'err'); if (btn) btn.disabled = false; }
  }

  $('btn-next').addEventListener('click', () => {
    const btn = $('btn-next');
    const busy = data.some(q => q.status === 'serving' && q.room === room());
    if (busy && !confirm('ยังมีผู้ป่วยในห้องนี้ ต้องการเรียกคิวถัดไปเพิ่มหรือไม่?')) return;
    act({ action: 'call_next', department_id: DEPT, room: room() }, btn).then(() => { btn.disabled = false; });
  });
  document.querySelector('main').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-act]'); if (!b) return;
    if (b.dataset.act === 'skip' && !confirm('ยืนยันข้ามคิวนี้ (ผู้ป่วยไม่มา)?')) return;
    act({ action: b.dataset.act, id: b.dataset.id, room: room() }, b);
  });

  H.poll(load, 4000);
});
</script>
<?php endif; ?>
<?php page_footer();
