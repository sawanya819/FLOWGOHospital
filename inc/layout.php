<?php
/** ส่วนหัว/ท้ายของหน้าเว็บ */
function page_header(string $title, array $opt = []): void
{
    $u = current_user();
    $p = current_patient();
    $sys = setting('system_name', 'ระบบคิวโรงพยาบาล');
    $hosp = setting('hospital_name', '');
    $bare = $opt['bare'] ?? false;
    $bodyClass = $opt['body'] ?? 'page';
    ?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrf_token() ?>">
<meta name="base" content="<?= e(base_url()) ?>">
<title><?= e($title) ?> · <?= e($sys) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=1">
<script src="<?= e(base_url('assets/app.js')) ?>?v=2" defer></script>
</head>
<body class="<?= e($bodyClass) ?>">
<?php if (!$bare): ?>
<header class="topbar">
  <a class="brand" href="<?= e(base_url($u ? home_for($u['role']) : ($p ? 'patient/index.php' : 'index.php'))) ?>">
    <span class="logo" aria-hidden="true">+</span>
    <span><b><?= e($sys) ?></b><small><?= e($hosp) ?></small></span>
  </a>
  <nav class="nav">
    <?php if ($u): ?>
      <?php if (in_array($u['role'], ['staff', 'admin'], true)): ?><a href="<?= e(base_url('counter.php')) ?>">เคาน์เตอร์</a><?php endif; ?>
      <?php if (in_array($u['role'], ['doctor', 'admin'], true)): ?><a href="<?= e(base_url('room.php')) ?>">ห้องตรวจ</a><?php endif; ?>
      <?php if ($u['role'] === 'admin'): ?>
        <a href="<?= e(base_url('admin/index.php')) ?>">รายงาน</a>
        <a href="<?= e(base_url('admin/departments.php')) ?>">แผนก</a>
        <a href="<?= e(base_url('admin/users.php')) ?>">ผู้ใช้</a>
        <a href="<?= e(base_url('admin/settings.php')) ?>">ตั้งค่า</a>
      <?php endif; ?>
      <a href="<?= e(base_url('display.php')) ?>" target="_blank">จอแสดงคิว</a>
      <span class="who"><?= e($u['full_name']) ?> · <?= e(ROLES[$u['role']]) ?></span>
      <a class="btn sm" href="<?= e(base_url('logout.php')) ?>">ออกจากระบบ</a>
    <?php elseif ($p): ?>
      <a href="<?= e(base_url('patient/index.php')) ?>">คิวของฉัน</a>
      <a href="<?= e(base_url('patient/history.php')) ?>">ประวัติการรักษา</a>
      <span class="who">HN <?= e($p['hn']) ?></span>
      <a class="btn sm" href="<?= e(base_url('patient/logout.php')) ?>">ออกจากระบบ</a>
    <?php endif; ?>
  </nav>
</header>
<?php endif; ?>
<?php if ($f = flash()): ?><div class="toast <?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div><?php endif; ?>
<?php
}

function page_footer(): void
{
    echo "\n</body>\n</html>";
}

function home_for(string $role): string
{
    return match ($role) {
        'admin' => 'admin/index.php',
        'doctor' => 'room.php',
        default => 'counter.php',
    };
}

function status_badge(string $status): string
{
    return '<span class="badge s-' . e($status) . '">' . e(Queue::STATUS_LABEL[$status] ?? $status) . '</span>';
}
