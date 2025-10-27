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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Sarabun',sans-serif;background:#f7f9fc;}
.navbar { transition: all 0.3s ease-in-out; }
.navbar-nav .nav-link:hover { background-color: rgba(255,255,255,0.15); border-radius: 6px; }
.btn-success { transition: all 0.3s ease; box-shadow: 0 3px 6px rgba(0,0,0,0.2); }
.btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.25); }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php">← กลับ Dashboard</a>
    <div class="d-flex">
      <a href="add_budget_item.php" class="btn btn-success fs-5 text-white px-3">➕ เพิ่มรายการงบประมาณ</a>
    </div>
  </div>
</nav>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>📊 Dashboard - งบประมาณ</h2>
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

  <!-- Chart -->
  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <canvas id="budgetChart" height="100"></canvas>
    </div>
  </div>

  <!-- ตารางแสดงรายการ -->
  <div class="row row-cols-1 row-cols-md-2 g-3">
    <?php foreach ($items as $item): ?>
    <div class="col">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
          <strong><?= htmlspecialchars($item['item_name']) ?> (<?= $item['fiscal_year'] ?>)</strong>
          <div class="btn-group">
            <a href="edit_budget_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-warning">แก้ไข</a>
            <a href="?delete=<?= $item['id'] ?>&year=<?= urlencode($selectedYear) ?>"
               class="btn btn-sm btn-danger"
               onclick="return confirm('ยืนยันการลบรายการ \"<?= htmlspecialchars($item['item_name']) ?>\" หรือไม่?')">
               ลบ
            </a>
          </div>
        </div>
        <div class="card-body">
          <p>งบที่ขอ: <?= number_format($item['requested_amount'], 2) ?> บาท</p>
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

</body>
</html>
