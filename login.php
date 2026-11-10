<?php
require __DIR__ . '/inc/bootstrap.php';

if ($u = current_user()) redirect(home_for($u['role']));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['login_fail'] ??= [];
    $_SESSION['login_fail'] = array_filter($_SESSION['login_fail'], fn($t) => $t > time() - 300);
    if (count($_SESSION['login_fail']) >= 5) {
        $error = 'เข้าสู่ระบบผิดหลายครั้ง กรุณารอ 5 นาที';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $st->execute([trim($_POST['username'] ?? '')]);
        $u = $st->fetch();
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['login_fail']);
            $_SESSION['user'] = [
                'id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'],
                'role' => $u['role'], 'department_id' => $u['department_id'] ? (int)$u['department_id'] : null, 'room' => $u['room'],
            ];
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
            $next = (string)($_GET['next'] ?? '');
            if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                header('Location: ' . $next);
                exit;
            }
            redirect(home_for($u['role']));
        }
        $_SESSION['login_fail'][] = time();
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}

page_header('เข้าสู่ระบบเจ้าหน้าที่');
?>
<main class="container narrow">
  <div class="card">
    <h1>เข้าสู่ระบบเจ้าหน้าที่</h1>
    <?php if ($error): ?><p class="alert err"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>ชื่อผู้ใช้<input name="username" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></label>
      <label>รหัสผ่าน<input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn primary">เข้าสู่ระบบ</button>
    </form>
    <p class="small muted">ผู้ป่วยเข้าสู่ระบบด้วย HN ได้ที่ <a href="<?= e(base_url('patient/login.php')) ?>">หน้าผู้ป่วย</a></p>
  </div>
</main>
<?php page_footer();
