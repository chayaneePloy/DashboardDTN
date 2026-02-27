<?php
include 'db.php';

/* ===================== FUNCTIONS ===================== */
function h($s){
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function thai_date($date){
    if(!$date || $date=='0000-00-00') return '';
    $m=['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
        'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d = new DateTime($date);
    return $d->format('j').' '.$m[(int)$d->format('n')].' '.($d->format('Y')+543);
}

/* ===== ช่วงวันที่ตามไตรมาส (ปีงบ พ.ศ.) ===== */
function quarterRangeBE($yearBE,$q){
    $start = ($yearBE-1).'-10-01';
    $end = match($q){
        1 => ($yearBE-1).'-12-31',
        2 => $yearBE.'-03-31',
        3 => $yearBE.'-06-30',
        default => $yearBE.'-09-30',
    };
    return [$start,$end];
}


/* ===================== ปีงบ ===================== */
$years = $pdo->query("
    SELECT DISTINCT fiscal_year
    FROM budget_items
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$fiscalYear = $_GET['year'] ?? ($years[0] ?? date('Y'));
//$fiscalYear2 = $_GET['year'] ?? ($years[0] ?? date('Y')-543);

$fiscalYear2 = $_GET['year'] - 543;
$quarter    = (int)($_GET['quarter'] ?? 1);
$itemId     = $_GET['item'] ?? '';

[$qs,$qe] = quarterRangeBE($fiscalYear2,$quarter);
//print_r([$qs, $qe]);

/* ===================== SQL ===================== */
$sql = "
SELECT
    bi.item_name,
    bd.detail_name AS project_name,
    c.contract_number,
    p.phase_number,
    p.payment_date AS ref_date,
    p.amount,
	bi.fiscal_year,
	p.status
FROM phases p
JOIN contracts c       ON c.contract_id = p.contract_detail_id
JOIN budget_detail bd  ON bd.id_detail = c.detail_item_id
JOIN budget_items bi   ON bi.id = bd.budget_item_id
WHERE bi.fiscal_year = :fy
  AND p.status = 'เสร็จสิ้น'
  AND p.payment_date BETWEEN :qs AND :qe
";


$params = [
    ':fy' => $fiscalYear,
    ':qs' => $qs,
    ':qe' => $qe
];

if($itemId){
    $sql .= " AND bi.id = :item ";
    $params[':item'] = $itemId;
}

$sql .= " ORDER BY bi.item_name, bd.detail_name, p.phase_number ";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
///
//$stmt = $pdo->prepare($sql);
///$stmt->execute($params);

//$stmt->debugDumpParams();


///
/* ===================== GROUP ===================== */
$data = [];
$sumByItem = [];

foreach($rows as $r){
    $data[$r['item_name']][$r['project_name']][] = $r;
    $sumByItem[$r['item_name']] =
        ($sumByItem[$r['item_name']] ?? 0) + $r['amount'];
}



$budgetColors = [
    'งบลงทุน'       => '#e3f2fd', // ฟ้าอ่อน
    'งบบูรณาการ'   => '#e8f5e9', // เขียวอ่อน
    'งบดำเนินงาน'  => '#fff8e1', // เหลืองอ่อน
    'งบรายจ่ายอื่น' => '#fce4ec', // ชมพูอ่อน
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
        <title>Dashboard งบประมาณโครงการ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logo3.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="styles.css">
<?php if(isset($_GET['export'])): ?>
<style>
table{border-collapse:collapse}
th,td{border:1px solid #000;padding:6px}
th{text-align:center;background:#eee}
.text-end{text-align:right}
.text-center{text-align:center}


</style>
<?php else: ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>@media print{.no-print{display:none}}</style>
<?php endif; ?>

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
                               href="report_all.php?year=<?= htmlspecialchars($selectedYear) ?>">
                                รายงานรวมจัดซื้อจัดจ้าง/การจ่ายงวดงาน
                            </a>
                        </li>
                          <li>
                            <a class="dropdown-item"
                               href="quarter_projects.php?year=<?= htmlspecialchars($fiscalYear) ?>">
                                รายงานเบิกจ่ายงบประมาณตามไตรมาส
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

<!-- ================= HEADER ================= -->
<div class="d-flex justify-content-between align-items-center mb-3" >
  <h3 class="fw-bold text-primary">
    📊 รายงานผลการเบิกจ่ายงบประมาณไตรมาส <?=$quarter?> ปีงบประมาณ <?=$fiscalYear?>
  </h3>
</div>


<?php if(!isset($_GET['export'])): ?>
<form class="row g-2 align-items-end mb-3 no-print">

<!-- ปีงบประมาณ -->
<div class="col-md-2">
    <label class="form-label">ปีงบประมาณ</label>
    <select name="year" class="form-select">
        <?php foreach($years as $y): ?>
        <option value="<?=$y?>" <?=$y==$fiscalYear?'selected':''?>>
            <?=$y?>
        </option>
        <?php endforeach;?>
    </select>
</div>

<!-- ไตรมาส -->
<div class="col-md-2">
    <label class="form-label">ไตรมาส</label>
    <select name="quarter" class="form-select">
        <?php for($i=1;$i<=4;$i++): ?>
        <option value="<?=$i?>" <?=$quarter==$i?'selected':''?>>
            ไตรมาส <?=$i?>
        </option>
        <?php endfor;?>
    </select>
</div>

<!-- ประเภทงบ -->
<div class="col-md-3">
    <label class="form-label">ประเภทงบ</label>
    <select name="item" class="form-select">
        <option value="">ทุกประเภทงบ</option>
        <?php
        $items=$pdo->prepare("
            SELECT id,item_name
            FROM budget_items
            WHERE fiscal_year=?
        ");
        $items->execute([$fiscalYear]);
        foreach($items as $it):
        ?>
        <option value="<?=$it['id']?>" <?=$itemId==$it['id']?'selected':''?>>
            <?=h($it['item_name'])?>
        </option>
        <?php endforeach;?>
    </select>
</div>

<!-- ปุ่มแสดง -->
<div class="col-md-2 d-grid">
    <label class="form-label">&nbsp;</label>
    <button class="btn btn-primary fw-bold">แสดง</button>
</div>
<!-- ปุ่มพิมพ์ / Excel -->
<div class="col-md-3 text-start">
    <label class="form-label">&nbsp;</label><br>

    <a href="?<?=http_build_query(array_diff_key($_GET, ['export'=>1]))?>"
       onclick="window.print(); return false;"
       class="btn btn-secondary me-1">
       🖨 พิมพ์
    </a>

  <button type="button" onclick="exportExcel()" class="btn btn-success">
            📥 Excel
        </button>
</div>
</form>
   
<div class="container my-4">

<?php endif; ?>

<?php if(!$data): ?>
<div class="alert alert-warning text-center">ไม่พบข้อมูล</div>
<?php endif; ?>
<div id="reportTable">
    
     <h3>📊 ไตรมาส <?=$quarter?> ปีงบประมาณ <?=$fiscalYear?>
  </h3>

<?php foreach($data as $item => $projects): ?>
<?php
$bgColor = $budgetColors[$item] ?? '#f5f5f5';
?>

<h5 class="p-2 rounded fw-bold" style="background:<?=$bgColor?>">
    <?=$item?>
</h5>

<?php foreach($projects as $project => $rows): ?>
<b><?=$project?></b>

<div class="table-responsive mb-3">
<table class="table table-bordered table-striped" style="background:<?=$bgColor?>" >
<thead class="table-dark text-center" style="background:<?=$bgColor?>">
<tr>
    <th>เลขที่สัญญา</th>
    <th>งวด</th>
    <th>วันที่จ่าย</th>
    <th class="text-end">จำนวนเงิน (บาท)</th>
</tr>
</thead>

<tbody>
<?php $sum = 0; ?>
<?php foreach($rows as $r): $sum += $r['amount']; ?>
<tr>
    <td><?=$r['contract_number']?></td>
    <td class="text-center"><?=$r['phase_number']?></td>
    <td class="text-center"><?=thai_date($r['ref_date'])?></td>
    <td class="text-end"><?=number_format($r['amount'],2)?></td>
</tr>
<?php endforeach; ?>
</div>
</tbody>

<tfoot>
<tr class="table-secondary fw-bold">
    <td colspan="3" class="text-end">รวม</td>
    <td class="text-end"><?=number_format($sum,2)?></td>
</tr>
</tfoot>
</table>
</div>

<?php endforeach; ?>

<p class="text-end fw-bold">
รวม <?=number_format($sumByItem[$item] ?? 0, 2)?> บาท
</p>
<hr>

<?php endforeach; ?>


<script>
function exportExcel(){
    let table = document.getElementById("reportTable").outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(table);
    let a = document.createElement('a');
    a.href = url;
    a.download = 'report_<?=h($fiscalYear)?>_Q<?=$quarter?>.xls';
    a.click();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
