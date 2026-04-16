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
include 'db.php';
// ---------------- ปีงบประมาณทั้งหมด (จาก budget_items) ----------------
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) { $years = [date('Y')]; } // fallback เป็นปีปัจจุบัน (ค.ศ./พ.ศ. แล้วแต่โครงสร้าง)


// ปีงบประมาณที่เลือก (คุมทั้งหน้า)
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : max($years);

// ---------------- Helper: ช่วงวันที่ไตรมาส/ปีงบ ----------------
function getQuarterRangeForFiscalBE(int $fiscalBE, int $quarter): array {
    $gy = $fiscalBE; // แก้ตามที่คุณใช้ (ตอนนี้ใช้เลขเดียวกัน)
    switch ($quarter) {
        case 1: return [($gy-1) . "-10-01", ($gy-1) . "-12-31"]; // ต.ค.–ธ.ค. (ปีก่อนหน้า)
        case 2: return [$gy . "-01-01", $gy . "-03-31"];         // ม.ค.–มี.ค.
        case 3: return [$gy . "-04-01", $gy . "-06-30"];         // เม.ย.–มิ.ย.
        default: return [$gy . "-07-01", $gy . "-09-30"];        // ก.ค.–ก.ย.
    }
}


function getCumulativeQuarterRangeForFiscalBE(int $fiscalBE, int $quarter): array {
    $gy = $fiscalBE;
    $start = ($gy - 1) . "-10-01";
    switch ($quarter) {
        case 1: $end = ($gy - 1) . "-12-31"; break;
        case 2: $end = ($gy)     . "-03-31"; break;
        case 3: $end = ($gy)     . "-06-30"; break;
        default:$end = ($gy)     . "-09-30"; break;
    }
    return [$start, $end];
}
function getFiscalYearRangeBE(int $fiscalBE): array {
    $gy = $fiscalBE;
    return [($gy-1)."-10-01", $gy."-09-30"];
}

// ---------------- ตัวกรอง: ไตรมาส ----------------
$quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : 1;
if (!in_array($quarter, [1,2,3,4], true)) $quarter = 1;

$quarterFiscalBE = $selectedYear;
[$qOnlyStart, $qOnlyEnd] = getQuarterRangeForFiscalBE($quarterFiscalBE, $quarter);
// ---------------- ดึงข้อมูลทั้งปีของปีที่เลือก (รายชื่อ + requested_amount) ----------------
// 🔴 ปรับ ORDER BY ให้เรียงตามลำดับ 1) งบการลงทุน 2) งบบูรณาการ 3) งบดำเนินงาน 4) งบรายจ่ายอื่น 5) อื่น ๆ
$stmt = $pdo->prepare("
    SELECT *
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
    LEFT JOIN phases   p 
        ON p.contract_detail_id = c.contract_id
       AND p.status = 'เสร็จสิ้น'
    WHERE bd.budget_item_id IN (
        SELECT id FROM budget_items WHERE fiscal_year = :fy
    )
    GROUP BY bd.budget_item_id
");
$stmtSpentAll->execute([':fy' => $selectedYear]);
$spentAllByItem = $stmtSpentAll->fetchAll(PDO::FETCH_KEY_PAIR);


// ===== เตรียมข้อมูลกราฟ (ทั้งปี) — อิง (A) =====
$itemNamesArr = array_column($items, 'item_name');
$requestedArr = array_map('floatval', array_column($items, 'requested_amount'));
$approvedArr  = [];
$remainingArr = [];
foreach ($items as $row) {
    $id   = (int)$row['id'];
    $req  = (float)$row['requested_amount'];
    $usedFiscal = isset($spentRows[$id]) ? (float)$spentRows[$id] : 0.0;
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
$totalRequested = array_sum($requestedArr);
$totalUsedAll   = array_sum($spentAllByItem);
$percentUsed    = $totalRequested > 0 ? ($totalUsedAll / $totalRequested) * 100 : 0;

/* =============================================================================
   บล็อกไตรมาส
============================================================================= */

$baseFiscalYearForTable = $selectedYear;


$quarterFiscalBE2 = $selectedYear - 543;
[$qStart, $qEnd] = getCumulativeQuarterRangeForFiscalBE($quarterFiscalBE2, $quarter);

$quarterMonthsMap = [
    1 => 'ต.ค. – ธ.ค.',
    2 => 'ม.ค. – มี.ค.',
    3 => 'เม.ย. – มิ.ย.',
    4 => 'ก.ค. – ก.ย.',
];

$stmtYearRequestedDetail = $pdo->prepare("
    SELECT COALESCE(SUM(bd.requested_amount),0) AS total_req_detail
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
");
$stmtYearRequestedDetail->execute([':fy' => $selectedYear]);
$yearTotalRequestedDetail = (float)$stmtYearRequestedDetail->fetchColumn();

$sqlQuarterFromPhases = "
SELECT
    bi.id AS budget_item_id,
    bi.item_name,
    COALESCE(SUM(
        CASE 
            WHEN p.payment_date BETWEEN :qStart AND :qEnd 
            THEN p.amount 
            ELSE 0 
        END
    ), 0) AS paid_sum
FROM budget_items bi
LEFT JOIN budget_detail bd 
    ON bd.budget_item_id = bi.id
LEFT JOIN contracts c 
    ON c.detail_item_id = bd.id_detail
LEFT JOIN phases p 
    ON p.contract_detail_id = c.contract_id
    AND p.payment_date BETWEEN :qStart AND :qEnd
WHERE bi.fiscal_year = :baseFY
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


$stmtQ = $pdo->prepare($sqlQuarterFromPhases);
$stmtQ->execute([
  ':baseFY' => $baseFiscalYearForTable,
  ':qStart' => $qStart,
  ':qEnd'   => $qEnd,
]);
$rowsAgg = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

$grand_paid_sum  = array_sum(array_map(fn($r)=> (float)$r['paid_sum'],  $rowsAgg));
$grand_percent_against_year_req = ($yearTotalRequestedDetail > 0)
    ? ($grand_paid_sum / $yearTotalRequestedDetail * 100)
    : 0;

$stmtQuarterOnly = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount), 0) AS paid_quarter_only
FROM phases p
JOIN contracts c       ON c.contract_id = p.contract_detail_id
JOIN budget_detail bd  ON bd.id_detail  = c.detail_item_id
JOIN budget_items bi   ON bi.id          = bd.budget_item_id
WHERE bi.fiscal_year = :fy
  AND p.status = 'เสร็จสิ้น'
  AND p.payment_date BETWEEN :qs AND :qe

");
// 🔥 แปลงปีงบ พ.ศ. → ค.ศ.
$fiscalYearAD = $selectedYear - 543;

// 🔥 คำนวณช่วงไตรมาส (เฉพาะไตรมาสที่เลือก)
switch ($quarter) {
    case 1: // ต.ค. - ธ.ค. (ปีก่อน)
        $qOnlyStart = ($fiscalYearAD - 1) . '-10-01';
        $qOnlyEnd   = ($fiscalYearAD - 1) . '-12-31';
        break;

    case 2: // ม.ค. - มี.ค.
        $qOnlyStart = $fiscalYearAD . '-01-01';
        $qOnlyEnd   = $fiscalYearAD . '-03-31';
        break;

    case 3: // เม.ย. - มิ.ย.
        $qOnlyStart = $fiscalYearAD . '-04-01';
        $qOnlyEnd   = $fiscalYearAD . '-06-30';
        break;

    case 4: // ก.ค. - ก.ย.
        $qOnlyStart = $fiscalYearAD . '-07-01';
        $qOnlyEnd   = $fiscalYearAD . '-09-30';
        break;
}

$stmtQuarterOnly->execute([
    ':fy' => $selectedYear,
    ':qs' => $qOnlyStart,
    ':qe' => $qOnlyEnd,
]);

$grand_paid_sum_exact_quarter = (float)$stmtQuarterOnly->fetchColumn();

// งบตาม พ.ร.บ.
// งบตาม พ.ร.บ. + เป้าหมายไตรมาส
$stmtAct = $pdo->prepare("
    SELECT 
        COALESCE(budget_act_amount,0) AS budget_act_amount,
        COALESCE(q1_percent,0) AS q1_percent,
        COALESCE(q2_percent,0) AS q2_percent,
        COALESCE(q3_percent,0) AS q3_percent,
        COALESCE(q4_percent,0) AS q4_percent
    FROM budget_act
    WHERE fiscal_year = ?
    LIMIT 1
");
$stmtAct->execute([$selectedYear]);
$actRow = $stmtAct->fetch(PDO::FETCH_ASSOC);

$totalActAmount = (float)($actRow['budget_act_amount'] ?? 0);

$q1Percent = (float)($actRow['q1_percent'] ?? 0);
$q2Percent = (float)($actRow['q2_percent'] ?? 0);
$q3Percent = (float)($actRow['q3_percent'] ?? 0);
$q4Percent = (float)($actRow['q4_percent'] ?? 0);

// เป้าหมายไตรมาสที่เลือก
$quarterTargetMap = [
    1 => $q1Percent,
    2 => $q2Percent,
    3 => $q3Percent,
    4 => $q4Percent,
];

$currentQuarterTarget = $quarterTargetMap[$quarter] ?? 0;

// เป้าหมายคิดเป็น "จำนวนเงิน" ตามงบ พ.ร.บ.
$currentQuarterTargetAmount = ($totalActAmount * $currentQuarterTarget) / 100;

// % ใช้จ่ายจริงเทียบงบ พ.ร.บ.
$actualPercentAgainstAct = $totalActAmount > 0
    ? ($grand_paid_sum / $totalActAmount) * 100
    : 0;

// ส่วนต่างระหว่างเป้าหมายกับผลจริง
$percentDiff = $actualPercentAgainstAct - $currentQuarterTarget;

// งบตามโครงการ (จาก budget_detail ทั้งปี)
$stmtReqDetail = $pdo->prepare("
    SELECT COALESCE(SUM(bd.requested_amount), 0) AS total_req_detail
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
");
$stmtReqDetail->execute([':fy' => $selectedYear]);
$totalProjectRequested = (float)$stmtReqDetail->fetchColumn();
// ===== รวมวงเงินที่ได้รับ (budget_received) ทั้งปี =====
$stmtTotalReceived = $pdo->prepare("
    SELECT COALESCE(SUM(bd.budget_received), 0)
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
");
$stmtTotalReceived->execute([':fy' => $selectedYear]);
$totalBudgetReceived = (float)$stmtTotalReceived->fetchColumn();

$totalRemainAct = max(0, $totalActAmount - $totalProjectRequested);

// งบตามโครงการแยกตาม budget_item_id
$stmtSumDetailPerItem = $pdo->prepare("
    SELECT bd.budget_item_id, SUM(bd.requested_amount) AS total_detail_amount
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
    GROUP BY bd.budget_item_id
");
$stmtSumDetailPerItem->execute([':fy' => $selectedYear]);
$sumDetailByItem = $stmtSumDetailPerItem->fetchAll(PDO::FETCH_KEY_PAIR);
// ===== budget_received แยกตาม budget_item_id (ทั้งปี) =====
$stmtReceivedByItem = $pdo->prepare("
    SELECT 
        bd.budget_item_id,
        COALESCE(SUM(bd.budget_received),0) AS total_received
    FROM budget_detail bd
    JOIN budget_items bi ON bi.id = bd.budget_item_id
    WHERE bi.fiscal_year = :fy
    GROUP BY bd.budget_item_id
");
$stmtReceivedByItem->execute([':fy' => $selectedYear]);
$receivedByItem = $stmtReceivedByItem->fetchAll(PDO::FETCH_KEY_PAIR);



function formatThaiDate($date){
    if (!$date) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('d/m/Y') : $date;
}



// ตั้งค่าพื้นฐาน
date_default_timezone_set('Asia/Bangkok');

// ฟังก์ชันแปลงวันที่ไทย
function thai_date($date) {
    if(empty($date)) return '';
    $thai_months = [1=>'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $date = new DateTime($date);
    return $date->format('j').' '.$thai_months[(int)$date->format('n')].' '.($date->format('Y')+543);
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
    body {
        font-family: 'Sarabun', sans-serif;
        background: #f7f9fc;
    }

    .chart-container {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }

    .chart-box {
        background: #fff;
        border-radius: 12px;
        padding: 16px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        min-width: 280px;
    }

    .bg-blue-800 {
        background: #0d47a1
    }

    .bg-blue-600 {
        background: #1e88e5
    }

    .bg-blue-500 {
        background: #42a5f5
    }

    .bg-blue-400 {
        background: #64b5f6
    }

    .bg-blue-300 {
        background: #90caf9
    }

    .table thead th {
        white-space: nowrap;
    }

    .filter-note {
        font-size: 0.85rem;
        color: #6c757d;
    }

    .mono {
        font-family: ui-monospace, Menlo, Consolas, monospace;
    }

    .navbar {
        transition: all 0.3s ease-in-out;
    }

    .navbar-nav .nav-link {
        position: relative;
        transition: all 0.3s ease;
    }

    .navbar-nav .nav-link::after {
        content: '';
        position: absolute;
        width: 0%;
        height: 2px;
        left: 0;
        bottom: 0;
        background-color: #ffffff;
        transition: width 0.3s;
    }

    .navbar-nav .nav-link:hover::after {
        width: 100%;
    }

    .navbar-nav .nav-link:hover {
        background-color: rgba(255, 255, 255, 0.15);
        border-radius: 6px;
    }

    .btn-success {
        transition: all 0.3s ease;
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2);
    }

    .btn-success:hover {
        background-color: #28a745;
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.25);
    }

    .navbar-brand {
        letter-spacing: 0.5px;
    }
    </style>
</head>

<body>
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


    <div class="container my-4">
        <h2 class="text-center mb-4">📊 Dashboard งบประมาณโครงการ  (ปี <?php echo htmlspecialchars($selectedYear); ?>)
        </h2>

        <form method="GET" class="mb-3 text-center">
            <label for="year">ปีงบประมาณ:</label>
            <select name="year" onchange="this.form.submit()" class="form-select w-auto d-inline-block">
                <?php foreach($years as $year): ?>
                <option value="<?php echo htmlspecialchars($year); ?>"
                    <?php echo ($year == $selectedYear) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($year); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="quarter" value="<?php echo (int)$quarter; ?>">
        </form>

        <!-- การ์ดสรุปรวมทั้งปี -->
        <div class="row text-center mb-4">
            <div class="col-md-4">
                <div class="card p-3 bg-blue-200 text-white">
                    <h4>งบตาม พ.ร.บ.</h4>
                    <h4><?php echo number_format($totalActAmount,2); ?> บาท</h4>
                </div>
            </div>
            <div class="col-md-4">
    <div class="card p-3 bg-blue-700 text-white">
        <h4>วงเงินที่ขอ</h4>
        <h4><?php echo number_format($totalBudgetReceived, 2); ?> บาท</h4>
    </div>
</div>

            <div class="col-md-4">
                <div class="card p-3 bg-blue-800 text-white">
                    <h4>งบคงเหลือตาม พ.ร.บ.</h4>
                    <h4><?php echo number_format($totalRemainAct,2); ?> บาท</h4>
                </div>
            </div>
        </div>

        <div class="row text-center mb-4">
            <div class="col-md-3">
                <div class="card p-3 bg-blue-600 text-white">
                    <h4>วงเงินตามสัญญา</h4>
                    <h4><?php echo number_format($totalProjectRequested, 2); ?> บาท</h4>
                </div>
            </div>
           
            <div class="col-md-3">
                <div class="card p-3 bg-blue-500 text-white">
                    <h4>ใช้จ่ายตามโครงการ</h4>
                    <h4><?php echo number_format($totalUsedAll, 2); ?> บาท</h4>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 bg-blue-400 text-white">
                    <h4>คงเหลือ</h4>
                    <h4><?php echo number_format(max(0, $totalProjectRequested - $totalUsedAll), 2); ?> บาท</h4>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3 bg-blue-300 text-white">
                    <h4>% ใช้จ่ายจริง</h4>
                    <h4>
                        <?php
                        $percentUsedProject = $totalProjectRequested > 0
                            ? ($totalUsedAll / $totalProjectRequested) * 100
                            : 0;
                        echo number_format($percentUsedProject, 2);
                    ?>%
                    </h4>
                </div>
            </div>
        </div>

        <!-- ตาราง budget_items (ทั้งปี) -->
        <div class="card p-3 mb-4">
            <h4>📋 รายการประเภทงบประมาณ </h4>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm mt-3">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center">ประเภท</th>
                            <th class="text-center">วงเงินที่ขอ</th>
                            <th class="text-center">วงเงินตามสัญญา</th>
                            <th class="text-center">ใช้จ่ายแล้ว</th>
                            <th class="text-center">คงเหลือ</th>
                            <th class="text-center">% ใช้จ่าย</th>
                            <th class="text-center">รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items as $row): 
                    $id   = (int)$row['id'];
                    $req  = isset($sumDetailByItem[$id]) ? (float)$sumDetailByItem[$id] : 0.0;
                    $used = isset($spentAllByItem[$id]) ? (float)$spentAllByItem[$id] : 0.0;
                    $received = isset($receivedByItem[$id]) ? (float)$receivedByItem[$id] : 0.0;
                    $rem  = max(0, $req - $used);
                    $pct  = $req > 0 ? ($used / $req * 100) : 0;
                ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td class="text-end"><?php echo number_format($received, 2); ?></td>
                            <td class="text-end"><?php echo number_format($req, 2); ?></td>
                            <td class="text-end"><?php echo number_format($used, 2); ?></td>
                            <td class="text-end"><?php echo number_format($rem, 2); ?></td>
                            <td class="text-end"><?php echo number_format($pct, 2); ?>%</td>
                            <td class="text-center">
                                <button class="btn btn-info btn-sm" onclick="loadDetail(<?php echo $id; ?>)">
                                    ดู
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                ไม่พบข้อมูลปีงบ <?php echo htmlspecialchars($selectedYear); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php
// เปอร์เซ็นต์เป้าหมายของไตรมาสที่เลือก
$selectedQuarterPercent = 0;

switch ($quarter) {
    case 1:
        $selectedQuarterPercent = (float)$q1Percent;
        break;
    case 2:
        $selectedQuarterPercent = (float)$q2Percent;
        break;
    case 3:
        $selectedQuarterPercent = (float)$q3Percent;
        break;
    case 4:
        $selectedQuarterPercent = (float)$q4Percent;
        break;
}
?>
        <!-- บล็อกไตรมาส -->
        <div class="card p-3 mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <h4 class="mb-0">🗓️ รายการงบประมาณตามไตรมาส</h4>
                    <div class="filter-note mt-1">
                        ปีฐานตาราง: <span
                            class="text-success"><?php echo htmlspecialchars($baseFiscalYearForTable); ?></span>

                    </div>
                </div>

                <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="year" value="<?php echo htmlspecialchars($selectedYear); ?>">
                    <label for="quarter" class="mb-0">ไตรมาส:</label>
                    <select id="quarter" name="quarter" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="1" <?php echo $quarter===1?'selected':''; ?>>ไตรมาส 1 (ต.ค.–ธ.ค.)</option>
                        <option value="2" <?php echo $quarter===2?'selected':''; ?>>ไตรมาส 2 (ม.ค.–มี.ค.)</option>
                        <option value="3" <?php echo $quarter===3?'selected':''; ?>>ไตรมาส 3 (เม.ย.–มิ.ย.)</option>
                        <option value="4" <?php echo $quarter===4?'selected':''; ?>>ไตรมาส 4 (ก.ค.–ก.ย.)</option>
                    </select>
                </form>
            </div>

<div class="row text-center mt-3 g-3">
   
    <div class="col-md-3">
        <div class="card p-3 bg-purple-400 text-white h-100">
            <h6 class="mb-1">ใช้จ่ายแล้ว (ทั้งหมด)</h6>
            <div class="fs-5"><?php echo number_format($grand_paid_sum, 2); ?> บาท</div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card p-3 bg-purple-500 text-white h-100">
            <h6 class="mb-1">ใช้จ่ายแล้ว (ตามไตรมาส <?= $quarter ?>)</h6>
            <div class="fs-5"><?php echo number_format($grand_paid_sum_exact_quarter, 2); ?> บาท</div>
        </div>
    </div>
       <div class="col-md-3">
        <div class="card p-3 bg-purple-700 text-white h-100">
            <h6 class="mb-1">เป้าหมายไตรมาส <?= $quarter ?></h6>
            <div class="fs-4 fw-bold">
                <?= number_format($selectedQuarterPercent, 2) ?>%
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card p-3 bg-purple-800 text-white h-100">
            <h6 class="mb-1">% จ่ายแล้ว</h6>
            <div class="fs-5"><?php echo number_format($grand_percent_against_year_req, 2); ?>%</div>
        </div>
    </div>
  

</div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped mt-3">
                    <thead class="table-dark text-center">
                        <tr>
                            <th>ประเภท</th>
                            <th>วงเงินที่ขอ</th>
                            <th>วงเงินตามสัญญา</th>
                            <th>ใช้จ่ายแล้ว</th>
                            <th>คงเหลือ</th>
                            <th>% จ่ายแล้ว</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_req = 0;
                        $total_paid = 0;
                        $total_remain = 0;
                        $total_received = 0;
                    ?>
                        <?php if ($rowsAgg): ?>
                        <?php foreach ($rowsAgg as $r):
                            $paid_sum  = (float)$r['paid_sum'];
                            $itemId = (int)$r['budget_item_id'];
                                $receivedQuarter = isset($receivedByItem[$itemId]) 
        ? (float)$receivedByItem[$itemId] 
        : 0.0;

                            $reqQuarter = isset($sumDetailByItem[$itemId]) ? (float)$sumDetailByItem[$itemId] : 0.0;
                            $remain = max(0, $reqQuarter - $paid_sum);
                            $pct_against_year_req = ($yearTotalRequestedDetail > 0)
                                ? ($paid_sum / $yearTotalRequestedDetail * 100)
                                : 0;
                            $total_received += $receivedQuarter;

                            $total_req += $reqQuarter;
                            $total_paid += $paid_sum;
                            $total_remain += $remain;
                        ?>
                        <tr>
                            <td class="text-start"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>

                            <td class="text-end"><?php echo number_format($receivedQuarter, 2); ?></td>
                            <td class="text-end"><?php echo number_format($reqQuarter, 2); ?></td>
                            <td class="text-end"><?php echo number_format($paid_sum, 2); ?></td>
                            <td class="text-end"><?php echo number_format($remain, 2); ?></td>
                            <td class="text-end"><?php echo number_format($pct_against_year_req, 2); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">ไม่พบข้อมูลในไตรมาสที่เลือก</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($rowsAgg): ?>
                        
                    <tfoot>
<tr class="table-secondary fw-bold">
    <td>รวมทั้งหมด</td>
    <td class="text-end"><?php echo number_format($total_received, 2); ?></td>
    <td class="text-end"><?php echo number_format($total_req, 2); ?></td>
    <td class="text-end"><?php echo number_format($total_paid, 2); ?></td>
    <td class="text-end"><?php echo number_format($total_remain, 2); ?></td>
    <td class="text-end">
        <?php echo $yearTotalRequestedDetail > 0
            ? number_format(($total_paid / $yearTotalRequestedDetail) * 100, 2)
            : '0'; ?>%
    </td>
</tr>
</tfoot>

                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- กราฟ -->

    </div>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function loadDetail(itemId) {
        fetch('load_detail.php?id=' + itemId)
            .then(res => res.text())
            .then(html => {
                document.getElementById('detailContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            })
            .catch(() => {
                document.getElementById('detailContent').innerHTML =
                    '<div class="text-danger">โหลดข้อมูลไม่สำเร็จ</div>';
                new bootstrap.Modal(document.getElementById('detailModal')).show();
            });
    }
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const params = new URLSearchParams(window.location.search);
        const id = params.get('id');
        if (id) loadDetail(id);
    });
    </script>
</body>

</html>