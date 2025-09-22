<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $item_name = trim($_POST['item_name']);
    $requested_amount = floatval($_POST['requested_amount']);
    $approved_amount = floatval($_POST['approved_amount']);
    $fiscal_year = intval($_POST['fiscal_year']);

    $stmt = $pdo->prepare("INSERT INTO budget_items (item_name, requested_amount, approved_amount, fiscal_year) VALUES (?, ?, ?, ?)");
    $stmt->execute([$item_name, $requested_amount, $approved_amount, $fiscal_year]);
    $item_id = $pdo->lastInsertId();

    if(!empty($_POST['detail_name'])){
        foreach($_POST['detail_name'] as $index => $dname){
            $stmt = $pdo->prepare("INSERT INTO budget_detail (budget_item_id, detail_name, requested_amount, approved_amount, fiscal_year) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$item_id, $dname, floatval($_POST['requested_amount_detail'][$index]), floatval($_POST['approved_amount_detail'][$index]), $fiscal_year]);
        }
    }

    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่ม Budget Item</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.css" rel="stylesheet"/>
<script>
function addDetailRow(){
    const container = document.getElementById('details-container');
    container.insertAdjacentHTML('beforeend', `
    <div class="row mb-2 align-items-center">
        <div class="col"><input type="text" name="detail_name[]" class="form-control" placeholder="Detail Name" required></div>
        <div class="col"><input type="number" step="0.01" name="requested_amount_detail[]" class="form-control" placeholder="Requested Amount" required></div>
        <div class="col"><input type="number" step="0.01" name="approved_amount_detail[]" class="form-control" placeholder="Approved Amount" required></div>
        <div class="col-auto"><button type="button" class="btn btn-danger" onclick="this.parentElement.parentElement.remove()">❌</button></div>
    </div>`);
}
</script>
</head>
<body class="bg-light">
<div class="container my-4">
<h2>➕ เพิ่ม Budget Item</h2>
<form method="post" class="card p-4 shadow-sm bg-white">
<div class="mb-3">
<label>Item Name</label>
<input type="text" name="item_name" class="form-control" required>
</div>
<div class="row mb-3">
<div class="col">
<label>Requested Amount</label>
<input type="number" step="0.01" name="requested_amount" class="form-control" required>
</div>
<div class="col">
<label>Approved Amount</label>
<input type="number" step="0.01" name="approved_amount" class="form-control" required>
</div>
<div class="col">
<label>Fiscal Year</label>
<input type="number" name="fiscal_year" class="form-control" required>
</div>
</div>

<h5>รายละเอียด (Budget Detail)</h5>
<div id="details-container"></div>
<button type="button" class="btn btn-secondary mb-3" onclick="addDetailRow()">➕ เพิ่ม Detail</button>
<br>
<button type="submit" class="btn btn-primary">บันทึก</button>
<a href="dashboard.php" class="btn btn-secondary">ยกเลิก</a>
</form>
</div>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/6.4.0/mdb.min.js"></script>
</body>
</html>
