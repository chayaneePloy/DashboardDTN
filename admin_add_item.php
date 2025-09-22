<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if($_SERVER['REQUEST_METHOD']=='POST'){
  $item_name = trim($_POST['item_name']);
  $req_item  = floatval($_POST['requested_amount_item']);
  $app_item  = floatval($_POST['approved_amount_item']);
  $fy_item   = intval($_POST['fiscal_year_item']);

  $stmt = $pdo->prepare("INSERT INTO budget_items (item_name, requested_amount, approved_amount, fiscal_year) VALUES (?, ?, ?, ?)");
  $stmt->execute([$item_name, $req_item, $app_item, $fy_item]);
  $item_id = $pdo->lastInsertId();

  if(!empty($_POST['detail_name'])){
    foreach($_POST['detail_name'] as $k => $dname){
      if($dname==='') continue;
      $req = floatval($_POST['requested_amount_detail'][$k] ?? 0);
      $app = floatval($_POST['approved_amount_detail'][$k] ?? 0);
      $ins = $pdo->prepare("INSERT INTO budget_detail (budget_item_id, detail_name, requested_amount, approved_amount, fiscal_year) VALUES (?,?,?,?,?)");
      $ins->execute([$item_id, $dname, $req, $app, $fy_item]);
    }
  }
  header("Location: admin_home.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่ม Budget Item</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
function addDetailRow(){
  document.getElementById('details').insertAdjacentHTML('beforeend', `
  <div class="row g-2 align-items-center detail-row mb-2">
    <div class="col-5"><input name="detail_name[]" class="form-control" placeholder="ชื่อรายละเอียด" required></div>
    <div class="col-3"><input type="number" step="0.01" name="requested_amount_detail[]" class="form-control" placeholder="Requested" required></div>
    <div class="col-3"><input type="number" step="0.01" name="approved_amount_detail[]" class="form-control" placeholder="Approved" required></div>
    <div class="col-1 text-end"><button class="btn btn-outline-danger" type="button" onclick="this.closest('.detail-row').remove()">✖</button></div>
  </div>`);
}
</script>
</head>
<body class="p-4">
<div class="container">
  <h3 class="mb-3">➕ เพิ่ม Budget Item</h3>
  <form method="post" class="card p-3">
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">ชื่อ Item</label>
        <input name="item_name" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">ปีงบ</label>
        <input type="number" name="fiscal_year_item" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Requested</label>
        <input type="number" step="0.01" name="requested_amount_item" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Approved</label>
        <input type="number" step="0.01" name="approved_amount_item" class="form-control" required>
      </div>
    </div>

    <h5 class="mt-2">รายละเอียด (Detail)</h5>
    <div id="details" class="mb-2"></div>
    <button type="button" class="btn btn-outline-secondary mb-3" onclick="addDetailRow()">➕ เพิ่ม Detail</button>

    <div class="d-flex gap-2">
      <button class="btn btn-success">บันทึก</button>
      <a class="btn btn-secondary" href="admin_home.php">ยกเลิก</a>
    </div>
  </form>
</div>
</body>
</html>
