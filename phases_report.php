<?php
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$project_id = $_GET['project'] ?? '';

$stmt = $pdo->prepare("
    SELECT p.phase_number, p.phase_name, p.amount, p.payment_date, p.status
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    WHERE c.detail_item_id = ?
    ORDER BY p.phase_number ASC
");
$stmt->execute([$project_id]);
$phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>รายงานการจ่ายงวด</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <h2 class="mb-4 text-primary">📑 รายงานการจ่ายงวด</h2>

  <?php if ($phases): ?>
    <table class="table table-bordered table-striped">
      <thead class="table-light">
        <tr>
          <th>งวดที่</th>
          <th>ชื่อ</th>
          <th>จำนวนเงิน (บาท)</th>
          <th>วันที่จ่าย</th>
          <th>สถานะ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($phases as $p): ?>
          <tr>
            <td><?= $p['phase_number'] ?></td>
            <td><?= htmlspecialchars($p['phase_name']) ?></td>
            <td><?= number_format($p['amount'], 2) ?></td>
            <td><?= $p['payment_date'] ? date("d/m/Y", strtotime($p['payment_date'])) : '-' ?></td>
            <td><?= $p['status'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="alert alert-warning">❌ ไม่มีข้อมูลงวดการจ่าย</div>
  <?php endif; ?>

  <a href="dashboard_bootstrap.php" class="btn btn-secondary mt-3">⬅ กลับไปหน้า Dashboard</a>
</div>
</body>
</html>
