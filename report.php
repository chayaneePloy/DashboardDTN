<?php
// ================= CONNECT =================
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8","root","");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function formatDateTH_DMY($date) {
    if (!$date || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return date('d/m/', $ts) . (date('Y', $ts) );
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ================= ปีงบประมาณ =================
$year = $_GET['year'] ?? '';
$budgetItem = $_GET['budget_item'] ?? 'all'; // หมวดงบ
$export = $_GET['export'] ?? ''; // excel

// ปีที่มีข้อมูล
$years = $pdo->query("
  SELECT DISTINCT fiscal_year 
  FROM budget_items 
  ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$budgetItems = [];
if ($year) {
    $stmt = $pdo->prepare("
        SELECT id, item_name
        FROM budget_items
        WHERE fiscal_year = ?
        ORDER BY item_name
    ");
    $stmt->execute([$year]);
    $budgetItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ================= ดึงข้อมูลรายงาน =================
$data = [];
if ($year) {

    $sql = "
        SELECT
            bi.fiscal_year,
            bi.item_name,

            bd.id_detail,
            bd.detail_name,
            bd.requested_amount,

            c.contract_id,
            c.contract_number,
            c.contractor_name,
            c.contract_date,
            c.total_amount,

            p.phase_number,
            p.phase_name,
            p.amount AS phase_amount,
            p.due_date,
            p.completion_date,
            p.status

        FROM budget_items bi
        JOIN budget_detail bd ON bd.budget_item_id = bi.id
        LEFT JOIN contracts c ON c.detail_item_id = bd.id_detail
        LEFT JOIN phases p ON p.contract_detail_id = c.contract_id

        WHERE bi.fiscal_year = ?
    ";

    $params = [$year];

    if ($budgetItem !== 'all') {
        $sql .= " AND bi.id = ? ";
        $params[] = $budgetItem;
    }

    $sql .= "
        ORDER BY bi.item_name, bd.detail_name, c.contract_number, p.phase_number
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ================= Export Excel =================
if ($export === 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=report_$year.xls");
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
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
@media print {
  .no-print { display:none; }
}
th { background:#f1f1f1; }
.date-nowrap {
    white-space: nowrap;
}
</style>
</head>
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
<body class="bg-light">
<div class="container my-4">

<!-- ================= HEADER ================= -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="fw-bold text-primary">
    📊 รายงานการจ่ายงวดงานโครงการประจำปี <?=h($year)?>
  </h3>
</div>

<!-- ================= FILTER ================= -->
<form class="row g-2 mb-3 no-print">

  <!-- ปีงบประมาณ -->
  <div class="col-md-3">
    <select name="year" class="form-select" onchange="this.form.submit()">
      <option value="">-- เลือกปีงบประมาณ --</option>
      <?php foreach($years as $y): ?>
        <option value="<?=h($y)?>" <?= $y==$year?'selected':'' ?>>
          <?=h($y)?>
        </option>
      <?php endforeach ?>
    </select>
  </div>

  <!-- หมวดงบ -->
  <div class="col-md-4">
    <select name="budget_item" class="form-select" onchange="this.form.submit()">
      <option value="all">-- โครงการทั้งหมด --</option>
      <?php foreach($budgetItems as $bi): ?>
        <option value="<?=h($bi['id'])?>"
          <?= $budgetItem==$bi['id']?'selected':'' ?>>
          <?=h($bi['item_name'])?>
        </option>
      <?php endforeach ?>
    </select>
  </div>

  <!-- ปุ่ม -->
  <?php if($year): ?>
  <div class="col-md-5 text-end">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary">
      🖨️ พิมพ์
    </button>
    <a href="?year=<?=$year?>&budget_item=<?=$budgetItem?>&export=excel"
       class="btn btn-success">
     📥 Excel
    </a>
  </div>
  <?php endif ?>

</form>


<!-- ================= TABLE ================= -->
<?php if($data): ?>
<div class="table-responsive">
<table class="table table table-bordered table-striped">
<thead class="text-center table-dark">
<tr >
  <th>ประเภทโครงการ</th>
  <th>โครงการ</th>
  <th>วงเงินตามสัญญา</th>
  <th>เลขสัญญา</th>
  <th>บริษัท</th>
  <th>งวด</th>
  <th>รายละเอียดงวด</th>
  <th>วันที่เริ่ม</th>
  <th>วันที่สิ้นสุด</th>
  <th class="text-end">จำนวนเงิน</th>
  <th>สถานะ</th>
</tr>
</thead>
<tbody>
<?php foreach($data as $r): ?>
<tr>
  <td><?=h($r['item_name'])?></td>
  <td data-bs-toggle="tooltip"data-bs-placement="top"title="<?= h($r['detail_name']) ?>">
  <a class="text-decoration-none text-dark">
  <?= h(mb_strimwidth($r['detail_name'], 0, 50, '...', 'UTF-8')) ?>
  </a>
  </td>

  <td class="text-end"><?=number_format($r['requested_amount'],2)?></td>
  <td><?=h($r['contract_number'])?></td>
  <td><?=h($r['contractor_name'])?></td>
  <td class="text-center"><?=h($r['phase_number'])?></td>


  <td data-bs-toggle="tooltip"data-bs-placement="top"title="<?= h($r['phase_name']) ?>">
  <a class="text-decoration-none text-dark">
  <?= h(mb_strimwidth($r['phase_name'], 0, 50, '...', 'UTF-8')) ?>
  </a>
  </td>


<td class="date-nowrap"><?= formatDateTH_DMY($r['due_date']) ?></td>
<td class="date-nowrap"><?= formatDateTH_DMY($r['completion_date']) ?></td>

  <td class="text-end"><?=number_format($r['phase_amount'],2)?></td>
  <td><?=h($r['status'])?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php elseif($year): ?>
<div class="alert alert-warning">ไม่พบข้อมูลในปีงบประมาณนี้</div>
<?php endif ?>

<!-- ================= FOOTER ================= -->
<?php if($year): ?>
<div class="mt-4 text-end">
  <small class="text-muted">
    รายงาน ณ วันที่ <?=date('d/m/') . (date('Y')+543)?>
  </small>
</div>
<?php endif ?>

</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const tooltipTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
  );
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
