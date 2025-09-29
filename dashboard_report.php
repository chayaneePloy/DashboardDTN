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
               p.due_date, p.completion_date, p.status
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
        )";
        $params[] = $filterStart;
        $params[] = $filterEnd;
        $params[] = $filterStart;
        $params[] = $filterEnd;
    }

    // จัดเรียง: งบประมาณ > โครงการ > วัน (เอาวันที่ที่มีจริงก่อน)
    $query .= " ORDER BY bi.item_name ASC, bd.detail_name ASC, COALESCE(p.due_date, p.completion_date) ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>Dashboard งบประมาณ (Phases)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .table-primary { --bs-table-bg: #e7f1ff; }
    .table-secondary { --bs-table-bg: #f3f4f6; }
    .sticky-head th { position: sticky; top: 0; background: #fff; z-index: 1; }
    .number { text-align: right; }
  </style>
</head>
<body class="bg-light">
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

  <!-- ตารางแสดงผล -->
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
    <th>งวด/ชื่อ</th>
    <th class="number">จำนวนเงิน (บาท)</th>
    <th>Due Date</th>
    <th>Completion Date</th>
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
              // รวมโครงการ (เซลล์เดียว ไม่มีช่องว่างเกิน)
              echo '<tr class="table-secondary fw-semibold">
                      <td colspan="6">
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

          // หัวกลุ่มโครงการ (เซลล์เดียว ครอบทั้ง 6 คอลัมน์)
          echo '<tr class="table-primary">
                  <td colspan="6" class="fw-bold">
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

      // รวม "งวดที่" + "ชื่อ" ไว้คอลัมน์เดียว
      $phaseLabel =  $p['phase_name'];
      

      $editUrl = 'edit_phase.php?phase_id='.urlencode($p['phase_id']).'&return='.urlencode($_SERVER['REQUEST_URI']);
?>
  <tr>
    <td><?= htmlspecialchars($phaseLabel) ?></td>
    <td class="number"><?= number_format($amount, 2) ?></td>
    <td><?= $p['due_date'] ? date("d/m/Y", strtotime($p['due_date'])) : '-' ?></td>
    <td><?= $p['completion_date'] ? date("d/m/Y", strtotime($p['completion_date'])) : '-' ?></td>
    <td><?= htmlspecialchars((string)$p['status']) ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="<?= $editUrl ?>">แก้ไข</a></td>
  </tr>
<?php endforeach; ?>

<?php if ($currentProject !== null): ?>
  <!-- ปิดกลุ่มสุดท้าย -->
  <tr class="table-secondary fw-semibold">
    <td colspan="6">
      <div class="d-flex justify-content-between">
        <span>รวมโครงการ</span>
        <span class="number"><?= number_format($projectSubtotal, 2) ?></span>
      </div>
    </td>
  </tr>
<?php endif; ?>

<!-- รวมทั้งหมด (เซลล์เดียว) -->
<tr class="table-dark fw-bold">
  <td colspan="6" class="text-white">
    <div class="d-flex justify-content-between">
      <span>รวมทั้งหมด</span>
      <span class="number text-white"><?= number_format($grandTotal, 2) ?></span>
    </div>
  </td>
</tr>
</tbody>

        </div>
      </div>
    </div>
  <?php elseif ($selected_year && $selected_item): ?>
    <div class="alert alert-warning">❌ ไม่พบข้อมูลงวดงานในช่วงเวลาที่เลือก</div>
  <?php else: ?>
    <div class="alert alert-info">ℹ️ โปรดเลือก ปีงบประมาณ และ งบประมาณ เพื่อแสดงข้อมูล</div>
  <?php endif; ?>
</div>
</body>
</html>
