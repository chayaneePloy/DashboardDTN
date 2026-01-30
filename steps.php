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
$stmtDetail = $pdo->prepare("
    SELECT detail_name, budget_item_id 
    FROM budget_detail 
    WHERE id_detail = :id_detail
");
$stmtDetail->execute([':id_detail' => $id_detail]);
$project_detail = $stmtDetail->fetch(PDO::FETCH_ASSOC);

$detail_name    = $project_detail['detail_name']    ?? '-';
$budget_item_id = $project_detail['budget_item_id'] ?? null;

// --- ดึงเลขสัญญา และ ผู้รับจ้าง (ถ้ามี) จากตาราง contracts
$contract_stmt = $pdo->prepare("
    SELECT contract_number, contractor_name, contract_date, total_amount
    FROM contracts
    WHERE detail_item_id = :id_detail
    ORDER BY contract_id ASC
    LIMIT 1
");
$contract_stmt->execute([':id_detail' => $id_detail]);
$contract = $contract_stmt->fetch(PDO::FETCH_ASSOC);

// --- ดึงงบที่ขอจาก budget_detail (requested_amount)
$requested = $pdo->prepare("
    SELECT requested_amount
    FROM budget_detail
    WHERE id_detail = :id_detail
    LIMIT 1
");
$requested->execute([':id_detail' => $id_detail]);
$requested_row = $requested->fetch(PDO::FETCH_ASSOC);

// เตรียมตัวแปรสำหรับแสดงผล
$contract_number  = $contract['contract_number']    ?? '-';
$contractor_name  = $contract['contractor_name']    ?? '-';
$contract_date  = $contract['contract_date']    ?? '-';
$contract_total   = isset($contract['total_amount']) ? number_format($contract['total_amount'], 2) : '-';
$requested_amount = isset($requested_row['requested_amount']) ? number_format($requested_row['requested_amount'], 2) : '-';

// ---------------- 9 ขั้นตอนมาตรฐาน ----------------
$defaultSteps = [
    1 => 'ขออนุมัติโครงการ',
    2 => 'แต่งตั้ง คกก.+ร่าง TOR+ราคากลาง',
    3 => 'รายงานขออนุมัติร่าง TOR',
    4 => 'รายงานขอซื้อขอจ้าง + แต่งตั้งคกก จจ/ตร',
    5 => 'ขึ้นประชาพิจารณ์',
    6 => 'ขึ้นประกาศ e-bidding',
    7 => 'ยื่นข้อเสนอและข้อเสนอราคา + คกก. พิจารณา',
    8 => 'ขึ้นประกาศผู้ชนะ (รวมให้อุทธรณ์แล้ว)',
    9 => 'ทำหนังสือเชิญทำสัญญา และทำสัญญาหลังจากได้รับหนังสือ',
];

function getDefaultStepName(int $order, array $defaults): string {
    return $defaults[$order] ?? ('ขั้นตอนที่ '.$order);
}

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

// ---------------- Handle: Update (ไม่ให้สร้าง/ลบเองแล้ว) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update') {
        $id              = (int)($_POST['id'] ?? 0);
        $step_order      = (int)($_POST['step_order'] ?? 0);
        // ชื่อขั้นตอน fix ตามลำดับ
        $step_name       = getDefaultStepName($step_order, $defaultSteps);
        $step_description= trim($_POST['step_description'] ?? '');
        $step_date       = $_POST['step_date'] ?? '';
        $sub_steps       = trim($_POST['sub_steps'] ?? '');
        $is_completed    = isset($_POST['is_completed']) ? 1 : 0;
        $existing_doc    = $_POST['existing_document_path'] ?? null;
        $doc             = handleUpload($_FILES['document_file'] ?? null, $existing_doc);

        // ถ้าไม่ได้เลือกวันที่ ให้ใช้วันที่วันนี้ (พ.ศ.)
        if ($step_date === '' || $step_date === null) {
            $yearCE = (int)date('Y');
            $month  = (int)date('m');
            $day    = (int)date('d');
            $yearTH = $yearCE + 543;
            $step_date = sprintf('%04d-%02d-%02d', $yearTH, $month, $day);
        }

        $sql = "UPDATE project_steps
                   SET step_order      = :step_order,
                       step_name       = :step_name,
                       step_description= :step_description,
                       step_date       = :step_date,
                       sub_steps       = :sub_steps,
                       is_completed    = :is_completed,
                       document_path   = :document_path
                 WHERE id = :id AND $idCol = :id_detail";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':step_order'      => $step_order,
            ':step_name'       => $step_name,
            ':step_description'=> $step_description,
            ':step_date'       => $step_date,
            ':sub_steps'       => $sub_steps,
            ':is_completed'    => $is_completed,
            ':document_path'   => $doc,
            ':id'              => $id,
            ':id_detail'       => $id_detail
        ]);

        header("Location: ".$_SERVER['REQUEST_URI']);
        exit;
    }

    // ถ้าเคยมี action=delete ให้ไม่ทำอะไรแล้ว (กันลบขั้นตอน)
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

// ---------------- ถ้ายังไม่มีขั้นตอนเลย -> สร้าง 9 ขั้นตอนมาตรฐาน ----------------
if (empty($steps) && $id_detail > 0) {
    // วันที่ปัจจุบันแบบ พ.ศ. YYYY-MM-DD
    $today    = new DateTime();
    $yearCE   = (int)$today->format('Y');
    $month    = (int)$today->format('m');
    $day      = (int)$today->format('d');
    $yearTH   = $yearCE + 543;
    $defaultDate = sprintf('%04d-%02d-%02d', $yearTH, $month, $day);

    $insert = $pdo->prepare("
        INSERT INTO project_steps 
            ($idCol, step_order, step_name, step_description, step_date, sub_steps, is_completed, document_path)
        VALUES 
            (:id_detail, :step_order, :step_name, '', :step_date, '', 0, NULL)
    ");
    foreach ($defaultSteps as $order => $name) {
        $insert->execute([
            ':id_detail'  => $id_detail,
            ':step_order' => $order,
            ':step_name'  => $name,
            ':step_date'  => $defaultDate
        ]);
    }

    // ดึงใหม่หลังสร้าง
    $steps_stmt->execute([':id_detail' => $id_detail]);
    $steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------- ฟังก์ชันแปลงวันที่ไทย ----------------
// ---------------- ฟังก์ชันแปลงวันที่ไทย (แสดงเป็น พ.ศ. เสมอ) ----------------
function thai_date($date) {
    if (!$date || $date == '0000-00-00') return '';

    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];

    [$y, $m, $d] = explode('-', $date);
    $y = (int)$y; $m = (int)$m; $d = (int)$d;

    // ถ้าเป็น ค.ศ. (เช่น 2025) -> แปลงเป็น พ.ศ.
    if ($y > 0 && $y < 2400) $y += 543;

    return $d . " " . $months[$m] . " " . $y;
}





// ใช้สำหรับแปลง YYYY-MM-DD (พ.ศ.) -> dd/mm/YYYY (พ.ศ.) แสดงในช่อง input fake
function thai_date_input($date) {
    if (!$date || $date == '0000-00-00') return '';
    [$y, $m, $d] = explode('-', $date);
    return sprintf('%02d/%02d/%04d', (int)$d, (int)$m, (int)$y);
}

// ---------------- คำนวณ progress + current/next step ----------------
$completed = array_sum(array_map(fn($s)=> (int)$s['is_completed'], $steps));
$total     = count($steps);
$percent   = $total > 0 ? round(($completed / $total) * 100) : 0;

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

// ---------------- ดึง phases ----------------
$phase_sql = "
    SELECT p.phase_id, p.phase_number, p.phase_name, p.amount, 
           p.due_date, p.completion_date, p.payment_date, p.status
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    WHERE c.detail_item_id = :id_detail
     ORDER BY p.phase_number ASC
";
$phase_st = $pdo->prepare($phase_sql);
$phase_st->execute([':id_detail' => $id_detail]);
$phases = $phase_st->fetchAll(PDO::FETCH_ASSOC);

// ถ้าใน DB ของ phases ก็เก็บเป็น พ.ศ. เช่นกัน -> ไม่ต้อง +543 แล้ว
function thai_date_full($date) {
if (!$date || $date == '0000-00-00') return '';

    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];

    [$y, $m, $d] = explode('-', $date);
    $y = (int)$y; $m = (int)$m; $d = (int)$d;

    // ถ้าเป็น ค.ศ. (เช่น 2025) -> แปลงเป็น พ.ศ.
    if ($y > 0 && $y < 2400) $y += 543;

    return $d . " " . $months[$m] . " " . $y;
}

$item_id = 0;
$st = $pdo->prepare("SELECT budget_item_id FROM budget_detail WHERE id_detail = :id_detail LIMIT 1");
$st->execute([':id_detail' => $id_detail]);
$item_id = (int)($st->fetchColumn() ?: 0);
// ---------------- ฟังก์ชันแปลงวันที่ไทย (แสดงเป็น พ.ศ. เสมอ) ----------------

// ---------------- Progress งวดงาน (อิง phase_number + status) ----------------

// งวดทั้งหมด (นับจาก phase_number)
$total_phases = count($phases);

$completed_phases = 0;

// สถานะที่ถือว่า "งวดเสร็จแล้ว"
$finished_status = [
    'จ่ายแล้ว',
    'จ่ายครบ',
    'เสร็จสิ้น',
    'เสร็จสิ้นแล้ว'
];

foreach ($phases as $p) {
    if (in_array(trim($p['status']), $finished_status, true)) {
        $completed_phases++;
    }
}
// คำนวณเปอร์เซ็นต์ความก้าวหน้างวด
$phase_percent = ($total_phases > 0)
    ? round(($completed_phases / $total_phases) * 100)
    : 0;

// ---------------- ฟังก์ชันแปลงวันที่เป็น วว/ดด/ปปปป (พ.ศ.) ----------------
function thai_date_ddmmyyyy($date) {
    if (!$date || $date === '0000-00-00') return '-';

    $parts = explode('-', $date);
    if (count($parts) !== 3) return '-';

    [$y, $m, $d] = $parts;

    $y = (int)$y;
    $m = (int)$m;
    $d = (int)$d;

    // กันวันที่ 0
    if ($y === 0 || $m === 0 || $d === 0) return '-';

    // ถ้าเป็น ค.ศ. → แปลงเป็น พ.ศ.
    if ($y < 2400) {
        $y += 543;
    }

    return sprintf('%02d/%02d/%04d', $d, $m, $y);
}




?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบติดตามขั้นตอนโครงการ</title>
    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
    body {
        font-family: 'Kanit', sans-serif;
        background-color: #f4f6f9;
    }

    .navbar {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    }

    .navbar-dark .navbar-nav .nav-link {
        color: #ffffff !important;
        font-weight: 500;
    }

    .navbar-dark .navbar-nav .nav-link:hover {
        color: #ffeb3b !important;
    }

    .navbar-brand {
        color: #ffffff !important;
    }

    .step-card.step-done {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
    }
    </style>
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">

            <!-- Brand -->
            <a class="navbar-brand fw-bold" href="index.php">
                📊Dashboard งบประมาณโครงการ

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
                        <a class="nav-link text-white" href="index.php?id=<?= $item_id ?>">
                            <i class="bi bi-arrow-left"></i> กลับ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-4">

        <!-- Project Overview -->
        <div class="card shadow-lg border-0 mb-4">
            <div class="card-body text-center">
                <h2 class="fw-bold text-primary text-break"><?= htmlspecialchars($detail_name) ?></h2>
                <p class="text-muted">ติดตามความก้าวหน้าของโครงการขั้นตอนจัดซื้อจัดจ้าง</p>

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
            <div class="col-md-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold text-success">✅ ขั้นตอนล่าสุด</h5>
                        <p><?= $current_step ? $current_step['step_order'].'. '.$current_step['step_name'] : 'ยังไม่เริ่มดำเนินการ' ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Timeline Header -->
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="text-secondary mb-0">📌 ขั้นตอนจัดซื้อจัดจ้าง</h4>
            <!-- ❌ ไม่มีปุ่มเพิ่มขั้นตอนแล้ว -->
        </div>

        <!-- Timeline -->
        <div class="timeline-container">
            <?php foreach($steps as $step): ?>
            <div class="card step-card shadow-sm <?= $step['is_completed'] ? 'step-done' : '' ?>">
                <div class="card-body">
                    <h5 class="fw-bold <?= $step['is_completed'] ? 'text-success' : 'text-danger' ?>">
                        <?= (int)$step['step_order'] ?>.
                        <?= htmlspecialchars(getDefaultStepName((int)$step['step_order'], $defaultSteps)) ?>
                    </h5>
                    <span class="badge bg-warning text-dark"><?= thai_date($step['step_date']) ?></span>
                    <p class="mt-2 small text-muted">
                        <?= htmlspecialchars(mb_strimwidth($step['step_description'], 0, 80, '...')) ?>
                    </p>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                            data-bs-target="#stepModal<?= $step['id'] ?>">
                            ดูรายละเอียด
                        </button>

                        <form method="post" action="steps_edit.php?id_detail=<?= $id_detail ?>" style="display:inline;">
                            <input type="hidden" name="toggle_id" value="<?= $step['id'] ?>">
                            <input type="hidden" name="current_state" value="<?= (int)$step['is_completed'] ?>">
                            <button type="submit"
                                class="btn btn-sm <?= $step['is_completed'] ? 'btn-outline-success' : 'btn-success' ?>">
                                <?= $step['is_completed'] ? 'แก้ไข' : 'แก้ไข' ?>
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
                            <h5 class="modal-title">
                                <?= htmlspecialchars(getDefaultStepName((int)$step['step_order'], $defaultSteps)) ?>
                            </h5>
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
                            <a href="documents/<?= htmlspecialchars($step['document_path']) ?>" target="_blank"
                                class="btn btn-sm btn-outline-success">
                                เปิดเอกสาร
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-warning" data-bs-target="#editStepModal<?= $step['id'] ?>"
                                data-bs-toggle="modal">เพิ่มข้อมูล</button>
                            <!-- ❌ ตัดปุ่มลบออก ไม่ให้ลบขั้นตอน -->
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
                            <input type="hidden" name="existing_document_path"
                                value="<?= htmlspecialchars($step['document_path'] ?? '') ?>">

                            <div class="modal-header bg-warning">
                                <h5 class="modal-title">
                                    แก้ไขขั้นตอน:
                                    <?= htmlspecialchars(getDefaultStepName((int)$step['step_order'], $defaultSteps)) ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>

                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">ลำดับ</label>
                                        <input type="number" name="step_order" class="form-control"
                                            value="<?= (int)$step['step_order'] ?>" readonly>
                                    </div>
                                    <div class="col-md-9">
                                        <label class="form-label">ชื่อขั้นตอน</label>
                                        <input type="text" class="form-control"
                                            value="<?= htmlspecialchars(getDefaultStepName((int)$step['step_order'], $defaultSteps)) ?>"
                                            readonly>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="d-flex align-items-center gap-3">
                                            <label class="form-label mb-0">สถานะ</label>

                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" name="is_completed"
                                                    id="done<?= $step['id'] ?>"
                                                    <?= $step['is_completed'] ? 'checked':'' ?>>

                                                <label class="form-check-label"
                                                    for="done<?= $step['id'] ?>">เสร็จแล้ว</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">รายละเอียด</label>
                                        <textarea name="step_description" class="form-control"
                                            rows="3"><?= htmlspecialchars($step['step_description']) ?></textarea>
                                    </div>

                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-secondary" data-bs-target="#stepModal<?= $step['id'] ?>"
                                    data-bs-toggle="modal">กลับ</button>
                                <button type="submit" class="btn btn-warning">บันทึกการแก้ไข</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php endforeach; ?>
        </div>

    </div>

    <!-- ✅ ตาราง phases -->
    <div class="container">
        <!-- Timeline Header -->
        <div class="d-flex align-items-center justify-content-between ">
            <h4 class="text-secondary mb-0">📍 ขั้นตอนตรวจรับ</h4>
            <!-- ❌ ไม่มีปุ่มเพิ่มขั้นตอนแล้ว -->
        </div>
     <div class="row mb-4">
            <div class="col-md-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                                               
                        <div class="d-flex  justify-content-between align-items-center mb-2">
                <span class="fw-semibold text-secondary">
                    ความก้าวหน้างวดงาน
                </span>
                <span class="badge bg-green-600 fs-6 px-3">
                    <?= $completed_phases ?>/<?= $total_phases ?> งวด
                </span>
            </div>
            <div class="progress" style="height: 20px;">
                <div
                    class="progress-bar bg-success fw-bold progress-bar-striped progress-bar-animated"
                    role="progressbar"
                    style="width: <?= $phase_percent ?>%;"
                    aria-valuenow="<?= $phase_percent ?>"
                    aria-valuemin="0"
                    aria-valuemax="100">
                    <?= $phase_percent ?>%
                </div>
            </div>
                    </div>
                </div>
            </div>
        </div>
       
        <div class="card shadow-sm mt-3">
            <div class="card-header bg-green-700 text-white fw-bold">
                <div>💰 งวดงานของโครงการ </div>
                <div class="mt-1 small ">
                    <strong>เลขสัญญา:</strong> <?= htmlspecialchars($contract_number) ?> &nbsp;|&nbsp;
                    <strong>ผู้รับจ้าง:</strong> <?= htmlspecialchars($contractor_name) ?> &nbsp;|&nbsp;
                   <strong>วันที่:</strong><?= $contract_date ? thai_date_ddmmyyyy($contract_date) : '-' ?>&nbsp;|&nbsp;

                    <strong>งบที่ขอ:</strong> <?= $requested_amount ?> บาท
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered table-striped m-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>งวดที่</th>
                            <th>วันที่เริ่ม </th>
                            <th>วันที่สิ้นสุด</th>
                            <th>วันที่จ่าย </th>
                            <th>จำนวนเงิน(บาท)</th>
                            <th>สถานะ</th>
                            <th>การดำเนินการ</th>
                            <th>แก้ไข</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($phases): foreach($phases as $p): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($p['phase_number']) ?>

                            </td>
                            <td><?= thai_date_full($p['due_date']) ?></td>
                            <td><?= thai_date_full($p['completion_date']) ?></td>
                            <td><?= thai_date_full($p['payment_date']) ?></td>
                            <td class="text-end"><?= number_format($p['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($p['status']) ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($p['phase_name'], 0, 30, '...')) ?></td>
                            <td>
                                <a href="edit_phase.php?phase_id=<?= $p['phase_id'] ?>" class="btn btn-sm btn-warning">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7" class="text-muted">ไม่มีข้อมูลงวดงานของโครงการนี้</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>



    
    
   
    </div>

</div>

     



    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3 mt-5">
        <p>&copy; <?= date('Y')+543 ?> ระบบติดตามขั้นตอนโครงการ</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // ผูก date-picker (ค.ศ.) + hidden (พ.ศ.) + textbox (พ.ศ.)
    document.addEventListener('DOMContentLoaded', function() {

        function bindThaiDate(pickerId, realId, fakeId) {
            const picker = document.getElementById(pickerId); // <input type="date"> (ค.ศ.)
            const real = document.getElementById(realId); // hidden พ.ศ. YYYY-MM-DD
            const fake = document.getElementById(fakeId); // textbox แสดง dd/mm/YYYY (พ.ศ.)

            if (!picker || !real || !fake) return;

            // คลิกช่อง fake => เปิดปฏิทินของ picker
            fake.addEventListener('click', function() {
                if (typeof picker.showPicker === 'function') {
                    picker.showPicker();
                } else {
                    picker.focus();
                }
            });

            // เมื่อเลือกวันที่ในปฏิทิน (ได้ ค.ศ.)
            picker.addEventListener('change', function() {
                if (!picker.value) {
                    real.value = "";
                    fake.value = "";
                    return;
                }
                const d = new Date(picker.value);
                if (isNaN(d)) return;

                const dayCE = String(d.getDate()).padStart(2, '0');
                const monthCE = String(d.getMonth() + 1).padStart(2, '0');
                const yearCE = d.getFullYear();
                const yearTH = yearCE + 543;

                // hidden: เก็บ พ.ศ. YYYY-MM-DD
                real.value = `${yearTH}-${monthCE}-${dayCE}`;

                // ช่องแสดง: dd/mm/YYYY (พ.ศ.)
                fake.value = `${dayCE}/${monthCE}/${yearTH}`;
            });

            // ถ้ามีค่า พ.ศ. ใน hidden อยู่แล้ว -> sync กลับให้ picker/fake
            if (real.value) {
                const parts = real.value.split('-'); // [YYYY(TH), MM, DD]
                if (parts.length === 3) {
                    let yTH = parseInt(parts[0], 10);
                    let m = parseInt(parts[1], 10);
                    let d = parseInt(parts[2], 10);
                    if (!isNaN(yTH) && !isNaN(m) && !isNaN(d)) {
                        const yCE = yTH - 543;
                        const mm = String(m).padStart(2, '0');
                        const dd = String(d).padStart(2, '0');
                        picker.value = `${yCE}-${mm}-${dd}`;
                        fake.value = `${dd}/${mm}/${yTH}`;
                    }
                }
            }
        }

        // bind สำหรับแต่ละขั้นตอน (แก้ไข)
        <?php foreach ($steps as $step): ?>
        bindThaiDate('step_date_picker_<?= $step['id'] ?>',
            'step_date_real_<?= $step['id'] ?>',
            'step_date_fake_<?= $step['id'] ?>');
        <?php endforeach; ?>
    });
    </script>


</body>

</html>
<?php $pdo = null; ?>