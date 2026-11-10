<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        $st = db()->prepare('SELECT COUNT(*) FROM queues WHERE department_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn()) {
            db()->prepare('UPDATE departments SET active = 0 WHERE id = ?')->execute([$id]);
            flash('แผนกนี้มีประวัติคิว จึงปิดการใช้งานแทนการลบ', 'warn');
        } else {
            db()->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            flash('ลบแผนกแล้ว');
        }
        redirect('admin/departments.php');
    }
    $f = [
        'code' => strtoupper(trim((string)$_POST['code'])),
        'name' => trim((string)$_POST['name']),
        'prefix' => strtoupper(trim((string)$_POST['prefix'])),
        'rooms' => max(1, min(50, (int)$_POST['rooms'])),
        'avg_service_min' => max(1, min(240, (int)$_POST['avg_service_min'])),
        'daily_limit' => max(0, (int)$_POST['daily_limit']),
        'allow_online' => !empty($_POST['allow_online']) ? 1 : 0,
        'active' => !empty($_POST['active']) ? 1 : 0,
        'sort' => (int)$_POST['sort'],
    ];
    if ($f['code'] === '' || $f['name'] === '' || !preg_match('/^[A-Z]{1,3}$/', $f['prefix'])) {
        flash('กรุณากรอกรหัส ชื่อแผนก และอักษรนำหน้าคิว (A-Z 1-3 ตัว)', 'err');
        redirect('admin/departments.php' . ($id ? '?edit=' . $id : ''));
    }
    try {
        if ($id) {
            $st = db()->prepare('UPDATE departments SET code=:code, name=:name, prefix=:prefix, rooms=:rooms, avg_service_min=:avg_service_min,
                daily_limit=:daily_limit, allow_online=:allow_online, active=:active, sort=:sort WHERE id=:id');
            $st->execute($f + ['id' => $id]);
        } else {
            $st = db()->prepare('INSERT INTO departments (code, name, prefix, rooms, avg_service_min, daily_limit, allow_online, active, sort)
                VALUES (:code, :name, :prefix, :rooms, :avg_service_min, :daily_limit, :allow_online, :active, :sort)');
            $st->execute($f);
        }
        flash('บันทึกแผนก ' . $f['name'] . ' แล้ว');
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'รหัสแผนกซ้ำกับที่มีอยู่' : 'บันทึกไม่สำเร็จ', 'err');
    }
    redirect('admin/departments.php');
}

$depts = Queue::departments(false);
$edit = null;
if (isset($_GET['edit'])) {
    $edit = Queue::department((int)$_GET['edit']);
}
$edit ??= ['id' => 0, 'code' => '', 'name' => '', 'prefix' => '', 'rooms' => 1, 'avg_service_min' => 10, 'daily_limit' => 0, 'allow_online' => 1, 'active' => 1, 'sort' => count($depts) + 1];

page_header('จัดการแผนก');
?>
<main class="container">
  <h1>จัดการแผนก</h1>
  <div class="grid" style="grid-template-columns:1fr minmax(280px,360px);align-items:start" id="layout">
    <div class="table-wrap"><table>
      <thead><tr><th>ลำดับ</th><th>รหัส</th><th>ชื่อแผนก</th><th>คิว</th><th class="num">ห้อง</th><th class="num">นาที/คิว</th><th class="num">จำกัด/วัน</th><th>จองออนไลน์</th><th>สถานะ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($depts as $d): ?>
        <tr>
          <td class="num"><?= (int)$d['sort'] ?></td><td><?= e($d['code']) ?></td><td><b><?= e($d['name']) ?></b></td><td><?= e($d['prefix']) ?>001</td>
          <td class="num"><?= (int)$d['rooms'] ?></td><td class="num"><?= (int)$d['avg_service_min'] ?></td>
          <td class="num"><?= (int)$d['daily_limit'] ?: 'ไม่จำกัด' ?></td>
          <td><?= $d['allow_online'] ? 'เปิด' : 'ปิด' ?></td>
          <td><?= $d['active'] ? '<span class="badge s-done">เปิดบริการ</span>' : '<span class="badge s-cancelled">ปิด</span>' ?></td>
          <td class="actions">
            <a class="btn sm" href="?edit=<?= (int)$d['id'] ?>">แก้ไข</a>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn sm danger" data-confirm="ลบแผนก <?= e($d['name']) ?>?">ลบ</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <section class="card">
      <h2><?= $edit['id'] ? 'แก้ไขแผนก' : 'เพิ่มแผนกใหม่' ?></h2>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <label>ชื่อแผนก<input name="name" required value="<?= e($edit['name']) ?>"></label>
        <div class="grid c2" style="grid-template-columns:1fr 1fr">
          <label>รหัสแผนก<input name="code" required maxlength="20" value="<?= e($edit['code']) ?>"></label>
          <label>อักษรนำหน้าคิว<input name="prefix" required maxlength="3" pattern="[A-Za-z]{1,3}" value="<?= e($edit['prefix']) ?>"></label>
          <label>จำนวนห้องตรวจ<input type="number" name="rooms" min="1" max="50" value="<?= (int)$edit['rooms'] ?>"></label>
          <label>เวลาตรวจเฉลี่ย (นาที)<input type="number" name="avg_service_min" min="1" max="240" value="<?= (int)$edit['avg_service_min'] ?>"></label>
          <label>จำกัดคิว/วัน (0=ไม่จำกัด)<input type="number" name="daily_limit" min="0" value="<?= (int)$edit['daily_limit'] ?>"></label>
          <label>ลำดับการแสดง<input type="number" name="sort" value="<?= (int)$edit['sort'] ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="allow_online" value="1" <?= $edit['allow_online'] ? 'checked' : '' ?>> เปิดให้ผู้ป่วยจองคิวออนไลน์</label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> เปิดให้บริการ</label>
        <div class="row"><button class="btn primary">บันทึก</button><?php if ($edit['id']): ?><a class="btn" href="departments.php">ยกเลิก</a><?php endif; ?></div>
        <p class="small muted" style="margin:0">เวลาตรวจเฉลี่ยใช้คำนวณเวลานับถอยหลัง ระบบจะใช้ค่าเฉลี่ยจริงของวันนั้นแทนเมื่อตรวจไปแล้วอย่างน้อย 3 ราย</p>
      </form>
    </section>
  </div>
</main>
<style>@media (max-width: 900px) { #layout { grid-template-columns: 1fr !important; } }</style>
<?php page_footer();
