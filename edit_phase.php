<?php
session_start(); // ✅ ต้องมี
// ===================== CONNECT =====================

include 'db.php';

function toThaiDisplay($dateStr){
    if (!$dateStr) return '';

    $parts = explode('-', $dateStr);
    if (count($parts) !== 3) return '';

    $y = (int)$parts[0] + 543; // 🔥 แปลงเป็น พ.ศ.
    $m = (int)$parts[1];
    $d = (int)$parts[2];

    return sprintf('%02d/%02d/%04d', $d, $m, $y);
}
function toDBDate($dateStr){
    if (!$dateStr) return null;

    $parts = explode('/', $dateStr);
    if (count($parts) !== 3) return null;

    $d = (int)$parts[0];
    $m = (int)$parts[1];
    $y = (int)$parts[2];

    // 🔥 ถ้าเป็น พ.ศ. → แปลงเป็น ค.ศ.
    if ($y > 2400) {
        $y -= 543;
    }

    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}
// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$allowedStatus = ['รอดำเนินการ','อยู่ระหว่างดำเนินการ','เสร็จสิ้น','ยกเลิก'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];


// ===================== LOAD PARAMS =====================
$phase_id   = $_GET['phase_id'] ?? $_POST['phase_id'] ?? null;
$return_url = $_GET['return']    ?? $_POST['return_url'] ?? '';

if (!$phase_id || !ctype_digit((string)$phase_id)) {
    http_response_code(400);
    exit("ไม่ระบุงวดงาน (phase_id) ถูกต้อง");
}

$successMsg = $errorMsg = "";
$saveSuccess = false;
$redirectUrl = '';

// ===================== HANDLE POST (UPDATE/DELETE) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจ CSRF
    $csrf_ok = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if (!$csrf_ok) {
        http_response_code(403);
        exit("CSRF token ไม่ถูกต้อง");
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        // ====== DELETE PHASE ======
        try {
            $stmt = $pdo->prepare("DELETE FROM phases WHERE phase_id = ?");
            $stmt->execute([$phase_id]);

            if ($return_url) {
                header("Location: ".$return_url.(str_contains($return_url,'?') ? '&' : '?')."deleted=1");
                exit;
            } else {
                $successMsg = "ลบงวดงานเรียบร้อยแล้ว";
            }
        } catch (Throwable $e) {
            $errorMsg = "ไม่สามารถลบงวดงานได้: ".$e->getMessage();
        }

    } else {
        // ====== SAVE (UPDATE) ======
        $phase_number    = $_POST['phase_number'] ?? '';
        $phase_name      = trim($_POST['phase_name'] ?? '');
        $amountInput     = str_replace([',',' '], '', $_POST['amount'] ?? '0');
        $due_date        = toDBDate($_POST['due_date'] ?? '');
        $completion_date = toDBDate($_POST['completion_date'] ?? '');
        $payment_date    = toDBDate($_POST['payment_date'] ?? '');
        $status          = $_POST['status'] ?? $allowedStatus[0];

        $phase_number = (int)$phase_number;
        if (!is_numeric($amountInput)) {
            $errorMsg = "จำนวนเงินไม่ถูกต้อง";
        } else {
            $amount = (float)$amountInput;
            if ($amount < 0) $errorMsg = "จำนวนเงินต้องเป็นค่าบวก";
        }
        if (!in_array($status, $allowedStatus, true)) {
            $status = $allowedStatus[0];
        }

        // แนะนำ: due_date <= completion_date
        if (!$errorMsg && $due_date && $completion_date && ($due_date > $completion_date)) {
            $errorMsg = "Due Date ต้องไม่เกิน Completion Date";
        }

        if (!$errorMsg) {
            $stmt = $pdo->prepare("
                UPDATE phases
                SET phase_number = ?,
                    phase_name = ?,
                    amount = ?,
                    due_date = ?,
                    completion_date = ?,
                    status = ?,
                    payment_date = ?
                WHERE phase_id = ?
            ");
            $stmt->execute([
                $phase_number,
                $phase_name,
                $amount,
                $due_date,
                $completion_date,
                $status,
                $payment_date,
                $phase_id
            ]);
            $saveSuccess = true;

        }
    }
}

// ===================== LOAD CURRENT DATA =====================
// ดึง id_detail มาด้วย เพื่อใช้สร้างลิงก์กลับไป steps.php?id_detail=...
$stmt = $pdo->prepare("
    SELECT 
        p.phase_id, p.contract_detail_id, p.phase_number, p.phase_name, p.amount,
        p.due_date, p.completion_date, p.status, p.payment_date,
        c.contract_number, c.contractor_name,
        bd.id_detail, bd.detail_name,
        bi.id AS item_id,          
        bi.item_name, 
        bi.fiscal_year

    FROM phases p
    JOIN contracts c      ON p.contract_detail_id = c.contract_id
    JOIN budget_detail bd ON c.detail_item_id     = bd.id_detail
    JOIN budget_items bi  ON bd.budget_item_id    = bi.id
    WHERE p.phase_id = ?
");
$stmt->execute([$phase_id]);
$phase = $stmt->fetch(PDO::FETCH_ASSOC);
// ===================== BUILD REDIRECT URL AFTER LOAD DATA =====================
if ($saveSuccess && $phase) {
    $redirectUrl = 'dashboard_report.php?' . http_build_query([
        'year'    => $phase['fiscal_year'],
        'item'    => $phase['item_id'],
        'project' => $phase['id_detail'],
    ]);
}


if (!$phase) {
    if ($successMsg) {
        $phase = [
            'phase_id' => $phase_id,
            'fiscal_year' => '',
            'item_name' => '',
            'detail_name' => '',
            'contract_number' => '',
            'contractor_name' => '',
            'phase_number' => '',
            'phase_name' => '',
            'amount' => 0,
            'due_date' => '',
            'completion_date' => '',
            'status' => '',
            'payment_date' => '',
            'id_detail' => ''
        ];
    } else {
        http_response_code(404);
        exit("ไม่พบนงวดงานที่ต้องการแก้ไข");
    }
}

// ===================== สร้าง URL สำหรับปุ่มกลับ =====================
// ถ้ามี return_url ให้ใช้ก่อน ถ้าไม่มีก็ไป steps.php?id_detail=...
$stepsUrl = '';
if (!empty($phase['id_detail'])) {
    $stepsUrl = 'steps.php?id_detail=' . urlencode($phase['id_detail']);
}

$backUrl = $return_url ?: $stepsUrl ?: 'dashboard_report.php';

// ===================== VIEW =====================
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>แก้ไขงวดงาน #<?= h($phase['phase_id']) ?></title>
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
    body {
        font-family: 'Sarabun', sans-serif;
    }

    .form-section-title {
        font-weight: 700;
        color: #0d6efd;
    }

    .number {
        text-align: right;
    }
    </style>
</head>

<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">
                📊 Dashboard การจ่ายงวด
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link text-white" href="index.php">
                            <i class="bi bi-house"></i> หน้าหลัก
                        </a>
                    </li>
                    <li class="nav-item">
                        <!-- 🔁 ปุ่มกลับ: ไป steps.php?id_detail=... หรือ return_url ถ้ามี -->
                        <a class="nav-link text-white" href="<?= h($backUrl) ?>">
                            <i class="bi bi-arrow-left"></i> กลับ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="form-section-title">🛠️ แก้ไขงวดงาน</h3>
        </div>

        <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= h($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= h($errorMsg) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                ข้อมูลงวดงาน #<?= h($phase['phase_id']) ?>
            </div>
            <div class="card-body">
                <!-- ข้อมูลอ้างอิง (อ่านอย่างเดียว) -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label">ปีงบประมาณ</label>
                        <input type="text" class="form-control" value="<?= h($phase['fiscal_year']) ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">งบประมาณ</label>
                        <input type="text" class="form-control" value="<?= h($phase['item_name']) ?>" readonly>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">โครงการ</label>
                        <input type="text" class="form-control" value="<?= h($phase['detail_name']) ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">เลขที่สัญญา</label>
                        <input type="text" class="form-control" value="<?= h($phase['contract_number']) ?>" readonly>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">ผู้รับจ้าง</label>
                        <input type="text" class="form-control" value="<?= h($phase['contractor_name']) ?>" readonly>
                    </div>
                </div>

                <!-- ฟอร์มแก้ไข -->
                <form method="post" class="row g-3">
                    <input type="hidden" name="phase_id" value="<?= h($phase['phase_id']) ?>">
                    <input type="hidden" name="return_url" value="<?= h($return_url) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="action" value="save">

                    <div class="col-md-2">
                        <label class="form-label">งวดที่</label>
                        <input type="number" name="phase_number" class="form-control"
                            value="<?= h($phase['phase_number']) ?>" min="1" required>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">สถานะ</label>
                        <select name="status" class="form-select">
                            <?php foreach ($allowedStatus as $st): ?>
                            <option value="<?= h($st) ?>" <?= ($st === $phase['status']) ? 'selected' : '' ?>>
                                <?= h($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">จำนวนเงิน (บาท)</label>
                        <input type="text" name="amount" class="form-control number"
                            value="<?= number_format((float)$phase['amount'], 2, '.', '') ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">วันที่เริ่มงวดงาน</label>
                        <input type="text" name="due_date" class="form-control"
value="<?= h(toThaiDisplay($phase['due_date'])) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">วันที่สิ้นสุดงวดงาน</label>
                        <input type="text" name="completion_date" class="form-control"
value="<?= h(toThaiDisplay($phase['completion_date'])) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">วันที่จ่ายเงินงวดงาน</label>
                        <input type="text" name="payment_date" class="form-control"
value="<?= h(toThaiDisplay($phase['payment_date'])) ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">รายละเอียด</label>
                        <textarea name="phase_name" class="form-control"
                            rows="3"><?= h($phase['phase_name']) ?></textarea>

                    </div>


                    <div class="col-12 d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary">บันทึก</button>

                        <!-- ปุ่มยกเลิก ใช้ backUrl เดียวกัน -->
                        <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary">ยกเลิก</a>

                        <!-- ปุ่มลบ เปิด Modal -->
                        <button type="button" class="btn btn-danger ms-auto" data-bs-toggle="modal"
                            data-bs-target="#deleteModal">
                            ลบงวดงาน
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============ DELETE CONFIRM MODAL ============ -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">ยืนยันการลบ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        แน่ใจหรือไม่ว่าต้องการลบงวดงาน #<?= h($phase['phase_id']) ?> ?
                        <div class="text-danger mt-2 small">การลบนี้ไม่สามารถย้อนกลับได้</div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="phase_id" value="<?= h($phase['phase_id']) ?>">
                        <input type="hidden" name="return_url" value="<?= h($return_url) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-danger">ลบงวดงาน</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- ============ SAVE SUCCESS MODAL ============ -->
<div class="modal fade" id="saveSuccessModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">บันทึกสำเร็จ</h5>
            </div>
            <div class="modal-body text-center">
                ✅ บันทึกข้อมูลเรียบร้อยแล้ว
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-success" id="btnSaveOk">
                    ตกลง
                </button>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($saveSuccess)): ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const modal = new bootstrap.Modal(
        document.getElementById('saveSuccessModal')
    );
    modal.show();

    document.getElementById('btnSaveOk').addEventListener('click', function () {
        window.location.href = <?= json_encode($redirectUrl) ?>;
    });
});
</script>
<?php endif; ?>

   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>