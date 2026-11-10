<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_patient()) redirect('patient/index.php');
$verify = setting('patient_verify', 'birthdate') === 'birthdate';
$error = null;
$hnIn = trim((string)($_POST['hn'] ?? $_GET['hn'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['p_fail'] = array_filter($_SESSION['p_fail'] ?? [], fn($t) => $t > time() - 300);
    if (count($_SESSION['p_fail']) >= 5) {
        $error = 'กรอกข้อมูลผิดหลายครั้ง กรุณารอ 5 นาทีแล้วลองใหม่';
    } else {
        try {
            $p = His::findPatient($hnIn);
        } catch (Throwable $ex) {
            $p = null;
            $error = 'ไม่สามารถเชื่อมต่อฐานข้อมูลโรงพยาบาลได้ กรุณาติดต่อเคาน์เตอร์';
        }
        $ok = (bool)$p;
        if ($ok && $verify) {
            $d = (int)($_POST['bd_d'] ?? 0);
            $m = (int)($_POST['bd_m'] ?? 0);
            $y = (int)($_POST['bd_y'] ?? 0) - 543;
            $ok = checkdate($m, $d, $y) && sprintf('%04d-%02d-%02d', $y, $m, $d) === substr((string)$p['birthdate'], 0, 10);
        }
        if ($ok) {
            session_regenerate_id(true);
            unset($_SESSION['p_fail']);
            $_SESSION['patient'] = ['hn' => $p['hn'], 'name' => $p['fullname'], 'fname' => $p['fname'], 'lname' => $p['lname']];
            redirect('patient/index.php');
        }
        if (!$error) {
            $_SESSION['p_fail'][] = time();
            $error = $verify ? 'HN หรือวันเกิดไม่ถูกต้อง' : 'ไม่พบ HN นี้ในระบบของโรงพยาบาล';
        }
    }
}

page_header('ผู้ป่วยเข้าสู่ระบบ');
$thisYear = (int)date('Y') + 543;
?>
<main class="container narrow">
  <div class="card">
    <h1>ผู้ป่วยเข้าสู่ระบบ</h1>
    <p class="muted">ใช้เลข HN (เลขประจำตัวผู้ป่วย) ที่อยู่บนบัตรโรงพยาบาล</p>
    <?php if ($error): ?><p class="alert err"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>HN<input name="hn" inputmode="numeric" autocomplete="off" required autofocus value="<?= e($hnIn) ?>" placeholder="เช่น 000012345"></label>
      <?php if ($verify): ?>
      <fieldset style="border:0;padding:0;margin:0">
        <legend style="font-weight:500;margin-bottom:6px">วันเกิด (เพื่อยืนยันตัวตน)</legend>
        <div class="row" style="flex-wrap:nowrap">
          <select name="bd_d" required aria-label="วัน"><option value="">วัน</option>
            <?php for ($i = 1; $i <= 31; $i++): ?><option <?= (int)($_POST['bd_d'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
          </select>
          <select name="bd_m" required aria-label="เดือน"><option value="">เดือน</option>
            <?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>" <?= (int)($_POST['bd_m'] ?? 0) === $i ? 'selected' : '' ?>><?= TH_MONTHS[$i] ?></option><?php endfor; ?>
          </select>
          <select name="bd_y" required aria-label="ปี พ.ศ."><option value="">ปี พ.ศ.</option>
            <?php for ($i = $thisYear; $i >= $thisYear - 110; $i--): ?><option <?= (int)($_POST['bd_y'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
          </select>
        </div>
      </fieldset>
      <?php endif; ?>
      <button class="btn primary lg">เข้าสู่ระบบ</button>
    </form>
    <p class="small muted">ยังไม่มี HN? กรุณาติดต่อเคาน์เตอร์ต้อนรับเพื่อทำบัตรผู้ป่วยใหม่</p>
  </div>
</main>
<?php page_footer();
