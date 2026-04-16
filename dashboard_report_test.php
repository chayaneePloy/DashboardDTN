<?php
session_start();
include 'db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function thaiDate($date){
    if (!$date || $date === '0000-00-00') return '-';
    $t = strtotime($date);
    if (!$t) return '-';
    return date('d/m/', $t) . (date('Y', $t) + 543);
}

$allowedOverlap = ['','ปกติ','กันเหลื่อม'];

/* ===================== FILTERS ===================== */
$selected_year    = $_GET['year'] ?? '';
$selected_item    = $_GET['item'] ?? '';
$selected_project = $_GET['project'] ?? '';
$selected_overlap = $_GET['overlap_type'] ?? '';

if (!in_array($selected_overlap, $allowedOverlap, true)) {
    $selected_overlap = '';
}

/* ===================== LOAD FILTER DROPDOWNS ===================== */

// ปีงบประมาณ
$years = $pdo->query("
    SELECT DISTINCT fiscal_year
    FROM budget_items
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// งบประมาณ
$items = [];
if ($selected_year !== '') {
    $stmt = $pdo->prepare("
        SELECT id, item_name
        FROM budget_items
        WHERE fiscal_year = ?
        ORDER BY item_name
    ");
    $stmt->execute([$selected_year]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// โครงการ
$projects = [];
if ($selected_item !== '') {
    $stmt = $pdo->prepare("
        SELECT id_detail, detail_name
        FROM budget_detail
        WHERE budget_item_id = ?
        ORDER BY detail_name
    ");
    $stmt->execute([$selected_item]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ===================== MAIN QUERY ===================== */
$sql = "
SELECT
    p.phase_id,
    p.phase_number,
    p.phase_name,
    p.amount,
    p.due_date,
    p.completion_date,
    p.payment_date,
    p.status,
    p.overlap_type,

    c.contract_id,
    c.contract_number,
    c.contractor_name,

    bd.id_detail,
    bd.detail_name,

    bi.id AS item_id,
    bi.item_name,
    bi.fiscal_year

FROM phases p
JOIN contracts c      ON p.contract_detail_id = c.contract_id
JOIN budget_detail bd ON c.detail_item_id     = bd.id_detail
JOIN budget_items bi  ON bd.budget_item_id    = bi.id
WHERE 1=1
";

$params = [];

if ($selected_year !== '') {
    $sql .= " AND bi.fiscal_year = ? ";
    $params[] = $selected_year;
}

if ($selected_item !== '') {
    $sql .= " AND bi.id = ? ";
    $params[] = $selected_item;
}

if ($selected_project !== '') {
    $sql .= " AND bd.id_detail = ? ";
    $params[] = $selected_project;
}

if ($selected_overlap !== '') {
    $sql .= " AND p.overlap_type = ? ";
    $params[] = $selected_overlap;
}

$sql .= "
ORDER BY 
    bi.fiscal_year DESC,
    bi.item_name ASC,
    bd.detail_name ASC,
    c.contract_number ASC,
    p.phase_number ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===================== SUMMARY ===================== */
$totalAmount = 0;
$totalNormal = 0;
$totalOverlap = 0;

foreach ($rows as $r) {
    $amt = (float)($r['amount'] ?? 0);
    $totalAmount += $amt;

    if (($r['overlap_type'] ?? 'ปกติ') === 'กันเหลื่อม') {
        $totalOverlap += $amt;
    } else {
        $totalNormal += $amt;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานงวดงาน</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logo3.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .table th, .table td { vertical-align: middle; }
        .number { text-align: right; white-space: nowrap; }
        .badge-normal { background: #6c757d; }
        .badge-overlap { background: #dc3545; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            📊 Dashboard งบประมาณโครงการ
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link text-white" href="index.php">
                        <i class="bi bi-house"></i> หน้าหลัก
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container my-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold text-primary mb-0">📋 รายงานงวดงาน</h3>
    </div>

    <!-- FILTER -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">
            ตัวกรองรายงาน
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">

                <div class="col-md-3">
                    <label class="form-label">ปีงบประมาณ</label>
                    <select name="year" class="form-select" onchange="this.form.submit()">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= h($y) ?>" <?= ($selected_year == $y) ? 'selected' : '' ?>>
                                <?= h($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">งบประมาณ</label>
                    <select name="item" class="form-select" onchange="this.form.submit()">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($items as $i): ?>
                            <option value="<?= h($i['id']) ?>" <?= ($selected_item == $i['id']) ? 'selected' : '' ?>>
                                <?= h($i['item_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label">โครงการ</label>
                    <select name="project" class="form-select">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= h($p['id_detail']) ?>" <?= ($selected_project == $p['id_detail']) ? 'selected' : '' ?>>
                                <?= h($p['detail_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">ประเภทงบ</label>
                    <select name="overlap_type" class="form-select">
                        <option value="">-- ทั้งหมด --</option>
                        <option value="ปกติ" <?= ($selected_overlap === 'ปกติ') ? 'selected' : '' ?>>ปกติ</option>
                        <option value="กันเหลื่อม" <?= ($selected_overlap === 'กันเหลื่อม') ? 'selected' : '' ?>>กันเหลื่อม</option>
                    </select>
                </div>

                <div class="col-md-9 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> ค้นหา
                    </button>
                    <a href="dashboard_report.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i> ล้างตัวกรอง
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- SUMMARY -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">ยอดรวมทั้งหมด</div>
                    <div class="fs-4 fw-bold text-primary"><?= number_format($totalAmount, 2) ?></div>
                    <div class="small text-muted">บาท</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">งบปกติ</div>
                    <div class="fs-4 fw-bold text-success"><?= number_format($totalNormal, 2) ?></div>
                    <div class="small text-muted">บาท</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">งบกันเหลื่อม</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($totalOverlap, 2) ?></div>
                    <div class="small text-muted">บาท</div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-bold">
            รายการงวดงาน
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-primary">
                    <tr>
                        <th class="text-center">ลำดับ</th>
                        <th>ปีงบ</th>
                        <th>งบประมาณ</th>
                        <th>โครงการ</th>
                        <th>เลขที่สัญญา</th>
                        <th>ผู้รับจ้าง</th>
                        <th class="text-center">งวด</th>
                        <th>รายละเอียด</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">ประเภทงบ</th>
                        <th class="number">จำนวนเงิน</th>
                        <th class="text-center">วันที่เริ่ม</th>
                        <th class="text-center">วันที่สิ้นสุด</th>
                        <th class="text-center">วันที่จ่าย</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="15" class="text-center text-muted py-4">
                                ไม่พบข้อมูล
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <?php
                                $returnUrl = 'dashboard_report.php?' . http_build_query([
                                    'year' => $selected_year,
                                    'item' => $selected_item,
                                    'project' => $selected_project,
                                    'overlap_type' => $selected_overlap
                                ]);
                            ?>
                            <tr>
                                <td class="text-center"><?= $i + 1 ?></td>
                                <td><?= h($row['fiscal_year']) ?></td>
                                <td><?= h($row['item_name']) ?></td>
                                <td><?= h($row['detail_name']) ?></td>
                                <td><?= h($row['contract_number']) ?></td>
                                <td><?= h($row['contractor_name']) ?></td>
                                <td class="text-center"><?= h($row['phase_number']) ?></td>
                                <td><?= nl2br(h($row['phase_name'])) ?></td>
                                <td class="text-center"><?= h($row['status']) ?></td>
                                <td class="text-center">
                                    <?php if (($row['overlap_type'] ?? 'ปกติ') === 'กันเหลื่อม'): ?>
                                        <span class="badge badge-overlap">กันเหลื่อม</span>
                                    <?php else: ?>
                                        <span class="badge badge-normal">ปกติ</span>
                                    <?php endif; ?>
                                </td>
                                <td class="number"><?= number_format((float)$row['amount'], 2) ?></td>
                                <td class="text-center"><?= h(thaiDate($row['due_date'])) ?></td>
                                <td class="text-center"><?= h(thaiDate($row['completion_date'])) ?></td>
                                <td class="text-center"><?= h(thaiDate($row['payment_date'])) ?></td>
                                <td class="text-center">
                                    <a href="edit_phase.php?phase_id=<?= urlencode($row['phase_id']) ?>&return=<?= urlencode($returnUrl) ?>"
                                       class="btn btn-sm btn-warning">
                                        <i class="bi bi-pencil-square"></i> แก้ไข
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

                <?php if ($rows): ?>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="10" class="text-end">รวมทั้งสิ้น</th>
                        <th class="number"><?= number_format($totalAmount, 2) ?></th>
                        <th colspan="4"></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>