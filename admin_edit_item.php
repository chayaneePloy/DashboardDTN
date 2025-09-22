<?php
session_start();
if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

if(!isset($_GET['id'])){ header("Location: admin_home.php"); exit; }
$id = intval($_GET['id']);

$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id=?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$item){ header("Location: admin_home.php"); exit; }

$dt = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id=? ORDER BY id_detail");
$dt->execute([$id]);
$details = $dt->fetchAll(PDO::FETCH_ASSOC);

if($_SERVER['REQUEST_METHOD']=='POST'){
  $item_name = trim($_POST['item_name']);
  $fy       = intval($_POST['fiscal_year_item']);
  $reqI     = floatval($_POST['requested_amount_item']);
  $appI     = floatval($_POST['approved_amount_item']);

  $u = $pdo->prepare("UPDATE budget_items SET item_name=?, requested_amount=?, approved_amount=?, fiscal_year=? WHERE id=?");
  $u->execute([$item_name, $reqI, $appI, $fy, $id]);

  if(!empty($_POST['detail_name'])){
    foreach($_POST['detail_name'] as $k=>$name){
      $detail_id = intval($_POST['detail_id'][$k] ?? 0);
      $req = floatval($_POST['requested_amount'][$k] ?? 0);
      $app = floatval($_POST['approved_amount'][$k] ?? 0);

      if($detail_id>0){
        $q = $pdo->prepare("UPDATE budget_detail SET detail_name=?, requested_amount=?, approved_amount=?, fiscal_year=? WHERE id_detail=?");
        $q->execute([$name,$req,$app,$fy,$detail_id]);
      }else{
        if($name!==''){
          $q = $pdo->prepare("INSERT INTO budget_detail (budget_item_id, detail_name, requested_amount, approved_amount, fiscal_year) VALUES (?,?,?,?,?)");
          $q->execute([$id,$name,$req,$app,$fy]);
        }
      }
    }
  }

  header("Location: admin_home.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แก้ไข Budget Item</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
function addDetailRow(){
  document.getElementById('details').insertAdjacentHTML('beforeend', `
  <div class="row g-2 align-items-center detail-row mb-2">
    <input type="hidden" name="detail_id[]" value="0">
    <div class="col-5"><input name="detail_name[]" class="form-control" placeholder="ชื่อรายละเอียด" required></div>
    <div class="col-3"><input type="number" step="0.01" name="requested_amount[]" class="form-control" placeholder="Requested" required></div>
    <div class="col-3"><input type="number" step="0.01" name="approved_amount[]" class="form-control" placeholder="Approved" required></div>
    <div class="col-1 text-end"><button class="btn btn-outline-danger" type="button" onclick="this.closest('.detail-row').remove()">✖</button></div>
  </div>`);
}
</script>
</head>
<body class="p-4">
<div class="container">
  <h3 class="mb-3">✏️ แก้ไข Budget Item</h3>
  <form method="post" class="card p-3">
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">ชื่อ Item</label>
        <input name="item_name" class="form-control" value="<?= htmlspecialchars($item['item_name']) ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">ปีงบ</label>
        <input type="number" name="fiscal_year_item" class="form-control" value="<?= $item['fiscal_year'] ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Requested</label>
        <input type="number" step="0.01" name="requested_amount_item" class="form-control" value="<?= $item['requested_amount'] ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Approved</label>
        <input type="number" step="0.01" name="approved_amount_item" class="form-control" value="<?= $item['approved_amount'] ?>" required>
      </div>
    </div>

    <h5 class="mt-2">รายละเอียด (Detail)</h5>
    <div id="details">
      <?php foreach($details as $d): ?>
      <div class="row g-2 align-items-center detail-row mb-2">
        <input type="hidden" name="detail_id[]" value="<?= $d['id_detail'] ?>">
        <div class="col-5"><input name="detail_name[]" class="form-control" value="<?= htmlspecialchars($d['detail_name']) ?>" required></div>
        <div class="col-3"><input type="number" step="0.01" name="requested_amount[]" class="form-control" value="<?= $d['requested_amount'] ?>" required></div>
        <div class="col-3"><input type="number" step="0.01" name="approved_amount[]" class="form-control" value="<?= $d['approved_amount'] ?>" required></div>
        <div class="col-1 text-end">
          <a class="btn btn-outline-danger" href="admin_remove_detail.php?id_detail=<?= $d['id_detail'] ?>&redirect=admin_edit_item.php?id=<?= $item['id'] ?>" onclick="return confirm('ลบรายละเอียดนี้?')">🗑️</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <button type="button" class="btn btn-outline-secondary mb-3" onclick="addDetailRow()">➕ เพิ่ม Detail</button>
    <div class="d-flex gap-2">
      <button class="btn btn-primary">บันทึก</button>
      <a class="btn btn-secondary" href="admin_home.php">ยกเลิก</a>
    </div>
  </form>
</div>
</body>
</html>
