<?php
include 'db.php';

// -------------------- ดึงปีงบประมาณทั้งหมดจาก budget_act --------------------
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")
             ->fetchAll(PDO::FETCH_COLUMN);
if (!$years) {
    // ถ้าไม่มีปีในฐานข้อมูล ให้ใช้ปีปัจจุบัน (พ.ศ.)
    $years = [date('Y') + 543];
}

// -------------------- รับค่าปีงบประมาณ --------------------
$selectedYear = $_GET['year'] ?? '';

// -------------------- ตรวจสอบคำสั่งลบ --------------------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    try {
        // ใช้ Transaction เผื่อมี FK constraint
        $pdo->beginTransaction();

        // 1) ลบงบย่อยที่อ้างถึงงบหลักนี้ก่อน
        $stmt = $pdo->prepare("DELETE FROM budget_detail WHERE budget_item_id = ?");
        $stmt->execute([$id]);

        // 2) ลบงบหลัก
        $stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();

        // กลับมาหน้าเดิมพร้อม year เดิม
        header("Location: dashboard.php?year=" . urlencode($selectedYear));
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("ลบไม่สำเร็จ: " . htmlspecialchars($e->getMessage()));
    }
}

// -------------------- ดึงรายการงบประมาณ --------------------
if ($selectedYear) {
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE fiscal_year = ? ORDER BY id");
    $stmt->execute([$selectedYear]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $items = $pdo->query("SELECT * FROM budget_items ORDER BY fiscal_year, id")
                 ->fetchAll(PDO::FETCH_ASSOC);
}

// -------------------- เตรียมข้อมูลกราฟ --------------------
$labels = $requested = [];
foreach ($items as $item) {
    $labels[]    = $item['item_name'] . ' (' . $item['fiscal_year'] . ')';
    $requested[] = (float)$item['requested_amount'];
}

// -------------------- สรุปยอดตามประเภทงบ 4 กล่อง --------------------
$categories = [
    'งบลงทุน',
    'งบดำเนินงาน',
    'งบรายจ่ายอื่น',
    'งบบูรณาการ',
];

$summary = [];
foreach ($categories as $cat) {
    if ($selectedYear) {
        $sumStmt = $pdo->prepare("
            SELECT SUM(bd.requested_amount)
            FROM budget_detail bd
            JOIN budget_items bi ON bd.budget_item_id = bi.id
            WHERE bi.item_name = ? AND bi.fiscal_year = ?
        ");
        $sumStmt->execute([$cat, $selectedYear]);
    } else {
        $sumStmt = $pdo->prepare("
            SELECT SUM(bd.requested_amount)
            FROM budget_detail bd
            JOIN budget_items bi ON bd.budget_item_id = bi.id
            WHERE bi.item_name = ?
        ");
        $sumStmt->execute([$cat]);
    }
    $summary[$cat] = (float)$sumStmt->fetchColumn();
}
// -------------------- ดึงปีงบประมาณทั้งหมดจาก budget_act --------------------
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")
             ->fetchAll(PDO::FETCH_COLUMN);
if (!$years) {
    // ถ้าไม่มีปีในฐานข้อมูล ให้ใช้ปีปัจจุบัน (พ.ศ.)
    $years = [date('Y') + 543];
}

// ปีต่ำสุด–สูงสุด (ใช้ตอนแสดงข้อความกรณีเลือกทุกปี)
$minYear = min($years);
$maxYear = max($years);

// -------------------- รับค่าปีงบประมาณ --------------------
$selectedYear = $_GET['year'] ?? '';
// -------------------- นับจำนวนโครงการ (budget_detail) --------------------
// -------------------- นับจำนวนโครงการ (budget_detail) --------------------
if ($selectedYear) {
    // นับเฉพาะโครงการในปีที่เลือก
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ?
    ");
    $countStmt->execute([$selectedYear]);
} else {
    // นับโครงการทั้งหมดทุกปี
    $countStmt = $pdo->query("
        SELECT COUNT(*)
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
    ");
}

$totalProjects = (int)$countStmt->fetchColumn();





?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Dashboard </title>
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
  body {
    font-family: 'Sarabun', sans-serif;
    background:#f7f9fc;
  }
  .container {
    max-width: 950px;
  }
  .navbar {
    margin-bottom: 20px;
  }
  .navbar-dark .navbar-nav .nav-link:hover {
    color: #ffeb3b !important;        /* เวลา hover เป็นเหลือง */
  }
  .navbar-brand {
    color: #ffffff !important;
  }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand fw-bold" href="index.php?year=<?= $selectedYear ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>"">
      📊 Dashboard 
    </a>

    <!-- Hamburger -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Menu -->
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

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>เพิ่มปีงบประมาณ/โครงการ</h2>
  </div>
 <!-- ปุ่มเพิ่มงบ -->
  <div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="add_budget_item.php" class="btn btn-success">
      + เพิ่มงบประมาณ
    </a>
  </div>
  <!-- Filter ปีงบประมาณ -->
  <form method="get" class="row g-2 mb-4">
    <div class="col-auto">
      <select name="year" class="form-select" onchange="this.form.submit()">
        <option value="">-- แสดงทุกปีงบประมาณ --</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= htmlspecialchars($y) ?>" <?= $selectedYear==$y?'selected':'' ?>>
            <?= htmlspecialchars($y) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if($selectedYear): ?>
    <div class="col-auto">
      <a href="dashboard.php" class="btn btn-secondary">รีเซ็ต</a>
    </div>
    <?php endif; ?>
  </form>
    <!-- แสดงช่วงปีที่กำลังดู -->




  <div class="mb-3">
    <?php if ($selectedYear): ?>
      <span class="text-muted">
        แสดงข้อมูลปีงบประมาณ <?= htmlspecialchars($selectedYear) ?>
      </span>
    <?php else: ?>
      <span class="text-muted">
        แสดงข้อมูลปีงบประมาณ <?= htmlspecialchars($minYear) ?> ถึง <?= htmlspecialchars($maxYear) ?>
      </span>
    <?php endif; ?>
    : <?= number_format($totalProjects) ?> โครงการ
  </div>


 

  <!-- ✅ 4 กล่องสรุปตามประเภทงบ -->
  <div class="row g-3 mb-4">
    <!-- งบการลงทุน -->
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card shadow-sm h-100 border-start border-4 border-success">
        <div class="card-body">
          <h6 class="mb-1">งบลงทุน</h6>
          <small class="text-muted">
            <?= $selectedYear ? 'ปีงบประมาณ '.htmlspecialchars($selectedYear) : 'ทั้งปีงบประมาณ' ?>
          </small>
          <div class="fs-5 fw-bold text-success mt-2">
            <?= number_format($summary['งบลงทุน'], 2) ?> บาท
          </div>
        </div>
      </div>
    </div>

    <!-- งบดำเนินงาน -->
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card shadow-sm h-100 border-start border-4 border-success">
        <div class="card-body">
          <h6 class="mb-1">งบดำเนินงาน</h6>
          <small class="text-muted">
            <?= $selectedYear ? 'ปีงบประมาณ '.htmlspecialchars($selectedYear) : 'ทั้งปีงบประมาณ' ?>
          </small>
          <div class="fs-5 fw-bold text-success mt-2">
            <?= number_format($summary['งบดำเนินงาน'], 2) ?> บาท
          </div>
        </div>
      </div>
    </div>

    <!-- งบรายจ่ายอื่น -->
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card shadow-sm h-100 border-start border-4 border-success">
        <div class="card-body">
          <h6 class="mb-1">งบรายจ่ายอื่น</h6>
          <small class="text-muted">
            <?= $selectedYear ? 'ปีงบประมาณ '.htmlspecialchars($selectedYear) : 'ทั้งปีงบประมาณ' ?>
          </small>
          <div class="fs-5 fw-bold text-success mt-2">
            <?= number_format($summary['งบรายจ่ายอื่น'], 2) ?> บาท
          </div>
        </div>
      </div>
    </div>

    <!-- งบบูรณาการ -->
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card shadow-sm h-100 border-start border-4 border-success">
        <div class="card-body">
          <h6 class="mb-1">งบบูรณาการ</h6>
          <small class="text-muted">
            <?= $selectedYear ? 'ปีงบประมาณ '.htmlspecialchars($selectedYear) : 'ทั้งปีงบประมาณ' ?>
          </small>
          <div class="fs-5 fw-bold text-success mt-2">
            <?= number_format($summary['งบบูรณาการ'], 2) ?> บาท
          </div>
        </div>
      </div>
    </div>
  </div>
<p class="text-danger mt-4">*กด เพิ่ม/แก้ไข เพื่อใส่ข้อมูลเลขานุการ</p>
  <!-- ตารางแสดงรายการ -->
  <div class="row row-cols-1 row-cols-md-2 g-3">
    <?php foreach ($items as $item): ?>
    <div class="col">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
          <strong><?= htmlspecialchars($item['item_name']) ?> (<?= htmlspecialchars($item['fiscal_year']) ?>)</strong>
          <div class="btn-group">
            <!-- ปุ่มไปหน้าจัดการงบย่อย -->
            <a href="edit_budget_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-warning">
              เพิ่ม/แก้ไข
            </a>

            <!-- ปุ่มลบงบหลัก -->
            <a
              href="dashboard.php?delete=<?= $item['id'] ?>&year=<?= urlencode($selectedYear) ?>"
              class="btn btn-sm btn-danger"
              onclick="return confirm('ต้องการลบงบ &quot;<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>&quot; ทั้งชุดหรือไม่?');"
            >
              ลบ
            </a>
          </div>
        </div>
        <div class="card-body">
          <?php
            // ดึงงบย่อยทั้งหมดของงบหลักนี้
            $detailsStmt = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
            $detailsStmt->execute([$item['id']]);
            $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

            // คำนวณยอดรวม requested_amount
            $sumRequestedItem = 0;
            foreach ($details as $d) {
                $sumRequestedItem += (float)$d['requested_amount'];
            }
          ?>
          <p>งบที่จ้าง (รวมทั้งหมด): <?= number_format($sumRequestedItem, 2) ?> บาท</p>

          <ul class="list-group list-group-flush">
            <?php foreach ($details as $d): ?>
              <li class="list-group-item">
                <?= htmlspecialchars($d['detail_name']) ?> —
                <?= number_format($d['requested_amount'], 2) ?> บาท
              </li>
            <?php endforeach; ?>

            <?php if (!$details): ?>
              <li class="list-group-item text-muted">ยังไม่มีงบย่อย</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if(!$items): ?>
      <p class="text-center text-muted mt-4">ยังไม่มีข้อมูลงบประมาณในปีนี้</p>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
