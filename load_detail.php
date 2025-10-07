<?php
// เชื่อมต่อฐานข้อมูล
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// รับพารามิเตอร์
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ---------------- ดึงข้อมูล budget_items (หัวข้อหลักประเภทโครงการ) ----------------
$stmtItem = $pdo->prepare("
    SELECT item_name, requested_amount, approved_amount, percentage
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
    SELECT id_detail, detail_name, requested_amount, approved_amount, percentage
    FROM budget_detail
    WHERE budget_item_id = ?
    ORDER BY id_detail ASC
");
$stmt->execute([$id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------- คำนวณสรุปจากรายละเอียดด้านล่าง ----------------
$totalRequested = 0.0; // รวมงบประมาณจากรายละเอียด (ใช้สำหรับแถวรวมด้านล่าง)
$totalApproved  = 0.0; // รวมใช้จ่ายแล้วจากรายละเอียด

if ($details) {
    foreach ($details as $d) {
        $totalRequested += (float) $d['requested_amount'];
        $totalApproved  += (float) $d['approved_amount'];
    }
}

// ---------------- ค่าแสดง "สรุปด้านบน" ----------------
// งบประมาณ (ช่องบน) = ดึงจาก budget_items.requested_amount ตามที่ต้องการ
$topRequested = (float) $item['requested_amount'];

// ใช้จ่ายแล้ว (ช่องบน) = รวมจากรายละเอียดด้านล่าง (fallback เป็นค่าใน budget_items หากไม่มีรายละเอียด)
$topApproved  = $details ? $totalApproved : (float) $item['approved_amount'];

// คงเหลือ + % ใช้จ่าย (ช่องบน)
$topRemaining = $topRequested - $topApproved;
$topPercent   = $topRequested > 0 ? ($topApproved / $topRequested) * 100 : 0.0;

// ---------------- แสดงผล ----------------

// หัวข้อประเภทโครงการ
echo "<h5>📌 ประเภทโครงการ: <span class='text-primary'>".htmlspecialchars($item['item_name'])."</span></h5>";

// ตารางสรุปด้านบน (งบประมาณ = budget_items.requested_amount)
echo "<table class='table table-bordered mb-4'>
        <thead class='table-dark'> 
            <tr>
                <th>ประเภท</th>
                <th>งบประมาณ</th>
                <th>ใช้จ่ายแล้ว</th>
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

// ตารางรายละเอียดด้านล่าง
echo "<h6>🔎 รายการย่อย (Detail)</h6>";
echo "<table class='table table-bordered table-striped'>
        <thead class='table-secondary'>
            <tr>
                <th>รายละเอียด</th>
                <th>งบที่จ้าง</th>
                <th>ใช้จ่ายแล้ว</th>
                <th>คงเหลือ</th>
                <th>% ใช้จ่าย</th>
            </tr>
        </thead>
        <tbody>";

foreach ($details as $d) {
    $detailRequested = (float) $d['requested_amount'];
    $detailApproved  = (float) $d['approved_amount'];
    $detailRemain    = $detailRequested - $detailApproved;

    // ถ้า percentage ไม่มีค่า ให้คำนวณจากข้อมูลแถว
    $detailPercent = (isset($d['percentage']) && $d['percentage'] !== '' && $d['percentage'] !== null)
        ? (float) $d['percentage']
        : ($detailRequested > 0 ? ($detailApproved / $detailRequested) * 100 : 0);

    $link = "steps.php?id_detail=" . urlencode($d['id_detail']);

    echo "<tr>
            <td><a href='{$link}' class='text-decoration-none'>".htmlspecialchars($d['detail_name'])."</a></td>
            <td>".number_format($detailRequested, 2)."</td>
            <td>".number_format($detailApproved, 2)."</td>
            <td>".number_format($detailRemain, 2)."</td>
            <td>".number_format($detailPercent, 2)."%</td>
          </tr>";
}

// แถวรวม (อ้างจากผลรวมที่คำนวณไว้)
$bottomRemaining = $totalRequested - $totalApproved;
$bottomPercent   = $totalRequested > 0 ? ($totalApproved / $totalRequested) * 100 : 0;

echo "<tr class='fw-bold'>
        <td>รวมทั้งหมด</td>
        <td>".number_format($totalRequested, 2)."</td>
        <td>".number_format($totalApproved, 2)."</td>
        <td>".number_format($bottomRemaining, 2)."</td>
        <td>".number_format($bottomPercent, 2)."%</td>
      </tr>";

echo "</tbody></table>";
