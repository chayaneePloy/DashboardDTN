<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['item_name'];
    $year = $_POST['fiscal_year'];

    $stmt = $pdo->prepare("INSERT INTO budget_items (item_name, fiscal_year) VALUES (?, ?)");
    $stmt->execute([$name, $year]);

    header("Location: index.php?year=$year");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่มรายการงบประมาณ</title>
<!-- Favicon (โลโก้เล็กบนแท็บเว็บ) -->
    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body { font-family:'Sarabun',sans-serif; background:#f7f9fc; }
.navbar-nav .nav-link:hover { background-color: rgba(255,255,255,0.2); border-radius: 6px; }
</style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="dashboard.php">← กลับ Dashboard</a>
  </div>
</nav>

<div class="container my-5">
  <h2 class="mb-4">➕ เพิ่มรายการงบประมาณ</h2>
  <form method="POST" class="card p-4 shadow-sm">
    
    <!-- ช่องเลือกชื่อรายการงบประมาณ -->
    <div class="mb-3">
      <label class="form-label">ชื่อรายการงบประมาณ</label>
      <select name="item_name" class="form-select" required>
        <option value="">-- เลือกรายการงบประมาณ --</option>
        <option value="งบการลงทุน">งบการลงทุน</option>
        <option value="งบดำเนินงาน">งบดำเนินงาน</option>
        <option value="งบรายจ่ายอื่น">งบรายจ่ายอื่น</option>
        <option value="งบบูรณาการ">งบบูรณาการ</option>
      </select>
    </div>

    <!-- ช่องกรอกปีงบประมาณ (พิมพ์เองได้) -->
    <div class="mb-3">
      <label class="form-label">ปีงบประมาณ</label>
      <input type="number" name="fiscal_year" class="form-control" placeholder="เช่น 2569" required>
    </div>

    <!-- ปุ่มบันทึก -->
    <div class="mt-3">
      <button type="submit" class="btn btn-success">💾 บันทึก</button>
      <a href="index.php" class="btn btn-secondary">ย้อนกลับ</a>
    </div>

  </form>
</div>

</body>
</html>
