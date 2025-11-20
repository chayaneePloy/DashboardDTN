<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

// ดึงชื่อโครงการจาก budget_detail
$stmtDetail = $pdo->prepare("SELECT detail_name FROM budget_detail WHERE id_detail = :id_detail");
$stmtDetail->execute([':id_detail' => $id_detail]);
$project_detail = $stmtDetail->fetch(PDO::FETCH_ASSOC);
$detail_name = $project_detail ? $project_detail['detail_name'] : '-';

// ดึงข้อมูลขั้นตอนโครงการ
$steps_stmt = $pdo->prepare("
    SELECT id, step_order, step_name, step_date, step_description, sub_steps, document_path, is_completed, id_budget_detail
    FROM project_steps
    WHERE id_budget_detail = :id_detail
    ORDER BY step_order
");
$steps_stmt->execute([':id_detail' => $id_detail]);
$steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

// ฟังก์ชันแปลงวันที่ไทย
function thai_date($date) {
    if (!$date) return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    return date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts) + 543);
}

// คำนวณ progress
$completed = array_sum(array_column($steps, 'is_completed'));
$total = count($steps);
$percent = $total > 0 ? round(($completed / $total) * 100) : 0;

// ขั้นตอนล่าสุดที่เสร็จแล้ว
$current_stmt = $pdo->prepare("
    SELECT step_order, step_name 
    FROM project_steps 
    WHERE id_budget_detail = :id_detail AND is_completed = 1
    ORDER BY step_order DESC 
    LIMIT 1
");
$current_stmt->execute([':id_detail' => $id_detail]);
$current_step = $current_stmt->fetch(PDO::FETCH_ASSOC);

// ขั้นตอนถัดไป
$next_stmt = $pdo->prepare("
    SELECT step_order, step_name 
    FROM project_steps 
    WHERE id_budget_detail = :id_detail AND is_completed = 0
    ORDER BY step_order ASC 
    LIMIT 1
");
$next_stmt->execute([':id_detail' => $id_detail]);
$next_step = $next_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ระบบติดตามขั้นตอนโครงการ</title>
  <link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
      body {
          font-family: 'Kanit', sans-serif;
          background-color: #f4f6f9;
      }
      .navbar {
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      }
      .timeline-container {
          display: flex;
          overflow-x: auto;
          gap: 1rem;
          padding-bottom: 1rem;
      }
      .timeline-container::-webkit-scrollbar {
          height: 8px;
      }
      .timeline-container::-webkit-scrollbar-thumb {
          background: #bbb;
          border-radius: 4px;
      }
      .step-card {
          min-width: 280px;
          flex-shrink: 0;
          border: none;
          transition: transform 0.2s;
      }
      .step-card:hover {
          transform: translateY(-4px);
      }
      footer {
          box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
      }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#">📊 ระบบติดตามโครงการ</a>

    <div class="ms-auto">
           <!-- ปุ่มกลับหน้าหลัก -->
              <a href="index.php" class="btn btn-light back-btn">
                  <i class="bi bi-house"></i> หน้าหลัก
              </a>

            <!-- ปุ่มกลับหน้าก่อนหน้า -->
            <a href="javascript:history.back()" class="btn btn-light back-btn">
                <i class="bi bi-arrow-left"></i> กลับหน้าก่อนหน้า
            </a>
           
    </div>
  </div>
</nav>

<div class="container my-4">

  <!-- Project Overview -->
  <div class="card shadow-lg border-0 mb-4">
    <div class="card-body text-center">
      <h2 class="fw-bold text-primary"><?= htmlspecialchars($detail_name) ?></h2>
      <p class="text-muted">ติดตามความก้าวหน้าของโครงการ</p>

      <!-- Progress -->
      <div class="progress my-3" style="height: 22px;">
        <div class="progress-bar bg-success fw-bold" role="progressbar"
             style="width: <?= $percent ?>%;">
             <?= $percent ?>%
        </div>
      </div>
      <small class="text-secondary">ดำเนินการแล้ว <?= $completed ?>/<?= $total ?> ขั้นตอน</small>
    </div>
  </div>

  <!-- Current/Next Step -->
  <div class="row mb-4">
    <div class="col-md-6">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <h5 class="fw-bold text-success">✅ ขั้นตอนล่าสุดที่เสร็จแล้ว</h5>
          <p>
            <?= $current_step 
                ? $current_step['step_order'].'. '.$current_step['step_name'] 
                : 'ยังไม่เริ่มดำเนินการ' ?>
          </p>
        </div>
      </div>
    </div>
    <div class="col-md-6 mt-3 mt-md-0">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <h5 class="fw-bold text-warning">⏳ ขั้นตอนถัดไป</h5>
          <p>
            <?= $next_step 
                ? $next_step['step_order'].'. '.$next_step['step_name'] 
                : 'โครงการเสร็จสิ้นแล้ว' ?>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- Timeline -->
  <h4 class="mb-3 text-secondary">📌 ขั้นตอนการดำเนินงาน</h4>
  <div class="container">
    <?php foreach($steps as $step): ?>
    <div class="card step-card shadow-sm  card mb-3  <?= $step['is_completed'] ? 'bg-light' : '' ?>">
      <div class="card mb-3 border-success ">
        <div class='card-body'>
        <h5 class="fw-bold <?= $step['is_completed'] ? 'text-success' : 'text-dark' ?>">
          <?= $step['step_order'] ?>. <?= $step['step_name'] ?>
        </h5>
          <div class="ms-auto">
             <span class="badge bg-warning text-dark  "><?= thai_date($step['step_date']) ?></span>
            </div>
            <p class="mt-2 small text-muted">
          <?= mb_strimwidth($step['step_description'], 0, 80, '...') ?>
        </p>
        <div class="d-flex mb-4">
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#stepModal<?= $step['id'] ?>">
            ดูรายละเอียด <br/>

          </button>

          <!-- ปุ่มสลับสถานะแบบเร็ว (กดแล้วจะส่งไปยัง steps_edit.php เพื่ออัปเดตแบบง่าย) -->
        
        </div>
      </div>
      </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="stepModal<?= $step['id'] ?>" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><?= $step['step_name'] ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p><strong>วันที่:</strong> <?= thai_date($step['step_date']) ?></p>
            <p><?= nl2br($step['step_description']) ?></p>
            <?php if(!empty($step['sub_steps'])): ?>
              <div class="alert alert-info">
                <strong>ขั้นตอนย่อย:</strong><br>
                <?= nl2br($step['sub_steps']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<!-- Footer -->
<footer class="bg-dark text-white text-center py-3 mt-5">
  <p>&copy; <?= date('Y')+543 ?> ระบบติดตามขั้นตอนโครงการ</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $pdo = null; ?>
