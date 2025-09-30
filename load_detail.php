<?php
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", ""); 
$id = intval($_GET['id']);

// ---------------- ดึงข้อมูล budget_items ----------------
$stmtItem = $pdo->prepare("SELECT item_name, requested_amount, approved_amount, percentage 
                           FROM budget_items WHERE id = ?");
$stmtItem->execute([$id]);
$item = $stmtItem->fetch(PDO::FETCH_ASSOC);

if(!$item){
    echo "<p class='text-danger'>ไม่พบโครงการนี้</p>";
    exit;
}

$itemRemaining = $item['requested_amount'] - $item['approved_amount'];


// ดึงรายละเอียด
$stmt = $pdo->prepare("SELECT id_detail, detail_name, requested_amount, approved_amount, percentage 
                       FROM budget_detail 
                       WHERE budget_item_id = ?");
$stmt->execute([$id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(!$details){
    echo "<p>ไม่มีรายละเอียด</p>";
    exit;
} 
// ---------------- แสดงผล ----------------
echo "<h5>📌 ประเภทโครงการ: <span class='text-primary'>".htmlspecialchars($item['item_name'])."</span></h5>";

// ✅ แสดงข้อมูลรวมของโครงการ (ประเภท / งบประมาณ / ใช้จ่ายแล้ว / คงเหลือ / %)
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
                <td>".number_format($item['requested_amount'],2)."</td>
                <td>".number_format($item['approved_amount'],2)."</td>
                <td>".number_format($itemRemaining,2)."</td>
                <td>".number_format($item['percentage'],2)."%</td>
            </tr>
        </tbody>
      </table>";

// ✅ แสดงรายละเอียดแต่ละ detail
if(!$details){
    echo "<p>ไม่มีรายละเอียด</p>";
    exit;
}

// คำนวณรวมทั้งหมด
$totalRequested = 0;
$totalApproved = 0;

foreach($details as $d){
    $totalRequested += $d['requested_amount'];
    $totalApproved += $d['approved_amount'];
}

$remaining = $totalRequested - $totalApproved;
$percentUsed = $totalRequested > 0 ? ($totalApproved / $totalRequested) * 100 : 0;
// แสดงตาราง
echo "<h6>🔎 รายการย่อย (Detail)</h6>";
echo "<table class='table table-bordered table-striped'>
        <thead class='table-secondary'>
            <tr>
                <th>รายละเอียด</th>
                <th>งบประมาณ</th>
                <th>ใช้จ่ายแล้ว</th>
                <th>คงเหลือ</th>
                <th>% ใช้จ่าย</th>
            </tr>
        </thead>
        <tbody>";

foreach($details as $d){
    $link = "steps.php?id_detail=" . $d['id_detail'];
    echo "<tr>
        <td><a href='{$link}' class='text-decoration-none'>{$d['detail_name']}</a></td>
        <td>".number_format($d['requested_amount'],2)."</td>
        <td>".number_format($d['approved_amount'],2)."</td>
        <td>".number_format($d['requested_amount']-$d['approved_amount'],2)."</td>
        <td>{$d['percentage']}%</td>
        
    </tr>";

}
    // แถวรวม
echo "<tr class='fw-bold'>
        <td>รวมทั้งหมด</td>
        <td>".number_format($totalRequested,2)."</td>
        <td>".number_format($totalApproved,2)."</td>
        <td>".number_format($remaining,2)."</td>
        <td>".number_format($percentUsed,2)."%</td>
      </tr>";
echo "</tbody></table>";
