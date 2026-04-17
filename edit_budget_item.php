<?php
include 'db.php';

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) { die("ไม่พบข้อมูลงบประมาณหลัก"); }

$selected_year = $item['fiscal_year'] ?? null;

$detailsStmt = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

// เพิ่ม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_detail'])) {
    $stmt = $pdo->prepare("
        INSERT INTO budget_detail 
        (budget_item_id, detail_name, budget_received, requested_amount, description,
         tor_secretary, procurement_secretary, inspector_secretary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $id,
        $_POST['detail_name'],
        $_POST['budget_received'],
        floatval($_POST['requested_amount'] ?? 0),
        $_POST['description'] ?? '',
        $_POST['tor_secretary'] ?? '',
        $_POST['procurement_secretary'] ?? '',
        $_POST['inspector_secretary'] ?? ''
    ]);

    header("Location: edit_budget_item.php?id=$id");
    exit;
}
// ---------------- บันทึกทีละแถว ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_one'])) {

    $stmt = $pdo->prepare("
        UPDATE budget_detail 
        SET detail_name=?, budget_received=?, requested_amount=?, description=?,
            tor_secretary=?, procurement_secretary=?, inspector_secretary=?
        WHERE id_detail=?
    ");

    $stmt->execute([
        $_POST['detail_name'],
        $_POST['budget_received'],
        $_POST['requested_amount'],
        $_POST['description'],
        $_POST['tor_secretary'],
        $_POST['procurement_secretary'],
        $_POST['inspector_secretary'],
        $_POST['id_detail']
    ]);

    header("Location: edit_budget_item.php?id=$id&saved=1");
    exit;
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
body{font-family:'Sarabun',sans-serif;background:#f7f9fc;}
textarea{resize:vertical;font-size:13px;}
.small-label{font-size:12px;color:#666;margin-bottom:2px;}
</style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand fw-bold" href="index.php">
      📊 Dashboard 
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
          <a class="nav-link text-white" href="dashboard.php<?= $selected_year ? '?year='.urlencode($selected_year) : '' ?>"
>
            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>

      </ul>
    </div>

  </div>
</nav>

 
  

<div class="container my-4">
   <h4>📋 <?= htmlspecialchars($item['item_name']) ?></h4>
<!-- ➕ เพิ่มงบ (แยกชัดด้านล่าง) -->
<div class="card mt-4">
<div class="card-header bg-success text-white">➕ เพิ่มโครงการใหม่</div>
<div class="card-body">

<form method="POST">
<input type="hidden" name="add_detail" value="1">

<div class="row g-2">
  <div class="col-md-3">
    <input name="detail_name" class="form-control" placeholder="ชื่อโครงการ" required>
  </div>
  <div class="col-md-2">
    <input name="budget_received" class="form-control" placeholder="งบ" required>
  </div>
  <div class="col-md-2">
    <input name="requested_amount" class="form-control" placeholder="ราคาจ้าง">
  </div>
  <div class="col-md-2">
    <input name="description" class="form-control" placeholder="หมายเหตุ">
  </div>
</div>

<div class="row mt-2">
  <div class="col-md-4">
    <textarea name="tor_secretary" class="form-control" placeholder="เลขานุการร่าง TOR"></textarea>
  </div>
  <div class="col-md-4">
    <textarea name="procurement_secretary" class="form-control" placeholder="เลขานุการจัดจ้าง"></textarea>
  </div>
  <div class="col-md-4">
    <textarea name="inspector_secretary" class="form-control" placeholder="เลขานุการตรวจรับ"></textarea>
  </div>
</div>

<button class="btn btn-success mt-2">เพิ่ม</button>

</form>

</div>
</div>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success">บันทึกเรียบร้อยแล้ว</div>
<?php endif; ?>

<!-- ✅ ปุ่มบันทึกย้ายขึ้นบน -->
<form method="POST">
<input type="hidden" name="save_all" value="1">

<div class=" mt-5 mb-3 ">
  <h4>รายละเอียดโครงการ</h4>
</div>

<div class="card">
<div class="table-responsive">
<table class="table table-bordered table-striped align-middle mb-0">

<thead class="table-dark text-center">
<tr>
<th>#</th>
<th style="min-width:220px;">โครงการ</th>
<th style="width:120px;">งบ</th>
<th style="width:120px;">ราคาจ้าง</th>
<th style="min-width:150px;">หมายเหตุ</th>
<th style="min-width:220px;">ผู้รับผิดชอบ</th>
<th>ลบ</th>
</tr>
</thead>

<tbody>
<?php foreach ($details as $i => $d): ?>
<tr>

<form method="POST">

<td><?= $i+1 ?></td>

<input type="hidden" name="id_detail" value="<?= $d['id_detail'] ?>">

<td>
  <input name="detail_name" class="form-control"
    value="<?= htmlspecialchars($d['detail_name']) ?>">
</td>

<td>
  <input name="budget_received" class="form-control text-end"
    value="<?= $d['budget_received'] ?>">
</td>

<td>
  <input name="requested_amount" class="form-control text-end"
    value="<?= $d['requested_amount'] ?>">
</td>

<td>
  <input name="description" class="form-control"
    value="<?= htmlspecialchars($d['description']) ?>">
</td>

<td>
  <div class="small-label">TOR</div>
  <textarea name="tor_secretary" class="form-control mb-1"><?= htmlspecialchars($d['tor_secretary'] ?? '') ?></textarea>

  <div class="small-label">จัดจ้าง</div>
  <textarea name="procurement_secretary" class="form-control mb-1"><?= htmlspecialchars($d['procurement_secretary'] ?? '') ?></textarea>

  <div class="small-label">ตรวจรับ</div>
  <textarea name="inspector_secretary" class="form-control"><?= htmlspecialchars($d['inspector_secretary'] ?? '') ?></textarea>
</td>

<td class="text-center">

  <!-- ✅ ปุ่มบันทึก -->
  <button type="submit" name="save_one" class="btn btn-success btn-sm mb-1">
    บันทึก
  </button>

  <!-- ❌ ปุ่มลบ -->
  <a href="?id=<?= $id ?>&delete_detail=<?= $d['id_detail'] ?>"
     onclick="return confirm('ลบรายการนี้?')"
     class="btn btn-danger btn-sm">
     ลบ
  </a>

</td>

</form>

</tr>
<?php endforeach; ?>
</tbody>

</table>
</div>
</div>

</form>
</div>


</body>
</html>