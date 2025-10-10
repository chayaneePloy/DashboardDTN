<?php
// เชื่อมต่อฐานข้อมูล
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// รับพารามิเตอร์
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ---------------- ดึงข้อมูล budget_items (หัวข้อหลักประเภทโครงการ) ----------------
$stmtItem = $pdo->prepare("
    SELECT item_name, requested_amount
    FROM budget_items
    WHERE id = ?
");
$stmtItem->execute([$id]);
$item = $stmtItem->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo "<p class='text-danger'>ไม่พบโครงการนี้</p>";
    exit;
}

// ---------------- ดึงรายการย่อย (budget_detail) ----------------
$stmt = $pdo->prepare("
    SELECT id_detail, detail_name, requested_amount
    FROM budget_detail
    WHERE budget_item_id = ?
    ORDER BY id_detail ASC
");
$stmt->execute([$id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------- ดึงยอดใช้จ่ายจาก phases (รวมตามโครงการ) ----------------
// map: detail_id => sum(phases.amount)
$phaseSumByDetail = [];
if ($details) {
    // ใช้ LEFT JOIN เพื่อให้โครงการที่ยังไม่มี phase ก็ยังแสดง (ยอด = 0)
    $stmtPhase = $pdo->prepare("
        SELECT 
            bd.id_detail,
            COALESCE(SUM(p.amount), 0) AS phase_sum
        FROM budget_detail bd
        LEFT JOIN contracts c ON c.detail_item_id = bd.id_detail
        LEFT JOIN phases   p ON p.contract_detail_id = c.contract_id
        WHERE bd.budget_item_id = ?
        GROUP BY bd.id_detail
    ");
    $stmtPhase->execute([$id]);
    $rowsPhase = $stmtPhase->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsPhase as $r) {
        $phaseSumByDetail[(int)$r['id_detail']] = (float)$r['phase_sum'];
    }
}

// ---------------- คำนวณสรุปจากรายละเอียด + phases ----------------
$totalRequested = 0.0; // รวมงบประมาณจากรายละเอียด
$totalPhases    = 0.0; // รวมยอดใช้จ่ายจาก phases (ทุกโครงการในหมวดนี้)

if ($details) {
    foreach ($details as $d) {
        $detailId   = (int)$d['id_detail'];
        $detailReq  = (float)$d['requested_amount'];
        $detailUsed = $phaseSumByDetail[$detailId] ?? 0.0;

        $totalRequested += $detailReq;
        $totalPhases    += $detailUsed;
    }
}

// ---------------- ค่าแสดง "สรุปด้านบน" ----------------
// งบประมาณ (ช่องบน) = budget_items.requested_amount
$topRequested = (float) $item['requested_amount'];

// ใช้จ่ายแล้ว (ช่องบน) = รวมจาก phases (ทุกโครงการภายใต้ budget_item_id นี้)
$topApproved  = $totalPhases;

// คงเหลือ + % ใช้จ่าย (ช่องบน)
$topRemaining = $topRequested - $topApproved;
$topPercent   = $topRequested > 0 ? ($topApproved / $topRequested) * 100 : 0.0;

// ---------------- แสดงผล ----------------

// หัวข้อประเภทโครงการ
echo "<h5>📌 ประเภทโครงการ: <span class='text-primary'>".htmlspecialchars($item['item_name'])."</span></h5>";

// ตารางสรุปด้านบน (งบประมาณ = budget_items.requested_amount, ใช้จ่ายแล้ว = phases.sum)
echo "<table class='table table-bordered mb-4'>
        <thead class='table-dark'> 
            <tr>
                <th>ประเภท</th>
                <th>งบประมาณ</th>
                <th>ใช้จ่ายแล้ว (จากงวดงาน)</th>
                <th>คงเหลือ</th>
                <th>% ใช้จ่าย</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>".htmlspecialchars($item['item_name'])."</td>
                <td>".number_format($topRequested, 2)."</td>
                <td>".number_format($topApproved, 2)."</td>
                <td>".number_format($topRemaining, 2)."</td>
                <td>".number_format($topPercent, 2)."%</td>
            </tr>
        </tbody>
      </table>";

// ถ้าไม่มีรายละเอียด แสดงข้อความและจบ
if (!$details) {
    echo "<p>ไม่มีรายละเอียด</p>";
    exit;
}

// ตารางรายละเอียด (แถวละโครงการ)
echo "<h6>🔎 รายการย่อย (Detail)</h6>";
echo "<table class='table table-bordered table-striped'>
        <thead class='table-secondary'>
            <tr>
                <th>รายละเอียด</th>
                <th>งบที่จ้าง</th>
                <th>ใช้จ่ายแล้ว (จากงวดงาน)</th>
                <th>คงเหลือ</th>
                <th>% ใช้จ่าย</th>
            </tr>
        </thead>
        <tbody>";

foreach ($details as $d) {
    $detailId        = (int)$d['id_detail'];
    $detailName      = (string)$d['detail_name'];
    $detailRequested = (float)$d['requested_amount'];

    // ยอดใช้จ่ายจาก phases ของโครงการนี้
    $detailUsed      = $phaseSumByDetail[$detailId] ?? 0.0;

    $detailRemain    = $detailRequested - $detailUsed;
    $detailPercent   = $detailRequested > 0 ? ($detailUsed / $detailRequested) * 100 : 0.0;

    $link = "steps.php?id_detail=" . urlencode($detailId);

    echo "<tr>
            <td><a href='{$link}' class='text-decoration-none'>".htmlspecialchars($detailName)."</a></td>
            <td>".number_format($detailRequested, 2)."</td>
            <td>".number_format($detailUsed, 2)."</td>
            <td>".number_format($detailRemain, 2)."</td>
            <td>".number_format($detailPercent, 2)."%</td>
          </tr>";
}

// แถวรวม (อ้างจากผลรวมจริง: requested จาก detail, used จาก phases)
$bottomRemaining = $totalRequested - $totalPhases;
$bottomPercent   = $totalRequested > 0 ? ($totalPhases / $totalRequested) * 100 : 0;

echo "<tr class='fw-bold'>
        <td>รวมทั้งหมด</td>
        <td>".number_format($totalRequested, 2)."</td>
        <td>".number_format($totalPhases, 2)."</td>
        <td>".number_format($bottomRemaining, 2)."</td>
        <td>".number_format($bottomPercent, 2)."%</td>
      </tr>";

echo "</tbody></table>";
