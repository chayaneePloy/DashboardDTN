<?php
include 'db.php';

$error = '';
$name  = '';
$year  = '';

// ถ้ามีการ submit ฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['item_name'] ?? '');
    $year = trim($_POST['fiscal_year'] ?? '');

    // ตรวจว่าใส่ครบ
    if ($name === '' || $year === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } else {
        // 1) ตรวจว่ามีข้อมูลซ้ำอยู่แล้วหรือไม่
        $check = $pdo->prepare("
            SELECT COUNT(*) 
            FROM budget_items 
            WHERE item_name = ? 
              AND fiscal_year = ?
        ");
        $check->execute([$name, $year]);
        $exists = $check->fetchColumn();

        if ($exists > 0) {
            // ถ้ามีแล้ว ให้แจ้งเตือน
            $error = "มีรายการงบ \"{$name}\" ของปี {$year} อยู่ในระบบแล้ว";
        } else {
            // 2) ถ้าไม่ซ้ำ -> บันทึกได้
            $stmt = $pdo->prepare("
                INSERT INTO budget_items (item_name, fiscal_year) 
                VALUES (?, ?)
            ");
            $stmt->execute([$name, $year]);

            // บันทึกเสร็จ -> ย้ายกลับหน้า index (หรือ dashboard ที่คุณใช้)
            header("Location: dashboard.php?year=" . urlencode($year));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Dashboard งบประมาณ</title>
<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logo3.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="styles.css">
<style>
body { font-family:'Sarabun',sans-serif; background:#f7f9fc; }
.navbar-nav .nav-link:hover { background-color: rgba(255,255,255,0.2); border-radius: 6px; }
</style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand fw-bold" href="index.php">
      📊 Dashboard การจ่ายงวด
    </a>

    <!-- Hamburger -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Menu -->
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

        <li class="nav-item">
          <a class="nav-link text-white" href="index.php">
            <i class="bi bi-house"></i> หน้าหลัก
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link text-white" href="dashboard.php">
            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>

      </ul>
    </div>

  </div>
</nav>

<div class="container my-5">
  <h2 class="mb-4">➕ เพิ่มรายการงบประมาณ</h2>

  <?php if ($error): ?>
    <div class="alert alert-danger">
      <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="card p-4 shadow-sm">
    
    <!-- ช่องเลือกชื่อรายการงบประมาณ -->
    <div class="mb-3">
      <label class="form-label">ชื่อรายการงบประมาณ</label>
      <select name="item_name" class="form-select" required>
        <option value="">-- เลือกรายการงบประมาณ --</option>
        <option value="งบการลงทุน"   <?= $name === 'งบการลงทุน'   ? 'selected' : '' ?>>งบการลงทุน</option>
        <option value="งบดำเนินงาน" <?= $name === 'งบดำเนินงาน' ? 'selected' : '' ?>>งบดำเนินงาน</option>
        <option value="งบรายจ่ายอื่น" <?= $name === 'งบรายจ่ายอื่น' ? 'selected' : '' ?>>งบรายจ่ายอื่น</option>
        <option value="งบบูรณาการ"  <?= $name === 'งบบูรณาการ'  ? 'selected' : '' ?>>งบบูรณาการ</option>
      </select>
    </div>

    <!-- ช่องกรอกปีงบประมาณ (พิมพ์เองได้) -->
    <div class="mb-3">
      <label class="form-label">ปีงบประมาณ</label>
      <input 
        type="number" 
        name="fiscal_year" 
        class="form-control" 
        placeholder="เช่น 2569" 
        value="<?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>"
        required
      >
    </div>

    <!-- ปุ่มบันทึก -->
    <div class="mt-3">
      <button type="submit" class="btn btn-success">💾 บันทึก</button>
      <a href="dashboard.php" class="btn btn-secondary">ย้อนกลับ</a>
    </div>

  </form>
</div>

</body>
</html>
