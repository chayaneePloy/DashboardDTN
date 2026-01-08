<?php
// ===================== CONNECT =====================
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================== FUNCTION =====================
function h($str){
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function thaiDate($date){
    if(!$date || $date=='0000-00-00') return '';
    $d = date('d', strtotime($date));
    $m = date('m', strtotime($date));
    $y = date('Y', strtotime($date)) + 543;
    return "$d/$m/$y";
}

// ===================== PARAM =====================
$year        = $_GET['year'] ?? '';
$budgetItem = $_GET['budget_item'] ?? 'all';

// ===================== YEAR LIST =====================
$years = $pdo->query("
    SELECT DISTINCT fiscal_year 
    FROM budget_items 
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ===================== BUDGET ITEM LIST =====================
$budgetItems = [];

if($year){
    $stmtBI = $pdo->prepare("
        SELECT id, item_name
        FROM budget_items
        WHERE fiscal_year = ?
        ORDER BY item_name
    ");
    $stmtBI->execute([$year]);
    $budgetItems = $stmtBI->fetchAll(PDO::FETCH_ASSOC);
}


// ===================== DATA =====================
$data = [];

if($year){
    $where  = "WHERE bi.fiscal_year = ?";
    $params = [$year];

    if($budgetItem !== 'all'){
        $where .= " AND bi.id = ?";
        $params[] = $budgetItem;
    }

    $stmt = $pdo->prepare("
        SELECT 
            bi.item_name,
            bd.detail_name,
            bd.budget_received,
            c.contract_number,
            c.contractor_name,
            c.contract_date,
            ps.step_order,
            ps.step_name,
            ps.step_description,
            ps.step_date,
            ps.is_completed
        FROM budget_items bi
        JOIN budget_detail bd ON bi.id = bd.budget_item_id
        LEFT JOIN contracts c ON bd.id_detail = c.detail_item_id
        LEFT JOIN project_steps ps ON bd.id_detail = ps.id_budget_detail
        $where
        ORDER BY bd.id_detail, ps.step_order
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>รายงานจัดซื้อจัดจ้าง</title>
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
<style>
@media print{ .no-print{ display:none } }
.date-nowrap{ white-space: nowrap; }
</style>
</head>

<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand fw-bold" href="index.php?year=<?= h($year) ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
      📊 Dashboard งบประมาณโครงการ
    </a>

    <!-- Hamburger -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Menu -->
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

        <li class="nav-item">
          <a class="nav-link text-white" href="index.php?year=<?= h($year) ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
            <i class="bi bi-house"></i> หน้าหลัก
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link text-white" href="index.php?year=<?= h($year) ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">

            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>

      </ul>
    </div>

  </div>
</nav>

<div class="container mt-4">

<h3 class="fw-bold text-primary mb-3">
    📊 รายงานจัดซื้อจัดจ้าง
</h3>

<!-- ================= FILTER ================= -->
<form method="get" class="row g-2 align-items-end no-print">

    <div class="col-md-3">
        <label class="form-label">ปีงบประมาณ</label>
        <select name="year" class="form-select" onchange="this.form.submit()">
            <option value="">-- เลือกปีงบประมาณ --</option>
            <?php foreach($years as $y): ?>
                <option value="<?=h($y)?>" <?= $year==$y?'selected':'' ?>>
                    <?=h($y)?>
                </option>
            <?php endforeach ?>
        </select>
    </div>

<div class="col-md-4">
    <label class="form-label">โครงการ</label>
    <select name="budget_item" class="form-select"
            <?= !$year ? 'disabled' : '' ?>
            onchange="this.form.submit()">

        <option value="all">-- โครงการทั้งหมด --</option>

        <?php foreach($budgetItems as $bi): ?>
            <option value="<?=h($bi['id'])?>"
                <?= $budgetItem==$bi['id']?'selected':'' ?>>
                <?=h($bi['item_name'])?>
            </option>
        <?php endforeach ?>

    </select>
</div>


    <?php if($year): ?>
    <div class="col-md-5 text-end">
        <button type="button" onclick="window.print()" class="btn btn-secondary">
            🖨️ พิมพ์
        </button>
        <button type="button" onclick="exportExcel()" class="btn btn-success">
            📥 Excel
        </button>
    </div>
    <?php endif ?>

   



</form>

<!-- ================= TABLE ================= -->
<?php if($year): ?>
<div class="table-responsive mt-4">
<table class="table table-bordered table-striped" id="reportTable">
<thead class="table-dark text-center">
<tr>
    <th>หมวดงบ</th>
    <th>โครงการ</th>
    <th>งบได้รับ</th>
    <th>เลขที่สัญญา</th>
    <th>คู่สัญญา</th>
    <th>วันที่สัญญา</th>
    <th>ลำดับ</th>
    <th>ขั้นตอน</th>
    <th>รายละเอียด</th>
    <th>วันที่ขั้นตอน</th>
    <th>สถานะ</th>
</tr>
</thead>
<tbody>
<?php foreach($data as $r): ?>
<tr>
    <td><?=h($r['item_name'])?></td>
    <td><?=h($r['detail_name'])?></td>
    <td class="text-end"><?=number_format($r['budget_received'],2)?></td>
    <td><?=h($r['contract_number'])?></td>
    <td><?=h($r['contractor_name'])?></td>
    <td class="date-nowrap"><?=thaiDate($r['contract_date'])?></td>
    <td class="text-center"><?=h($r['step_order'])?></td>
    <td><?=h($r['step_name'])?></td>
    <td><?=nl2br(h($r['step_description']))?></td>
    <td class="date-nowrap"><?=thaiDate($r['step_date'])?></td>
    <td class="text-center">
        <?= $r['is_completed']
            ? '<span class="badge bg-success">เสร็จสิ้น</span>'
            : '<span class="badge bg-warning text-dark">ยังไม่เสร็จ</span>' ?>
    </td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

</div>

<script>
function exportExcel(){
    let table = document.getElementById("reportTable").outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(table);
    let a = document.createElement('a');
    a.href = url;
    a.download = 'report_<?=h($year)?>.xls';
    a.click();
}
</script>

</body>
</html>
