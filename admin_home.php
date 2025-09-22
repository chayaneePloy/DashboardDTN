<?php
session_start();
if(!isset($_SESSION['user'])){ 
    header("Location: login.php"); 
    exit; 
}
include 'db.php';

// ================= Budget Items =================
$items = $pdo->query("
    SELECT * 
    FROM budget_items 
    ORDER BY fiscal_year DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$detailCountStmt = $pdo->query("
    SELECT budget_item_id, COUNT(*) as cnt 
    FROM budget_detail 
    GROUP BY budget_item_id
");
$detailCount = [];
foreach($detailCountStmt as $r){ 
    $detailCount[$r['budget_item_id']] = $r['cnt']; 
}

// ================= Contracts =================
$contracts = $pdo->query("
    SELECT c.contract_id, c.contractor_name, c.contract_number, c.contract_date, c.end_date, b.detail_name
    FROM contracts c
    LEFT JOIN budget_detail b ON c.detail_item_id = b.id_detail
    ORDER BY c.contract_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ================= Issues =================
$issues = $pdo->query("
    SELECT i.issue_id, i.issue_date,, i.deceription, i.solution, i.status, b.detail_name
    FROM issues i
    LEFT JOIN budget_detail b ON i.detail_item_id = b.id
    ORDER BY i.issue_id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Admin | Budget Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f6f7fb}
.card{border:none;border-radius:14px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.table thead th { background:#f1f3f9 }
</style>
</head>
<body class="p-4">
<div class="container">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="m-0">💼 Budget Dashboard</h2>
    <div class="d-flex gap-2">
      <a href="admin_add_item.php" class="btn btn-success">➕ เพิ่ม Item</a>
      <a href="logout.php" class="btn btn-outline-secondary">ออกจากระบบ</a>
    </div>
  </div>

  <!-- Budget Items -->
  <div class="card mb-4">
    <div class="card-body">
      <h4>📊 Budget Items</h4>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>ชื่อรายการ</th>
              <th>Requested</th>
              <th>Approved</th>
              <th>ปีงบ</th>
              <th>จำนวน Detail</th>
              <th class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($items as $i): ?>
            <tr>
              <td><?= $i['id'] ?></td>
              <td><?= htmlspecialchars($i['item_name']) ?></td>
              <td><?= number_format($i['requested_amount'],2) ?></td>
              <td><?= number_format($i['approved_amount'],2) ?></td>
              <td><?= $i['fiscal_year'] ?></td>
              <td><?= $detailCount[$i['id']] ?? 0 ?></td>
              <td class="text-end">
                <a class="btn btn-primary btn-sm" href="admin_edit_item.php?id=<?= $i['id'] ?>">✏️ แก้ไข</a>
                <a class="btn btn-danger btn-sm" href="admin_remove_item.php?id=<?= $i['id'] ?>" onclick="return confirm('ลบ Item นี้พร้อมรายละเอียดทั้งหมด?')">🗑️ ลบ</a>
              </td>
            </tr>
            <?php endforeach; if(empty($items)): ?>
            <tr><td colspan="7" class="text-center text-muted">ยังไม่มีข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Contracts -->
  <div class="card mb-4">
    <div class="card-body">
      <h4>📑 Contracts</h4>
      <div class="d-flex justify-content-end mb-2">
        <a class="btn btn-outline-primary btn-sm" href="admin_add_contract.php">➕ เพิ่มสัญญา</a>
      </div>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>เลขที่สัญญา</th>
              <th>คู่สัญญา</th>
              <th>วันที่ทำสัญญา</th>
              <th>สิ้นสุด</th>
              <th>Detail</th>
              <th class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($contracts as $c): ?>
            <tr>
              <td><?= $c['contract_id'] ?></td>
              <td><?= htmlspecialchars($c['contract_number']) ?></td>
              <td><?= htmlspecialchars($c['contractor_name']) ?></td>
              <td><?= $c['contract_date'] ?></td>
              <td><?= $c['end_date'] ?></td>
              <td><?= htmlspecialchars($c['detail_name'] ?? '-') ?></td>
              <td class="text-end">
                <a href="admin_edit_contract.php?id=<?= $c['contract_id'] ?>" class="btn btn-sm btn-primary">✏️</a>
                <a href="admin_remove_contract.php?id=<?= $c['contract_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('ยืนยันการลบสัญญา?')">🗑️</a>
              </td>
            </tr>
            <?php endforeach; if(empty($contracts)): ?>
            <tr><td colspan="7" class="text-center text-muted">ยังไม่มีสัญญา</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Issues -->
  <div class="card">
    <div class="card-body">
      <h4>⚠️ Issues</h4>
      <div class="d-flex justify-content-end mb-2">
        <a class="btn btn-outline-secondary btn-sm" href="admin_add_issue.php">➕ เพิ่มปัญหา</a>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>เรื่อง</th>
              <th>วันที่พบ</th>
              <th>สถานะ</th>
              <th>Detail</th>
              <th class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($issues as $iss): ?>
            <tr>
              <td><?= $iss['issue_id'] ?></td>
              <td><?= htmlspecialchars($iss['issue_title']) ?></td>
              <td><?= $iss['issue_date'] ?></td>
              <td><?= htmlspecialchars($iss['status']) ?></td>
              <td><?= htmlspecialchars($iss['detail_name'] ?? '-') ?></td>
              <td class="text-end">
                <a href="admin_edit_issue.php?id=<?= $iss['issue_id'] ?>" class="btn btn-sm btn-primary">✏️</a>
                <a href="admin_remove_issue.php?id=<?= $iss['issue_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('ยืนยันการลบปัญหานี้?')">🗑️</a>
              </td>
            </tr>
            <?php endforeach; if(empty($issues)): ?>
            <tr><td colspan="6" class="text-center text-muted">ยังไม่มีปัญหา</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
