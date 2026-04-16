<?php
// ===================== CONNECT =====================
include 'db.php';

// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$allowedStatus  = ['รอดำเนินการ','อยู่ระหว่างดำเนินการ','เสร็จสิ้น','ยกเลิก'];
$allowedOverlap = ['ไม่กันเหลื่อม', 'กันเหลื่อม'];

/**
 * แสดงวันที่จากฐานข้อมูล (ค.ศ.) เป็น dd/mm/YYYY (พ.ศ.)
 * เช่น 2025-02-01 -> 01/02/2568
 */
function toThaiDisplay($dateStr){
    if (!$dateStr) return '';
    $parts = explode('-', $dateStr);
    if (count($parts) !== 3) return '';

    $y = (int)$parts[0] + 543;
    $m = (int)$parts[1];
    $d = (int)$parts[2];

    return sprintf('%02d/%02d/%04d', $d, $m, $y);
}

/**
 * แปลง dd/mm/yyyy (พ.ศ.) -> YYYY-MM-DD (ค.ศ.)
 */
function thai_to_mysql_date($d){
    if (!$d) return null;

    // รองรับ dd/mm/yyyy (พ.ศ.)
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', trim($d), $m)) {
        $day   = (int)$m[1];
        $month = (int)$m[2];
        $year  = (int)$m[3];

        if ($year > 2400) {
            $year -= 543;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    // ถ้าเป็น yyyy-mm-dd อยู่แล้ว
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', trim($d))) {
        return $d;
    }

    return null;
}

$method = $_SERVER['REQUEST_METHOD'];
$return_url = $_GET['return'] ?? $_POST['return_url'] ?? '';

$selected_year     = $_GET['year'] ?? ($_POST['year'] ?? '');
$selected_item     = $_GET['item'] ?? ($_POST['item'] ?? '');
$selected_detail   = $_POST['detail_id'] ?? '';     // budget_detail.id_detail
$selected_contract = $_POST['contract_id'] ?? '';   // contracts.contract_id

$successMsg = $errorMsg = "";

// ===================== ดึงข้อมูลสำหรับ dropdown =====================

// ปีงบฯ
$years = $pdo->query("
    SELECT DISTINCT bi.fiscal_year 
    FROM budget_items bi
    INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
    ORDER BY bi.fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// งบประมาณตามปี
$items = [];
if ($selected_year !== '') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT bi.id, bi.item_name 
        FROM budget_items bi
        INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
        WHERE bi.fiscal_year = ?
        ORDER BY 
            CASE bi.item_name
                WHEN 'งบลงทุน' THEN 1
                WHEN 'งบบูรณาการ' THEN 2
                WHEN 'งบดำเนินงาน' THEN 3
                WHEN 'งบรายจ่ายอื่น' THEN 4
                ELSE 5
            END,
            bi.id ASC
    ");
    $stmt->execute([$selected_year]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// โครงการตามงบประมาณ
$details = [];
if ($selected_year !== '' && $selected_item !== '') {
    $stmt = $pdo->prepare("
        SELECT bd.id_detail, bd.detail_name
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ? AND bd.budget_item_id = ?
        ORDER BY bd.detail_name
    ");
    $stmt->execute([$selected_year, $selected_item]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// สัญญาตามโครงการที่เลือก
$contracts = [];
if ($selected_detail !== '') {
    $stmt = $pdo->prepare("
        SELECT c.contract_id, c.contract_number, c.contractor_name
        FROM contracts c
        WHERE c.detail_item_id = ?
        ORDER BY c.contract_number
    ");
    $stmt->execute([$selected_detail]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== บันทึก (POST) =====================
if ($method === 'POST') {
    // รับค่า
    $year         = $_POST['year'] ?? '';
    $item         = $_POST['item'] ?? '';
    $detail_id    = $_POST['detail_id'] ?? '';
    $contract_id  = $_POST['contract_id'] ?? '';

    $phase_number = (int)($_POST['phase_number'] ?? 0);
    $phase_name   = trim($_POST['phase_name'] ?? '');
    $amountInput  = str_replace([',',' '], '', $_POST['amount'] ?? '0');

    // วันที่จาก hidden field (ค.ศ.) หรือ fallback จาก text field (พ.ศ.)
    $due_date        = $_POST['due_date'] ?: thai_to_mysql_date($_POST['due_date_th'] ?? '');
    $completion_date = $_POST['completion_date'] ?: thai_to_mysql_date($_POST['completion_date_th'] ?? '');
    $payment_date    = $_POST['payment_date'] ?: thai_to_mysql_date($_POST['payment_date_th'] ?? '');

    $status       = $_POST['status'] ?? $allowedStatus[0];
    $overlap_type = $_POST['overlap_type'] ?? 'ไม่กันเหลื่อม';

    // ตรวจค่าพื้นฐาน
    if ($year === '' || $item === '' || $detail_id === '' || $contract_id === '') {
        $errorMsg = "กรุณาเลือก ปีงบประมาณ, งบประมาณ, โครงการ และ สัญญา ให้ครบ";
    } elseif (!is_numeric($amountInput)) {
        $errorMsg = "จำนวนเงินไม่ถูกต้อง";
    } else {
        $amount = (float)$amountInput;
        if ($amount < 0) $errorMsg = "จำนวนเงินต้องเป็นค่าบวก";
    }

    if (!$errorMsg && !in_array($status, $allowedStatus, true)) {
        $status = $allowedStatus[0];
    }

    if (!$errorMsg && !in_array($overlap_type, $allowedOverlap, true)) {
        $overlap_type = 'ไม่กันเหลื่อม';
    }

    // ตรวจรูปแบบวันที่
    if (!$errorMsg) {
        foreach ([
            'วันที่เริ่มงวดงาน' => $due_date,
            'วันที่สิ้นสุดงวดงาน' => $completion_date,
            'วันที่จ่ายเงินงวดงาน' => $payment_date
        ] as $label => $dateVal) {
            if ($dateVal !== null && $dateVal !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateVal)) {
                $errorMsg = "{$label} ไม่ถูกต้อง";
                break;
            }
        }
    }

    // due_date <= completion_date
    if (!$errorMsg && $due_date && $completion_date && ($due_date > $completion_date)) {
        $errorMsg = "วันที่เริ่มงวดงานต้องไม่มากกว่าวันที่สิ้นสุดงวดงาน";
    }

    // ตรวจความสัมพันธ์: contract_id ต้องอยู่ใต้ detail_id ที่เลือก
    if (!$errorMsg) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM contracts 
            WHERE contract_id = ? AND detail_item_id = ?
        ");
        $stmt->execute([$contract_id, $detail_id]);
        if ($stmt->fetchColumn() == 0) {
            $errorMsg = "สัญญาไม่อยู่ในโครงการที่เลือก";
        }
    }

    // ตรวจเลขงวดซ้ำภายใต้สัญญาเดียวกัน
    if (!$errorMsg && $phase_number > 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM phases 
            WHERE contract_detail_id = ? AND phase_number = ?
        ");
        $stmt->execute([$contract_id, $phase_number]);
        if ($stmt->fetchColumn() > 0) {
            $errorMsg = "งวดที่ {$phase_number} มีอยู่แล้วในสัญญานี้";
        }
    }

    // Insert
    if (!$errorMsg) {
        $stmt = $pdo->prepare("
            INSERT INTO phases (
                contract_detail_id,
                phase_number,
                phase_name,
                amount,
                due_date,
                completion_date,
                status,
                payment_date,
                overlap_type
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $contract_id,
            $phase_number,
            $phase_name,
            $amount,
            $due_date,
            $completion_date,
            $status,
            $payment_date,
            $overlap_type
        ]);

        $successMsg = "เพิ่มงวดงานเรียบร้อยแล้ว";

        if ($return_url) {
            header("Location: " . $return_url);
            exit;
        }

        // reset บางค่าเมื่อบันทึกสำเร็จ
        $_POST['phase_number'] = '';
        $_POST['phase_name'] = '';
        $_POST['amount'] = '0.00';
        $_POST['due_date_th'] = '';
        $_POST['completion_date_th'] = '';
        $_POST['payment_date_th'] = '';
        $_POST['status'] = $allowedStatus[0];
        $_POST['overlap_type'] = 'ไม่กันเหลื่อม';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เพิ่มงวดงานใหม่</title>
    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .form-section-title { font-weight: 700; color:#198754; }
        .number { text-align: right; }
    </style>
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php?year=<?= h($selected_year) ?>&quarter=<?= h($_GET['quarter'] ?? 1) ?>">
            📊Dashboard งบประมาณโครงการ
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link text-white" href="index.php?year=<?= h($selected_year) ?>&quarter=<?= h($_GET['quarter'] ?? 1) ?>">
                        <i class="bi bi-house"></i> หน้าหลัก
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-white" href="<?= h($return_url ?: ('dashboard_report.php?year=' . urlencode($selected_year))) ?>">
                        <i class="bi bi-arrow-left"></i> กลับ
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container my-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="form-section-title">➕ เพิ่มงวดงาน (Phase)</h3>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= h($successMsg) ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-success text-white">
            กรอกข้อมูลงวดงานใหม่
        </div>

        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="return_url" value="<?= h($return_url) ?>">

                <!-- ปีงบประมาณ -->
                <div class="col-md-3">
                    <label class="form-label">ปีงบประมาณ</label>
                    <select class="form-select" name="year" onchange="this.form.submit()">
                        <option value="">-- เลือกปี --</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= h($y) ?>" <?= ($y == $selected_year) ? 'selected' : '' ?>>
                                <?= h($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- งบประมาณ -->
                <div class="col-md-4">
                    <label class="form-label">งบประมาณ</label>
                    <select class="form-select" name="item" onchange="this.form.submit()">
                        <option value="">-- เลือกงบประมาณ --</option>
                        <?php foreach ($items as $i): ?>
                            <option value="<?= h($i['id']) ?>" <?= ($i['id'] == $selected_item) ? 'selected' : '' ?>>
                                <?= h($i['item_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- โครงการ -->
                <div class="col-md-5">
                    <label class="form-label">โครงการ</label>
                    <select class="form-select" name="detail_id" onchange="this.form.submit()">
                        <option value="">-- เลือกโครงการ --</option>
                        <?php foreach ($details as $d): ?>
                            <option value="<?= h($d['id_detail']) ?>" <?= ($d['id_detail'] == $selected_detail) ? 'selected' : '' ?>>
                                <?= h($d['detail_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- สัญญา -->
                <div class="col-md-6">
                    <label class="form-label">สัญญา</label>
                    <select class="form-select" name="contract_id" <?= $selected_detail ? '' : 'disabled' ?>>
                        <option value="">-- เลือกสัญญา --</option>
                        <?php foreach ($contracts as $c): ?>
                            <option value="<?= h($c['contract_id']) ?>" <?= ($c['contract_id'] == $selected_contract) ? 'selected' : '' ?>>
                                <?= h($c['contract_number'].' — '.$c['contractor_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- งวดที่ -->
                <div class="col-md-2">
                    <label class="form-label">งวดที่</label>
                    <input type="number" class="form-control" name="phase_number" min="1"
                        value="<?= h($_POST['phase_number'] ?? '') ?>" required>
                </div>

                <!-- งบกันเหลื่อม  -->
                <div class="col-md-4">
                    <label class="form-label">งบกันเหลื่อม </label>
                    <select class="form-select" name="overlap_type">
                        <?php foreach ($allowedOverlap as $ov): ?>
                            <option value="<?= h($ov) ?>" <?= (($ov == ($_POST['overlap_type'] ?? 'ไม่กันเหลื่อม')) ? 'selected' : '') ?>>
                                <?= h($ov) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- จำนวนเงิน -->
                <div class="col-md-4">
                    <label class="form-label">จำนวนเงิน (บาท)</label>
                    <input type="text" class="form-control number" name="amount"
                        value="<?= h($_POST['amount'] ?? '0.00') ?>" required>
                </div>

                <!-- สถานะ -->
                <div class="col-md-4">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" name="status">
                        <?php foreach ($allowedStatus as $st): ?>
                            <option value="<?= h($st) ?>" <?= (($st == ($_POST['status'] ?? 'รอดำเนินการ')) ? 'selected' : '') ?>>
                                <?= h($st) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- วันที่เริ่ม -->
                <div class="col-md-4">
                    <label class="form-label">วันที่เริ่ม (พ.ศ.)</label>
                    <input type="text"
                        class="form-control"
                        id="due_date_th"
                        name="due_date_th"
                        placeholder="dd/mm/yyyy"
                        value="<?= h($_POST['due_date_th'] ?? '') ?>">
                    <input type="hidden" name="due_date" id="due_date">
                </div>

                <!-- วันที่สิ้นสุด -->
                <div class="col-md-4">
                    <label class="form-label">วันที่สิ้นสุด (พ.ศ.)</label>
                    <input type="text"
                        class="form-control"
                        id="completion_date_th"
                        name="completion_date_th"
                        placeholder="dd/mm/yyyy"
                        value="<?= h($_POST['completion_date_th'] ?? '') ?>">
                    <input type="hidden" name="completion_date" id="completion_date">
                </div>

                <!-- วันที่จ่าย -->
                <div class="col-md-4">
                    <label class="form-label">วันที่จ่าย (พ.ศ.)</label>
                    <input type="text"
                        class="form-control"
                        id="payment_date_th"
                        name="payment_date_th"
                        placeholder="dd/mm/yyyy"
                        value="<?= h($_POST['payment_date_th'] ?? '') ?>">
                    <input type="hidden" name="payment_date" id="payment_date">
                </div>

                <!-- หมายเหตุ -->
                <div class="col-md-12">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea
                        class="form-control"
                        name="phase_name"
                        rows="2"
                        placeholder="เช่น ส่งมอบงานงวดแรก / งวดสุดท้าย / หักค่าปรับ"><?= h($_POST['phase_name'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-success">บันทึก</button>

                    <?php if ($return_url): ?>
                        <a href="<?= h($return_url) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <?php else: ?>
                        <a href="javascript:history.back()" class="btn btn-outline-secondary">ยกเลิก</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function thaiToGregorian(thaiDate){
    if(!thaiDate) return '';

    const parts = thaiDate.split('/');
    if(parts.length !== 3) return '';

    const d = parts[0].trim();
    const m = parts[1].trim();
    const y = parts[2].trim();

    if(!d || !m || !y) return '';

    const gy = parseInt(y, 10) - 543;
    if (isNaN(gy)) return '';

    return `${gy}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}

// sync ตอนกรอก/เปลี่ยนค่า
['due','completion','payment'].forEach(prefix => {
    const th = document.getElementById(prefix + '_date_th');
    const real = document.getElementById(prefix + '_date');

    if(!th || !real) return;

    const syncDate = () => {
        real.value = thaiToGregorian(th.value);
    };

    th.addEventListener('change', syncDate);
    th.addEventListener('blur', syncDate);
});

// sync ก่อน submit อีกรอบกันพลาด
document.querySelector('form').addEventListener('submit', function(){
    ['due','completion','payment'].forEach(prefix => {
        const th = document.getElementById(prefix + '_date_th');
        const real = document.getElementById(prefix + '_date');
        if(th && real){
            real.value = thaiToGregorian(th.value);
        }
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>