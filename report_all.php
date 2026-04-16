<?php 
// ================= CONNECT =================
include 'db.php';
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : max($years);
// ================= FUNCTION =================
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function thaiDate($date){
    if(!$date || $date=='0000-00-00') return '';
    return date('d/m/', strtotime($date)) . (date('Y', strtotime($date)) + 543);
}

// ================= PARAM =================
$year       = $_GET['year'] ?? '';
$budgetItem = $_GET['budget_item'] ?? 'all';
$detailId   = $_GET['detail_id'] ?? 'all';

// ================= YEAR LIST =================
$years = $pdo->query("
    SELECT DISTINCT fiscal_year 
    FROM budget_items 
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ================= BUDGET ITEM LIST =================
$budgetItems = [];
if($year){
    $stmt = $pdo->prepare("
        SELECT id, item_name
        FROM budget_items
        WHERE fiscal_year = ?
        ORDER BY item_name
    ");
    $stmt->execute([$year]);
    $budgetItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================= PROJECT LIST =================
$projects = [];
if($year){
    $sql = "
        SELECT bi.item_name, bd.*
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ?
    ";
    $params = [$year];

    if($budgetItem !== 'all'){
        $sql .= " AND bi.id = ?";
        $params[] = $budgetItem;
    }

    if($detailId !== 'all'){
        $sql .= " AND bd.id_detail = ?";
        $params[] = $detailId;
    }

    $sql .= " ORDER BY bi.item_name, bd.detail_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
<div class="container mt-4">

<h3 class="fw-bold text-primary">
📊 รายงานโครงการประจำปี <?=h($year)?>
</h3>

<!-- FILTER -->
<form method="get" class="row g-2 align-items-end mb-4 no-print">
<div class="col-md-2">
<label>ปีงบประมาณ</label>
<select name="year" class="form-select" onchange="this.form.submit()">
<option value="">-- เลือกปี --</option>
<?php foreach($years as $y): ?>
<option value="<?=h($y)?>" <?= $year==$y?'selected':'' ?>>
<?=h($y)?>
</option>
<?php endforeach ?>
</select>
</div>

<div class="col-md-3">
<label>ประเภท</label>
<select name="budget_item" class="form-select" <?= !$year?'disabled':'' ?> onchange="this.form.submit()">
<option value="all">ทั้งหมด</option>
<?php foreach($budgetItems as $bi): ?>
<option value="<?=h($bi['id'])?>" <?= $budgetItem==$bi['id']?'selected':'' ?>>
<?=h($bi['item_name'])?>
</option>
<?php endforeach ?>
</select>
</div>

<div class="col-md-4">
<label>โครงการ</label>
<select name="detail_id" class="form-select" <?= !$year?'disabled':'' ?> onchange="this.form.submit()">
<option value="all">ทุกโครงการ</option>
<?php
$details = [];
if($year){
    $params = [$year];

$sql = "
    SELECT bd.id_detail, bd.detail_name
    FROM budget_detail bd
    JOIN budget_items bi ON bd.budget_item_id = bi.id
    WHERE bi.fiscal_year = ?
";

if($budgetItem !== 'all'){
    $sql .= " AND bi.id = ?";
    $params[] = $budgetItem;
}

$sql .= " ORDER BY bd.detail_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);
   
}
foreach($details as $d):
?>
<option value="<?=h($d['id_detail'])?>" <?= $detailId==$d['id_detail']?'selected':'' ?>>
<?=h($d['detail_name'])?>
</option>
<?php endforeach ?>
</select>
</div>

<div class="col-md-3 text-end">
<button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ พิมพ์</button>
<button type="button" onclick="exportExcel()" class="btn btn-success">📥 Export Excel</button>
</div>
</form>

<?php if($year && $projects): ?>
<div id="reportTable">

<?php foreach($projects as $p): ?>
<div class="project-box">

<h5 class="fw-bold text-primary"><?=h($p['detail_name'])?></h5>

<p>
<strong>หมวดงบ:</strong> <?=h($p['item_name'])?> |
<strong>งบที่ได้รับ:</strong> <?=number_format($p['budget_received'],2)?> บาท |
<strong>วงเงินตามสัญญา:</strong> <?=number_format($p['requested_amount'],2)?> บาท
</p>

<!-- 1) ขั้นตอนจัดซื้อจัดจ้าง -->
<?php
$stmt = $pdo->prepare("SELECT * FROM project_steps 
WHERE id_budget_detail=? ORDER BY step_date");
$stmt->execute([$p['id_detail']]);
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h6 class="mt-3 fw-bold">1) ขั้นตอนจัดซื้อจัดจ้าง</h6>
<table class="table table-bordered">
     <colgroup>
        <col style="width: 10%;">
        <col style="width: 30%;">
        <col style="width: 15%;">
        <col style="width: 45%;">
    </colgroup>
<tr class="table-light center">
<th>ลำดับ</th>
<th>ขั้นตอน</th>
<th>วันที่ดำเนินการ</th>
<th>รายละเอียด</th>
</tr>
<?php 
$stepNo = 1;
foreach($steps as $s): 
    if(empty($s['step_date']) || $s['step_date'] == '0000-00-00' || $s['step_date'] == '0000-00-00 00:00:00') {
        continue;
    }
?>
<tr>
<td><?= $stepNo++ ?></td>
<td><?=h($s['step_name'])?></td>
<td><?=thaiDate($s['step_date'])?></td>
 <td><?=nl2br(h($s['step_description']))?></td>
</tr>
<?php endforeach ?>
</table>

<!-- 2) ข้อมูลสัญญา -->
<?php
$stmt = $pdo->prepare("SELECT * FROM contracts WHERE detail_item_id=?");
$stmt->execute([$p['id_detail']]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h6 class="mt-3 fw-bold">2) ข้อมูลสัญญา</h6>
<table class="table table-bordered">
<tr class="table-light">
<th>เลขที่สัญญา</th>
<th>ผู้รับจ้าง</th>
<th>วันที่ลงนาม</th>
<th>สิ้นสุดสัญญา</th>
</tr>
<?php foreach($contracts as $c): ?>
<tr>
<td><?=h($c['contract_number'])?></td>
<td><?=h($c['contractor_name'])?></td>
<td><?=thaiDate($c['contract_date'])?></td>
<td><?=thaiDate($c['contract_ends'])?></td>
</tr>
<?php endforeach ?>
</table>

<!-- 3) งวดงาน -->
<?php
$stmt = $pdo->prepare("
SELECT * FROM phases p
LEFT JOIN contracts c ON p.contract_detail_id = c.contract_id
WHERE c.detail_item_id=?
ORDER BY p.phase_number
");
$stmt->execute([$p['id_detail']]);
$phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h6 class="mt-3 fw-bold">3) งวดงาน</h6>
<table class="table table-bordered">
<tr class="table-light">
        <colgroup>
        <col style="width: 8%;">   <!-- งวด -->
        <col style="width: 14%;">  <!-- วันที่เริ่ม -->
        <col style="width: 14%;">  <!-- วันที่สิ้นสุด -->
        <col style="width: 18%;">  <!-- จำนวนเงิน -->
        <col style="width: 16%;">  <!-- ยอดคงเหลือ -->
        <col style="width: 12%;">  <!-- สถานะ -->
        <col style="width: 20%;">  <!-- รายละเอียด -->
    </colgroup>
<th>งวด</th>
<th>วันที่เริ่ม</th>
<th>วันที่สิ้นสุด</th>
<th class="text-end">จำนวนเงิน</th>
<th class="text-end">ยอดคงเหลือ</th>
<th>สถานะ</th>
<th>รายละเอียด</th>
</tr>

<?php 
$contractAmount = $p['requested_amount'];
$paidTotal = 0;

foreach($phases as $ph):

$remaining = '-';

if($ph['status'] == 'เสร็จสิ้น'){
    $paidTotal += $ph['amount'];
    $remaining = number_format($contractAmount - $paidTotal,2);
}
?>
<tr>
<td><?=h($ph['phase_number'])?></td>
<td><?=thaiDate($ph['due_date'])?></td>
<td><?=thaiDate($ph['completion_date'])?></td>
<td class="text-end"><?=number_format($ph['amount'],2)?></td>
<td class="text-end"><?=$remaining?></td>
<td><?=h($ph['status'])?></td>
<td><?=h($ph['phase_name'])?></td>
</tr>
<?php endforeach ?>
</table>

</div>
<?php endforeach; ?>
</div>

<?php elseif($year): ?>
<div class="alert alert-warning">ไม่พบข้อมูล</div>
<?php endif; ?>

</div>

<script>
function exportExcel(){
    let table = document.getElementById("reportTable").outerHTML;

    let html = `
    <html xmlns:o="urn:schemas-microsoft-com:office:office" 
          xmlns:x="urn:schemas-microsoft-com:office:excel" 
          xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"></head>
    <body>${table}</body>
    </html>`;

    let blob = new Blob([html], { type: "application/vnd.ms-excel" });
    let a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "report_<?=h($year)?>.xls";
    a.click();
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>