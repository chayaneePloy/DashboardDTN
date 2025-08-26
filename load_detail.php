<?php
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", ""); 
$id = intval($_GET['id']);

// ดึงชื่อโครงการ
$stmtProject = $pdo->prepare("SELECT item_name FROM budget_items WHERE id = ?");
$stmtProject->execute([$id]);
$project = $stmtProject->fetch(PDO::FETCH_ASSOC);
$projectName = $project ? $project['item_name'] : '-';

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
echo "<h5>รายละเอียดงบประมาณโครงการ: <span class='text-primary'>{$projectName}</span></h5>";
echo "<table class='table table-bordered'>
        <thead>
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
    $link = "view_project.php?id_detail=" . $d['id_detail'];
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
