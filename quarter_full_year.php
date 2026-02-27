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
$fiscalYearCE = $fiscalYear - 543;
$itemId = $_GET['item'] ?? '';

/* ===================== ฟังก์ชันดึงข้อมูล ===================== */
function getQuarterData($pdo,$fiscalYear,$fiscalYearCE,$quarter,$itemId){

    [$qs,$qe] = quarterRangeBE($fiscalYearCE,$quarter);

    $sql = "
    SELECT
        bi.item_name,
        bd.detail_name AS project_name,
        c.contract_number,
        p.phase_number,
        p.payment_date,
        p.amount
    FROM phases p
    JOIN contracts c       ON c.contract_id = p.contract_detail_id
    JOIN budget_detail bd  ON bd.id_detail = c.detail_item_id
    JOIN budget_items bi   ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
      AND p.status = 'เสร็จสิ้น'
      AND p.payment_date BETWEEN :qs AND :qe
    ";

    $params = [
        ':fy'=>$fiscalYear,
        ':qs'=>$qs,
        ':qe'=>$qe
    ];

    if($itemId){
        $sql .= " AND bi.id = :item ";
        $params[':item'] = $itemId;
    }

    $sql .= " ORDER BY bi.item_name, bd.detail_name, p.phase_number ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ===================== ดึงครบ 4 ไตรมาส ===================== */
$quartersData = [];
$sumQuarter = [];

for($q=1;$q<=4;$q++){

    $rows = getQuarterData($pdo,$fiscalYear,$fiscalYearCE,$q,$itemId);

    $group = [];
    $total = 0;

    foreach($rows as $r){
        $group[$r['item_name']][] = $r;
        $total += $r['amount'];
    }

    $quartersData[$q] = $group;
    $sumQuarter[$q] = $total;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงาน 4 ไตรมาส</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{font-family:'Sarabun',sans-serif}
.quarter-box{
    border:1px solid #ddd;
    padding:10px;
    min-height:500px;
    background:#fff;
}
.quarter-header{
    background:#0d6efd;
    color:#fff;
    padding:6px;
    text-align:center;
    font-weight:bold;
}
.table-sm td, .table-sm th{
    font-size:13px;
}
</style>
</head>

<body class="bg-light">

<div class="container my-4">

<h3 class="text-primary fw-bold text-center mb-4">
📊 รายงานผลการเบิกจ่ายงบประมาณครบ 4 ไตรมาส  
ปีงบประมาณ <?=$fiscalYear?>
</h3>

<form class="row g-2 align-items-end mb-4">

    <!-- ปีงบประมาณ -->
    <div class="col-md-3">
        <label class="form-label">ปีงบประมาณ</label>
        <select name="year" class="form-select">
            <?php foreach($years as $y): ?>
                <option value="<?=$y?>" <?=$y==$fiscalYear?'selected':''?>>
                    <?=$y?>
                </option>
            <?php endforeach;?>
        </select>
    </div>

    <!-- ประเภทงบ -->
    <div class="col-md-3">
        <label class="form-label">ประเภทงบ</label>
        <select name="item" class="form-select">
            <option value="">ทุกประเภทงบ</option>
            <?php
            $items=$pdo->prepare("SELECT id,item_name FROM budget_items WHERE fiscal_year=?");
            $items->execute([$fiscalYear]);
            foreach($items as $it):
            ?>
            <option value="<?=$it['id']?>" <?=$itemId==$it['id']?'selected':''?>>
                <?=h($it['item_name'])?>
            </option>
            <?php endforeach;?>
        </select>
    </div>

    <!-- ปุ่ม -->
    <div class="col-md-6">
        <div class="d-flex gap-2">

            <button type="submit" class="btn btn-primary">
                🔍 แสดง
            </button>

          <button type="button"
        onclick="exportExcel()"
        class="btn btn-success">
   📥 Excel
</button>

            <button type="button"
                    onclick="window.print()"
                    class="btn btn-secondary">
                🖨 พิมพ์
            </button>

        </div>
    </div>

</form>
<div id="reportTable">
<div class="row">
    

<?php for($q=1;$q<=4;$q++): ?>
<div class="col-md-3">
<div class="quarter-box">

<div class="quarter-header">
ไตรมาส <?=$q?><br>
<small>รวม <?=number_format($sumQuarter[$q],2)?> บาท</small>
</div>

<?php if(empty($quartersData[$q])): ?>
<div class="alert alert-warning text-center mt-2">ไม่มีข้อมูล</div>
<?php else: ?>

<?php foreach($quartersData[$q] as $item=>$rows): ?>

<h6 class="fw-bold mt-2"><?=$item?></h6>

<table class="table table-bordered table-sm" >
<thead class="table-light text-center">
<tr>
<th>สัญญา</th>
<th>งวด</th>
<th>วันที่</th>
<th>เงิน</th>
</tr>
</thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><?=$r['contract_number']?></td>
<td class="text-center"><?=$r['phase_number']?></td>
<td class="text-center"><?=thai_date($r['payment_date'])?></td>
<td class="text-end"><?=number_format($r['amount'],2)?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>
<?php endfor; ?>

</div>

</div>
</div>

<script>
function exportExcel(){

    let quarters = document.querySelectorAll(".quarter-box");
    let content = `
    <html xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <xml>
        <x:ExcelWorkbook>
            <x:ExcelWorksheets>
                <x:ExcelWorksheet>
                    <x:Name>รายงาน</x:Name>
                    <x:WorksheetOptions>
                        <x:PageSetup>
                            <x:Layout x:Orientation="Landscape"/>
                        </x:PageSetup>
                    </x:WorksheetOptions>
                </x:ExcelWorksheet>
            </x:ExcelWorksheets>
        </x:ExcelWorkbook>
        </xml>
        <style>
            table { border-collapse: collapse; width:100%; }
            td { vertical-align: top; width:25%; }
            .box { border:1px solid #000; padding:5px; }
            th, td table td, td table th {
                border:1px solid #000;
                padding:4px;
                font-size:13px;
            }
            th { background:#eeeeee; }
            h4 { background:#0d6efd; color:#fff; padding:5px; }
        </style>
    </head>
    <body>
        <h3>รายงานผลการเบิกจ่ายงบประมาณ ปี <?=$fiscalYear?></h3>
        <table>
            <tr>
    `;

    quarters.forEach(function(q){
        content += `<td class="box">` + q.innerHTML + `</td>`;
    });

    content += `
            </tr>
        </table>
    </body>
    </html>
    `;

    let blob = new Blob([content], {type: "application/vnd.ms-excel;charset=utf-8;"});
    let link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "report_<?=h($fiscalYear)?>.xls";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>