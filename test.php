<?php
/*******************************
 * รายงานงบประมาณตามไตรมาส (แยกหน้า)
 *******************************/
include 'db.php';

/* ================= Helper ================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getCumulativeQuarterRangeForFiscalBE(int $fy, int $q): array {
    $start = ($fy-1)."-10-01";
    switch ($q) {
        case 1: $end = ($fy-1)."-12-31"; break;
        case 2: $end = $fy."-03-31"; break;
        case 3: $end = $fy."-06-30"; break;
        default:$end = $fy."-09-30";
    }
    return [$start, $end];
}

/* ================= ปีงบ ================= */
$years = $pdo->query("
    SELECT DISTINCT fiscal_year 
    FROM budget_items 
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$selectedYear = $_GET['year'] ?? ($years[0] ?? date('Y'));
$selectedYear2 = $_GET['year'] - 543;
$quarter      = isset($_GET['quarter']) ? (int)$_GET['quarter'] : 1;
if (!in_array($quarter,[1,2,3,4],true)) $quarter = 1;

[$qStart,$qEnd] = getCumulativeQuarterRangeForFiscalBE((int)$selectedYear2,$quarter);

/* ================= วงเงินทั้งปี (ฐาน % ) ================= */
$stmtYearReq = $pdo->prepare("
    SELECT COALESCE(SUM(bd.requested_amount),0)
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
");
$stmtYearReq->execute([':fy'=>$selectedYear]);
$yearTotalRequested = (float)$stmtYearReq->fetchColumn();

/* ================= SQL หลัก (ไตรมาส) ================= */
$sql = "
SELECT
    bi.id   AS budget_item_id,
    bi.item_name,
    COALESCE(SUM(
        CASE
            WHEN p.status = 'เสร็จสิ้น'
             AND p.payment_date BETWEEN :qs AND :qe
            THEN p.amount
            ELSE 0
        END
    ),0) AS paid_sum
FROM budget_items bi
LEFT JOIN budget_detail bd 
    ON bd.budget_item_id = bi.id
LEFT JOIN contracts c 
    ON c.detail_item_id = bd.id_detail
LEFT JOIN phases p 
    ON p.contract_detail_id = c.contract_id
WHERE bi.fiscal_year = :fy
GROUP BY bi.id, bi.item_name
ORDER BY
  CASE bi.item_name
    WHEN 'งบลงทุน' THEN 1
    WHEN 'งบบูรณาการ' THEN 2
    WHEN 'งบดำเนินงาน' THEN 3
    WHEN 'งบรายจ่ายอื่น' THEN 4
    ELSE 5
  END

";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':fy'=>$selectedYear,
    ':qs'=>$qStart,
    ':qe'=>$qEnd
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= วงเงินตามสัญญา (ทั้งปี แยกตามงบ) ================= */
$stmtReqByItem = $pdo->prepare("
    SELECT bd.budget_item_id, SUM(bd.requested_amount) total_req
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
    GROUP BY bd.budget_item_id
");
$stmtReqByItem->execute([':fy'=>$selectedYear]);
$reqByItem = $stmtReqByItem->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงานงบประมาณตามไตรมาส</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Sarabun',sans-serif;background:#f6f8fb;}
</style>
</head>
<body>

<div class="container my-4">
<h3 class="text-center mb-4">
📊 รายงานงบประมาณตามไตรมาส (ปี <?php echo h($selectedYear); ?>)
</h3>

<form method="get" class="d-flex gap-2 justify-content-center mb-3">
<select name="year" class="form-select w-auto">
<?php foreach($years as $y): ?>
<option value="<?=h($y)?>" <?=($y==$selectedYear?'selected':'')?>>
<?=h($y)?>
</option>
<?php endforeach; ?>
</select>

<select name="quarter" class="form-select w-auto" onchange="this.form.submit()">
<option value="1" <?=$quarter==1?'selected':''?>>ไตรมาส 1 (ต.ค.–ธ.ค.)</option>
<option value="2" <?=$quarter==2?'selected':''?>>ไตรมาส 2 (ม.ค.–มี.ค.)</option>
<option value="3" <?=$quarter==3?'selected':''?>>ไตรมาส 3 (เม.ย.–มิ.ย.)</option>
<option value="4" <?=$quarter==4?'selected':''?>>ไตรมาส 4 (ก.ค.–ก.ย.)</option>
</select>

<button class="btn btn-primary">แสดง</button>
</form>

<div class="card shadow-sm">
<div class="card-body table-responsive">
<table class="table table-bordered table-striped">
<thead class="table-dark text-center">
<tr>
<th>ประเภทงบ</th>
<th>วงเงินตามสัญญา</th>
<th>ใช้จ่ายแล้ว (สะสม)</th>
<th>คงเหลือ</th>
<th>% ใช้จ่าย</th>
</tr>
</thead>
<tbody>
<?php
$totalReq=0; $totalPaid=0; $totalRemain=0;
foreach($rows as $r):
$id=(int)$r['budget_item_id'];
$req = $reqByItem[$id] ?? 0;
$paid=(float)$r['paid_sum'];
$remain=max(0,$req-$paid);
$pct=$yearTotalRequested>0 ? ($paid/$yearTotalRequested*100):0;

$totalReq+=$req;
$totalPaid+=$paid;
$totalRemain+=$remain;
?>
<tr>
<td><?=h($r['item_name'])?></td>
<td class="text-end"><?=number_format($req,2)?></td>
<td class="text-end"><?=number_format($paid,2)?></td>
<td class="text-end"><?=number_format($remain,2)?></td>
<td class="text-end"><?=number_format($pct,2)?>%</td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-secondary fw-bold">
<tr>
<td>รวม</td>
<td class="text-end"><?=number_format($totalReq,2)?></td>
<td class="text-end"><?=number_format($totalPaid,2)?></td>
<td class="text-end"><?=number_format($totalRemain,2)?></td>
<td class="text-end">
<?= $yearTotalRequested>0 ? number_format(($totalPaid/$yearTotalRequested)*100,2):'0' ?>%
</td>
</tr>
</tfoot>
</table>
</div>
</div>

</div>
</body>
</html>
