<?php
//session_start();
//if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

// ดึงรายละเอียดเพื่อเลือกผูก
$details = $pdo->query("SELECT d.id_detail, d.detail_name, i.item_name, d.fiscal_year
                        FROM budget_detail d
                        LEFT JOIN budget_items i ON i.id = d.budget_item_id
                        ORDER BY d.fiscal_year DESC, d.id_detail DESC")->fetchAll(PDO::FETCH_ASSOC);

if($_SERVER['REQUEST_METHOD']=='POST'){
  $id_detail = intval($_POST['id_detail']);
  $issue_date = $_POST['issue_date'] ?: null;
  $description = trim($_POST['description']);
  $solution = trim($_POST['solution']);
  $status = trim($_POST['status']);

  $ins = $pdo->prepare("INSERT INTO issues (id_detail, issue_date, description, solution, status) VALUES (?,?,?,?,?)");
  $ins->execute([$id_detail,$issue_date,$description,$solution,$status]);
  header("Location: admin_home.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่มปัญหา (Issue)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
  <h3>➕ เพิ่มปัญหา (Issue)</h3>
  <form method="post" class="card p-3">
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">ผูกกับรายละเอียด</label>
        <select name="id_detail" class="form-select" required>
          <option value="">-- เลือก --</option>
          <?php foreach($details as $d): ?>
          <option value="<?= $d['id_detail'] ?>">
            [FY<?= $d['fiscal_year'] ?>] <?= htmlspecialchars($d['item_name']) ?> - <?= htmlspecialchars($d['detail_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">วันที่</label>
        <input type="date" name="issue_date" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label">สถานะ</label>
        <input name="status" class="form-control" placeholder="กำลังแก้ไข/แก้ไขแล้ว/รอดำเนินการ">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">รายละเอียด</label>
      <textarea name="description" class="form-control" rows="3" required></textarea>
    </div>
    <div class="mb-3">
      <label class="form-label">การแก้ไข</label>
      <textarea name="solution" class="form-control" rows="2"></textarea>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-success">บันทึก</button>
      <a class="btn btn-secondary" href="admin_home.php">ยกเลิก</a>
    </div>
  </form>
</div>
</body>
</html>
