<?php
include 'db.php';

// -------------------- ดึงปีงบประมาณทั้งหมดจาก budget_act --------------------
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) { $years = [date('Y') + 543]; }

// -------------------- รับค่าปีงบประมาณ --------------------
$selectedYear = $_GET['year'] ?? '';

// -------------------- ตรวจสอบคำสั่งลบ --------------------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: index.php?year=" . urlencode($selectedYear));
    exit;
}

// -------------------- ดึงรายการงบประมาณ --------------------
if ($selectedYear) {
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE fiscal_year = ? ORDER BY id");
    $stmt->execute([$selectedYear]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $items = $pdo->query("SELECT * FROM budget_items ORDER BY fiscal_year, id")->fetchAll(PDO::FETCH_ASSOC);
}

// -------------------- เตรียมข้อมูลกราฟ --------------------
$labels = $requested = [];
foreach ($items as $item) {
    $labels[] = $item['item_name'] . ' (' . $item['fiscal_year'] . ')';
    $requested[] = (float)$item['requested_amount'];
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
body { font-family: 'Sarabun', sans-serif; background:#f7f9fc; }
.container { max-width: 950px; }
.navbar { margin-bottom: 20px; }

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
          <a class="nav-link text-white" href="javascript:history.back()">
            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>

      </ul>
    </div>

  </div>
</nav>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>งบประมาณ</h2>
  </div>

  <!-- Filter ปีงบประมาณ -->
  <form method="get" class="row g-2 mb-4">
    <div class="col-auto">
      <select name="year" class="form-select" onchange="this.form.submit()">
        <option value="">-- แสดงทุกปีงบประมาณ --</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $selectedYear==$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if($selectedYear): ?>
    <div class="col-auto">
      <a href="dashboard.php" class="btn btn-secondary">รีเซ็ต</a>
    </div>
    <?php endif; ?>
  </form>

 

  <!-- ตารางแสดงรายการ -->
  <div class="row row-cols-1 row-cols-md-2 g-3">
    <?php foreach ($items as $item): ?>
    <div class="col">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
          <strong><?= htmlspecialchars($item['item_name']) ?> (<?= $item['fiscal_year'] ?>)</strong>
          <div class="btn-group">
            <a href="edit_budget_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-warning">เพิ่ม/แก้ไข/ลบ</a>
          </div>
        </div>
        <div class="card-body">
          <?php
    // ดึงงบย่อยทั้งหมด
    $details = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
    $details->execute([$item['id']]);
    $details = $details->fetchAll(PDO::FETCH_ASSOC);

    // คำนวณยอดรวม requested_amount
    $sumRequested = 0;
    foreach ($details as $d) {
        $sumRequested += (float)$d['requested_amount'];
    }
        ?>
        <p>งบที่จ้าง (รวมทั้งหมด): <?= number_format($sumRequested, 2) ?> บาท</p>

          <?php
            $details = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
            $details->execute([$item['id']]);
            $details = $details->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($details as $d): ?>
              <li class="list-group-item">
                <?= htmlspecialchars($d['detail_name']) ?> — <?= number_format($d['requested_amount'], 2) ?> บาท
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

  <div class="container">
  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <canvas id="budgetChart" height="100"></canvas>
    </div>
  </div>
  </div>

<!-- Chart Script -->
<script>
const ctx = document.getElementById('budgetChart');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      { label: 'งบประมาณที่ขอ (บาท)', data: <?= json_encode($requested) ?>, backgroundColor: 'rgba(54,162,235,0.6)' }
    ]
  },
  options: { responsive:true, scales:{ y:{ beginAtZero:true } } }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
