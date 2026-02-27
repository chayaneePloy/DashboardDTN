<?php
// ================= CONNECT =================
include 'db.php';

$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) { $years = [date('Y')]; } // fallback เป็นปีปัจจุบัน (ค.ศ./พ.ศ. แล้วแต่โครงสร้าง)


// ปีงบประมาณที่เลือก (คุมทั้งหน้า)
$year = isset($_GET['year']) ? intval($_GET['year']) : '';
$selectedYear = $year;


function formatDateTH_DMY($date) {
    if (!$date || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return date('d/m/', $ts) . (date('Y', $ts) + 543);
}


function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ================= ปีงบประมาณ =================
$year = $_GET['year'] ?? '';
$budgetItem = $_GET['budget_item'] ?? 'all'; // หมวดงบ
$detailId = $_GET['detail_id'] ?? 'all';

$export = $_GET['export'] ?? ''; // excel

// ปีที่มีข้อมูล
$budgetItems = [];
if ($year) {
    $stmt = $pdo->prepare("
        SELECT id, item_name
        FROM budget_items
        WHERE fiscal_year = ?
        ORDER BY 
      CASE item_name
        WHEN 'งบลงทุน'   THEN 1
        WHEN 'งบบูรณาการ'   THEN 2
        WHEN 'งบดำเนินงาน'  THEN 3
        WHEN 'งบรายจ่ายอื่น' THEN 4
        ELSE 5
      END,
      id ASC
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
        c.contract_ends,
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

if ($detailId !== 'all') {
    $sql .= " AND bd.id_detail = ? ";
    $params[] = $detailId;
}

$sql .= "
    ORDER BY bi.item_name, bd.detail_name, c.contract_number, p.phase_number
";


    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$details = [];
if ($year) {
    $sql = "
        SELECT bd.id_detail, bd.detail_name
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ?
    ";
    $params = [$year];

    if ($budgetItem !== 'all') {
        $sql .= " AND bi.id = ? ";
        $params[] = $budgetItem;
    }
    
    $sql .= " ORDER BY bd.detail_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}




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
                        เพิ่มงบตาม พ.ร.บ.
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
                               href="quarter_projects.php?year=<?= htmlspecialchars($selectedYear) ?>">
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
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="fw-bold text-primary">
    📊 รายงานการจ่ายงวดงานโครงการประจำปี <?=h($year)?>
  </h3>
</div>

<!-- ================= FILTER ================= -->
<form class="row g-2 align-items-end mb-3 no-print">


  <!-- ปีงบประมาณ -->
  <div class="col-md-2">
     <label class="form-label">ปีงบประมาณ</label>
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
  <div class="col-md-3">
     <label class="form-label">ประเภท</label>
    <select name="budget_item" class="form-select" onchange="this.form.submit()">
      <option value="all">-- ประเภทงบทั้งหมด --</option>
      <?php foreach($budgetItems as $bi): ?>
        <option value="<?=h($bi['id'])?>"
          <?= $budgetItem==$bi['id']?'selected':'' ?>>
          <?=h($bi['item_name'])?>
        </option>
      <?php endforeach ?>
    </select>
  </div>
  <div class="col-md-4">
     <label class="form-label">โครงการ</label>
  <select name="detail_id" class="form-select"
          <?= !$year ? 'disabled' : '' ?>
          onchange="this.form.submit()">
    <option value="all">-- ทุกโครงการ --</option>
    <?php foreach($details as $d): ?>
      <option value="<?=h($d['id_detail'])?>"
        <?= $detailId==$d['id_detail']?'selected':'' ?>>
        <?=h($d['detail_name'])?>
      </option>
    <?php endforeach ?>
  </select>
</div>


  <!-- ปุ่ม -->
  <?php if($year): ?>
  <div class="col-md-3 text-end">
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary">
      🖨️ พิมพ์
    </button>
     <button type="button" onclick="exportExcel()" class="btn btn-success">
            📥 Excel
        </button>
  </div>
  <?php endif ?>

</form>


<!-- ================= TABLE ================= -->
<?php if($data): ?>
<div class="table-responsive">
<table class="table table table-bordered table-striped"id="reportTable">
<thead class="text-center table-dark">
<tr >
  <th>ประเภท</th>
  <th>โครงการ</th>
  <th>วงเงินตามสัญญา</th>
  <th>เลขที่สัญญา</th>
  <th>ผู้รับจ้าง</th>
  <th>งวด</th>
  <th>รายละเอียด</th>
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

   <td class="text-center">
        <?php
$status = trim((string)$r['status']);

if ($status === 'เสร็จสิ้น') {
    echo '<span class="badge bg-success">เสร็จสิ้น</span>';
} elseif ($status === 'ยังไม่เสร็จ') {
    echo '<span class="badge bg-warning text-dark">ยังไม่เสร็จ</span>';
} else {
    echo '<span class="badge bg-secondary">รอดำเนินการ</span>';
}
?>

    </td>
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
function exportExcel(){
    let table = document.getElementById("reportTable").outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(table);
    let a = document.createElement('a');
    a.href = url;
    a.download = 'report_<?=h($year)?>.xls';
    a.click();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
