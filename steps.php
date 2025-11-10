<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --------- helper: ตรวจชื่อคอลัมน์อ้างอิงโครงการใน project_steps (กันสะกดต่าง) ----------
function detectIdDetailColumn(PDO $pdo): string {
    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'project_steps' 
              AND COLUMN_NAME = :col";
    $chk = $pdo->prepare($sql);
    foreach (['id_budget_detail','id_butget_detail'] as $c) {
        $chk->execute([':col'=>$c]);
        if ($chk->fetchColumn() > 0) return $c;
    }
    // fallback; ใช้ชื่อที่คุณใช้ในโค้ดเดิม
    return 'id_budget_detail';
}
$idCol = detectIdDetailColumn($pdo);

$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

// ---------------- ดึงชื่อโครงการจาก budget_detail ----------------
$stmtDetail = $pdo->prepare("SELECT detail_name FROM budget_detail WHERE id_detail = :id_detail");
$stmtDetail->execute([':id_detail' => $id_detail]);
$project_detail = $stmtDetail->fetch(PDO::FETCH_ASSOC);
$detail_name = $project_detail ? $project_detail['detail_name'] : '-';

// ---------------- ฟังก์ชันอัปโหลดไฟล์เอกสาร (ถ้ามี) ----------------
function handleUpload(?array $file, ?string $old = null): ?string {
    if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return $old; // ไม่อัปโหลดใหม่ -> ใช้ของเดิม
    }
    $dir = __DIR__ . '/documents';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $safe = preg_replace('/[^A-Za-z0-9._-]/','_', $file['name']);
    $name = time() . '_' . $safe;
    $dest = $dir . '/' . $name;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $name; // เก็บเฉพาะชื่อไฟล์ (โฟลเดอร์อ้างในลิงก์)
    }
    return $old;
}

// ---------------- Handle: Create / Update / Delete ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create') {
        $step_order = (int)($_POST['step_order'] ?? 0);
        $step_name  = trim($_POST['step_name'] ?? '');
        $step_description = trim($_POST['step_description'] ?? '');
        $step_date  = $_POST['step_date'] ?? null;
        $sub_steps  = trim($_POST['sub_steps'] ?? '');
        $is_completed = isset($_POST['is_completed']) ? 1 : 0;
        $doc = handleUpload($_FILES['document_file'] ?? null, null);

        $sql = "INSERT INTO project_steps ($idCol, step_order, step_name, step_description, step_date, sub_steps, is_completed, document_path)
                VALUES (:id_detail, :step_order, :step_name, :step_description, :step_date, :sub_steps, :is_completed, :document_path)";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':id_detail' => $id_detail,
            ':step_order'=> $step_order,
            ':step_name' => $step_name,
            ':step_description' => $step_description,
            ':step_date' => $step_date,
            ':sub_steps' => $sub_steps,
            ':is_completed' => $is_completed,
            ':document_path' => $doc
        ]);

        header("Location: ".$_SERVER['REQUEST_URI']); exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $step_order = (int)($_POST['step_order'] ?? 0);
        $step_name  = trim($_POST['step_name'] ?? '');
        $step_description = trim($_POST['step_description'] ?? '');
        $step_date  = $_POST['step_date'] ?? null;
        $sub_steps  = trim($_POST['sub_steps'] ?? '');
        $is_completed = isset($_POST['is_completed']) ? 1 : 0;
        $existing_doc = $_POST['existing_document_path'] ?? null;
        $doc = handleUpload($_FILES['document_file'] ?? null, $existing_doc);

        $sql = "UPDATE project_steps
                   SET step_order = :step_order,
                       step_name = :step_name,
                       step_description = :step_description,
                       step_date = :step_date,
                       sub_steps = :sub_steps,
                       is_completed = :is_completed,
                       document_path = :document_path
                 WHERE id = :id AND $idCol = :id_detail";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':step_order'=> $step_order,
            ':step_name' => $step_name,
            ':step_description' => $step_description,
            ':step_date' => $step_date,
            ':sub_steps' => $sub_steps,
            ':is_completed' => $is_completed,
            ':document_path' => $doc,
            ':id' => $id,
            ':id_detail' => $id_detail
        ]);

        header("Location: ".$_SERVER['REQUEST_URI']); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $sql = "DELETE FROM project_steps WHERE id = :id AND $idCol = :id_detail";
        $st = $pdo->prepare($sql);
        $st->execute([':id'=>$id, ':id_detail'=>$id_detail]);

        header("Location: ".$_SERVER['REQUEST_URI']); exit;
    }
}

// ---------------- ดึงข้อมูลขั้นตอนโครงการ ----------------
$steps_stmt = $pdo->prepare("
    SELECT id, step_order, step_name, step_date, step_description, sub_steps, document_path, is_completed
    FROM project_steps
    WHERE $idCol = :id_detail
    ORDER BY step_order
");
$steps_stmt->execute([':id_detail' => $id_detail]);
$steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

// next default order สำหรับ modal เพิ่ม
$nextOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(step_order),0)+1 AS nxt FROM project_steps WHERE $idCol = :id_detail");
$nextOrderStmt->execute([':id_detail'=>$id_detail]);
$next_order = (int)$nextOrderStmt->fetchColumn();

// ---------------- ฟังก์ชันแปลงวันที่ไทย ----------------
function thai_date($date) {
    if (!$date) return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    return date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts));
}

// ---------------- คำนวณ progress + current/next step ----------------
$completed = array_sum(array_map(fn($s)=> (int)$s['is_completed'], $steps));
$total = count($steps);
$percent = $total > 0 ? round(($completed / $total) * 100) : 0;

$current_stmt = $pdo->prepare("
    SELECT step_order, step_name
    FROM project_steps
    WHERE $idCol = :id_detail AND is_completed = 1
    ORDER BY step_order DESC
    LIMIT 1
");
$current_stmt->execute([':id_detail' => $id_detail]);
$current_step = $current_stmt->fetch(PDO::FETCH_ASSOC);

$next_stmt = $pdo->prepare("
    SELECT step_order, step_name
    FROM project_steps
    WHERE $idCol = :id_detail AND is_completed = 0
    ORDER BY step_order ASC
    LIMIT 1
");
$next_stmt->execute([':id_detail' => $id_detail]);
$next_step = $next_stmt->fetch(PDO::FETCH_ASSOC);
?>
<?php
$phase_sql = "
    SELECT p.phase_id, p.phase_name, p.amount, p.due_date, p.completion_date, p.payment_date, p.status
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    WHERE c.detail_item_id = :id_detail
    ORDER BY CAST(REGEXP_SUBSTR(p.phase_name, '[0-9]+') AS UNSIGNED) ASC, p.phase_id ASC
";

$phase_st = $pdo->prepare($phase_sql);
$phase_st->execute([':id_detail' => $id_detail]);
$phases = $phase_st->fetchAll(PDO::FETCH_ASSOC);

function thai_date_full($date) {
    if (!$date || $date == '0000-00-00') return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    return date('j', $ts)." ".$months[date('n', $ts)]." ".(date('Y', $ts)+543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ระบบติดตามขั้นตอนโครงการ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
      body { font-family: 'Kanit', sans-serif; background-color: #f4f6f9; }
      .navbar { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
      .timeline-container { display: flex; overflow-x: auto; gap: 1rem; padding-bottom: 1rem; }
      .timeline-container::-webkit-scrollbar { height: 8px; }
      .timeline-container::-webkit-scrollbar-thumb { background: #bbb; border-radius: 4px; }
      .step-card { min-width: 280px; flex-shrink: 0; border: none; transition: transform 0.2s; }
      .step-card:hover { transform: translateY(-4px); }
      footer { box-shadow: 0 -2px 10px rgba(0,0,0,0.1); }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#">📊 ระบบติดตามโครงการ</a>
    <div class="ms-auto d-flex gap-2">
      <a href="steps_edit.php?id_detail=<?= $id_detail ?>" class="btn btn-light">⚙️ จัดการขั้นตอน</a>
      <a href="index.php" class="btn btn-light"><i class="bi bi-house"></i> หน้าหลัก</a>
      <a href="javascript:history.back()" class="btn btn-light"><i class="bi bi-arrow-left"></i> กลับ</a>
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
        <div class="progress-bar bg-success fw-bold" role="progressbar" style="width: <?= $percent ?>%;">
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
          <p><?= $current_step ? $current_step['step_order'].'. '.$current_step['step_name'] : 'ยังไม่เริ่มดำเนินการ' ?></p>
        </div>
      </div>
    </div>
    <div class="col-md-6 mt-3 mt-md-0">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <h5 class="fw-bold text-warning">⏳ ขั้นตอนถัดไป</h5>
          <p><?= $next_step ? $next_step['step_order'].'. '.$next_step['step_name'] : 'โครงการเสร็จสิ้นแล้ว' ?></p>
        </div>
      </div>
    </div>
  </div>

  <!-- Timeline Header + Add Button -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="text-secondary mb-0">📌 ขั้นตอนการดำเนินงาน</h4>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStepModal">
      <i class="bi bi-plus-circle"></i> เพิ่มขั้นตอน
    </button>
  </div>

  <!-- Timeline -->
  <div class="timeline-container">
    <?php foreach($steps as $step): ?>
    <div class="card step-card shadow-sm <?= $step['is_completed'] ? 'bg-light' : '' ?>">
      <div class="card-body">
        <h5 class="fw-bold <?= $step['is_completed'] ? 'text-success' : 'text-danger' ?>">
          <?= (int)$step['step_order'] ?>. <?= htmlspecialchars($step['step_name']) ?>
        </h5>
        <span class="badge bg-warning text-dark"><?= thai_date($step['step_date']) ?></span>
        <p class="mt-2 small text-muted">
          <?= htmlspecialchars(mb_strimwidth($step['step_description'], 0, 80, '...')) ?>
        </p>
        <div class="d-flex gap-2">
          <!-- ดูรายละเอียด -->
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#stepModal<?= $step['id'] ?>">
            ดูรายละเอียด
          </button>

          <!-- ปุ่มสลับสถานะ (ของเดิม) -->
          <form method="post" action="steps_edit.php?id_detail=<?= $id_detail ?>" style="display:inline;">
            <input type="hidden" name="toggle_id" value="<?= $step['id'] ?>">
            <input type="hidden" name="current_state" value="<?= (int)$step['is_completed'] ?>">
            <button type="submit" class="btn btn-sm <?= $step['is_completed'] ? 'btn-outline-success' : 'btn-success' ?>">
              <?= $step['is_completed'] ? 'ทำซ้ำเป็นยังไม่เสร็จ' : 'ทำเครื่องหมายเสร็จ' ?>
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal: ดูรายละเอียด -->
    <div class="modal fade" id="stepModal<?= $step['id'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><?= htmlspecialchars($step['step_name']) ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p><strong>ลำดับ:</strong> <?= (int)$step['step_order'] ?></p>
            <p><strong>วันที่:</strong> <?= thai_date($step['step_date']) ?></p>
            <p><?= nl2br(htmlspecialchars($step['step_description'])) ?></p>
            <?php if(!empty($step['sub_steps'])): ?>
              <div class="alert alert-info">
                <strong>ขั้นตอนย่อย:</strong><br>
                <?= nl2br(htmlspecialchars($step['sub_steps'])) ?>
              </div>
            <?php endif; ?>
            <?php if(!empty($step['document_path'])): ?>
              <a href="documents/<?= htmlspecialchars($step['document_path']) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                เปิดเอกสาร
              </a>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
               <button class="btn btn-warning" data-bs-target="#editStepModal<?= $step['id'] ?>" data-bs-toggle="modal">แก้ไข</button>
               <button class="btn btn-danger" data-bs-target="#deleteStepModal<?= $step['id'] ?>" data-bs-toggle="modal">ลบ</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: แก้ไข -->
    <div class="modal fade" id="editStepModal<?= $step['id'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $step['id'] ?>">
            <input type="hidden" name="existing_document_path" value="<?= htmlspecialchars($step['document_path'] ?? '') ?>">
            <div class="modal-header bg-warning">
              <h5 class="modal-title">แก้ไขขั้นตอน: <?= htmlspecialchars($step['step_name']) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">ลำดับ</label>
                  <input type="number" name="step_order" class="form-control" value="<?= (int)$step['step_order'] ?>" required>
                </div>
                <div class="col-md-9">
                  <label class="form-label">ชื่อขั้นตอน</label>
                  <input type="text" name="step_name" class="form-control" value="<?= htmlspecialchars($step['step_name']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">วันที่</label>
                  <input type="date" name="step_date" class="form-control" value="<?= htmlspecialchars($step['step_date']) ?>" required>
                </div>
                <div class="col-md-8 d-flex align-items-center">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_completed" id="done<?= $step['id'] ?>" <?= $step['is_completed'] ? 'checked':'' ?>>
                    <label class="form-check-label" for="done<?= $step['id'] ?>">เสร็จแล้ว</label>
                  </div>
                </div>
                <div class="col-12">
                  <label class="form-label">รายละเอียด</label>
                  <textarea name="step_description" class="form-control" rows="3" required><?= htmlspecialchars($step['step_description']) ?></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">ขั้นตอนย่อย</label>
                  <textarea name="sub_steps" class="form-control" rows="3"><?= htmlspecialchars($step['sub_steps']) ?></textarea>
                </div>
                <div class="col-md-12">
                  <label class="form-label">เอกสาร (อัปโหลดใหม่เพื่อแทนที่)</label>
                  <input type="file" name="document_file" class="form-control">
                  <?php if(!empty($step['document_path'])): ?>
                    <div class="form-text">ไฟล์ปัจจุบัน: <?= htmlspecialchars($step['document_path']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-secondary" data-bs-target="#stepModal<?= $step['id'] ?>" data-bs-toggle="modal">กลับ</button>
              <button type="submit" class="btn btn-warning">บันทึกการแก้ไข</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <!-- Modal: ลบ -->
    <div class="modal fade" id="deleteStepModal<?= $step['id'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form method="post">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $step['id'] ?>">
            <div class="modal-header bg-danger text-white">
              <h5 class="modal-title">ยืนยันการลบ</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              ต้องการลบขั้นตอน "<strong><?= htmlspecialchars($step['step_name']) ?></strong>" จริงหรือไม่?
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
              <button type="submit" class="btn btn-danger">ลบ</button>
            </div>
          </form>
        </div>
      </div>
    </div>

  
    <?php endforeach; ?>
  </div>

</div>

<!-- Modal: เพิ่มขั้นตอน -->

<div class="modal fade" id="addStepModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title">เพิ่มขั้นตอนใหม่</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">ลำดับ</label>
              <input type="number" name="step_order" class="form-control" value="<?= $next_order ?>" required>
            </div>
            <div class="col-md-9">
              <label class="form-label">ชื่อขั้นตอน</label>
              <input type="text" name="step_name" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">วันที่</label>
              <input type="date" name="step_date" class="form-control" required>
            </div>
            <div class="col-md-8 d-flex align-items-center">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="is_completed" id="add_done">
                <label class="form-check-label" for="add_done">เสร็จแล้ว</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">รายละเอียด</label>
              <textarea name="step_description" class="form-control" rows="3" required></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">ขั้นตอนย่อย</label>
              <textarea name="sub_steps" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-12">
              <label class="form-label">เอกสาร (ถ้ามี)</label>
              <input type="file" name="document_file" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn btn-success">บันทึก</button>
        </div>
      </form>
    </div>
    </div>
</div>
<!-- ✅ ตาราง phases ที่เพิ่มใหม่ -->
<div class="container">
<div class="card shadow-sm mt-5">
  <div class="card-header bg-success text-white fw-bold">💰 งวดงานของโครงการ (Phases)</div>
  <div class="card-body p-0">
    <table class="table table-bordered table-striped m-0 text-center align-middle">
      <thead class="table-light">
        <tr>
          <th>งวด/ชื่อ</th>
          <th>Due Date (พ.ศ.)</th>
          <th>Completion Date (พ.ศ.)</th>
          <th>วันที่จ่าย (พ.ศ.)</th>
          <th>จำนวนเงิน (บาท)</th>
          <th>สถานะ</th>
          <th>แก้ไข</th>
        </tr>
      </thead>
      <tbody>
      <?php if($phases): foreach($phases as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['phase_name']) ?></td>
          <td><?= thai_date_full($p['due_date']) ?></td>
          <td><?= thai_date_full($p['completion_date']) ?></td>
          <td><?= thai_date_full($p['payment_date']) ?></td>
          <td class="text-end"><?= number_format($p['amount'],2) ?></td>
          <td><?= htmlspecialchars($p['status']) ?></td>
          <td>
            <a href="edit_phase.php?phase_id=<?= $p['phase_id'] ?>" class="btn btn-sm btn-warning">
              <i class="bi bi-pencil-square"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="7" class="text-muted">ไม่มีข้อมูลงวดงานของโครงการนี้</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
</div> <!-- ปิด container -->



<!-- Footer -->
<footer class="bg-dark text-white text-center py-3 mt-5">
  <p>&copy; <?= date('Y')+543 ?> ระบบติดตามขั้นตอนโครงการ</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $pdo = null; ?>
