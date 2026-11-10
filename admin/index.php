<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_role('admin');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-13 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$dept = (int)($_GET['dept'] ?? 0);
$depts = Queue::departments(false);

$where = 'q.queue_date BETWEEN ? AND ?' . ($dept ? ' AND q.department_id = ?' : '');
$args = $dept ? [$from, $to, $dept] : [$from, $to];
$q = function (string $sql) use ($args) {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
};

/* ---------- CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="queue-report-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['วันที่', 'คิว', 'แผนก', 'HN', 'ชื่อ', 'ช่องทาง', 'สถานะ', 'ห้อง', 'ออกบัตร', 'ลงทะเบียน', 'เรียก', 'ส่งรับยา', 'เสร็จสิ้น', 'รอ(นาที)', 'ตรวจ(นาที)']);
    $st = $q("SELECT q.*, d.name dept FROM queues q JOIN departments d ON d.id=q.department_id WHERE $where ORDER BY q.queue_date, d.sort, q.queue_no");
    while ($r = $st->fetch()) {
        $wait = $r['called_at'] && $r['checkin_at'] ? round((strtotime($r['called_at']) - strtotime($r['checkin_at'])) / 60, 1) : '';
        $end = $r['pharmacy_at'] ?: ($r['status'] === 'done' ? $r['finished_at'] : null);
        $svc = $r['called_at'] && $end ? round((strtotime($end) - strtotime($r['called_at'])) / 60, 1) : '';
        fputcsv($out, [$r['queue_date'], $r['queue_code'], $r['dept'], $r['hn'], $r['patient_name'], Queue::SOURCE_LABEL[$r['source']],
            Queue::STATUS_LABEL[$r['status']], $r['room'], $r['created_at'], $r['checkin_at'], $r['called_at'], $r['pharmacy_at'], $r['finished_at'], $wait, $svc]);
    }
    exit;
}

/* ---------- KPI ---------- */
$kpi = $q("SELECT COUNT(*) total, SUM(status='done') done, SUM(status='skipped') skipped, SUM(status='cancelled') cancelled,
    AVG(CASE WHEN called_at IS NOT NULL AND checkin_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, checkin_at, called_at) END)/60 avg_wait,
    MAX(CASE WHEN called_at IS NOT NULL AND checkin_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, checkin_at, called_at) END)/60 max_wait,
    AVG(CASE WHEN called_at IS NOT NULL AND (pharmacy_at IS NOT NULL OR status='done') THEN TIMESTAMPDIFF(SECOND, called_at, COALESCE(pharmacy_at, finished_at)) END)/60 avg_service,
    AVG(CASE WHEN status='done' AND checkin_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, checkin_at, finished_at) END)/60 avg_total
    FROM queues q WHERE $where")->fetch();
$days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);

$perDay = $q("SELECT queue_date d, SUM(status='done') done, SUM(status IN ('skipped')) skipped, SUM(status='cancelled') cancelled,
    SUM(status NOT IN ('done','skipped','cancelled')) pending FROM queues q WHERE $where GROUP BY queue_date ORDER BY queue_date")->fetchAll();
$perHour = $q("SELECT HOUR(COALESCE(checkin_at, created_at)) h, COUNT(*) n FROM queues q WHERE $where AND status <> 'cancelled' GROUP BY h ORDER BY h")->fetchAll(PDO::FETCH_KEY_PAIR);
$bySource = $q("SELECT source, COUNT(*) n FROM queues q WHERE $where GROUP BY source")->fetchAll(PDO::FETCH_KEY_PAIR);
$byDept = $q("SELECT d.name, COUNT(*) total, SUM(q.status='done') done, SUM(q.status='skipped') skipped, SUM(q.status='cancelled') cancelled,
    AVG(CASE WHEN q.called_at IS NOT NULL AND q.checkin_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, q.checkin_at, q.called_at) END)/60 avg_wait,
    MAX(CASE WHEN q.called_at IS NOT NULL AND q.checkin_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, q.checkin_at, q.called_at) END)/60 max_wait,
    AVG(CASE WHEN q.called_at IS NOT NULL AND (q.pharmacy_at IS NOT NULL OR q.status='done') THEN TIMESTAMPDIFF(SECOND, q.called_at, COALESCE(q.pharmacy_at, q.finished_at)) END)/60 avg_service
    FROM queues q JOIN departments d ON d.id = q.department_id WHERE $where GROUP BY d.id ORDER BY d.sort, d.id")->fetchAll();

$today = db()->query("SELECT d.name, SUM(q.status='registered') registered, SUM(q.status='waiting') waiting, SUM(q.status='serving') serving,
    SUM(q.status='pharmacy') pharmacy, SUM(q.status='done') done
    FROM departments d LEFT JOIN queues q ON q.department_id=d.id AND q.queue_date=CURDATE()
    WHERE d.active=1 GROUP BY d.id ORDER BY d.sort, d.id")->fetchAll();

$fmt = fn($v, $dec = 1) => $v === null ? '-' : number_format((float)$v, $dec);
$hours = range(6, 18);
foreach ($perHour as $h => $n) if ($h < 6 || $h > 18) $hours[] = (int)$h;
sort($hours);
$hours = array_values(array_unique($hours));

page_header('รายงานสถิติ');
?>
<main class="container">
  <div class="row between">
    <h1 style="margin:0">รายงานสถิติและสรุปข้อมูล</h1>
    <form class="inline-form" method="get">
      <label>ตั้งแต่<input type="date" name="from" value="<?= e($from) ?>"></label>
      <label>ถึง<input type="date" name="to" value="<?= e($to) ?>"></label>
      <label>แผนก<select name="dept"><option value="0">ทุกแผนก</option>
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></label>
      <button class="btn primary">แสดงรายงาน</button>
      <a class="btn" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'dept' => $dept, 'export' => 'csv'])) ?>">ส่งออก CSV</a>
    </form>
  </div>
  <p class="muted"><?= e(thai_date($from)) ?> – <?= e(thai_date($to)) ?> (<?= $days ?> วัน)</p>

  <div class="grid c4" style="margin-bottom:16px">
    <div class="stat"><div class="label">จำนวนคิวทั้งหมด</div><div class="value"><?= number_format((int)$kpi['total']) ?></div><div class="sub">เฉลี่ย <?= $fmt($kpi['total'] / $days) ?> คิว/วัน</div></div>
    <div class="stat"><div class="label">ให้บริการสำเร็จ</div><div class="value"><?= number_format((int)$kpi['done']) ?></div><div class="sub"><?= $kpi['total'] ? $fmt($kpi['done'] * 100 / $kpi['total']) : 0 ?>% ของทั้งหมด</div></div>
    <div class="stat"><div class="label">เวลารอเรียกเฉลี่ย</div><div class="value"><?= $fmt($kpi['avg_wait']) ?> <small style="font-size:1rem">นาที</small></div><div class="sub">นานสุด <?= $fmt($kpi['max_wait'], 0) ?> นาที</div></div>
    <div class="stat"><div class="label">เวลาพบแพทย์เฉลี่ย</div><div class="value"><?= $fmt($kpi['avg_service']) ?> <small style="font-size:1rem">นาที</small></div><div class="sub">รวมทั้งกระบวนการ <?= $fmt($kpi['avg_total']) ?> นาที</div></div>
  </div>

  <div class="grid c2" style="margin-bottom:16px">
    <section class="card"><h2>จำนวนคิวรายวัน</h2><div class="chart-box"><canvas id="ch-day"></canvas></div></section>
    <section class="card"><h2>ช่วงเวลาที่มีผู้รับบริการ</h2><div class="chart-box"><canvas id="ch-hour"></canvas></div></section>
  </div>

  <section class="card" style="margin-bottom:16px">
    <h2>สรุปรายแผนก</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>แผนก</th><th class="num">ทั้งหมด</th><th class="num">สำเร็จ</th><th class="num">เลยคิว</th><th class="num">ยกเลิก</th><th class="num">รอเฉลี่ย (นาที)</th><th class="num">รอนานสุด</th><th class="num">ตรวจเฉลี่ย (นาที)</th></tr></thead>
      <tbody>
      <?php foreach ($byDept as $r): ?>
        <tr><td><?= e($r['name']) ?></td><td class="num"><?= (int)$r['total'] ?></td><td class="num"><?= (int)$r['done'] ?></td>
          <td class="num"><?= (int)$r['skipped'] ?></td><td class="num"><?= (int)$r['cancelled'] ?></td>
          <td class="num"><?= $fmt($r['avg_wait']) ?></td><td class="num"><?= $fmt($r['max_wait'], 0) ?></td><td class="num"><?= $fmt($r['avg_service']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$byDept): ?><tr><td colspan="8" class="muted" style="text-align:center">ไม่มีข้อมูลในช่วงเวลานี้</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>

  <div class="grid c2">
    <section class="card">
      <h2>ช่องทางการรับคิว</h2>
      <div class="table-wrap"><table>
        <thead><tr><th>ช่องทาง</th><th class="num">จำนวน</th><th class="num">สัดส่วน</th></tr></thead>
        <tbody>
        <?php $sum = array_sum($bySource) ?: 1; foreach (Queue::SOURCE_LABEL as $k => $l): ?>
          <tr><td><?= e($l) ?></td><td class="num"><?= (int)($bySource[$k] ?? 0) ?></td><td class="num"><?= $fmt(($bySource[$k] ?? 0) * 100 / $sum) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <section class="card">
      <h2>สถานะคิววันนี้ (ขณะนี้)</h2>
      <div class="table-wrap"><table>
        <thead><tr><th>แผนก</th><th class="num">รอยืนยัน</th><th class="num">รอเรียก</th><th class="num">ตรวจ</th><th class="num">รับยา</th><th class="num">เสร็จ</th></tr></thead>
        <tbody>
        <?php foreach ($today as $r): ?>
          <tr><td><?= e($r['name']) ?></td><td class="num"><?= (int)$r['registered'] ?></td><td class="num"><?= (int)$r['waiting'] ?></td>
            <td class="num"><?= (int)$r['serving'] ?></td><td class="num"><?= (int)$r['pharmacy'] ?></td><td class="num"><?= (int)$r['done'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  if (!window.Chart) return;
  const css = getComputedStyle(document.documentElement);
  const c = (v) => css.getPropertyValue(v).trim();
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.color = c('--muted');
  Chart.defaults.borderColor = c('--line');
  const day = <?= json_encode($perDay) ?>;
  const fmtD = (s) => { const d = new Date(s + 'T00:00'); return d.getDate() + '/' + (d.getMonth() + 1); };
  new Chart(document.getElementById('ch-day'), {
    type: 'bar',
    data: { labels: day.map(r => fmtD(r.d)), datasets: [
      { label: 'สำเร็จ', data: day.map(r => +r.done), backgroundColor: c('--ok') },
      { label: 'ค้าง/กำลังดำเนินการ', data: day.map(r => +r.pending), backgroundColor: c('--primary') },
      { label: 'เลยคิว', data: day.map(r => +r.skipped), backgroundColor: c('--warn') },
      { label: 'ยกเลิก', data: day.map(r => +r.cancelled), backgroundColor: c('--err') },
    ] },
    options: { maintainAspectRatio: false, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true } },
      plugins: { legend: { position: 'bottom' } } }
  });
  const hours = <?= json_encode($hours) ?>, ph = <?= json_encode($perHour, JSON_FORCE_OBJECT) ?>;
  new Chart(document.getElementById('ch-hour'), {
    type: 'bar',
    data: { labels: hours.map(h => String(h).padStart(2, '0') + ':00'), datasets: [
      { label: 'จำนวนผู้ลงทะเบียน', data: hours.map(h => +(ph[h] || 0)), backgroundColor: c('--primary') } ] },
    options: { maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
  });
});
</script>
<?php page_footer();
