<?php
session_start();
include 'db.php';

$years = $pdo->query("
    SELECT DISTINCT fiscal_year
    FROM budget_items
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);
// 👉 เข้า page ครั้งแรก ให้ redirect ไปปีล่าสุด + กันเหลื่อม
if ((!isset($_GET['year']) || $_GET['year'] === '') && count($years)) {
    $defaultYear = max($years);
    header("Location: ?year={$defaultYear}&overlap_type=กันเหลื่อม");
    exit;
}

if (!isset($_GET['overlap_type']) || $_GET['overlap_type'] === '') {
    $year = $_GET['year'] ?? max($years);
    header("Location: ?year={$year}&overlap_type=กันเหลื่อม");
    exit;
}

$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : max($years);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function thaiDate($date){
    if (!$date || $date === '0000-00-00') return '-';
    $t = strtotime($date);
    if (!$t) return '-';
    return date('d/m/', $t) . (date('Y', $t) + 543);
}

$allowedOverlap = ['','ไม่กันเหลื่อม','กันเหลื่อม'];

/* ===================== FILTERS ===================== */
/* ===================== FILTERS ===================== */
$selected_year = $_GET['year'] ?? (count($years) ? max($years) : '');
$selected_item = $_GET['item'] ?? '';
$selected_project = $_GET['project'] ?? '';

// 👇 บังคับ default เป็น "กันเหลื่อม"
$selected_overlap = $_GET['overlap_type'] ?? 'กันเหลื่อม';

if (!in_array($selected_overlap, $allowedOverlap, true)) {
    $selected_overlap = '';
}
if (!isset($_GET['overlap_type'])) {
    $selected_overlap = 'กันเหลื่อม';
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
        SELECT id AS item_id, item_name
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

if ($selected_overlap === 'ไม่กันเหลื่อม') {
    $sql .= " AND (p.overlap_type IS NULL OR p.overlap_type = '' OR p.overlap_type = 'ไม่กันเหลื่อม') ";
}
elseif ($selected_overlap === 'กันเหลื่อม') {
    $sql .= " AND p.overlap_type = 'กันเหลื่อม' ";
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
$summary = [];
$totalRows = count($rows);

foreach ($rows as $r) {

    $year     = $r['fiscal_year'];
    $itemId   = $r['item_id'];
    $itemName = $r['item_name'];
    $amount   = (float)($r['amount'] ?? 0);
    $isOverlap = (($r['overlap_type'] ?? '') === 'กันเหลื่อม');

    // 🔥 แยกตามปี + งบ
    if (!isset($summary[$year][$itemId])) {
        $summary[$year][$itemId] = [
            'item_name' => $itemName,
            'total_amount' => 0,
            'overlap_amount' => 0,
            'projects_all' => [],
            'projects_overlap' => [],
            'phase_overlap_count' => 0
        ];
    }

    $summary[$year][$itemId]['total_amount'] += $amount;

    if (!empty($r['id_detail'])) {
        $summary[$year][$itemId]['projects_all'][$r['id_detail']] = $r['detail_name'];
    }

    if ($isOverlap) {
        $summary[$year][$itemId]['overlap_amount'] += $amount;
        $summary[$year][$itemId]['projects_overlap'][$r['id_detail']] = $r['detail_name'];
        $summary[$year][$itemId]['phase_overlap_count']++;
    }
}

/* calc */
foreach ($summary as $year => $yearItems) {
    foreach ($yearItems as $k => $s) {

        $summary[$year][$k]['project_total']   = count($s['projects_all']);
        $summary[$year][$k]['project_overlap'] = count($s['projects_overlap']);

        $summary[$year][$k]['percent'] = ($s['total_amount'] > 0)
            ? ($s['overlap_amount'] / $s['total_amount']) * 100
            : 0;
    }
}


$totalAmountAll = 0;
$totalOverlapAll = 0;
$totalNormalAll = 0;

foreach ($rows as $r) {
    $amt = (float)$r['amount'];

    $totalAmountAll += $amt;

    if (($r['overlap_type'] ?? '') === 'กันเหลื่อม') {
        $totalOverlapAll += $amt;
    } else {
        $totalNormalAll += $amt;
    }
}
$yearsInResult = [];

foreach ($rows as $r) {
    if (!empty($r['fiscal_year'])) {
        $yearsInResult[$r['fiscal_year']] = true;
    }
}

$yearsInResult = array_keys($yearsInResult);
sort($yearsInResult);
/* ===================== EXPORT EXCEL ===================== */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "report_phases_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    ?>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            table {
                border-collapse: collapse;
                width: 100%;
                font-family: Tahoma, sans-serif;
                font-size: 13px;
            }
            th, td {
                border: 1px solid #000;
                padding: 6px;
                vertical-align: middle;
            }
            th {
                background: #d9eaf7;
                text-align: center;
                font-weight: bold;
            }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .bg-overlap { background: #fdeaea; }
            .title {
                font-size: 18px;
                font-weight: bold;
            }
        </style>
    </head>
    <body>

    <table style="margin-bottom:12px;">
        <tr>
            <td colspan="4" class="title">รายงานงบกันเหลื่อม</td>
        </tr>
        <tr>
            <td><strong>ปีงบประมาณ</strong></td>
            <td><?= h($selected_year ?: 'ทั้งหมด') ?></td>
            <td><strong>งบกันเหลื่อม</strong></td>
            <td><?= h($selected_overlap ?: 'ทั้งหมด') ?></td>
        </tr>
        <tr>
            <td><strong>ยอดรวมทั้งหมด</strong></td>
            <td class="text-right"><?= number_format($totalAmountAll, 2) ?> </td>
            <td><strong>งบกันเหลื่อม</strong></td>
            <td class="text-right"><?= number_format($totalOverlapAll, 2) ?></td>
        </tr>
        <tr>
            <td><strong>งบไม่กันเหลื่อม</strong></td>
            <td class="text-right"><?= number_format($totalNormalAll, 2) ?></td>
            <td><strong>จำนวนรายการ</strong></td>
            <td class="text-right"><?= number_format($totalRows) ?></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>ลำดับ</th>
                <th>ปีงบ</th>
                <th>งบประมาณ</th>
                <th>โครงการ</th>
                <th>เลขที่สัญญา</th>
                <th>ผู้รับจ้าง</th>
                <th>งวด</th>
                <th>รายละเอียด</th>
                <th>สถานะ</th>
                <th>งบกันเหลื่อม</th>
                <th>จำนวนเงิน</th>
                <th>ครบกำหนด</th>
                <th>ส่งมอบ</th>
                <th>จ่ายเงิน</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="14" class="text-center">ไม่พบข้อมูล</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $row): ?>
                    <?php $isOverlap = (($row['overlap_type'] ?? 'ไม่กันเหลื่อม') === 'กันเหลื่อม'); ?>
                    <tr class="<?= $isOverlap ? 'bg-overlap' : '' ?>">
                        <td class="text-center"><?= $i + 1 ?></td>
                        <td class="text-center"><?= h($row['fiscal_year']) ?></td>
                        <td><?= h($row['item_name']) ?></td>
                        <td><?= h($row['detail_name']) ?></td>
                        <td><?= h($row['contract_number']) ?></td>
                        <td><?= h($row['contractor_name']) ?></td>
                        <td class="text-center"><?= h($row['phase_number']) ?></td>
                        <td><?= nl2br(h($row['phase_name'])) ?></td>
                        <td class="text-center"><?= h($row['status']) ?></td>
                        <td class="text-center"><?= h(!empty($row['overlap_type']) ? $row['overlap_type'] : 'ไม่กันเหลื่อม') ?></td>
                        <td class="text-right"><?= number_format((float)$row['amount'], 2) ?></td>
                        <td class="text-center"><?= h(thaiDate($row['due_date'])) ?></td>
                        <td class="text-center"><?= h(thaiDate($row['completion_date'])) ?></td>
                        <td class="text-center"><?= h(thaiDate($row['payment_date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>

        <?php if ($rows): ?>
        <tfoot>
            <tr>
                <th colspan="10" class="text-right">รวมทั้งสิ้น</th>
                <th class="text-right"><?= number_format($totalAmountAll, 2) ?></th>
                <th colspan="3"></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานงบกันเหลื่อม</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logo3.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background: #f4f7fb;
        }

        .page-title {
            font-weight: 700;
            color: #0d6efd;
        }

        .table th, .table td {
            vertical-align: middle;
            font-size: 14px;
        }

        .number {
            text-align: right;
            white-space: nowrap;
            font-weight: 600;
        }

        .badge-normal {
            background: #6c757d;
        }

        .badge-overlap {
            background: #dc3545;
        }

        .summary-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,.06);
            transition: .2s ease;
        }

        .summary-card:hover {
            transform: translateY(-2px);
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .filter-card,
        .table-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 10px 24px rgba(0,0,0,.06);
        }

        .table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            white-space: nowrap;
        }

        .table tbody tr:nth-child(even) {
            background-color: #fcfcfd;
        }

        .table tbody tr:hover {
            background-color: #f1f7ff;
        }

        .overlap-row {
            background: #fff5f5 !important;
        }

        .search-box {
            max-width: 360px;
        }

        .small-muted {
            font-size: 13px;
            color: #6c757d;
        }

        .nowrap {
            white-space: nowrap;
        }

        .table-responsive {
            max-height: 72vh;
        }
        @media print {
    nav,
    .navbar,
    .btn,
    .filter-card,
    .search-box {
        display: none !important;
    }

    body {
        background: #fff !important;
    }

    .table-card {
        box-shadow: none !important;
        border: none !important;
    }

    .table-responsive {
        max-height: none !important;
        overflow: visible !important;
    }
}
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="assets/logo2.png" alt="Dashboard งบประมาณ" style="height:40px;">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#mainNavbar" aria-controls="mainNavbar"
            aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link active fs-5 text-white px-3"
                       href="add_budget_act.php?year=<?= htmlspecialchars($selectedYear) ?>">
                       เพิ่มงบตาม พ.ร.บ.+เป้าหมายไตรมาส
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard.php?year=<?= htmlspecialchars($selectedYear) ?>">
                        เพิ่มงบตามวงเงินสัญญา
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard_report.php?year=<?= htmlspecialchars($selectedYear) ?>">
                        เพิ่มการจ่ายงวดงาน
                    </a>
                </li>

                <!-- ===== เมนูรายงาน (Dropdown) ===== -->
                <li class="nav-item dropdown">
                    <a class="nav-link fs-5 text-white px-3"
                       href="#" id="reportDropdown" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        รายงาน
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="reportDropdown">
                        <li>
                            <a class="dropdown-item"
                               href="report_project_full.php?year=<?= htmlspecialchars($selectedYear) ?>">
                                รายงานจัดซื้อจัดจ้าง
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="report.php?year=<?= htmlspecialchars($selectedYear) ?>">
                                รายงานการจ่ายงวดงาน
                            </a>
                        </li>
                         <li>
                            <a class="dropdown-item"
                               href="report_all.php?year=<?= htmlspecialchars($selectedYear) ?>">
                                รายงานรวมจัดซื้อจัดจ้าง/การจ่ายงวดงาน
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="report_landscape.php?year=<?= htmlspecialchars($selectedYear) ?>">
                                รายงานภาพรวมงบประมาณตามไตรมาส
                            </a>
                        </li>
                          <li>
                            <a class="dropdown-item"
                               href="report_overlap.php?year=<?= htmlspecialchars($selectedYear) ?>">
                               รายงานงบกันเหลื่อม
                               
                            </a>
                        </li>
                    </ul>
                </li>
                <!-- =============================== -->

            </ul>
             <span class="ms-auto text-white fs-5 fw-semibold d-none d-lg-block">
        กรมเจรจาการค้าระหว่างประเทศ
    </span>
        </div>
    </div>
</nav>

<div class="container my-4">

    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h2 class="page-title mb-1">📋 งบกันเหลื่อม</h2>
           
        </div>

        <div class="d-flex gap-2 flex-wrap">
             <button class="btn btn-outline-secondary" onclick="window.print()">
        🖨️ พิมพ์
    </button>
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-success">
         📥 Excel
    </a>

   
   
</div>
    </div>

    <!-- FILTER -->
    <div class="card filter-card mb-4">
       
        <div class="card-body px-4 pb-4">
            <form method="get" class="row g-3">

                <div class="col-md-3">
                    <label class="form-label fw-semibold">ปีงบประมาณ</label>
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
                    <label class="form-label fw-semibold">งบประมาณ</label>
                    <select name="item" class="form-select" onchange="this.form.submit()">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($items as $i): ?>
    <option value="<?= h($i['item_id']) ?>" <?= ($selected_item == $i['item_id']) ? 'selected' : '' ?>>
        <?= h($i['item_name']) ?>
    </option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label fw-semibold">โครงการ</label>
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
                    <label class="form-label fw-semibold">งบกันเหลื่อม</label>
                    <select name="overlap_type" class="form-select">
                        <option value="">-- ทั้งหมด --</option>
                        <option value="ไม่กันเหลื่อม" <?= ($selected_overlap === 'ไม่กันเหลื่อม') ? 'selected' : '' ?>>ไม่กันเหลื่อม</option>
                        <option value="กันเหลื่อม" <?= ($selected_overlap === 'กันเหลื่อม') ? 'selected' : '' ?>>กันเหลื่อม</option>
                    </select>
                </div>

                <div class="col-md-9 d-flex align-items-end gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-search"></i> ค้นหา
                    </button>
                    <a href="report_overlap.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i> ล้างตัวกรอง
                    </a>
                </div>
            </form>
        </div>
    </div>

<div class="row">
<div class="row g-3 mb-4">
    <div class="small text-muted">
<?php if ($selected_year === ''): ?>
    แสดงข้อมูลหลายปี:
    <strong><?= implode(', ', $yearsInResult) ?></strong>
<?php else: ?>
    ปีงบประมาณ: <strong><?= h($selected_year) ?></strong>
<?php endif; ?>
</div>
<?php foreach($summary as $year => $items): ?>

<div class="mb-3">
    <h5 class="fw-bold text-primary">
        📅 ปีงบประมาณ <?= h($year) ?>
    </h5>
</div>

<div class="row g-3 mb-4">

<?php foreach($items as $i=>$s): ?>
<div class="col-md-4">

<div class="card summary-card p-3 h-100">

<div class="d-flex justify-content-between">
    <div class="fw-bold text-primary"><?= h($s['item_name']) ?></div>
    <span class="badge bg-primary"><?= number_format($s['percent'],2) ?>%</span>
</div>

<div class="mt-2 small">
โครงการกันเหลื่อม:  <b><?= $s['project_overlap'] ?> โครงการ </b><br>
งวดกันเหลื่อม: <b class="text-danger"><?= $s['phase_overlap_count'] ?></b> งวด
</div>

<hr>

<div class="small text-muted">งบกันเหลื่อม</div>
<div class="fw-bold text-danger"><?= number_format($s['overlap_amount'],2) ?></div>

<div class="small text-muted mt-2">งบทั้งหมด</div>
<div class="fw-semibold"><?= number_format($s['total_amount'],2) ?></div>

<!-- รายชื่อโครงการ -->
<div class="mt-3">
    <button class="btn btn-sm btn-outline-primary"
            onclick="toggleProject('<?= $year ?>_<?= $i ?>')">
        ดูโครงการ
    </button>

    <div id="proj<?= $year ?>_<?= $i ?>" style="display:none" class="mt-2 small">
        <?php foreach($s['projects_overlap'] as $p): ?>
            <div>• <?= h($p) ?></div>
        <?php endforeach; ?>
    </div>
</div>

</div>
</div>
<?php endforeach; ?>

</div>

<?php endforeach; ?>
</div>
</div>

    <!-- TABLE -->
    <div class="card table-card">
        <div class="card-header bg-white border-0 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 pt-4 px-4">
            <div class="fw-bold">
                <i class="bi bi-table"></i> รายการงวดงาน
            </div>

            <div class="search-box">
                <input type="text" id="tableSearch" class="form-control" placeholder="ค้นหาเลขสัญญา / โครงการ / ผู้รับจ้าง...">
            </div>
        </div>

        <div class="card-body px-4 pb-4">
            <div class="mb-3 small text-muted">
                แสดงทั้งหมด <strong><?= number_format($totalRows) ?></strong> รายการ
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="reportTable">
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
                            <th class="text-center">งบกันเหลื่อม</th>
                            <th class="number">จำนวนเงิน</th>
                            <th class="text-center">ครบกำหนด</th>
                            <th class="text-center">ส่งมอบ</th>
                            <th class="text-center">จ่ายเงิน</th>
                            
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="15" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    ไม่พบข้อมูล
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $i => $row): ?>
                                <?php
                                    $returnUrl = 'report_overlap.php?' . http_build_query([
                                        'year' => $selected_year,
                                        'item' => $selected_item,
                                        'project' => $selected_project,
                                        'overlap_type' => $selected_overlap
                                    ]);

                                    $isOverlap = (($row['overlap_type'] ?? 'ไม่กันเหลื่อม') === 'กันเหลื่อม');
                                ?>
                                <tr class="<?= $isOverlap ? 'overlap-row' : '' ?>">
                                    <td class="text-center"><?= $i + 1 ?></td>
                                    <td class="nowrap"><?= h($row['fiscal_year']) ?></td>
                                    <td><?= h($row['item_name']) ?></td>
                                    <td><?= h($row['detail_name']) ?></td>
                                    <td class="nowrap"><?= h($row['contract_number']) ?></td>
                                    <td><?= h($row['contractor_name']) ?></td>
                                    <td class="text-center"><?= h($row['phase_number']) ?></td>
                                    <td><?= nl2br(h($row['phase_name'])) ?></td>
                                    <td class="text-center"><?= h($row['status']) ?></td>
                                    <td class="text-center">
                                        <?php if ($isOverlap): ?>
                                            <span class="badge badge-overlap">กันเหลื่อม</span>
                                        <?php else: ?>
                                            <span class="badge badge-normal">ไม่กันเหลื่อม</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="number"><?= number_format((float)$row['amount'], 2) ?></td>
                                    <td class="text-center nowrap"><?= h(thaiDate($row['due_date'])) ?></td>
                                    <td class="text-center nowrap"><?= h(thaiDate($row['completion_date'])) ?></td>
                                    <td class="text-center nowrap"><?= h(thaiDate($row['payment_date'])) ?></td>
                                  
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                    <?php if ($rows): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="10" class="text-end">รวมทั้งสิ้น</th>
                            <th class="number text-primary"> <?= number_format($totalAmountAll, 2) ?></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('tableSearch')?.addEventListener('keyup', function() {
    const keyword = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#reportTable tbody tr');

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(keyword) ? '' : 'none';
    });
});
</script>
<script>
function toggleProject(id){
    let el = document.getElementById('proj'+id);
    el.style.display = (el.style.display === 'none') ? 'block' : 'none';
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>