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


/* ================= ช่วงสะสม ================= */
$rangeAll = [
    1 => [($fy-1).'-10-01', ($fy-1).'-12-31'],
    2 => [($fy-1).'-10-01', $fy.'-03-31'],
    3 => [($fy-1).'-10-01', $fy.'-06-30'],
    4 => [($fy-1).'-10-01', $fy.'-09-30'],
];

$allData=[];
$sumQuarter=[1=>0,2=>0,3=>0,4=>0];

for($q=1;$q<=4;$q++){
    [$qs,$qe] = $rangeAll[$q];

    $sql="
    SELECT bi.item_name, bd.detail_name project_name,
           c.contract_number, p.phase_number,
           p.payment_date, p.amount
    FROM phases p
    JOIN contracts c ON c.contract_id=p.contract_detail_id
    JOIN budget_detail bd ON bd.id_detail=c.detail_item_id
    JOIN budget_items bi ON bi.id=bd.budget_item_id
    WHERE bi.fiscal_year=:fy
    AND p.status='เสร็จสิ้น'
    AND p.payment_date BETWEEN :qs AND :qe
    ORDER BY bi.item_name, bd.detail_name
    ";

    $stmt=$pdo->prepare($sql);
    $stmt->execute([
        ':fy'=>$fiscalYear,
        ':qs'=>$qs,
        ':qe'=>$qe
    ]);

    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){
        $allData[$q][$r['item_name']][$r['project_name']][]=$r;
        $sumQuarter[$q]+=$r['amount'];
    }
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
@media print {

  @page {
    size: A4 landscape;
    margin: 10mm;
  }

  body {
    zoom: 75%;
  }

  /* 🔥 บังคับ 4 ไตรมาสให้อยู่แถวเดียว */
  .row {
    display: flex !important;
    flex-wrap: nowrap !important;
  }

  .col-md-3 {
    width: 25% !important;
    flex: 0 0 25% !important;
  }

}

.card-q1{border-left:6px solid #0d6efd}
.card-q2{border-left:6px solid #198754}
.card-q3{border-left:6px solid #ffc107}
.card-q4{border-left:6px solid #dc3545}
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
                       href="add_budget_act.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                        เพิ่มงบตาม พ.ร.บ.
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                        เพิ่มงบตามวงเงินสัญญา
                    </a>
                </li>
                

                <li class="nav-item">
                    <a class="nav-link fs-5 text-white px-3"
                       href="dashboard_report.php?year=<?= htmlspecialchars($fiscalYear) ?>">
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
                               href="report_project_full.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                                รายงานจัดซื้อจัดจ้าง
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="report.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                                รายงานการจ่ายงวดงาน
                            </a>
                        </li>
                           <li>
                            <a class="dropdown-item"
                               href="report_all.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                                รายงานรวมจัดซื้อจัดจ้าง/การจ่ายงวดงาน
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="report_landscape.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                                รายงานภาพรวมงบประมาณตามไตรมาส
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
<div class="container-fluid my-4">

<h3 class="text-center text-primary fw-bold mb-3">
📊 รายงานไตรมาส ปี <?=$fiscalYear?>
</h3>

<!-- 🔥 filter -->
<form class="row g-2 mb-3 no-print">

<div class="col-md-2">
<select name="year" class="form-select" onchange="this.form.submit()">
<?php foreach($years as $y): ?>
<option value="<?=$y?>" <?=$y==$fiscalYear?'selected':''?>><?=$y?></option>
<?php endforeach;?>
</select>
</div>

<div class="col-md-6">
<button type="button" onclick="printReport()" class="btn btn-secondary">
🖨 พิมพ์
</button>

<button type="button" onclick="exportExcel()" class="btn btn-success">
📥 Excel
</button>
</div>

</form>

<div id="reportTable">

<div class="row">

<?php for($q=1;$q<=4;$q++): ?>
<div class="col-md-3 mb-3">

<div class="card shadow-sm card-q<?=$q?>">
<div class="card-header text-center fw-bold">
ไตรมาส <?=$q?>
</div>

<div class="card-body" style="max-height:600px; overflow:auto;">

<?php if(!empty($allData[$q])): ?>

<?php foreach($allData[$q] as $item=>$projects): ?>
<div class="fw-bold text-primary"><?=$item?></div>

<?php foreach($projects as $project=>$rows): ?>
<div class="ms-2 mb-2">
<div><?=$project?></div>

<table class="table table-sm table-bordered table-striped">
<tr>
<th>สัญญา</th>
<th>งวด</th>
<th>วันที่</th>
<th class="text-end">จำนวนเงิน</th>
</tr>

<?php foreach($rows as $r): ?>
<tr>
<td><?=$r['contract_number']?></td>
<td><?=$r['phase_number']?></td>
<td><?=thai_date($r['payment_date'])?></td>
<td class="text-end"><?=number_format($r['amount'],2)?></td>
</tr>
<?php endforeach; ?>

</table>
</div>
<?php endforeach; ?>

<?php endforeach; ?>

<hr>
<div class="text-end fw-bold text-success">
รวมสะสม: <?=number_format($sumQuarter[$q],2)?> บาท
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
<!-- 🔥 Excel แนวนอน -->
<script>
function exportExcel(){

let html = `
<html xmlns:o="urn:schemas-microsoft-com:office:office"
xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta charset="UTF-8">

<style>
@page { size: A4 landscape; }
table { border-collapse: collapse; width:100%; }
td { vertical-align: top; border:1px solid #000; padding:5px; }
</style>

</head>

<body>

<h3>รายงานสะสม 4 ไตรมาส</h3>

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
a.download = "quarter_report.xls";
a.click();

URL.revokeObjectURL(url);
}

/* 🔥 ดึง content แต่ละไตรมาส */
function getQ(q){
    let el = document.querySelector(".card-q"+q);
    return el ? el.innerHTML : "ไม่มีข้อมูล";
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>