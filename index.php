<?php
/*******************************
 * Dashboard งบประมาณ (สรุปไตรมาสรวมตามงบโครงการ จาก phases)
 * - ปีงบประมาณ: เลือกด้านบน (ควบคุมทั้งหน้า)
 * - ไตรมาส: แบบสะสม (Q2 = Q1+Q2, Q3 = Q1+Q2+Q3, Q4 = Q1+Q2+Q3+Q4)
 * - ตารางไตรมาส: ดึงจาก phases (join contracts → budget_detail → budget_items)
 *                 รวมตาม budget_item_id พร้อมแสดง item_name
 * - การ์ด/กราฟทั้งปี:
 *      - กราฟ/เปอร์เซ็นต์ในกราฟ อิงช่วงปีงบ (payment→completion→due)
 *      - การ์ด "ใช้จ่ายตามโครงการ" = SUM(phases.amount) แบบไม่กรองวันที่ (ให้ตรงกับตารางทั้งปี)
 * - บล็อกไตรมาส:
 *      - กรองด้วย phases.payment_date เฉพาะช่วงไตรมาสที่เลือก
 *      - คอลัมน์ “% จ่ายแล้ว (เทียบงบที่จ้างทั้งปี)” = (ยอดจ่ายของรายการในไตรมาส ÷
 *        ผลรวม budget_detail.requested_amount ของ “ปีที่เลือก”) × 100
 *******************************/

// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------- ปีงบประมาณทั้งหมด (จาก budget_items) ----------------
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_items ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) { $years = [date('Y') + 543]; } // fallback เป็นปี พ.ศ. ปัจจุบัน

// ปีงบประมาณที่เลือก (คุมทั้งหน้า) — *หมายเหตุ*: ถือว่า fiscal_year ใน DB เป็น "พ.ศ."
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : max($years);

// ---------------- Helper: ช่วงวันที่ไตรมาส/ปีงบ (พ.ศ.) → (ค.ศ.) ----------------
function getQuarterRangeForFiscalBE(int $fiscalBE, int $quarter): array {
    $gy = $fiscalBE - 543; // พ.ศ. → ค.ศ.
    switch ($quarter) {
        case 1: return [($gy-1) . "-10-01", ($gy-1) . "-12-31"]; // ต.ค.–ธ.ค. (ปีก่อนหน้า)
        case 2: return [$gy . "-01-01", $gy . "-03-31"];         // ม.ค.–มี.ค.
        case 3: return [$gy . "-04-01", $gy . "-06-30"];         // เม.ย.–มิ.ย.
        default: return [$gy . "-07-01", $gy . "-09-30"];        // ก.ค.–ก.ย.
    }
}
function getCumulativeQuarterRangeForFiscalBE(int $fiscalBE, int $quarter): array {
    // แบบสะสม (YTD): Q1=10/01~12/31, Q2=01/01~03/31, Q3=04/01~06/30, Q4=07/01~09/30
    $gy = $fiscalBE - 543;
    $start = ($gy - 1) . "-10-01"; // ต้นปีงบ (ค.ศ.) = 1 ต.ค.ของปีก่อนหน้า
    switch ($quarter) {
        case 1: $end = ($gy - 1) . "-12-31"; break;
        case 2: $end = ($gy)     . "-03-31"; break;
        case 3: $end = ($gy)     . "-06-30"; break;
        default:$end = ($gy)     . "-09-30"; break;
    }
    return [$start, $end];
}
function getFiscalYearRangeBE(int $fiscalBE): array {
    // ปีงบ พ.ศ. XXXX = 1 ต.ค. (XXXX-543-1) ถึง 30 ก.ย. (XXXX-543)
    $gy = $fiscalBE - 543;
    return [($gy-1)."-10-01", $gy."-09-30"];
}

// ---------------- ตัวกรอง: ไตรมาส ----------------
$quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : 1;
if (!in_array($quarter, [1,2,3,4], true)) $quarter = 1;

// ---------------- ดึงข้อมูลทั้งปีของปีที่เลือก (รายชื่อ + requested_amount) ----------------
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE fiscal_year = ? ORDER BY id ASC");
$stmt->execute([$selectedYear]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== ขอบเขตวันของปีงบที่เลือก =====
[$fyStart, $fyEnd] = getFiscalYearRangeBE($selectedYear);

// ===== (A) ใช้จ่ายแล้วทั้งปี (อิงช่วงปีงบ) — สำหรับกราฟ =====
$stmtSpent = $pdo->prepare("
    SELECT 
        bi.id AS budget_item_id,
        COALESCE(SUM(
            CASE 
                WHEN p.payment_date IS NOT NULL AND p.payment_date BETWEEN :yStart AND :yEnd THEN p.amount
                WHEN p.payment_date IS NULL AND p.completion_date IS NOT NULL AND p.completion_date BETWEEN :yStart AND :yEnd THEN p.amount
                WHEN p.payment_date IS NULL AND p.completion_date IS NULL AND p.due_date BETWEEN :yStart AND :yEnd THEN p.amount
                ELSE 0
            END
        ),0) AS spent_sum
    FROM budget_items bi
    LEFT JOIN budget_detail bd ON bd.budget_item_id = bi.id
    LEFT JOIN contracts c      ON c.detail_item_id  = bd.id_detail
    LEFT JOIN phases p         ON p.contract_detail_id = c.contract_id
    WHERE bi.fiscal_year = :fy
    GROUP BY bi.id
");
$stmtSpent->execute([
    ':fy'     => $selectedYear,
    ':yStart' => $fyStart,
    ':yEnd'   => $fyEnd,
]);
$spentRows = $stmtSpent->fetchAll(PDO::FETCH_KEY_PAIR); // [budget_item_id => spent_sum]

// ===== (B) ใช้จ่ายแล้ว (จากงวดงาน) แบบ “ไม่กรองวันที่” — สำหรับตารางทั้งปี + การ์ด "ใช้จ่ายตามโครงการ" =====
$stmtSpentAll = $pdo->prepare("
    SELECT 
        bd.budget_item_id AS budget_item_id,
        COALESCE(SUM(p.amount), 0) AS spent_all_sum
    FROM budget_detail bd
    LEFT JOIN contracts c ON c.detail_item_id = bd.id_detail
    LEFT JOIN phases   p ON p.contract_detail_id = c.contract_id
    WHERE bd.budget_item_id IN (
        SELECT id FROM budget_items WHERE fiscal_year = :fy
    )
    GROUP BY bd.budget_item_id
");
$stmtSpentAll->execute([':fy' => $selectedYear]);
$spentAllByItem = $stmtSpentAll->fetchAll(PDO::FETCH_KEY_PAIR); // [budget_item_id => sum(phases.amount)]

// ===== เตรียมข้อมูลกราฟ (ทั้งปี) — อิง (A) =====
$itemNamesArr = array_column($items, 'item_name');
$requestedArr = array_map('floatval', array_column($items, 'requested_amount'));
$approvedArr  = [];   // ใช้จ่ายแล้ว (อิงช่วงปีงบ) สำหรับกราฟ
$remainingArr = [];
foreach ($items as $row) {
    $id   = (int)$row['id'];
    $req  = (float)$row['requested_amount'];
    $usedFiscal = isset($spentRows[$id]) ? (float)$spentRows[$id] : 0.0; // อิงช่วงปีงบ
    $approvedArr[]  = $usedFiscal;
    $remainingArr[] = max(0, $req - $usedFiscal);
}
$itemNames  = json_encode($itemNamesArr, JSON_UNESCAPED_UNICODE);
$requested  = json_encode($requestedArr);
$approved   = json_encode($approvedArr);
$percentage = json_encode(array_map(function($req, $used){
                    return $req > 0 ? round(($used / $req) * 100, 2) : 0;
                }, $requestedArr, $approvedArr));
$remaining  = json_encode($remainingArr);

// ===== การ์ดสรุปทั้งปี =====
$totalRequested = array_sum($requestedArr);      // งบตาม พรบ.
$totalUsedAll   = array_sum($spentAllByItem);    // ใช้จ่ายตามโครงการ = SUM(phases.amount) ไม่กรองวันที่
$percentUsed    = $totalRequested > 0 ? ($totalUsedAll / $totalRequested) * 100 : 0;

/* =============================================================================
   บล็อกไตรมาส (ใช้ "ปีที่ผู้ใช้เลือก") — กรองเฉพาะ phases.payment_date
   % จ่ายแล้ว (เทียบงบทั้งปี) = paid_sum(ไตรมาสนี้) / SUM(budget_detail.requested_amount ทั้งปีที่เลือก)
============================================================================= */

// ✅ ปีฐานตารางไตรมาส = ปีที่เลือกจริง
$baseFiscalYearForTable = $selectedYear;

// ✅ ช่วงวันของ “ไตรมาสที่เลือก” = แบบสะสม และคำนวณจาก “ปีที่เลือกจริง”
$quarterFiscalBE = $selectedYear;
[$qStart, $qEnd] = getCumulativeQuarterRangeForFiscalBE($quarterFiscalBE, $quarter);

// ป้ายกำกับไตรมาส
$quarterMonthsMap = [
    1 => 'ต.ค. – ธ.ค.',
    2 => 'ม.ค. – มี.ค.',
    3 => 'เม.ย. – มิ.ย.',
    4 => 'ก.ค. – ก.ย.',
];

// (1) ฐานงบที่จ้างของ “ปีที่เลือก” = SUM(budget_detail.requested_amount) ทุกโครงการในปีนั้น
$stmtYearRequestedDetail = $pdo->prepare("
    SELECT COALESCE(SUM(bd.requested_amount),0) AS total_req_detail
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
");
$stmtYearRequestedDetail->execute([':fy' => $selectedYear]);
$yearTotalRequestedDetail = (float)$stmtYearRequestedDetail->fetchColumn(); // ตัวหารของ % ในตารางไตรมาส

// (2) ยอดจ่ายตามไตรมาส (อิง phases.payment_date) รวมตามงบโครงการ
$sqlQuarterFromPhases = "
  SELECT
    bi.id                        AS budget_item_id,
    MAX(bi.item_name)           AS item_name,
    COALESCE(SUM(p.amount), 0)  AS paid_sum
  FROM phases p
  JOIN contracts c      ON p.contract_detail_id = c.contract_id
  JOIN budget_detail bd ON c.detail_item_id     = bd.id_detail
  JOIN budget_items bi  ON bd.budget_item_id    = bi.id
  WHERE bi.fiscal_year = :baseFY
    AND p.payment_date BETWEEN :qStart AND :qEnd
  GROUP BY bi.id
  ORDER BY item_name ASC
";

$stmtQ = $pdo->prepare($sqlQuarterFromPhases);
$stmtQ->execute([
  ':baseFY' => $baseFiscalYearForTable,
  ':qStart' => $qStart,
  ':qEnd'   => $qEnd,
]);
$rowsAgg = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

// รวมยอดจ่ายทั้งหมดในไตรมาส (เพื่อแสดง)
$grand_paid_sum  = array_sum(array_map(fn($r)=> (float)$r['paid_sum'],  $rowsAgg));

// % รวมทั้งตารางเมื่อเทียบ “งบที่จ้างทั้งปี”
$grand_percent_against_year_req = ($yearTotalRequestedDetail > 0)
    ? ($grand_paid_sum / $yearTotalRequestedDetail * 100)
    : 0;
// ✅ รวมงบตาม พ.ร.บ. (จากคอลัมน์ budget_act_amount)
$stmtAct = $pdo->prepare("SELECT COALESCE(SUM(budget_act_amount),0) FROM budget_act WHERE fiscal_year = ?");
$stmtAct->execute([$selectedYear]);
$totalActAmount = (float)$stmtAct->fetchColumn();

// ✅ คำนวณงบคงเหลือที่ (requested_amount - ใช้จ่ายตามโครงการ)
$totalRemainAct = max(0, $totalActAmount - $totalRequested);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Dashboard งบประมาณ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Bootstrap & Chart.js -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Custom -->
    <link rel="stylesheet" href="styles.css">
    <style>
        body { font-family: 'Sarabun', sans-serif; background:#f7f9fc; }
        .chart-container { display: flex; gap: 16px; flex-wrap: wrap; }
        .chart-box { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); min-width: 280px; }
        .bg-blue-800{background:#0d47a1}.bg-blue-600{background:#1e88e5}.bg-blue-500{background:#42a5f5}.bg-blue-400{background:#64b5f6}.bg-blue-300{background:#90caf9}
        .table thead th { white-space: nowrap; }
        .filter-note { font-size: 0.85rem; color:#6c757d; }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
        
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
      <div class="container-fluid">
        <a class="navbar-brand fs-3" href="#">Dashboard</a>
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
            <li class="nav-item"><a class="nav-link active fs-5 text-white" href="dashboard_report.php">การจ่ายงวด (Phases)</a></li>
        </ul>
        <div class="d-flex">
          <a href="dashboard.php" class="btn btn-success btn-lg">เพิ่มงบประมาณ</a>
        </div>
      </div>
    </nav>

    <div class="container my-4">
        <h2 class="text-center mb-4">📊 Dashboard งบประมาณโครงการ IT (ปี <?php echo htmlspecialchars($selectedYear); ?>)</h2>

        <!-- เลือกปีงบประมาณ -->
        <form method="GET" class="mb-3 text-center">
            <label for="year">ปีงบประมาณ:</label>
            <select name="year" onchange="this.form.submit()" class="form-select w-auto d-inline-block">
                <?php foreach($years as $year): ?>
                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo ($year == $selectedYear) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($year); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <!-- คงค่าไตรมาสไว้เมื่อเปลี่ยนปี -->
            <input type="hidden" name="quarter" value="<?php echo (int)$quarter; ?>">
        </form>

        <!-- การ์ดสรุปรวมทั้งปี -->
        <div class="row text-center mb-4">
             <div class="col-md-6">
      <div class="card p-3 bg-purple-700 text-white">
         <h4>งบตาม พ.ร.บ.</h4>
        <h2><?php echo number_format($totalActAmount,2); ?> บาท</h2>
        
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3 bg-blue-800  text-white">
        <h4>งบที่ใช้ตามโครงการ</h4>
        <h2><?php echo number_format($totalRemainAct,2); ?> บาท</h2>
      </div>
    </div>
        </div>
        <div class="row text-center mb-4">
            <div class="col-md-3">
                <div class="card p-3 bg-blue-600 text-white">
                    <h4>งบตามโครงการ</h4>
                    <h2><?php echo number_format($totalRequested); ?> บาท</h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 bg-blue-500 text-white">
                    <h4>ใช้จ่ายตามโครงการ</h4>
                    <h2><?php echo number_format($totalUsedAll); ?> บาท</h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 bg-blue-400 text-white">
                    <h4>คงเหลือ</h4>
                    <h2><?php echo number_format(max(0, $totalRequested - $totalUsedAll)); ?> บาท</h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 bg-blue-300 text-white">
                    <h4>% ใช้จ่ายจริง</h4>
                    <h2><?php echo number_format($percentUsed, 2); ?>%</h2>
                </div>
            </div>
        </div>

        <!-- ตาราง budget_items (ทั้งปี) — ใช้จ่ายแล้ว (จากงวดงาน) = รวม phases แบบไม่กรองวันที่ -->
        <div class="card p-3 mb-4">
            <h4>📋 รายการงบประมาณ (ทั้งปี)</h4>  
            <table class="table table-bordered table-striped mt-3">
                <thead class="table-dark">
                    <tr>
                        <th>ประเภท</th>
                        <th>งบประมาณ</th>
                        <th>ใช้จ่ายแล้ว (จากงวดงาน)</th>
                        <th>คงเหลือ</th>
                        <th>% ใช้จ่าย</th>
                        <th>รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($items as $row): 
                    $id   = (int)$row['id'];
                    $req  = (float)$row['requested_amount'];

                    // SUM(phases.amount) ไม่กรองวันที่
                    $used = isset($spentAllByItem[$id]) ? (float)$spentAllByItem[$id] : 0.0;

                    $rem  = max(0, $req - $used);
                    $pct  = $req > 0 ? ($used / $req * 100) : 0;
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                        <td><?php echo number_format($req, 2); ?></td>
                        <td><?php echo number_format($used, 2); ?></td>
                        <td><?php echo number_format($rem, 2); ?></td>
                        <td><?php echo number_format($pct, 2); ?>%</td>
                        <td><button class="btn btn-info btn-sm" onclick="loadDetail(<?php echo $id; ?>)">ดู</button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="6" class="text-center text-muted">ไม่พบข้อมูลปีงบ <?php echo htmlspecialchars($selectedYear); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ====================== บล็อกไตรมาส (ดึงจาก phases.payment_date) ====================== -->
        <div class="card p-3 mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <h4 class="mb-0">🗓️ รายการงบประมาณตามไตรมาส (อิง payment_date)</h4>
                    <div class="filter-note mt-1">
                        ปีฐานตาราง: <span class="text-success"><?php echo htmlspecialchars($baseFiscalYearForTable); ?></span> |
                        ฐานคิด % = งบที่จ้างทั้งปีจาก <span class="mono">budget_detail.requested_amount</span>
                    </div>
                </div>
                <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                    <!-- คงค่าปีงบประมาณ -->
                    <input type="hidden" name="year" value="<?php echo htmlspecialchars($selectedYear); ?>">
                    <!-- ไตรมาส -->
                    <label for="quarter" class="mb-0">ไตรมาส (สะสม):</label>
                    <select id="quarter" name="quarter" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="1" <?php echo $quarter===1?'selected':''; ?>>ไตรมาส 1 (ต.ค.–ธ.ค.)</option>
                        <option value="2" <?php echo $quarter===2?'selected':''; ?>>ไตรมาส 2 (ม.ค.–มี.ค.)</option>
                        <option value="3" <?php echo $quarter===3?'selected':''; ?>>ไตรมาส 3 (เม.ย.–มิ.ย.)</option>
                        <option value="4" <?php echo $quarter===4?'selected':''; ?>>ไตรมาส 4 (ก.ค.–ก.ย.)</option>
                    </select>
                </form>
            </div>

            <div class="mt-2 text-muted">
                <small>
                    ช่วงเดือน (สะสมถึงไตรมาส <?php echo $quarter; ?>): <?php echo $quarterMonthsMap[$quarter]; ?> |
                    อ้างอิงเฉพาะ <em>phases.payment_date</em> ในช่วง
                    <strong><?php echo $qStart; ?> → <?php echo $qEnd; ?></strong> |
                    งบที่จ้างทั้งปี: <strong><?php echo number_format($yearTotalRequestedDetail, 2); ?></strong> บาท
                </small>
            </div>

            <!-- การ์ดสรุปรวมของบล็อกไตรมาส -->
            <div class="row text-center mt-3">
                <div class="col-md-4"><div class="card p-3 bg-blue-600 text-white"><h6 class="mb-1">ยอดจ่าย (รวมในไตรมาส)</h6><div class="fs-5"><?php echo number_format($grand_paid_sum, 2); ?> บาท</div></div></div>
                <div class="col-md-4"><div class="card p-3 bg-blue-500 text-white"><h6 class="mb-1">งบที่จ้างทั้งปี</h6><div class="fs-5"><?php echo number_format($yearTotalRequestedDetail, 2); ?> บาท</div></div></div>
                <div class="col-md-4"><div class="card p-3 bg-blue-400 text-white"><h6 class="mb-1">% จ่ายแล้ว (ไตรมาสนี้เทียบงบทั้งปี)</h6><div class="fs-5"><?php echo number_format($grand_percent_against_year_req, 2); ?>%</div></div></div>
            </div>

            <!-- ตารางรวมยอดตาม “งบโครงการ (budget_item_id + item_name)” จาก phases.payment_date -->
            <div class="table-responsive">
                <table class="table table-bordered table-striped mt-3">
                    <thead class="table-dark">
                        <tr>
                            <th>ประเภทงบ</th>
                            <th>ยอดจ่าย (จาก phases.amount)</th>
                            <th>% จ่ายแล้ว (เทียบงบทั้งปี)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rowsAgg): ?>
                            <?php foreach ($rowsAgg as $r):
                                $paid_sum  = (float)$r['paid_sum']; // จ่ายของรายการนี้ภายในไตรมาส
                                $pct_against_year_req = ($yearTotalRequestedDetail > 0)
                                    ? ($paid_sum / $yearTotalRequestedDetail * 100)
                                    : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
                                    <td><?php echo number_format($paid_sum, 2); ?></td>
                                    <td><?php echo number_format($grand_percent_against_year_req, 2); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted">ไม่พบข้อมูลในไตรมาสที่เลือก</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($rowsAgg): ?>
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <td>รวมทั้งหมด</td>
                                <td><?php echo number_format($grand_paid_sum, 2); ?></td>
                                <td><?php echo number_format($grand_percent_against_year_req, 2); ?>%</td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <!-- ====================== จบ: บล็อกไตรมาส ====================== -->

        <!-- กราฟ (อิงทั้งปี: อิงช่วงปีงบ) -->
        <div class="chart-container">
            <div class="chart-box" style="flex: 2%;">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">งบประมาณเปรียบเทียบกับการใช้จ่ายจริง (ทั้งปี)</h5>
                    <select id="chartType" class="form-select w-auto">
                        <option value="all">รวมทั้งหมด</option>
                        <option value="requested">งบประมาณ</option>
                        <option value="approved">ใช้จ่ายแล้ว</option>
                        <option value="remaining">คงเหลือ</option>
                    </select>
                </div>
                <canvas id="budgetChart"></canvas>
            </div>
            <div class="chart-box" style="flex: 1%;">
                <h5 class="text-center">สัดส่วนงบประมาณตามประเภท (%)</h5>
                <canvas id="pieChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Modal สำหรับรายละเอียด -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดงบประมาณ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ====== ข้อมูลกราฟ (ทั้งปี) — approved/remaining คิดจากช่วงปีงบ ======
    const labels     = <?php echo $itemNames ?: '[]'; ?>;
    const requested  = <?php echo $requested ?: '[]'; ?>;
    const approved   = <?php echo $approved ?: '[]'; ?>;
    const percentage = <?php echo $percentage ?: '[]'; ?>;
    const remaining  = <?php echo $remaining ?: '[]'; ?>;

    const ctx = document.getElementById('budgetChart');
    let budgetChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'งบประมาณ',  data: requested, backgroundColor: '#42A5F5', borderRadius: 10 },
                { label: 'ใช้จ่ายแล้ว', data: approved,  backgroundColor: '#66BB6A', borderRadius: 10 },
                { label: 'คงเหลือ',    data: remaining, backgroundColor: '#FFA726', borderRadius: 10 }
            ]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, title: { display: true, text: 'บาท' } } }
        }
    });

    document.getElementById('chartType').addEventListener('change', function(){
        const type = this.value;
        let ds = [];
        if(type === 'requested') {
            ds = [{ label: 'งบประมาณ', data: requested, backgroundColor: '#42A5F5', borderRadius: 10 }];
        } else if(type === 'approved') {
            ds = [{ label: 'ใช้จ่ายแล้ว', data: approved, backgroundColor: '#66BB6A', borderRadius: 10 }];
        } else if(type === 'remaining') {
            ds = [{ label: 'คงเหลือ', data: remaining, backgroundColor: '#FFA726', borderRadius: 10 }];
        } else {
            ds = [
                { label: 'งบประมาณ',  data: requested, backgroundColor: '#42A5F5', borderRadius: 10 },
                { label: 'ใช้จ่ายแล้ว', data: approved,  backgroundColor: '#66BB6A', borderRadius: 10 },
                { label: 'คงเหลือ',    data: remaining, backgroundColor: '#FFA726', borderRadius: 10 }
            ];
        }
        budgetChart.data.datasets = ds;
        budgetChart.update();
    });

    new Chart(document.getElementById('pieChart'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: percentage, backgroundColor: ['#42A5F5','#66BB6A','#FFA726','#AB47BC','#26C6DA','#ef5350','#8d6e63','#26a69a'] }]
        }
    });

    // โหลดรายละเอียดผ่าน AJAX (ของตารางทั้งปี)
    function loadDetail(itemId){
        fetch('load_detail.php?id='+itemId)
            .then(res => res.text())
            .then(html => {
                document.getElementById('detailContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            })
            .catch(() => {
                document.getElementById('detailContent').innerHTML = '<div class="text-danger">โหลดข้อมูลไม่สำเร็จ</div>';
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            });
    }
    </script>
</body>
</html>
