<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$me = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $f = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'role' => array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : 'staff',
        'department_id' => (int)($_POST['department_id'] ?? 0) ?: null,
        'room' => trim((string)($_POST['room'] ?? '')) ?: null,
        'active' => !empty($_POST['active']) ? 1 : 0,
    ];
    $pass = (string)($_POST['password'] ?? '');
    $back = 'admin/users.php' . ($id ? '?edit=' . $id : '');

    if ($f['full_name'] === '' || (!$id && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username))) {
        flash('กรุณากรอกชื่อ-สกุล และชื่อผู้ใช้ (a-z, 0-9, _ . - อย่างน้อย 3 ตัว)', 'err');
        redirect($back);
    }
    if ((!$id || $pass !== '') && strlen($pass) < 4) {
        flash('รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร', 'err');
        redirect($back);
    }
    if ($f['role'] === 'doctor' && !$f['department_id']) {
        flash('แพทย์/พยาบาลต้องระบุแผนก', 'err');
        redirect($back);
    }
    if ($id === $me['id'] && ($f['role'] !== 'admin' || !$f['active'])) {
        flash('ไม่สามารถลดสิทธิ์หรือปิดบัญชีของตัวเองได้', 'err');
        redirect($back);
    }
    try {
        if ($id) {
            db()->prepare('UPDATE users SET full_name=?, role=?, department_id=?, room=?, active=? WHERE id=?')
                ->execute([$f['full_name'], $f['role'], $f['department_id'], $f['room'], $f['active'], $id]);
            if ($pass !== '') db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        } else {
            db()->prepare('INSERT INTO users (username, password_hash, full_name, role, department_id, room, active) VALUES (?,?,?,?,?,?,?)')
                ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $f['full_name'], $f['role'], $f['department_id'], $f['room'], $f['active']]);
        }
        flash('บันทึกผู้ใช้แล้ว');
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'ชื่อผู้ใช้นี้มีอยู่แล้ว' : 'บันทึกไม่สำเร็จ', 'err');
        redirect($back);
    }
    redirect('admin/users.php');
}

$users = db()->query('SELECT u.*, d.name dept FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY u.role, u.username')->fetchAll();
$depts = Queue::departments(false);
$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$edit ??= ['id' => 0, 'username' => '', 'full_name' => '', 'role' => 'staff', 'department_id' => null, 'room' => '', 'active' => 1];

page_header('จัดการผู้ใช้');
?>
<main class="container">
  <h1>จัดการผู้ใช้งาน</h1>
  <div class="grid" style="grid-template-columns:1fr minmax(280px,360px);align-items:start" id="layout">
    <div class="table-wrap"><table>
      <thead><tr><th>ชื่อผู้ใช้</th><th>ชื่อ-สกุล</th><th>บทบาท</th><th>แผนก / ห้อง</th><th>เข้าใช้ล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $x): ?>
        <tr>
          <td><b><?= e($x['username']) ?></b></td><td><?= e($x['full_name']) ?></td><td><?= e(ROLES[$x['role']]) ?></td>
          <td><?= e($x['dept'] ?? '-') ?><?= $x['room'] ? ' · ' . e($x['room']) : '' ?></td>
          <td class="small"><?= e(thai_date($x['last_login'], true)) ?></td>
          <td><?= $x['active'] ? '<span class="badge s-done">ใช้งาน</span>' : '<span class="badge s-cancelled">ปิด</span>' ?></td>
          <td class="actions"><a class="btn sm" href="?edit=<?= (int)$x['id'] ?>">แก้ไข</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <section class="card">
      <h2><?= $edit['id'] ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' ?></h2>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <label>ชื่อผู้ใช้<input name="username" value="<?= e($edit['username']) ?>" <?= $edit['id'] ? 'disabled' : 'required' ?> autocomplete="off"></label>
        <label>ชื่อ-สกุล<input name="full_name" required value="<?= e($edit['full_name']) ?>"></label>
        <label>บทบาท<select name="role" id="role">
          <?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $edit['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></label>
        <label>แผนก (สำหรับแพทย์/พยาบาล)<select name="department_id"><option value="">-</option>
          <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$edit['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label>ห้องตรวจเริ่มต้น<input name="room" value="<?= e($edit['room']) ?>" placeholder="เช่น ห้องตรวจ 1"></label>
        <label><?= $edit['id'] ? 'รหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)' : 'รหัสผ่าน' ?><input type="password" name="password" autocomplete="new-password" <?= $edit['id'] ? '' : 'required' ?>></label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> เปิดใช้งาน</label>
        <div class="row"><button class="btn primary">บันทึก</button><?php if ($edit['id']): ?><a class="btn" href="users.php">ยกเลิก</a><?php endif; ?></div>
      </form>
    </section>
  </div>
</main>
<style>@media (max-width: 900px) { #layout { grid-template-columns: 1fr !important; } }</style>
<?php page_footer();
