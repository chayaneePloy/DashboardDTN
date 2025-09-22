<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

// ดึงปีทั้งหมดจาก DB
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_items ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// รับค่าปีจาก GET (ถ้ามี)
$selectedYear = $_GET['year'] ?? '';

// ดึงข้อมูล item ตามปี
if ($selectedYear) {
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE fiscal_year = ? ORDER BY id");
    $stmt->execute([$selectedYear]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $items = $pdo->query("SELECT * FROM budget_items ORDER BY fiscal_year, id")->fetchAll(PDO::FETCH_ASSOC);
}

// เตรียมข้อมูล chart
$labels = $approved = $requested = [];
foreach ($items as $item) {
    $labels[] = $item['item_name'] . ' (' . $item['fiscal_year'] . ')';
    $approved[] = (float)$item['approved_amount'];
    $requested[] = (float)$item['requested_amount'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>body{font-family:'Sarabun',sans-serif;}</style>
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="#">Admin Panel</a>
    <div class="d-flex">
      <span class="navbar-text text-white me-3">สวัสดี, <?= htmlspecialchars($_SESSION['user']) ?></span>
      <a href="index.php" class="btn btn-danger btn-sm">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-4">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Dashboard - งบประมาณ</h2>
    <a href="add_budget_item.php" class="btn btn-success">➕ เพิ่ม Item</a>
  </div>

  <!-- Filter ปี -->
  <form method="get" class="row g-2 mb-4">
    <div class="col-auto">
      <select name="year" class="form-select" onchange="this.form.submit()">
        <option value="">-- แสดงทุกปี --</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $selectedYear==$y?'selected':'' ?>>
            <?= $y ?>
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

  <!-- Chart -->
  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <canvas id="budgetChart" height="100"></canvas>
    </div>
  </div>

  <!-- Items -->
  <div class="row row-cols-1 row-cols-md-2 g-3">
    <?php foreach ($items as $item):
      $details = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
      $details->execute([$item['id']]);
      $details = $details->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="col">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong><?= htmlspecialchars($item['item_name']) ?> (<?= $item['fiscal_year'] ?>)</strong>
          <div>
            <a href="edit_budget_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-warning">แก้ไข</a>
            <a href="delete_budget_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('ลบจริงหรือไม่?')">ลบ</a>
          </div>
        </div>
        <div class="card-body">
          <p>
            ขอ: <?= number_format($item['requested_amount'],2) ?> /
            อนุมัติ: <?= number_format($item['approved_amount'],2) ?> 
            (<?= $item['percentage'] ?>%)
          </p>
          <ul class="list-group list-group-flush">
            <?php foreach ($details as $d): ?>
              <li class="list-group-item">
                <?= htmlspecialchars($d['detail_name']) ?> - 
                ขอ: <?= number_format($d['requested_amount'],2) ?> /
                อนุมัติ: <?= number_format($d['approved_amount'],2) ?> 
                (<?= $d['percentage'] ?>%)
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
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
      { label: 'Requested', data: <?= json_encode($requested) ?>, backgroundColor: 'rgba(54, 162, 235, 0.6)' },
      { label: 'Approved', data: <?= json_encode($approved) ?>, backgroundColor: 'rgba(75, 192, 192, 0.6)' }
    ]
  },
  options: { responsive:true, scales:{ y:{ beginAtZero:true } } }
});
</script>

</body>
</html>
