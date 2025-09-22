<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$items = $pdo->query("SELECT * FROM budget_items ORDER BY fiscal_year, id")->fetchAll(PDO::FETCH_ASSOC);
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">Admin Panel</a>
    <div class="d-flex">
      <span class="navbar-text text-white me-3">สวัสดี, <?= $_SESSION['user'] ?></span>
      <a href="index.php" class="btn btn-danger btn-sm">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Dashboard - งบประมาณ</h2>
    <a href="add_budget_item.php" class="btn btn-success">➕ เพิ่ม Item</a>
  </div>

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
          <p>Requested: <?= number_format($item['requested_amount'],2) ?> / Approved: <?= number_format($item['approved_amount'],2) ?> (<?= $item['percentage'] ?>%)</p>
          <ul class="list-group list-group-flush">
            <?php foreach ($details as $d): ?>
            <li class="list-group-item"><?= htmlspecialchars($d['detail_name']) ?> - Requested: <?= number_format($d['requested_amount'],2) ?> / Approved: <?= number_format($d['approved_amount'],2) ?> (<?= $d['percentage'] ?>%)</li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

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

<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.js"></script>
</body>
</html>
