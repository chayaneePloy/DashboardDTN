<?php
include 'db.php';

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function thai_date($date){
    if(!$date) return '';
    $m=['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
        'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d=new DateTime($date);
    return $d->format('j').' '.$m[$d->format('n')].' '.($d->format('Y')+543);
}

/* ================= ปี ================= */
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_items ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$fiscalYear = $_GET['year'] ?? ($years[0] ?? date('Y'));
$fy = $fiscalYear - 543;

/* ================= เปอร์เซ็นต์แผนรายไตรมาส ================= */
$stmtPlan = $pdo->prepare("
    SELECT 
        q1_percent,
        q2_percent,
        q3_percent,
        q4_percent
    FROM budget_act
    WHERE fiscal_year = ?
    LIMIT 1
");
$stmtPlan->execute([$fiscalYear]);
$plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

$q1Percent = (float)($plan['q1_percent'] ?? 0);
$q2Percent = (float)($plan['q2_percent'] ?? 0);
$q3Percent = (float)($plan['q3_percent'] ?? 0);
$q4Percent = (float)($plan['q4_percent'] ?? 0);

$planPercent = [
    1 => $q1Percent,
    2 => $q2Percent,
    3 => $q3Percent,
    4 => $q4Percent
];

/* ================= วงเงินรวมทั้งปี (ฐานคิด %) ================= */

/* ใช้งบที่ได้รับจริงของแต่ละโครงการ */
$stmtTotalBudget = $pdo->prepare("
    SELECT COALESCE(SUM(bd.budget_received),0)
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = ?
");
$stmtTotalBudget->execute([$fiscalYear]);
$yearTotalBudget = (float)$stmtTotalBudget->fetchColumn();

/* ================= ช่วงสะสม ================= */
$rangeAll = [
    1 => [($fy-1).'-10-01', ($fy-1).'-12-31'],
    2 => [($fy-1).'-10-01', $fy.'-03-31'],
    3 => [($fy-1).'-10-01', $fy.'-06-30'],
    4 => [($fy-1).'-10-01', $fy.'-09-30'],
];

$allData = [];
$sumQuarter = [1=>0,2=>0,3=>0,4=>0];
$qPercent = [1=>0,2=>0,3=>0,4=>0];

/* ================= ดึงข้อมูลแต่ละไตรมาส ================= */
for($q=1;$q<=4;$q++){
    [$qs,$qe] = $rangeAll[$q];

    $sql = "
    SELECT 
        bi.item_name, 
        bd.detail_name AS project_name,
        c.contract_number, 
        p.phase_number,
        p.payment_date, 
        p.amount
    FROM phases p
    JOIN contracts c ON c.contract_id = p.contract_detail_id
    JOIN budget_detail bd ON bd.id_detail = c.detail_item_id
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
      AND p.status = 'เสร็จสิ้น'
      AND p.payment_date BETWEEN :qs AND :qe
    ORDER BY bi.item_name, bd.detail_name, p.payment_date
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fy' => $fiscalYear,
        ':qs' => $qs,
        ':qe' => $qe
    ]);

    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){
        $allData[$q][$r['item_name']][$r['project_name']][] = $r;
        $sumQuarter[$q] += (float)$r['amount'];
    }

    // % ใช้จ่ายสะสมของแต่ละไตรมาส
    $qPercent[$q] = ($yearTotalBudget > 0)
        ? ($sumQuarter[$q] / $yearTotalBudget) * 100
        : 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงานภาพรวมสะสมไตรมาส</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />

<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logo3.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
body {
    font-family: 'Sarabun', sans-serif;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 10mm;
    }

    body {
        zoom: 75%;
    }

    .no-print,
    nav,
    .btn,
    select {
        display: none !important;
    }

    .row {
        display: flex !important;
        flex-wrap: nowrap !important;
    }

    .col-md-3 {
        width: 25% !important;
        flex: 0 0 25% !important;
    }

    .alert {
        page-break-inside: avoid;
    }

    .alert .col-md-3 {
        width: 25% !important;
        flex: 0 0 25% !important;
    }

    .alert .row {
        display: flex !important;
        flex-wrap: nowrap !important;
    }
}

.card-q1{border-left:6px solid #0d6efd}
.card-q2{border-left:6px solid #198754}
.card-q3{border-left:6px solid #ffc107}
.card-q4{border-left:6px solid #dc3545}

.card-header-q1{background:#e7f1ff;}
.card-header-q2{background:#eaf7ee;}
.card-header-q3{background:#fff8e1;}
.card-header-q4{background:#fdeaea;}

.summary-box{
    border-radius: 12px;
    transition: 0.2s ease;
}
.summary-box:hover{
    transform: translateY(-2px);
}

.small-label{
    font-size: 0.9rem;
    color: #6c757d;
}
</style>
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm no-print">
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
                       href="add_budget_act.php?year=<?= h($fiscalYear) ?>">
                        เพิ่มงบตาม พ.ร.บ.+เป้าหมายไตรมาส
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard.php?year=<?= h($fiscalYear) ?>">
                        เพิ่มงบตามวงเงินสัญญา
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard_report.php?year=<?= h($fiscalYear) ?>">
                        เพิ่มการจ่ายงวดงาน
                    </a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link fs-5 text-white px-3"
                       href="#" id="reportDropdown" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        รายงาน
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="reportDropdown">
                        <li><a class="dropdown-item" href="report_project_full.php?year=<?= h($fiscalYear) ?>">รายงานจัดซื้อจัดจ้าง</a></li>
                        <li><a class="dropdown-item" href="report.php?year=<?= h($fiscalYear) ?>">รายงานการจ่ายงวดงาน</a></li>
                        <li><a class="dropdown-item" href="report_all.php?year=<?= h($fiscalYear) ?>">รายงานรวมจัดซื้อจัดจ้าง/การจ่ายงวดงาน</a></li>
                        <li><a class="dropdown-item" href="report_landscape.php?year=<?= h($fiscalYear) ?>">รายงานภาพรวมงบประมาณตามไตรมาส</a></li>
                        <li><a class="dropdown-item" href="report_overlap.php?year=<?= h($fiscalYear) ?>">รายงานงบกันเหลื่อม</a></li>
                          
                    </ul>
                </li>
            </ul>

            <span class="ms-auto text-white fs-5 fw-semibold d-none d-lg-block">
                กรมเจรจาการค้าระหว่างประเทศ
            </span>
        </div>
    </div>
</nav>

<div class="container-fluid my-4">

    <!-- FILTER -->
    <form class="row g-2 mb-3 no-print">
        <div class="col-md-2">
            <select name="year" class="form-select" onchange="this.form.submit()">
                <?php foreach($years as $y): ?>
                    <option value="<?= h($y) ?>" <?= $y==$fiscalYear?'selected':'' ?>><?= h($y) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6 d-flex justify-content-end ms-auto gap-2">
            <button type="button" onclick="printReport()" class="btn btn-secondary">
                🖨 พิมพ์
            </button>

            <button type="button" onclick="exportExcel()" class="btn btn-success">
                📥 Excel
            </button>
        </div>
    </form>

    <h3 class="text-center text-primary fw-bold mb-3">
        📊 รายงานไตรมาส ปี <?= h($fiscalYear) ?>
    </h3>

    <!-- เป้าหมายรายไตรมาส -->
    <div class="alert alert-light border mt-3 mb-3 shadow-sm">
        <div class="row text-center g-2">
            <?php for($q=1;$q<=4;$q++): ?>
                <div class="col-md-3">
                    <div class="p-3 border rounded bg-white h-100 summary-box">
                        <div class="fw-bold text-primary mb-2">ไตรมาส <?= $q ?></div>
                        <div class="fs-4 fw-bold text-success">
                            <?= number_format($planPercent[$q] ?? 0, 2) ?>%
                        </div>
                        <small class="text-muted">เป้าหมายการใช้จ่าย</small>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- % ใช้จ่ายสะสม 
    <div class="alert alert-light border mt-3 mb-3 shadow-sm">
        <div class="row text-center g-2">
            <?php for($q=1;$q<=4;$q++): ?>
                <div class="col-md-3">
                    <div class="p-3 border rounded bg-white h-100 summary-box">
                        <div class="fw-bold text-primary mb-2">ไตรมาส <?= $q ?></div>
                        <div class="fs-4 fw-bold text-danger">
                            <?= number_format($qPercent[$q] ?? 0, 2) ?>%
                        </div>
                        <small class="text-muted">% ใช้จ่ายสะสม</small>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div> -->

    <div id="reportTable">
        <div class="row">

            <?php for($q=1;$q<=4;$q++): ?>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm card-q<?= $q ?>">

                    <div class="card-header text-center fw-bold card-header-q<?= $q ?>">
                        ไตรมาส <?= $q ?>
                    </div>

                    <div class="px-3 pt-2 text-end fw-bold text-primary">
                        % ใช้จ่ายสะสม: <?= number_format($qPercent[$q] ?? 0, 2) ?>%
                    </div>

                    <div class="card-body" style="max-height:600px; overflow:auto;">

                        <?php if(!empty($allData[$q])): ?>

                            <?php foreach($allData[$q] as $item=>$projects): ?>
                                <div class="fw-bold text-primary mt-2"><?= h($item) ?></div>

                                <?php foreach($projects as $project=>$rows): ?>
                                    <div class="ms-2 mb-2">
                                        <div class="fw-semibold"><?= h($project) ?></div>

                                        <table class="table table-sm table-bordered table-striped mt-1">
                                            <tr class="table-light">
                                                <th>สัญญา</th>
                                                <th>งวด</th>
                                                <th>วันที่</th>
                                                <th class="text-end">จำนวนเงิน</th>
                                            </tr>

                                            <?php foreach($rows as $r): ?>
                                            <tr>
                                                <td><?= h($r['contract_number']) ?></td>
                                                <td><?= h($r['phase_number']) ?></td>
                                                <td><?= thai_date($r['payment_date']) ?></td>
                                                <td class="text-end"><?= number_format((float)$r['amount'],2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>

                            <hr>
                            <div class="text-end fw-bold text-success">
                                รวมสะสม: <?= number_format($sumQuarter[$q],2) ?> บาท
                            </div>

                        <?php else: ?>
                            <div class="text-muted text-center">ไม่มีข้อมูล</div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
            <?php endfor; ?>

        </div>
    </div>
</div>

<script>
function printReport(){
    document.body.classList.add("print-mode");

    setTimeout(()=>{
        window.print();
        document.body.classList.remove("print-mode");
    }, 300);
}
</script>

<script>
function exportExcel(){

let html = `
<html xmlns:o="urn:schemas-microsoft-com:office:office"
xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta charset="UTF-8">

<style>
@page { size: A4 landscape; }
body { font-family: Tahoma, sans-serif; }
table { border-collapse: collapse; width:100%; }
td, th { vertical-align: top; border:1px solid #000; padding:5px; }
</style>

</head>

<body>

<h3>รายงานสะสม 4 ไตรมาส ปี <?= h($fiscalYear) ?></h3>

<table>
<tr>
<th>ไตรมาส 1</th>
<th>ไตรมาส 2</th>
<th>ไตรมาส 3</th>
<th>ไตรมาส 4</th>
</tr>

<tr>
<td>${getQ(1)}</td>
<td>${getQ(2)}</td>
<td>${getQ(3)}</td>
<td>${getQ(4)}</td>
</tr>
</table>

</body>
</html>
`;

let blob = new Blob([html], {type: "application/vnd.ms-excel"});
let url = URL.createObjectURL(blob);

let a = document.createElement("a");
a.href = url;
a.download = "quarter_report_<?= h($fiscalYear) ?>.xls";
a.click();

URL.revokeObjectURL(url);
}

function getQ(q){
    let el = document.querySelector(".card-q"+q);
    return el ? el.innerHTML : "ไม่มีข้อมูล";
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>