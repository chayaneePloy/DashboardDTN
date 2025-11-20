<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------- ปีงบประมาณจาก Dashboard (ถ้ามี) ----------------
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : (date('Y') + 543);

// ---------------- เพิ่มข้อมูล ----------------
if (isset($_POST['add'])) {
    $amount = $_POST['budget_act_amount'] ?? 0;
    $year   = $_POST['fiscal_year'] ?? $selectedYear;

    $stmt = $pdo->prepare("INSERT INTO budget_act (budget_act_amount, fiscal_year) VALUES (?, ?)");
    $stmt->execute([$amount, $year]);

    header("Location: add_budget_act.php?year=$year&msg=added");
    exit;
}

// ---------------- แก้ไขข้อมูล ----------------
if (isset($_POST['update'])) {
    $id     = $_POST['id'];
    $amount = $_POST['budget_act_amount'];
    $year   = $_POST['fiscal_year'];

    $stmt = $pdo->prepare("UPDATE budget_act SET budget_act_amount=?, fiscal_year=? WHERE id=?");
    $stmt->execute([$amount, $year, $id]);

    header("Location: add_budget_act.php?year=$year&msg=updated");
    exit;
}

// ---------------- ลบข้อมูล ----------------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM budget_act WHERE id=?")->execute([$id]);
    header("Location: add_budget_act.php?year=$selectedYear&msg=deleted");
    exit;
}

// ---------------- ดึงข้อมูลงบตามปีงบประมาณ ----------------
$stmt = $pdo->prepare("SELECT * FROM budget_act WHERE fiscal_year = ? ORDER BY id DESC");
$stmt->execute([$selectedYear]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------- ดึงปีทั้งหมดไว้ใน dropdown ----------------
$allYears = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่ม/แก้ไข งบประมาณตาม พ.ร.บ.</title>
<!-- Favicon (โลโก้เล็กบนแท็บเว็บ) -->
    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logo3.png">
    
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body { font-family: 'Sarabun', sans-serif; background:#f7f9fc; }
.container { max-width: 950px; }
.navbar { margin-bottom: 20px; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php?year=<?= $selectedYear ?>">← กลับ Dashboard</a>
    <div class="d-flex text-white">
      ปีงบประมาณที่เลือก: <span class="fw-bold ms-2"><?= htmlspecialchars($selectedYear) ?></span>
    </div>
  </div>
</nav>

<div class="container">
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
      <h4 class="mb-0">งบประมาณตาม พ.ร.บ.</h4>
    </div>
    <div class="card-body">

      <!-- ฟอร์มเพิ่มข้อมูล -->
      <form method="POST" class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label">ปีงบประมาณ (พ.ศ.)</label>
          <input type="number" name="fiscal_year" class="form-control" value="<?= htmlspecialchars($selectedYear) ?>" required>
        </div>
        <div class="col-md-5">
          <label class="form-label">จำนวนงบประมาณ (บาท)</label>
          <input type="number" name="budget_act_amount" class="form-control" required>
        </div>
        <div class="col-md-3 align-self-end">
          <button type="submit" name="add" class="btn btn-success w-100">+ เพิ่มงบใหม่</button>
        </div>
      </form>

      <!-- ฟิลเตอร์ปี -->
      <form method="GET" class="mb-3">
        <div class="d-flex align-items-center gap-2">
          <label for="year" class="fw-semibold">ดูข้อมูลงบประมาณของปี:</label>
          <select name="year" id="year" class="form-select w-auto" onchange="this.form.submit()">
            <?php foreach ($allYears as $y): ?>
              <option value="<?= $y ?>" <?= ($y == $selectedYear) ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <!-- ตารางข้อมูล -->
      <table class="table table-bordered table-striped text-center align-middle">
        <thead class="table-dark">
          <tr>
            <th>ลำดับ</th>
            <th>ปีงบประมาณ</th>
            <th>จำนวนงบประมาณ (บาท)</th>
            <th>การจัดการ</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows): ?>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['id']) ?></td>
              <td><?= htmlspecialchars($r['fiscal_year']) ?></td>
              <td><?= number_format($r['budget_act_amount'], 2) ?></td>
              <td>
                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?= $r['id'] ?>">แก้ไข</button>
                <a href="?delete=<?= $r['id'] ?>&year=<?= $selectedYear ?>" class="btn btn-danger btn-sm" onclick="return confirm('ยืนยันการลบข้อมูลนี้?')">ลบ</a>
              </td>
            </tr>

            <!-- Modal แก้ไข -->
            <div class="modal fade" id="editModal<?= $r['id'] ?>" tabindex="-1">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header bg-warning">
                    <h5 class="modal-title">แก้ไขงบประมาณ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <form method="POST">
                    <div class="modal-body">
                      <input type="hidden" name="id" value="<?= $r['id'] ?>">
                      <div class="mb-3">
                        <label class="form-label">ปีงบประมาณ</label>
                        <input type="number" name="fiscal_year" class="form-control" value="<?= $r['fiscal_year'] ?>" required>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">จำนวนงบประมาณ (บาท)</label>
                        <input type="number" name="budget_act_amount" class="form-control" value="<?= $r['budget_act_amount'] ?>" required>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                      <button type="submit" name="update" class="btn btn-warning">บันทึกการแก้ไข</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4" class="text-muted">ยังไม่มีข้อมูลงบประมาณสำหรับปีนี้</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
