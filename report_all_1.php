<?php
// ================= CONNECT =================
include 'db.php';

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
        ORDER BY 
        CASE item_name
            WHEN 'งบลงทุน' THEN 1
            WHEN 'งบบูรณาการ' THEN 2
            WHEN 'งบดำเนินงาน' THEN 3
            WHEN 'งบรายจ่ายอื่น' THEN 4
            ELSE 5
        END
    ");
    $stmt->execute([$year]);
    $budgetItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================= PROJECT LIST =================
$projects = [];
if($year){
    $sql = "
        SELECT 
            bi.item_name,
            bd.*
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
<title>รายงานโครงการแบบต่อท้าย</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
@media print{ .no-print{ display:none } }
.date-nowrap{ white-space: nowrap; }
.project-box{
    border:1px solid #ccc;
    padding:20px;
    margin-bottom:40px;
    background:#fff;
}
</style>
</head>
<body class="bg-light">

<div class="container mt-4">

<h3 class="fw-bold text-primary">
📊 รายงานโครงการประจำปี <?=h($year)?>
</h3>

<!-- ================= FILTER ================= -->
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
<select name="budget_item" class="form-select"
<?= !$year?'disabled':'' ?>
onchange="this.form.submit()">
<option value="all">ทั้งหมด</option>
<?php foreach($budgetItems as $bi): ?>
<option value="<?=h($bi['id'])?>"
<?= $budgetItem==$bi['id']?'selected':'' ?>>
<?=h($bi['item_name'])?>
</option>
<?php endforeach ?>
</select>
</div>

<div class="col-md-4">
<label>โครงการ</label>
<select name="detail_id" class="form-select"
<?= !$year?'disabled':'' ?>
onchange="this.form.submit()">
<option value="all">ทุกโครงการ</option>
<?php
// โหลดรายการโครงการ
$details = [];
if($year){
    $stmt = $pdo->prepare("
        SELECT bd.id_detail, bd.detail_name
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ?
    ");
    $stmt->execute([$year]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
foreach($details as $d):
?>
<option value="<?=h($d['id_detail'])?>"
<?= $detailId==$d['id_detail']?'selected':'' ?>>
<?=h($d['detail_name'])?>
</option>
<?php endforeach ?>
</select>
</div>

<div class="col-md-3 text-end">
<button type="button" onclick="window.print()" class="btn btn-secondary">
🖨️ พิมพ์
</button>

<button type="button" onclick="exportExcel()" class="btn btn-success">
📥 Export Excel
</button>
</div>

</form>

<?php if($year && $projects): ?>

<div id="reportTable">

<?php foreach($projects as $p): ?>

<div class="project-box">

<h5 class="fw-bold text-primary">
<?=h($p['detail_name'])?>
</h5>

<p>
<strong>หมวดงบ:</strong> <?=h($p['item_name'])?> |
<strong>งบที่ได้รับ:</strong> <?=number_format($p['budget_received'],2)?> บาท
<strong>วงเงินตามสัญญา:</strong> <?=number_format($p['requested_amount'],2)?> บาท
</p>

<?php
$stmt = $pdo->prepare("SELECT * FROM contracts WHERE detail_item_id=?");
$stmt->execute([$p['id_detail']]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h6 class="mt-3 fw-bold">1) ข้อมูลสัญญา</h6>
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

<h6 class="mt-3 fw-bold">2) งวดงาน</h6>
<table class="table table-bordered">
<tr class="table-light">
<th>งวด</th>
<th>รายละเอียด</th>
<th>วันที่เริ่ม</th>
<th>วันที่สิ้นสุด</th>
<th>จำนวนเงิน</th>
<th>สถานะ</th>
</tr>
<?php foreach($phases as $ph): ?>
<tr>
<td><?=h($ph['phase_number'])?></td>
<td><?=h($ph['phase_name'])?></td>
<td><?=thaiDate($ph['due_date'])?></td>
<td><?=thaiDate($ph['completion_date'])?></td>
<td class="text-end"><?=number_format($ph['amount'],2)?></td>
<td><?=h($ph['status'])?></td>
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
    <head>
    <meta charset="UTF-8">
    </head>
    <body>
    ${table}
    </body>
    </html>
    `;

    let blob = new Blob([html], { type: "application/vnd.ms-excel" });
    let a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "report_<?=h($year)?>.xls";
    a.click();
}
</script>

</body>
</html>