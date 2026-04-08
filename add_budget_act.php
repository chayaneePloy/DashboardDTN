<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
include 'db.php';

// ปีเริ่มต้นสำหรับช่องกรอก
$selectedYear = isset($_GET['year']) 
    ? (int)$_GET['year'] 
    : (date('Y') + 543);

// ตัวแปรเก็บข้อความแจ้งเตือน/สถานะ
$error = '';
$msg   = $_GET['msg'] ?? '';

// ---------------- เพิ่มข้อมูล ----------------
if (isset($_POST['add'])) {
    $amount = (float)($_POST['budget_act_amount'] ?? 0);
    $year   = (int)($_POST['fiscal_year'] ?? (date('Y') + 543));

    $q1 = (float)($_POST['q1_percent'] ?? 0);
    $q2 = (float)($_POST['q2_percent'] ?? 0);
    $q3 = (float)($_POST['q3_percent'] ?? 0);
    $q4 = (float)($_POST['q4_percent'] ?? 0);

    // เช็คว่ามีปีนี้อยู่แล้วหรือยัง
    $chk = $pdo->prepare("SELECT COUNT(*) FROM budget_act WHERE fiscal_year = ?");
    $chk->execute([$year]);
    $exists = $chk->fetchColumn();

    if ($exists > 0) {
        $error = "มีข้อมูลงบประมาณปี {$year} อยู่แล้ว";
        $selectedYear = $year;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO budget_act (
                budget_act_amount, fiscal_year,
                q1_percent, q2_percent, q3_percent, q4_percent
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$amount, $year, $q1, $q2, $q3, $q4]);

        header("Location: add_budget_act.php?msg=added");
        exit;
    }
}

// ---------------- แก้ไขข้อมูล ----------------
if (isset($_POST['update'])) {
    $id     = (int)$_POST['id'];
    $amount = (float)$_POST['budget_act_amount'];
    $year   = (int)$_POST['fiscal_year'];

    $q1 = (float)($_POST['q1_percent'] ?? 0);
    $q2 = (float)($_POST['q2_percent'] ?? 0);
    $q3 = (float)($_POST['q3_percent'] ?? 0);
    $q4 = (float)($_POST['q4_percent'] ?? 0);

    // ป้องกันปีซ้ำตอนแก้ไข
    $chk = $pdo->prepare("SELECT COUNT(*) FROM budget_act WHERE fiscal_year = ? AND id <> ?");
    $chk->execute([$year, $id]);
    $exists = $chk->fetchColumn();

    if ($exists > 0) {
        $error = "ไม่สามารถแก้ไขได้ เนื่องจากปี {$year} มีอยู่แล้ว";
    } else {
        $stmt = $pdo->prepare("
            UPDATE budget_act 
            SET budget_act_amount=?, fiscal_year=?, 
                q1_percent=?, q2_percent=?, q3_percent=?, q4_percent=?
            WHERE id=?
        ");
        $stmt->execute([$amount, $year, $q1, $q2, $q3, $q4, $id]);

        header("Location: add_budget_act.php?msg=updated");
        exit;
    }
}

// ---------------- ลบข้อมูล ----------------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM budget_act WHERE id=?")->execute([$id]);
    header("Location: add_budget_act.php?msg=deleted");
    exit;
}

// ---------------- ดึงข้อมูลทั้งหมด ----------------
$stmt = $pdo->query("SELECT * FROM budget_act ORDER BY fiscal_year DESC, id DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่ม/แก้ไข งบประมาณตาม พ.ร.บ.</title>

<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logo3.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
body { font-family: 'Sarabun', sans-serif; background:#f7f9fc; }
.container { max-width: 1200px; }
.navbar { margin-bottom: 20px; }
.navbar-dark .navbar-nav .nav-link:hover { color: #ffeb3b !important; }
.navbar-brand { color: #ffffff !important; }
.card { border-radius: 14px; }
.table th, .table td { vertical-align: middle; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php?year=<?= $selectedYear ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
      📊 Dashboard งบประมาณโครงการ
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link text-white" href="index.php?year=<?= $selectedYear ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
            <i class="bi bi-house"></i> หน้าหลัก
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link text-white" href="index.php?year=<?= $selectedYear ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="container">
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
      <h4 class="mb-0">งบประมาณตาม พ.ร.บ. + เป้าหมายไตรมาส</h4>
    </div>
    <div class="card-body">

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($msg === 'added'): ?>
        <div class="alert alert-success">บันทึกงบประมาณใหม่เรียบร้อยแล้ว</div>
      <?php elseif ($msg === 'updated'): ?>
        <div class="alert alert-success">แก้ไขงบประมาณเรียบร้อยแล้ว</div>
      <?php elseif ($msg === 'deleted'): ?>
        <div class="alert alert-success">ลบข้อมูลงบประมาณเรียบร้อยแล้ว</div>
      <?php endif; ?>

      <!-- ฟอร์มเพิ่มข้อมูล -->
      <form method="POST" class="row g-3 mb-4">
        <div class="col-md-3">
          <label class="form-label">ปีงบประมาณ (พ.ศ.)</label>
          <input type="number" name="fiscal_year" class="form-control"
                 value="<?= htmlspecialchars($selectedYear) ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">งบประมาณตาม พ.ร.บ. (บาท)</label>
          <input type="number" step="0.01" name="budget_act_amount" class="form-control" required>
        </div>

        <div class="col-md-1">
          <label class="form-label">Q1 (%)</label>
          <input type="number" step="0.01" name="q1_percent" class="form-control" value="25">
        </div>

        <div class="col-md-1">
          <label class="form-label">Q2 (%)</label>
          <input type="number" step="0.01" name="q2_percent" class="form-control" value="50">
        </div>

        <div class="col-md-1">
          <label class="form-label">Q3 (%)</label>
          <input type="number" step="0.01" name="q3_percent" class="form-control" value="70">
        </div>

        <div class="col-md-1">
          <label class="form-label">Q4 (%)</label>
          <input type="number" step="0.01" name="q4_percent" class="form-control" value="80">
        </div>

        <div class="col-md-2 align-self-end">
          <button type="submit" name="add" class="btn btn-success w-100">+ เพิ่มงบใหม่</button>
        </div>
      </form>

      <div class="text-danger mb-2 text-end">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>หมายเหตุ :</strong>
        หากต้องการลบปีงบประมาณ ต้องลบโครงการในวงเงินสัญญาที่เกี่ยวข้องก่อน
      </div>

      <!-- ตารางข้อมูล -->
      <div class="table-responsive">
        <table class="table table-bordered table-striped text-center align-middle">
          <thead class="table-dark">
            <tr>
              <th>ปีงบประมาณ</th>
              <th>งบประมาณตาม พ.ร.บ. (บาท)</th>
              <th>Q1 (%)</th>
              <th>Q2 (%)</th>
              <th>Q3 (%)</th>
              <th>Q4 (%)</th>
              <th>การจัดการ</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): ?>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['fiscal_year']) ?></td>
                <td><?= number_format($r['budget_act_amount'], 2) ?></td>
                <td><?= number_format($r['q1_percent'], 2) ?></td>
                <td><?= number_format($r['q2_percent'], 2) ?></td>
                <td><?= number_format($r['q3_percent'], 2) ?></td>
                <td><?= number_format($r['q4_percent'], 2) ?></td>
                <td>
                  <button class="btn btn-warning btn-sm"
                          data-bs-toggle="modal"
                          data-bs-target="#editModal<?= $r['id'] ?>">แก้ไข</button>
                  <a href="?delete=<?= $r['id'] ?>"
                     class="btn btn-danger btn-sm"
                     onclick="return confirm('ยืนยันการลบข้อมูลนี้?')">ลบ</a>
                </td>
              </tr>

              <!-- Modal แก้ไข -->
              <div class="modal fade" id="editModal<?= $r['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                    <div class="modal-header bg-warning">
                      <h5 class="modal-title">แก้ไขงบประมาณ</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                      <div class="modal-body">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <div class="row g-3">
                          <div class="col-md-4">
                            <label class="form-label">ปีงบประมาณ</label>
                            <input type="number" name="fiscal_year" class="form-control"
                                   value="<?= $r['fiscal_year'] ?>" required>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label">งบประมาณตาม พ.ร.บ. (บาท)</label>
                            <input type="number" step="0.01" name="budget_act_amount" class="form-control"
                                   value="<?= $r['budget_act_amount'] ?>" required>
                          </div>
                          <div class="col-md-2">
                            <label class="form-label">Q1</label>
                            <input type="number" step="0.01" name="q1_percent" class="form-control"
                                   value="<?= $r['q1_percent'] ?>">
                          </div>
                          <div class="col-md-2">
                            <label class="form-label">Q2</label>
                            <input type="number" step="0.01" name="q2_percent" class="form-control"
                                   value="<?= $r['q2_percent'] ?>">
                          </div>
                          <div class="col-md-2">
                            <label class="form-label">Q3</label>
                            <input type="number" step="0.01" name="q3_percent" class="form-control"
                                   value="<?= $r['q3_percent'] ?>">
                          </div>
                          <div class="col-md-2">
                            <label class="form-label">Q4</label>
                            <input type="number" step="0.01" name="q4_percent" class="form-control"
                                   value="<?= $r['q4_percent'] ?>">
                          </div>
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
            <tr><td colspan="7" class="text-muted">ยังไม่มีข้อมูลงบประมาณ</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>