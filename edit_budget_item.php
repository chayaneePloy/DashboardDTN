<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if(!isset($_GET['id'])){ header("Location: dashboard.php"); exit; }

$id = intval($_GET['id']);

// ดึงข้อมูล item
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$item){ header("Location: dashboard.php"); exit; }

// ดึง details
$stmt = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
$stmt->execute([$id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);

// อัปเดต item
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $item_name = trim($_POST['item_name']);
    $requested_amount_item = floatval($_POST['requested_amount_item']);
    $approved_amount_item = floatval($_POST['approved_amount_item']);
    $fiscal_year_item = intval($_POST['fiscal_year_item']);

    // อัปเดต budget_items
    $stmt = $pdo->prepare("UPDATE budget_items SET item_name=?, requested_amount=?, approved_amount=?, fiscal_year=? WHERE id=?");
    $stmt->execute([$item_name, $requested_amount_item, $approved_amount_item, $fiscal_year_item, $id]);

    // อัปเดตหรือเพิ่ม budget_detail
    if(!empty($_POST['detail_name'])){
        foreach($_POST['detail_name'] as $index => $detail_name){
            $detail_id = intval($_POST['detail_id'][$index]);
            $requested_amount = floatval($_POST['requested_amount'][$index]);
            $approved_amount = floatval($_POST['approved_amount'][$index]);

            if($detail_id > 0){
                // อัปเดต detail
                $stmt = $pdo->prepare("UPDATE budget_detail SET detail_name=?, requested_amount=?, approved_amount=?, fiscal_year=? WHERE id_detail=?");
                $stmt->execute([$detail_name, $requested_amount, $approved_amount, $fiscal_year_item, $detail_id]);
            } else {
                // เพิ่ม detail ใหม่
                $stmt = $pdo->prepare("INSERT INTO budget_detail (budget_item_id, detail_name, requested_amount, approved_amount, fiscal_year) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$id, $detail_name, $requested_amount, $approved_amount, $fiscal_year_item]);
            }
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
<title>Edit Budget Item</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .detail-row { margin-bottom:10px; }
</style>
<script>
// เพิ่ม Detail แบบ dynamic
function addDetailRow(){
    const container = document.getElementById('details-container');
    container.insertAdjacentHTML('beforeend', `
    <div class="row detail-row align-items-center">
        <input type="hidden" name="detail_id[]" value="0">
        <div class="col"><input type="text" name="detail_name[]" class="form-control" placeholder="Detail Name" required></div>
        <div class="col"><input type="number" step="0.01" name="requested_amount[]" class="form-control" placeholder="Requested Amount" required></div>
        <div class="col"><input type="number" step="0.01" name="approved_amount[]" class="form-control" placeholder="Approved Amount" required></div>
        <div class="col-auto">
            <button type="button" class="btn btn-danger" onclick="this.closest('.detail-row').remove()">❌</button>
        </div>
    </div>
    `);
}
</script>
</head>
<body class="p-4">
<div class="container">
<h2>Edit Budget Item</h2>

<form method="post">
    <div class="mb-3">
        <label>Item Name</label>
        <input type="text" name="item_name" class="form-control" value="<?= htmlspecialchars($item['item_name']) ?>" required>
    </div>
    <div class="row mb-3">
        <div class="col">
            <label>Requested Amount</label>
            <input type="number" step="0.01" name="requested_amount_item" class="form-control" value="<?= $item['requested_amount'] ?>" required>
        </div>
        <div class="col">
            <label>Approved Amount</label>
            <input type="number" step="0.01" name="approved_amount_item" class="form-control" value="<?= $item['approved_amount'] ?>" required>
        </div>
        <div class="col">
            <label>Fiscal Year</label>
            <input type="number" name="fiscal_year_item" class="form-control" value="<?= $item['fiscal_year'] ?>" required>
        </div>
    </div>

    <h5>รายละเอียด (Budget Detail)</h5>
    <div id="details-container">
        <?php foreach($details as $d): ?>
        <div class="row detail-row align-items-center">
            <input type="hidden" name="detail_id[]" value="<?= $d['id_detail'] ?>">
            <div class="col"><input type="text" name="detail_name[]" class="form-control" value="<?= htmlspecialchars($d['detail_name']) ?>" required></div>
            <div class="col"><input type="number" step="0.01" name="requested_amount[]" class="form-control" value="<?= $d['requested_amount'] ?>" required></div>
            <div class="col"><input type="number" step="0.01" name="approved_amount[]" class="form-control" value="<?= $d['approved_amount'] ?>" required></div>
            <div class="col-auto">
                <a href="delete_detail.php?id_detail=<?= $d['id_detail'] ?>&redirect=edit_budget_item.php?id=<?= $item['id'] ?>" 
                   class="btn btn-danger" onclick="return confirm('ลบ detail นี้จริงหรือไม่?')">❌</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <button type="button" class="btn btn-secondary mb-3" onclick="addDetailRow()">➕ เพิ่ม Detail</button>
    <br>
    <button type="submit" class="btn btn-primary">บันทึก</button>
    <a href="dashboard.php" class="btn btn-secondary">ยกเลิก</a>
</form>

</div>
</body>
</html>
