<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('staff');
$depts = Queue::departments();

page_header('เคาน์เตอร์ต้อนรับ');
?>
<main class="container">
  <div class="row between" style="margin-bottom:12px">
    <h1 style="margin:0">เคาน์เตอร์ต้อนรับ</h1>
    <div class="row">
      <button class="btn" id="btn-screen" type="button" title="เปิดบนจอที่หันหาผู้ป่วย แสดงหมายเลขคิวและ QR code">เปิดจอผู้ป่วย</button>
      <button class="btn" id="btn-screen-clear" type="button">ล้างจอผู้ป่วย</button>
    </div>
    <div class="clock"><div class="t" id="clock-t"></div><div class="d" id="clock-d"></div></div>
  </div>

  <div class="grid" style="grid-template-columns:minmax(280px,360px) 1fr;align-items:start" id="layout">
    <!-- ออกบัตรคิว -->
    <section class="card">
      <h2>ออกบัตรคิว / ลงทะเบียน</h2>
      <form class="form" id="f-lookup">
        <label>HN<div class="row" style="flex-wrap:nowrap"><input name="hn" id="hn" inputmode="numeric" required autocomplete="off" autofocus><button class="btn">ค้นหา</button></div></label>
      </form>
      <div id="pt-info" hidden style="margin-top:12px">
        <div class="alert info" id="pt-card"></div>
        <div id="pt-queues"></div>
        <form class="form" id="f-issue">
          <label>แผนก
            <select name="department_id" required>
              <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['prefix']) ?>)</option><?php endforeach; ?>
            </select>
          </label>
          <button class="btn primary lg">ออกบัตรคิว</button>
        </form>
      </div>
      <div id="last-ticket" style="margin-top:16px"></div>
    </section>

    <!-- รายการคิว -->
    <section class="card">
      <div class="row between">
        <h2 style="margin:0">คิววันนี้</h2>
        <div class="inline-form">
          <label>แผนก
            <select id="filter-dept"><option value="">ทุกแผนก</option>
              <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>ค้นหา<input id="filter-q" placeholder="คิว / HN / ชื่อ" style="width:160px"></label>
        </div>
      </div>
      <div class="tabs" role="tablist" id="tabs" style="margin-top:12px">
        <button role="tab" data-tab="registered">รอยืนยัน<span class="count" data-c="registered">0</span></button>
        <button role="tab" data-tab="waiting" aria-selected="true">รอเรียก<span class="count" data-c="waiting">0</span></button>
        <button role="tab" data-tab="serving">กำลังตรวจ<span class="count" data-c="serving">0</span></button>
        <button role="tab" data-tab="pharmacy">รับยา/ชำระเงิน<span class="count" data-c="pharmacy">0</span></button>
        <button role="tab" data-tab="skipped">เลยคิว<span class="count" data-c="skipped">0</span></button>
        <button role="tab" data-tab="closed">เสร็จสิ้น/ยกเลิก<span class="count" data-c="closed">0</span></button>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>คิว</th><th>HN / ชื่อ</th><th>แผนก</th><th>ช่องทาง</th><th>เวลา</th><th>สถานะ</th><th></th></tr></thead>
        <tbody id="rows"></tbody>
      </table></div>
    </section>
  </div>
</main>
<style>@media (max-width: 900px) { #layout { grid-template-columns: 1fr !important; } }</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const H = window.HPQ, $ = (id) => document.getElementById(id);
  H.startClock($('clock-t'), $('clock-d'));
  let tab = 'waiting', data = [], hn = '';

  // จอฝั่งผู้ป่วย (counter_screen.php) — แจ้งให้อัปเดตทันทีถ้าเปิดในเบราว์เซอร์เดียวกัน
  const screenCh = 'BroadcastChannel' in window ? new BroadcastChannel('hpq-counter-screen') : null;
  const pingScreen = () => screenCh && screenCh.postMessage('update');
  $('btn-screen').addEventListener('click', () => window.open('counter_screen.php', 'hpq-counter-screen', 'popup,width=1100,height=700'));
  $('btn-screen-clear').addEventListener('click', async () => {
    try { const j = await H.api('api/action.php', { action: 'screen_clear' }); H.toast(j.message); pingScreen(); }
    catch (err) { H.toast(err.message, 'err'); }
  });

  const ACTIONS = {
    registered: [['checkin', 'ยืนยันลงทะเบียน', 'primary'], ['cancel', 'ยกเลิก', 'danger']],
    waiting: [['cancel', 'ยกเลิก', 'danger']],
    serving: [['recall', 'เรียกซ้ำ', ''], ['pharmacy', 'ส่งรับยา', 'primary']],
    pharmacy: [['finish', 'รับยา/ชำระเงินแล้ว', 'ok']],
    skipped: [['restore', 'นำกลับเข้าคิว', 'primary'], ['cancel', 'ยกเลิก', 'danger']],
    closed: [],
  };
  const group = (s) => ['done', 'cancelled'].includes(s) ? 'closed' : s;

  function render() {
    const counts = {};
    data.forEach(q => counts[group(q.status)] = (counts[group(q.status)] || 0) + 1);
    document.querySelectorAll('[data-c]').forEach(el => el.textContent = counts[el.dataset.c] || 0);
    const term = $('filter-q').value.trim().toLowerCase();
    const rows = data.filter(q => group(q.status) === tab)
      .filter(q => !term || (q.queue_code + ' ' + q.hn + ' ' + q.patient_name).toLowerCase().includes(term));
    $('rows').innerHTML = rows.map(q => {
      const t = q.status === 'serving' ? `เรียก ${q.called} · ${H.esc(q.room || '')}` :
        q.status === 'registered' ? `จอง ${q.created}` : (q.finished ? `เสร็จ ${q.finished}` : `ลงทะเบียน ${q.checkin || q.created}`);
      const btns = (ACTIONS[tab] || []).map(([a, l, c]) => `<button class="btn sm ${c}" data-act="${a}" data-id="${q.id}" data-code="${H.esc(q.queue_code)}">${l}</button>`).join('');
      return `<tr><td><b>${H.esc(q.queue_code)}</b></td><td>${H.esc(q.hn)}<div class="small muted">${H.esc(q.patient_name)}</div></td>
        <td>${H.esc(q.dept_name)}</td><td class="small">${H.esc(q.source)}</td><td class="small num">${t}</td>
        <td><span class="badge s-${q.status}">${H.esc(q.status_label)}</span></td><td class="actions">${btns}</td></tr>`;
    }).join('') || '<tr><td colspan="7" class="muted" style="text-align:center;padding:24px">ไม่มีคิวในสถานะนี้</td></tr>';
  }

  async function load() {
    const j = await H.api('api/action.php?department_id=' + $('filter-dept').value);
    data = j.queues; render();
  }

  $('tabs').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-tab]'); if (!b) return;
    tab = b.dataset.tab;
    document.querySelectorAll('#tabs button').forEach(x => x.setAttribute('aria-selected', x === b));
    render();
  });
  $('filter-dept').addEventListener('change', load);
  $('filter-q').addEventListener('input', render);

  $('rows').addEventListener('click', async (e) => {
    const b = e.target.closest('button[data-act]'); if (!b) return;
    if (b.dataset.act === 'cancel' && !confirm('ยืนยันยกเลิกคิว ' + b.dataset.code + '?')) return;
    b.disabled = true;
    try { const j = await H.api('api/action.php', { action: b.dataset.act, id: b.dataset.id }); H.toast(j.message); if (b.dataset.act === 'checkin') pingScreen(); await load(); }
    catch (err) { H.toast(err.message, 'err'); b.disabled = false; }
  });

  $('f-lookup').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const j = await H.api('api/action.php', { action: 'lookup', hn: $('hn').value });
      hn = j.patient.hn;
      $('hn').value = hn;
      $('pt-card').innerHTML = `<b>${H.esc(j.patient.name)}</b><br>HN ${H.esc(hn)} · เกิด ${H.esc(j.patient.birthdate)}${j.patient.phone ? ' · โทร ' + H.esc(j.patient.phone) : ''}`;
      $('pt-queues').innerHTML = j.queues.length ? '<p class="small muted" style="margin:0 0 6px">คิววันนี้ของผู้ป่วย</p>' + j.queues.map(q =>
        `<div class="row between" style="margin-bottom:8px"><span><b>${H.esc(q.queue_code)}</b> ${H.esc(q.dept_name)} <span class="badge s-${q.status}">${H.esc(q.status_label)}</span></span>
         ${q.status === 'registered' ? `<button class="btn sm primary" data-checkin="${q.id}">ยืนยันลงทะเบียน</button>` : `<button class="btn sm" data-show="${q.id}">แสดง QR</button>`}</div>`).join('') : '';
      $('pt-info').hidden = false;
    } catch (err) { $('pt-info').hidden = true; H.toast(err.message, 'err'); }
  });
  $('pt-queues').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-checkin],[data-show]'); if (!b) return;
    if (b.dataset.show) {
      try { const j = await H.api('api/action.php', { action: 'screen_show', id: b.dataset.show }); H.toast(j.message); pingScreen(); }
      catch (err) { H.toast(err.message, 'err'); }
      return;
    }
    try { const j = await H.api('api/action.php', { action: 'checkin', id: b.dataset.checkin }); H.toast(j.message); pingScreen(); $('f-lookup').requestSubmit(); load(); }
    catch (err) { H.toast(err.message, 'err'); }
  });

  $('f-issue').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.submitter; btn.disabled = true;
    try {
      const j = await H.api('api/action.php', { action: 'issue', hn, department_id: e.target.department_id.value });
      H.toast(j.message);
      pingScreen();
      $('last-ticket').innerHTML = `<div class="card" style="text-align:center;background:var(--primary-soft)">
        <div class="muted small">ออกบัตรคิวล่าสุด</div><div style="font-size:2.6rem;font-weight:700;color:var(--primary)">${H.esc(j.queue.queue_code)}</div>
        <div>${H.esc(j.queue.dept_name)} · รอก่อนหน้า ${j.ahead} คิว</div><div class="small muted">${H.esc(j.queue.patient_name)} (HN ${H.esc(j.queue.hn)})</div>
        <div class="small muted" style="margin-top:6px">แสดง QR บนจอผู้ป่วยแล้ว</div></div>`;
      $('pt-info').hidden = true; $('hn').value = ''; $('hn').focus();
      load();
    } catch (err) { H.toast(err.message, 'err'); }
    btn.disabled = false;
  });

  H.poll(load, 4000);
});
</script>
<?php page_footer();
