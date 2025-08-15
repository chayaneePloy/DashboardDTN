<?php
include 'db.php'; // เชื่อมต่อฐานข้อมูล

// ดึงรายการโครงการทั้งหมด
$projects = $pdo->query("SELECT * FROM projects ORDER BY fiscal_year DESC, project_id DESC");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายการโครงการ</title>
<style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background-color: #f0f0f0; }
    a.button { padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
    a.button:hover { background: #0056b3; }
</style>
</head>
<body>

<h1>รายการโครงการ</h1>
<table>
    <tr>
        <th>ชื่อโครงการ</th>
        <th>ประเภท</th>
        <th>ปีงบประมาณ</th>
        <th>ดูรายละเอียด</th>
    </tr>
    <?php while($row = $projects->fetch(PDO::FETCH_ASSOC)): ?>
        
        
    <tr>
        <td><?= htmlspecialchars($row['project_name']) ?></td>
        <td><?= htmlspecialchars($row['project_type']) ?></td>
        <td><?= htmlspecialchars($row['fiscal_year']) ?></td>
        <td><a class="button" href="view_project.php?id=<?= $row['project_id'] ?>">ดู</a></td>
    </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
