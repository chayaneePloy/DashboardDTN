<?php
function h($v){
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
include 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ------------------ FILTER ------------------
$year = $_GET['year'] ?? '';
$keyword = $_GET['keyword'] ?? '';

// ------------------ ปีงบประมาณ ------------------
$years = $pdo->query("
    SELECT DISTINCT fiscal_year 
    FROM budget_items 
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

if (!$years) {
    $years = [date('Y') + 543];
}

$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : max($years);
// ------------------ QUERY ------------------
$sql = "
SELECT 
    bi.fiscal_year,
    bd.id_detail,
    bd.detail_name,
    bd.budget_received,
    bd.requested_amount,
    bd.tor_secretary,
    bd.procurement_secretary,
    bd.inspector_secretary,

    MAX(c.contract_number) as contract_number,
    MAX(c.contractor_name) as contractor_name,

    COUNT(p.phase_id) as total_phase_count,

    SUM(
        CASE 
            WHEN p.status IN ('รอดำเนินการ','อยู่ระหว่างดำเนินการ','เสร็จสิ้น')
            THEN p.amount 
            ELSE 0 
        END
    ) as paid_amount

FROM budget_detail bd
JOIN budget_items bi ON bd.budget_item_id = bi.id
LEFT JOIN contracts c ON c.detail_item_id = bd.id_detail
LEFT JOIN phases p ON p.contract_detail_id = c.contract_id
WHERE 1
";

$params = [];

if ($year) {
    $sql .= " AND bi.fiscal_year = ? ";
    $params[] = $year;
}

if ($keyword) {
    $sql .= " AND bd.detail_name LIKE ? ";
    $params[] = "%$keyword%";
}

$sql .= " GROUP BY bd.id_detail ORDER BY bi.fiscal_year DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------ EXPORT EXCEL ------------------
if (isset($_GET['export'])) {

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=report.xls");

    // ✅ กันภาษาไทยพัง
    echo "\xEF\xBB\xBF";

    echo "ปี\tโครงการ\tงบ\tราคาจ้าง\tเลขา TOR\tเลขา จัดจ้าง\tเลขา ตรวจรับ\tจำนวนงวด\tรวมจ่ายแล้ว\n";

    foreach ($data as $r) {
        echo "{$r['fiscal_year']}\t"
            ."{$r['detail_name']}\t"
            ."{$r['budget_received']}\t"
            ."{$r['requested_amount']}\t"
            ."{$r['tor_secretary']}\t"
            ."{$r['procurement_secretary']}\t"
            ."{$r['inspector_secretary']}\t"
            ."{$r['total_phase_count']}\t"
            ."{$r['paid_amount']}\n";
    }

    exit;
}
?>

<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>รายงานโครงการ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
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
/* ===== TABLE UI ===== */
.custom-table {
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
}

/* หัวตาราง */
.custom-table thead th {
    background-color: #eef2f7; /* เทาอ่อน */
    color: #2c3e50;
    font-weight: 600;
    padding: 12px;
    border-bottom: 2px solid #dee2e6;
}

/* แถว */
.custom-table tbody td {
    padding: 10px;
    font-size: 14px;
}

/* สลับสี */
.custom-table tbody tr:nth-child(even) {
    background-color: #f8f9fa;
}

/* hover */
.custom-table tbody tr:hover {
    background-color: #eaf1ff;
    transition: 0.2s;
}

/* ตัวเลข */
.text-number {
    text-align: right;
    font-weight: 500;
}

/* เงิน */
.text-money {
    text-align: right;
    font-weight: 600;
    color: #198754;
}
</style>
</head>
<body class="bg-light">
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
                       เพิ่มงบตาม พ.ร.บ./เป้าหมายไตรมาส
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard.php?year=<?= htmlspecialchars($selectedYear) ?>">
                        เพิ่มปีงบ/โครงการ
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
                               href="report_full.php?year=<?= htmlspecialchars($selectedYear) ?>">
                                รายงานภาพรวมโครงการ
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

<h3 class="mb-3">📊 รายงานภาพรวมโครงการ</h3>

<!-- 🔍 FILTER -->
<form class="row g-2 mb-3 no-print">

<div class="col-md-2">
<select name="year" class="form-select" onchange="this.form.submit()">
    <option value="">-- ทุกปีงบประมาณ --</option>
    <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>>
            <?= $y ?>
        </option>
    <?php endforeach; ?>
</select>
</div>

<div class="col-md-4">
<input type="text" name="keyword" class="form-control" placeholder="ค้นหาโครงการ"
value="<?= htmlspecialchars($keyword) ?>">
</div>

<div class="col-md-6 d-flex gap-2">
<button class="btn btn-primary">ค้นหา</button>

<a href="report_full.php" class="btn btn-secondary">รีเซ็ต</a>

<button type="button" onclick="window.print()" class="btn btn-dark">
🖨 พิมพ์
</button>

<a href="?export=1&year=<?= $year ?>&keyword=<?= $keyword ?>" 
class="btn btn-success">
📥  Excel
</a>
</div>

</form>

<!-- 📊 TABLE -->
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle custom-table mb-0">

<thead class="text-center">
<tr>
<th>#</th>
<th>ปี</th>
<th>โครงการ</th>
<th>วงเงินตามสัญญา</th>
<th>เลขที่สัญญา</th>
<th>ผู้รับจ้าง</th>
<th>จำนวนงวด</th>
<th>จ่ายแล้ว</th>
<th>เลขาร่างTOR</th>
<th>เลขาจัดจ้าง</th>
<th>เลขาตรวจรับ</th>
</tr>
</thead>

<tbody>
<?php if ($data): foreach ($data as $i => $r): ?>
<tr>

<td><?= $i+1 ?></td>

<td><?= $r['fiscal_year'] ?></td>

<td><?= htmlspecialchars($r['detail_name']) ?></td>


<td class="text-end"><?= number_format($r['requested_amount'],2) ?></td>



<td><?= h($r['contract_number']) ?></td>

<td><?= h($r['contractor_name']) ?></td>

<td class="text-center"><?= $r['total_phase_count'] ?></td>

<td class="text-end text-success fw-bold">
    <?= number_format($r['paid_amount'],2) ?>
</td>
<td><?= nl2br(htmlspecialchars($r['tor_secretary'])) ?></td>

<td><?= nl2br(htmlspecialchars($r['procurement_secretary'])) ?></td>

<td><?= nl2br(htmlspecialchars($r['inspector_secretary'])) ?></td>

</tr>
<?php endforeach; else: ?>
<tr><td colspan="12" class="text-center text-muted">ไม่มีข้อมูล</td></tr>
<?php endif; ?>
</tbody>

</table>
</div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>