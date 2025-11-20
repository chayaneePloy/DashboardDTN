<?php
//session_start();
//if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$iid = intval($_GET['issue_id'] ?? 0);
if($iid<=0){ header("Location: admin_home.php"); exit; }

$iss = $pdo->prepare("SELECT * FROM issues WHERE issue_id=?");
$iss->execute([$iid]);
$issue = $iss->fetch(PDO::FETCH_ASSOC);
if(!$issue){ header("Location: admin_home.php"); exit; }

// รายการ detail เพื่อเลือกย้าย
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

  $u = $pdo->prepare("UPDATE issues SET id_detail=?, issue_date=?, description=?, solution=?, status=? WHERE issue_id=?");
  $u->execute([$id_detail,$issue_date,$description,$solution,$status,$iid]);
  header("Location: admin_home.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แก้ไขปัญหา</title>
<link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
  <h3>✏️ แก้ไขปัญหา</h3>
  <form method="post" class="card p-3">
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">รายละเอียดที่ผูก</label>
        <select name="id_detail" class="form-select" required>
          <?php foreach($details as $d): ?>
          <option value="<?= $d['id_detail'] ?>" <?= $issue['id_detail']==$d['id_detail']?'selected':'' ?>>
            [FY<?= $d['fiscal_year'] ?>] <?= htmlspecialchars($d['item_name']) ?> - <?= htmlspecialchars($d['detail_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">วันที่</label>
        <input type="date" name="issue_date" class="form-control" value="<?= $issue['issue_date'] ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">สถานะ</label>
        <input name="status" class="form-control" value="<?= htmlspecialchars($issue['status']) ?>">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">รายละเอียด</label>
      <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($issue['description']) ?></textarea>
    </div>
    <div class="mb-3">
      <label class="form-label">การแก้ไข</label>
      <textarea name="solution" class="form-control" rows="2"><?= htmlspecialchars($issue['solution']) ?></textarea>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary">บันทึก</button>
      <a class="btn btn-secondary" href="admin_home.php">ยกเลิก</a>
      <a class="btn btn-outline-danger ms-auto" href="admin_remove_issue.php?issue_id=<?= $issue['issue_id'] ?>" onclick="return confirm('ลบรายการปัญหานี้?')">🗑️ ลบ</a>
    </div>
  </form>
</div>
</body>
</html>
