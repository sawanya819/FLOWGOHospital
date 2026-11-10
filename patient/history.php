<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$p = require_patient();

$error = null;
try {
    $visits = His::history($p['hn']);
} catch (Throwable $ex) {
    $visits = [];
    $error = 'ไม่สามารถดึงประวัติการรักษาจากฐานข้อมูลโรงพยาบาลได้ในขณะนี้';
}
$st = db()->prepare("SELECT q.*, d.name AS dept_name FROM queues q JOIN departments d ON d.id = q.department_id
                     WHERE q.hn = ? ORDER BY q.queue_date DESC, q.id DESC LIMIT 50");
$st->execute([$p['hn']]);
$queues = $st->fetchAll();

page_header('ประวัติการรักษา');
?>
<main class="container">
  <h1>ประวัติการรักษาย้อนหลัง</h1>
  <p class="muted">คุณ<?= e($p['name']) ?> · HN <?= e($p['hn']) ?></p>
  <?php if ($error): ?><p class="alert err"><?= e($error) ?></p><?php endif; ?>

  <section class="stack">
    <h2>การรักษา</h2>
    <?php if (!$visits && !$error): ?>
      <div class="card muted">ยังไม่มีประวัติการรักษา</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>วันที่</th><th>แผนก</th><th>แพทย์</th><th>การวินิจฉัย</th><th>การรักษา</th><th>ยา</th></tr></thead>
      <tbody>
      <?php foreach ($visits as $v): ?>
        <tr>
          <td class="num" style="white-space:nowrap"><?= e(thai_date($v['visit_date'])) ?></td>
          <td><?= e($v['department']) ?></td>
          <td><?= e($v['doctor']) ?></td>
          <td><?= e($v['diagnosis']) ?></td>
          <td><?= e($v['treatment']) ?></td>
          <td><?= e($v['medication']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>

    <h2 style="margin-top:28px">ประวัติการใช้บริการคิว</h2>
    <?php if (!$queues): ?>
      <div class="card muted">ยังไม่มีประวัติการใช้คิว</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>วันที่</th><th>คิว</th><th>แผนก</th><th>ช่องทาง</th><th>เวลาเรียก</th><th>เสร็จสิ้น</th><th>สถานะ</th></tr></thead>
      <tbody>
      <?php foreach ($queues as $q): ?>
        <tr>
          <td class="num"><?= e(thai_date($q['queue_date'])) ?></td>
          <td><b><?= e($q['queue_code']) ?></b></td>
          <td><?= e($q['dept_name']) ?></td>
          <td><?= e(Queue::SOURCE_LABEL[$q['source']] ?? $q['source']) ?></td>
          <td class="num"><?= $q['called_at'] ? date('H:i', strtotime($q['called_at'])) : '-' ?></td>
          <td class="num"><?= $q['finished_at'] ? date('H:i', strtotime($q['finished_at'])) : '-' ?></td>
          <td><?= status_badge($q['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</main>
<?php page_footer();
