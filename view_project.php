<?php
include 'db.php'; // เชื่อมต่อฐานข้อมูล

$id_detail = intval($_GET['id_detail']);
$id_detail = intval($id_detail);

// ดึงชื่อโครงการ
$stmtProject = $pdo->prepare("SELECT item_name FROM budget_items WHERE id = ?");
$stmtProject->execute([$id_detail]);
$project = $stmtProject->fetch(PDO::FETCH_ASSOC);
$projectName = $project ? $project['item_name'] : '-';

// ข้อมูลโครงการ
$sql = "
    SELECT bd.*, bi.item_name
    FROM budget_detail bd
    LEFT JOIN budget_items bi ON bd.budget_item_id = bi.id
    WHERE bd.id_detail = :id_detail
";
$stmt = $pdo->prepare($sql); 
$stmt->execute([':id_detail' => $id_detail]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);


// งบประมาณ
$stmt = $pdo->prepare("SELECT * FROM budget_detail WHERE id_detail = :id_detail");
$stmt->execute([':id_detail' => $id_detail]);
$budgets = $stmt;

// สัญญา + งวดงาน
$sql_contracts = "
    SELECT c.*, p.phase_id, p.phase_number, p.phase_name, p.due_date, p.completion_date, p.amount, p.status
    FROM contracts c
    LEFT JOIN phases p ON c.contract_id = p.contract_detail_id
    LEFT JOIN budget_detail b ON c.detail_item_id = b.id_detail
    WHERE b.id_detail = :id_detail
";
$stmt = $pdo->prepare($sql_contracts);
$stmt->execute([':id_detail' => $id_detail]);
$contracts = $stmt;

// ปัญหา
$stmt = $pdo->prepare("SELECT * FROM issues WHERE id_detail = :id_detail");
$stmt->execute([':id_detail' => $id_detail]);
$issues = $stmt;

?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายละเอียดโครงการ</title>
<style>
    table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background-color: #f0f0f0; }
</style>
</head>
<body>

<h1>รายละเอียดโครงการ</h1>



<h2>ข้อมูลหลัก</h2>
<p><strong>ชื่อโครงการ:</strong> <?= htmlspecialchars($project['detail_name']) ?></p>
<p><strong>ประเภท:</strong> <?= htmlspecialchars($project['item_name']) ?></p>
<p><strong>ปีงบประมาณ:</strong> <?= htmlspecialchars($project['fiscal_year']) ?></p>
<p><strong>รายละเอียด:</strong> <?= nl2br(htmlspecialchars($project['description'])) ?></p>

<h2>งบประมาณ</h2>
<table>
<tr>
    <th>งบที่ได้รับ</th>
    <th>งบที่ทำสัญญา</th>
    <th>ปีงบ</th>
</tr>
    <?php while($row = $budgets->fetch(PDO::FETCH_ASSOC)): ?>
<tr>
    <td><?= number_format($row['requested_amount'], 2) ?></td>
    <td><?= number_format($row['approved_amount'], 2) ?></td>
    <td><?= htmlspecialchars($row['fiscal_year']) ?></td>
</tr>
<?php endwhile; ?>
</table>

<h2>สัญญาและงวดงาน</h2>
<table>
<tr>
    <th>เลขที่สัญญา</th>
    <th>ผู้รับจ้าง</th>
    <th>งวดงาน</th>
    <th>วันที่เริ่ม</th>
    <th>วันที่เสร็จสิน</th>
    <th>จำนวนเงิน</th>
    <th>สถานะ</th>
</tr>
<?php while($row = $contracts->fetch(PDO::FETCH_ASSOC)): ?>
<tr>
    <td><?= htmlspecialchars($row['contract_number']) ?></td>
    <td><?= htmlspecialchars($row['contractor_name']) ?></td>
    <td><?= htmlspecialchars($row['phase_name']) ?></td>
    <td><?= htmlspecialchars($row['due_date']) ?></td>
    <td><?= htmlspecialchars($row['completion_date']) ?></td>
    <td><?= number_format($row['amount'], 2) ?></td>
    <td><?= htmlspecialchars($row['status']) ?></td>
</tr>
<?php endwhile; ?>
</table>

<h2>ปัญหาและอุปสรรค</h2>
<table>
<tr>
    <th>วันที่พบปัญหา</th>
    <th>รายละเอียด</th>
    <th>ปัญหาอุปสรรค </th>
    <th>สถานะ</th>
</tr>
<?php while($row = $issues->fetch(PDO::FETCH_ASSOC)): ?>
<tr>
    <td><?= htmlspecialchars($row['issue_date']) ?></td>
    <td><?= htmlspecialchars($row['description']) ?></td> 
    <td><?= htmlspecialchars($row['solution']) ?></td> 
    <td><?= htmlspecialchars($row['status']) ?></td>
</tr>
<?php endwhile; ?>
</table>

<p><a href="dashboard.php">⬅ กลับหน้าหลัก</a></p>

</body>
</html>
