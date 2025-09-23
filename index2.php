<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

// ดึงชื่อโครงการ
$stmtDetail = $pdo->prepare("SELECT detail_name FROM budget_detail WHERE id_detail = :id_detail");
$stmtDetail->execute([':id_detail' => $id_detail]);
$project_detail = $stmtDetail->fetch(PDO::FETCH_ASSOC);
$detail_name = $project_detail ? $project_detail['detail_name'] : '-';

// ดึงข้อมูลขั้นตอน
$steps_stmt = $pdo->prepare("
    SELECT id, step_order, step_name, step_date, step_description, sub_steps, document_path, is_completed, id_butget_detail
    FROM project_steps
    WHERE id_butget_detail = :id_detail
    ORDER BY step_order
");
$steps_stmt->execute([':id_detail' => $id_detail]);
$steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

// หาขั้นตอนปัจจุบัน และถัดไป
$currentStep = null;
$nextStep = null;
foreach ($steps as $i => $s) {
    if (!$s['is_completed'] && !$currentStep) {
        $currentStep = $s;
        $nextStep = $steps[$i+1] ?? null;
    }
}

// ฟังก์ชันวันที่ไทย
function thai_date($date) {
    if (!$date) return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    return date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>ระบบติดตามโครงการ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { font-family: 'Kanit', sans-serif; background: #f8f9fa; }
    .process-step.completed { border-left: 4px solid #28a745; }
    .step-number { background:#3498db; color:#fff; border-radius:50%; width:40px; height:40px; display:flex; justify-content:center; align-items:center; font-weight:bold; }
  </style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary">
  <div class="container"><a class="navbar-brand" href="#">ระบบติดตามขั้นตอนโครงการ</a></div>
</nav>

<div class="container my-4">
  <h1 class="text-center">ขั้นตอนการดำเนินโครงการ</h1>
  <h3 class="text-center text-secondary mb-4"><?= htmlspecialchars($detail_name) ?></h3>

  <!-- แสดง Current/Next -->
  <?php if($currentStep): ?>
  <div class="alert alert-info">
    <strong>ขั้นตอนปัจจุบัน:</strong> <?= htmlspecialchars($currentStep['step_name']) ?>  
    <?php if($nextStep): ?><br><strong>ขั้นตอนถัดไป:</strong> <?= htmlspecialchars($nextStep['step_name']) ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Timeline -->
  <div class="process-timeline">
    <?php foreach($steps as $step): ?>
    <div class="card mb-3 <?= $step['is_completed'] ? 'border-success' : '' ?>">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <h5 class="card-title"><?= htmlspecialchars($step['step_order']) ?>. <?= htmlspecialchars($step['step_name']) ?></h5>
          <span class="badge bg-warning"><?= thai_date($step['step_date']) ?></span>
        </div>
        <p class="card-text"><?= nl2br(htmlspecialchars($step['step_description'])) ?></p>

        <?php if(!empty($step['sub_steps'])): ?>
          <div class="bg-light p-2 rounded"><strong>ขั้นตอนย่อย:</strong> <?= nl2br(htmlspecialchars($step['sub_steps'])) ?></div>
        <?php endif; ?>

        <?php if(!empty($step['document_path'])): ?>
          <a href="documents/<?= htmlspecialchars($step['document_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-2">📂 ดูไฟล์</a>
        <?php endif; ?>

        <!-- ปุ่มแก้ไข -->
        <button class="btn btn-sm btn-outline-success mt-2" data-bs-toggle="modal" data-bs-target="#editModal<?= $step['id'] ?>">✏️ แก้ไข</button>
      </div>
    </div>

    <!-- Modal แก้ไข -->
    <div class="modal fade" id="editModal<?= $step['id'] ?>" tabindex="-1">
      <div class="modal-dialog">
        <form method="post" action="update_step.php" enctype="multipart/form-data" class="modal-content">
          <div class="modal-header"><h5 class="modal-title">แก้ไขขั้นตอน</h5></div>
          <div class="modal-body">
            <input type="hidden" name="id" value="<?= $step['id'] ?>">
            <div class="mb-2">
              <label class="form-label">ชื่อขั้นตอน</label>
              <input type="text" name="step_name" class="form-control" value="<?= htmlspecialchars($step['step_name']) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">วันที่</label>
              <input type="date" name="step_date" class="form-control" value="<?= $step['step_date'] ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">รายละเอียด</label>
              <textarea name="step_description" class="form-control"><?= htmlspecialchars($step['step_description']) ?></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label">ขั้นตอนย่อย</label>
              <textarea name="sub_steps" class="form-control"><?= htmlspecialchars($step['sub_steps']) ?></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label">อัปโหลดไฟล์</label>
              <input type="file" name="document" class="form-control">
              <?php if($step['document_path']): ?>
                <p class="mt-1 small">ไฟล์ปัจจุบัน: <?= htmlspecialchars($step['document_path']) ?></p>
              <?php endif; ?>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_completed" value="1" <?= $step['is_completed'] ? 'checked' : '' ?>>
              <label class="form-check-label">ทำเสร็จแล้ว</label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">บันทึก</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<footer class="bg-dark text-white text-center py-3 mt-5">
  <p>&copy; <?= date('Y')+543 ?> ระบบติดตามขั้นตอนโครงการ</p>
</footer>
</body>
</html>
<?php $pdo=null; ?>
