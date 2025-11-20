<?php
include 'db.php';

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: index.php"); exit; }

// ---------------- ดึงข้อมูลงบหลัก ----------------
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) { die("ไม่พบข้อมูลงบประมาณหลัก"); }

// ---------------- ดึงรายการงบย่อยทั้งหมด ----------------
$details = $pdo->prepare("SELECT * FROM budget_detail WHERE budget_item_id = ?");
$details->execute([$id]);
$details = $details->fetchAll(PDO::FETCH_ASSOC);

// ---------------- ลบงบย่อย ----------------
if (isset($_GET['delete_detail'])) {
    $detail_id = intval($_GET['delete_detail']);
    $pdo->prepare("DELETE FROM budget_detail WHERE id_detail = ?")->execute([$detail_id]);
    header("Location: edit_budget_item.php?id=$id");
    exit;
}

// ---------------- แก้ไขงบย่อย ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_detail'])) {
    $detail_id = $_POST['id_detail'];
    $detail_name = $_POST['detail_name'];
    $budget_received = $_POST['budget_received'];
    $requested_amount = $_POST['requested_amount'];
    $description = $_POST['description'];

    $stmt = $pdo->prepare("UPDATE budget_detail SET detail_name=?, budget_received=?, requested_amount=?, description=? WHERE id_detail=?");
    $stmt->execute([$detail_name, $budget_received , $requested_amount, $description, $detail_id]);

    header("Location: edit_budget_item.php?id=$id");
    exit;
}

// ---------------- เพิ่มงบย่อยใหม่ ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_detail'])) {
    $detail_name = $_POST['detail_name'];
    $budget_received =$_POST['budget_received'];
    $requested_amount = $_POST['requested_amount'];
    $description = $_POST['description'] ?? '';

    $insert = $pdo->prepare("INSERT INTO budget_detail (budget_item_id, detail_name, budget_received, requested_amount, description) VALUES (?, ?, ?, ?, ?)");
    $insert->execute([$id, $detail_name, $budget_received, $requested_amount, $description]);
    header("Location: edit_budget_item.php?id=$id");
    exit;
}// ---------------- บันทึกทั้งหมด ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {

    $ids = $_POST['id_detail'];
    $names = $_POST['detail_name'];
    $budgets = $_POST['budget_received'];
    $requests = $_POST['requested_amount'];
    $descs = $_POST['description'];

    $stmt = $pdo->prepare("
        UPDATE budget_detail 
        SET detail_name=?, budget_received=?, requested_amount=?, description=?
        WHERE id_detail=?
    ");

    for ($i = 0; $i < count($ids); $i++) {
        $stmt->execute([
            $names[$i],
            $budgets[$i],
            $requests[$i],
            $descs[$i],
            $ids[$i]
        ]);
    }

    header("Location: edit_budget_item.php?id=$id");
    exit;
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จัดการงบย่อยของรายการหลัก</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body{font-family:'Sarabun',sans-serif;background:#f7f9fc;}
.table th, .table td{text-align:center;vertical-align:middle;}
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="dashboard.php">← กลับ Dashboard</a>
  </div>
</nav>

<div class="container my-5">
  <h2 class="mb-4">📋 จัดการงบย่อยของ “<?= htmlspecialchars($item['item_name']) ?>”</h2>

  <!-- ฟอร์มเพิ่มงบย่อย -->
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-bold">➕ เพิ่มงบย่อยใหม่</div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="add_detail" value="1">
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label">ชื่อรายการย่อย</label>
            <input type="text" name="detail_name" class="form-control" required>
          </div>
             <div class="col-md-2">
            <label class="form-label">งบที่ได้รับ (บาท)</label>
            <input type="number" step="0.01" name="budget_received" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">ราคาจ้าง (บาท)</label>
            <input type="number" step="0.01" name="requested_amount" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">คำอธิบาย (ถ้ามี)</label>
            <input type="text" name="description" class="form-control">
          </div>
          <div class="col-md-1">
            <button type="submit" class="btn btn-success w-100">เพิ่ม</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <form method="POST">
    <input type="hidden" name="save_all" value="1">
  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white fw-bold">🧾 รายการงบย่อยทั้งหมด</div>
    <table class="table table-bordered table-striped m-0">
        <thead class="table-dark">
            <tr>
                <th>ลำดับ</th>
                <th>ชื่อรายการย่อย</th>
                <th>งบที่ได้รับ (บาท)</th>
                <th>ราคาจ้าง (บาท)</th>
                <th>คำอธิบาย</th>
                <th>จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($details): $i=1; foreach ($details as $d): ?>
            <tr>
                <td><?= $i++ ?></td>

                <input type="hidden" name="id_detail[]" value="<?= $d['id_detail'] ?>">

                <td><input type="text" name="detail_name[]" class="form-control"
                    value="<?= htmlspecialchars($d['detail_name']) ?>"></td>

                <td><input type="number" step="0.01" name="budget_received[]" class="form-control text-end"
                    value="<?= $d['budget_received'] ?>"></td>

                <td><input type="number" step="0.01" name="requested_amount[]" class="form-control text-end"
                    value="<?= $d['requested_amount'] ?>"></td>

                <td><input type="text" name="description[]" class="form-control"
                    value="<?= htmlspecialchars($d['description']) ?>"></td>

                <td>
                    <a href="?id=<?= $id ?>&delete_detail=<?= $d['id_detail'] ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('ลบรายการย่อย “<?= htmlspecialchars($d['detail_name']) ?>” หรือไม่?')">ลบ</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- ปุ่มบันทึกทั้งหมด -->
    <div class="text-end p-2">
        <button type="submit" class="btn btn-success btn-sm">
            💾 บันทึกทั้งหมด
        </button>
    </div>
        </div>
        </div>
</form>

  


</body>
</html>
