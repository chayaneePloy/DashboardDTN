<?php
// ===================== CONFIG/CONNECT =====================
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================== รับค่าจากฟอร์ม =====================
$selected_year    = $_GET['year'] ?? '';
$selected_item    = $_GET['item'] ?? '';
$selected_quarter = $_GET['quarter'] ?? '';
$start_date       = $_GET['start_date'] ?? '';
$end_date         = $_GET['end_date'] ?? '';

// ===================== ดึงปีงบประมาณ =====================
$years = $pdo->query("
    SELECT DISTINCT bi.fiscal_year 
    FROM budget_items bi
    INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
    ORDER BY bi.fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ===================== ดึงงบประมาณตามปี =====================
$items = [];
if ($selected_year) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT bi.id, bi.item_name 
        FROM budget_items bi
        INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
        WHERE bi.fiscal_year = ?
        ORDER BY bi.item_name
    ");
    $stmt->execute([$selected_year]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== คำนวณช่วงวันจาก: วันที่/ไตรมาส =====================
$quarterRanges = [
    'Q1' => ['start' => '-10-01', 'end' => '-12-31'], // ต.ค.–ธ.ค.
    'Q2' => ['start' => '-01-01', 'end' => '-03-31'], // ม.ค.–มี.ค.
    'Q3' => ['start' => '-04-01', 'end' => '-06-30'], // เม.ย.–มิ.ย.
    'Q4' => ['start' => '-07-01', 'end' => '-09-30'], // ก.ค.–ก.ย.
];

$filterStart = null;
$filterEnd   = null;

// 1) ถ้ามีกรอกช่วงวันที่เอง ให้ใช้ก่อน (override ไตรมาส)
if (!empty($start_date) || !empty($end_date)) {
    $filterStart = !empty($start_date) ? $start_date : '0001-01-01';
    $filterEnd   = !empty($end_date)   ? $end_date   : '9999-12-31';
}
// 2) ถ้าไม่ได้กรอกวันเอง แต่เลือกไตรมาส ให้คำนวณช่วงจากปีงบฯ
elseif ($selected_year && $selected_quarter && isset($quarterRanges[$selected_quarter])) {
    // แปลงปีงบประมาณเป็น ค.ศ. ถ้าเป็น พ.ศ. ให้ลบ 543
    $yearAD = (int)$selected_year;
    if ($yearAD > 2500) $yearAD -= 543;

    if ($selected_quarter === 'Q1') {
        // Q1 อยู่ช่วงปลายปีปฏิทินก่อนหน้า
        $filterStart = ($yearAD - 1) . $quarterRanges['Q1']['start'];
        $filterEnd   = ($yearAD - 1) . $quarterRanges['Q1']['end'];
    } else {
        $filterStart = $yearAD . $quarterRanges[$selected_quarter]['start'];
        $filterEnd   = $yearAD . $quarterRanges[$selected_quarter]['end'];
    }
}

// ===================== ดึงข้อมูลงวด (phases) =====================
$phases = [];
if ($selected_year && $selected_item) {
    $query = "
        SELECT bi.fiscal_year, bi.item_name, bd.detail_name, 
               c.contract_number, c.contractor_name,
               p.phase_id, p.phase_number, p.phase_name, p.amount, 
               p.due_date, p.completion_date, p.status, p.payment_date
        FROM phases p
        JOIN contracts c      ON p.contract_detail_id = c.contract_id
        JOIN budget_detail bd ON c.detail_item_id     = bd.id_detail
        JOIN budget_items bi  ON bd.budget_item_id    = bi.id
        WHERE bi.fiscal_year = ? AND bi.id = ?
    ";
    $params = [$selected_year, $selected_item];

    // ถ้ามีช่วงวันที่ (จากกรอกเองหรือไตรมาส) ให้กรองแบบ "ทับซ้อนช่วง"
    if ($filterStart && $filterEnd) {
        $query .= " AND (
            (p.due_date BETWEEN ? AND ?)
            OR
            (p.completion_date BETWEEN ? AND ?)
            OR
            (p.payment_date BETWEEN ? AND ?)
        )";
        $params[] = $filterStart;
        $params[] = $filterEnd;
        $params[] = $filterStart;
        $params[] = $filterEnd;
        $params[] = $filterStart;
        $params[] = $filterEnd;
    }

    // จัดเรียง: งบประมาณ > โครงการ > วัน (เอาวันที่ที่มีจริงก่อน)
    $query .= " ORDER BY bi.item_name ASC, bd.detail_name ASC, COALESCE(p.payment_date, p.completion_date, p.due_date) ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== สรุปข้อมูลสำหรับตาราง/กราฟ (ถ้ามีผลลัพธ์) =====================
$projectSummary = [];
$js_projects = $js_total = $js_paid = $js_remain = $js_status_labels = $js_status_values = $js_month_labels = $js_month_values = [];

if (!empty($phases)) {
    $byProjectTotal = [];
    $byProjectPaid  = [];
    $byStatus       = [];
    $byMonth        = [];

    $pickDate = function($row) {
        if (!empty($row['payment_date']))    return $row['payment_date'];
        if (!empty($row['completion_date'])) return $row['completion_date'];
        if (!empty($row['due_date']))        return $row['due_date'];
        return null;
    };

    foreach ($phases as $row) {
        $project = $row['detail_name'] ?: 'ไม่ระบุโครงการ';
        $status  = (string)$row['status'] ?: 'ไม่ระบุสถานะ';
        $amt     = (float)$row['amount'];

        // รวมตามโครงการ
        if (!isset($byProjectTotal[$project])) $byProjectTotal[$project] = 0;
        $byProjectTotal[$project] += $amt;

        // ถือว่า "จ่ายแล้ว" ถ้ามี payment_date หรือสถานะบ่งชี้
        $isPaid = !empty($row['payment_date']) || in_array(mb_strtolower($status), ['paid','จ่ายแล้ว','ชำระแล้ว','จ่ายครบ']);
        if (!isset($byProjectPaid[$project])) $byProjectPaid[$project] = 0;
        if ($isPaid) $byProjectPaid[$project] += $amt;

        // รวมตามสถานะ
        if (!isset($byStatus[$status])) $byStatus[$status] = 0;
        $byStatus[$status] += $amt;

        // รวมตามเดือน (อิงวันที่สำคัญที่สุด)
        $d = $pickDate($row);
        if ($d) {
            $ym = date('Y-m', strtotime($d));
            if (!isset($byMonth[$ym])) $byMonth[$ym] = 0;
            $byMonth[$ym] += $amt;
        }
    }

    foreach ($byProjectTotal as $proj => $sum) {
        $paid = $byProjectPaid[$proj] ?? 0;
        $remain = max(0, $sum - $paid);
        $projectSummary[] = [
            'project'  => $proj,
            'total'    => $sum,
            'paid'     => $paid,
            'remain'   => $remain,
            'paid_pct' => $sum > 0 ? ($paid / $sum) * 100 : 0,
        ];
    }

    // เรียงโครงการตามยอดรวมมาก → น้อย
    usort($projectSummary, fn($a,$b) => ($b['total'] <=> $a['total']));

    // เตรียมข้อมูล JS
    $js_projects      = array_column($projectSummary, 'project');
    $js_total         = array_map(fn($x)=> round($x['total'], 2),  $projectSummary);
    $js_paid          = array_map(fn($x)=> round($x['paid'], 2),   $projectSummary);
    $js_remain        = array_map(fn($x)=> round($x['remain'], 2), $projectSummary);

    $js_status_labels = array_keys($byStatus);
    $js_status_values = array_map(fn($v)=> round($v,2), array_values($byStatus));

    ksort($byMonth);
    $js_month_labels  = array_keys($byMonth);
    $js_month_values  = array_map(fn($v)=> round($v,2), array_values($byMonth));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>Dashboard งบประมาณ (Phases)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">
  <style>
    body { font-family: 'Sarabun', system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans Thai', 'Sarabun', 'Noto Sans', sans-serif; }
    .table-primary { --bs-table-bg: #e7f1ff; }
    .table-secondary { --bs-table-bg: #f3f4f6; }
    .sticky-head th { position: sticky; top: 0; background: #fff; z-index: 1; }
    .number { text-align: right; }
    .card .card-body canvas { width: 100% !important; height: 380px !important; }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#">📊 Dashboard งบประมาณโครงการ</a>
    <div class="ms-auto">
      <a href="index.php" class="btn btn-light back-btn me-2">
        <i class="bi bi-house"></i> หน้าหลัก
      </a>
      <a href="javascript:history.back()" class="btn btn-light back-btn">
        <i class="bi bi-arrow-left"></i> กลับหน้าก่อนหน้า
      </a>
    </div>
  </div>
</nav>

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between">
    <h2 class="mb-4 text-primary">📊 Dashboard การจ่ายงวด (Phases)</h2>

    <!-- ปุ่มเพิ่มงวดงาน: แสดงเมื่อเลือกปี+งบประมาณแล้ว -->
    <?php if ($selected_year && $selected_item): ?>
      <div class="mb-3">
        <a class="btn btn-success"
           href="create_phase.php?year=<?= urlencode($selected_year) ?>&item=<?= urlencode($selected_item) ?>&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>">
          + เพิ่มงวดงาน
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- ฟอร์มเลือก -->
  <form method="get" class="row g-3 mb-4">
    <div class="col-md-3">
      <label class="form-label">ปีงบประมาณ</label>
      <select name="year" class="form-select" onchange="this.form.submit()">
        <option value="">-- เลือกปี --</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= htmlspecialchars($y) ?>" <?= ($y == $selected_year) ? 'selected' : '' ?>>
            <?= htmlspecialchars($y) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">งบประมาณ</label>
      <select name="item" class="form-select" onchange="this.form.submit()">
        <option value="">-- เลือกงบประมาณ --</option>
        <?php foreach ($items as $i): ?>
          <option value="<?= htmlspecialchars($i['id']) ?>" <?= ($i['id'] == $selected_item) ? 'selected' : '' ?>>
            <?= htmlspecialchars($i['item_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-2">
      <label class="form-label">ไตรมาส</label>
      <select name="quarter" class="form-select" onchange="this.form.submit()">
        <option value="">-- เลือก --</option>
        <option value="Q1" <?= $selected_quarter==='Q1'?'selected':'' ?>>ไตรมาส 1 (ต.ค.–ธ.ค.)</option>
        <option value="Q2" <?= $selected_quarter==='Q2'?'selected':'' ?>>ไตรมาส 2 (ม.ค.–มี.ค.)</option>
        <option value="Q3" <?= $selected_quarter==='Q3'?'selected':'' ?>>ไตรมาส 3 (เม.ย.–มิ.ย.)</option>
        <option value="Q4" <?= $selected_quarter==='Q4'?'selected':'' ?>>ไตรมาส 4 (ก.ค.–ก.ย.)</option>
      </select>
    </div>

    <div class="col-md-2">
      <label class="form-label">จากวันที่</label>
      <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control">
    </div>
    <div class="col-md-2">
      <label class="form-label">ถึงวันที่</label>
      <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control">
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary">ค้นหา</button>
      <?php if ($filterStart || $filterEnd): ?>
        <span class="ms-2 text-muted small">
          กำลังกรองช่วงวันที่:
          <?= htmlspecialchars($filterStart ?? '—') ?> ถึง <?= htmlspecialchars($filterEnd ?? '—') ?>
        </span>
      <?php endif; ?>
    </div>
  </form>

  <!-- ส่วนสรุป + กราฟ (แสดงเมื่อมีข้อมูล) -->
  <?php if (!empty($phases)): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-success text-white">
        สรุปต่อโครงการ (รวม / จ่ายแล้ว / คงเหลือ)
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>โครงการ</th>
                <th class="text-end">รวม (บาท)</th>
                <th class="text-end">จ่ายแล้ว (บาท)</th>
                <th class="text-end">คงเหลือ (บาท)</th>
                <th class="text-end">จ่ายแล้ว (%)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($projectSummary as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['project']) ?></td>
                  <td class="text-end"><?= number_format($row['total'], 2) ?></td>
                  <td class="text-end text-success"><?= number_format($row['paid'], 2) ?></td>
                  <td class="text-end text-danger"><?= number_format($row['remain'], 2) ?></td>
                  <td class="text-end"><?= number_format($row['paid_pct'], 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($projectSummary)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">ไม่มีข้อมูลให้สรุป</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-7">
        <div class="card shadow-sm h-100">
          <div class="card-header">ยอดรวมต่อโครงการ</div>
          <div class="card-body"><canvas id="chartByProject"></canvas></div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card shadow-sm h-100">
          <div class="card-header">สัดส่วนตามสถานะ</div>
          <div class="card-body"><canvas id="chartByStatus"></canvas></div>
        </div>
      </div>
      <div class="col-12">
        <div class="card shadow-sm">
          <div class="card-header">ยอดตามเดือน (timeline)</div>
          <div class="card-body"><canvas id="chartByMonth"></canvas></div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ตารางแสดงผลรายละเอียด -->
  <?php if ($phases): ?>
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white">
        งวดงานในปี <?= htmlspecialchars($selected_year) ?>
        <?php if ($selected_item && isset($items) && is_array($items)):
          $sel = array_values(array_filter($items, fn($x)=> (string)$x['id']===(string)$selected_item));
          if ($sel): ?>
            — <?= htmlspecialchars($sel[0]['item_name']) ?>
        <?php endif; endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0">
            <thead class="table-light sticky-head">
              <tr>
                <th class="center">งวด/ชื่อ</th>
                <th>Due Date</th>
                <th>Completion Date</th>
                <th>วันที่จ่าย</th>
                <th class="number">จำนวนเงิน (บาท)</th>
                <th>สถานะ</th>
                <th>แก้ไข</th>
              </tr>
            </thead>
            <tbody>
<?php
  $currentProject  = null;
  $projectSubtotal = 0.0;
  $grandTotal      = 0.0;

  foreach ($phases as $p):
      // เปลี่ยนโครงการ → ปิดกลุ่มก่อนหน้า + เปิดหัวกลุ่มใหม่
      if ($currentProject !== $p['detail_name']) {
          if ($currentProject !== null) {
              // รวมโครงการ
              echo '<tr class="table-secondary fw-semibold">
                      <td colspan="7">
                        <div class="d-flex justify-content-between">
                          <span>รวมโครงการ</span>
                          <span class="number">'.number_format($projectSubtotal, 2).'</span>
                        </div>
                      </td>
                    </tr>';
          }

          $currentProject  = $p['detail_name'];
          $projectSubtotal = 0.0;

          $item_name       = $p['item_name'];
          $contract_number = $p['contract_number'];
          $contractor_name = $p['contractor_name'];

          // หัวกลุ่มโครงการ
          echo '<tr class="table-primary">
                  <td colspan="7" class="fw-bold">
                    โครงการ: '.htmlspecialchars($currentProject).'<br>
                    <span class="fw-normal">
                      งบประมาณ: '.htmlspecialchars($item_name).' |
                      เลขสัญญา: '.htmlspecialchars($contract_number).' |
                      ผู้รับจ้าง: '.htmlspecialchars($contractor_name).'
                    </span>
                  </td>
                </tr>';
      }

      // แถวรายละเอียด
      $amount        = (float)$p['amount'];
      $projectSubtotal += $amount;
      $grandTotal      += $amount;

      $phaseLabel = $p['phase_name'];
      $editUrl = 'edit_phase.php?phase_id='.urlencode($p['phase_id']).'&return='.urlencode($_SERVER['REQUEST_URI']);
?>
              <tr>
                <td><?= htmlspecialchars($phaseLabel) ?></td>
                <td><?= $p['due_date'] ? date("d/m/Y", strtotime($p['due_date'])) : '-' ?></td>
                <td><?= $p['completion_date'] ? date("d/m/Y", strtotime($p['completion_date'])) : '-' ?></td>
                <td><?= $p['payment_date'] ? date("d/m/Y", strtotime($p['payment_date'])) : '-' ?></td>
                <td class="number"><?= number_format($amount, 2) ?></td>
                <td><?= htmlspecialchars((string)$p['status']) ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= $editUrl ?>">แก้ไข</a></td>
              </tr>
<?php endforeach; ?>

<?php if ($currentProject !== null): ?>
              <!-- ปิดกลุ่มสุดท้าย -->
              <tr class="table-secondary fw-semibold">
                <td colspan="7">
                  <div class="d-flex justify-content-between">
                    <span>รวมโครงการ</span>
                    <span class="number"><?= number_format($projectSubtotal, 2) ?></span>
                  </div>
                </td>
              </tr>
<?php endif; ?>

              <!-- รวมทั้งหมด -->
              <tr class="table-dark fw-bold">
                <td colspan="7" class="text-white">
                  <div class="d-flex justify-content-between">
                    <span>รวมทั้งหมด</span>
                    <span class="number text-white"><?= number_format($grandTotal, 2) ?></span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div> <!-- /table-responsive -->
      </div> <!-- /card-body -->
    </div> <!-- /card -->
  <?php elseif ($selected_year && $selected_item): ?>
    <div class="alert alert-warning">❌ ไม่พบข้อมูลงวดงานในช่วงเวลาที่เลือก</div>
  <?php else: ?>
    <div class="alert alert-info">ℹ️ โปรดเลือก ปีงบประมาณ และ งบประมาณ เพื่อแสดงข้อมูล</div>
  <?php endif; ?>
</div> <!-- /container -->

<?php if (!empty($phases)): ?>
<script>
// ====== รับข้อมูลจาก PHP ======
const PROJ_LABELS = <?php echo json_encode($js_projects, JSON_UNESCAPED_UNICODE); ?>;
const PROJ_TOTAL  = <?php echo json_encode($js_total); ?>;
const PROJ_PAID   = <?php echo json_encode($js_paid); ?>;
const PROJ_REMAIN = <?php echo json_encode($js_remain); ?>;

const STATUS_LABELS = <?php echo json_encode($js_status_labels, JSON_UNESCAPED_UNICODE); ?>;
const STATUS_VALUES = <?php echo json_encode($js_status_values); ?>;

const MONTH_LABELS = <?php echo json_encode($js_month_labels); ?>;
const MONTH_VALUES = <?php echo json_encode($js_month_values); ?>;

// ====== helper แสดงจำนวนเงินสวย ๆ ======
const currencyFmt = (v) => new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 2 }).format(v ?? 0);

// ====== Chart 1: Bar (ยอดรวม/จ่ายแล้ว/คงเหลือ ต่อโครงการ) ======
(() => {
  const ctx = document.getElementById('chartByProject');
  if (!ctx || PROJ_LABELS.length === 0) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: PROJ_LABELS,
      datasets: [
        { label: 'รวม',     data: PROJ_TOTAL,  borderWidth: 1 },
        { label: 'จ่ายแล้ว', data: PROJ_PAID,   borderWidth: 1 },
        { label: 'คงเหลือ',  data: PROJ_REMAIN, borderWidth: 1 },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        tooltip: {
          callbacks: { label: (ctx) => `${ctx.dataset.label}: ${currencyFmt(ctx.parsed.y)}` }
        },
        legend: { position: 'top' }
      },
      scales: {
        x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } },
        y: { beginAtZero: true, ticks: { callback: (v) => currencyFmt(v) } }
      }
    }
  });
})();

// ====== Chart 2: Doughnut (ยอดรวมตามสถานะ) ======
(() => {
  const ctx = document.getElementById('chartByStatus');
  if (!ctx || STATUS_LABELS.length === 0) return;

  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: STATUS_LABELS,
      datasets: [{ data: STATUS_VALUES }]
    },
    options: {
      responsive: true,
      plugins: {
        tooltip: {
          callbacks: { label: (ctx) => `${ctx.label}: ${currencyFmt(ctx.parsed)}` }
        },
        legend: { position: 'right' }
      },
      cutout: '60%'
    }
  });
})();

// ====== Chart 3: Line (ยอดตามเดือน) ======
(() => {
  const ctx = document.getElementById('chartByMonth');
  if (!ctx || MONTH_LABELS.length === 0) return;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: MONTH_LABELS, // YYYY-MM
      datasets: [{ label: 'ยอดต่อเดือน', data: MONTH_VALUES, tension: 0.25, borderWidth: 2, pointRadius: 3 }]
    },
    options: {
      responsive: true,
      plugins: {
        tooltip: {
          callbacks: { label: (ctx) => `${ctx.dataset.label}: ${currencyFmt(ctx.parsed.y)}` }
        },
        legend: { display: true }
      },
      scales: {
        y: { beginAtZero: true, ticks: { callback: (v)=> currencyFmt(v) } }
      }
    }
  });
})();
</script>
<?php endif; ?>

</body>
</html>
