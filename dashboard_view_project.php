<?php
include 'db.php'; // เชื่อมต่อฐานข้อมูล

$id_detail = intval($_GET['id_detail']);

// ดึงชื่อโครงการ
$stmtProject = $pdo->prepare("SELECT item_name FROM budget_items WHERE id = ?");
$stmtProject->execute([$id_detail]);
$projectName = $stmtProject->fetchColumn() ?: '-';

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

// ดึงขั้นตอน project_steps ของโครงการนี้
$steps_stmt = $pdo->prepare("
    SELECT id, step_name, step_order, id_budget_detail
    FROM project_steps
    WHERE id_budget_detail = :id_detail
    ORDER BY step_order
");
$steps_stmt->execute([':id_detail' => $id_detail]);
$steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
    :root {
        --primary-color: #3498db;
        --secondary-color: #2c3e50;
        --success-color: #2ecc71;
        --warning-color: #f39c12;
        --danger-color: #e74c3c;
    }
    
    body {
        font-family: 'Kanit', sans-serif;
        background-color: #f8f9fa;
        color: #333;
    }
    
    .project-header {
        background-color: var(--secondary-color);
        color: white;
        padding: 2rem 0;
        margin-bottom: 2rem;
        border-radius: 0 0 10px 10px;
    }
    
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        transition: transform 0.3s ease;
    }
    
    .card:hover {
        transform: translateY(-5px);
    }
    
    .card-header {
        background-color: var(--primary-color);
        color: white;
        border-radius: 10px 10px 0 0 !important;
        font-weight: 500;
    }
    
    .table {
        margin-bottom: 0;
    }
    
    .table th {
        background-color: #f8f9fa;
        font-weight: 500;
    }
    
    .status-badge {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .status-completed {
        background-color: #d4edda;
        color: #155724;
    }
    
    .status-inprogress {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .status-pending {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .back-btn {
        transition: all 0.3s ease;
    }
    
    .back-btn:hover {
        transform: translateX(-5px);
    }
    
    @media (max-width: 768px) {
        .table-responsive {
            overflow-x: auto;
        }
    }
</style>
</head>
<!-- ส่วนหัวเว็บ -->
<div class="project-header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-clipboard2-data"></i> รายละเอียดโครงการ</h1>
                <h2 class="h4"><?= htmlspecialchars($project['detail_name']) ?></h2>
            </div>

                <div class="btn-group" role="group">
            <!-- ปุ่มกลับหน้าหลัก -->
            <a href="index.php" class="btn btn-light back-btn">
                <i class="bi bi-house"></i> หน้าหลัก
            </a>

            <!-- ปุ่มกลับหน้าก่อนหน้า -->
            <a href="javascript:history.back()" class="btn btn-light back-btn">
                <i class="bi bi-arrow-left"></i> กลับหน้าก่อนหน้า
            </a>
            </div>

        </div>
    </div>
</div>
<body class="bg-light">

<div class="container my-4">
       <h2>ประเภท: <?= htmlspecialchars($project['item_name']) ?> | ปีงบ: <?= htmlspecialchars($project['fiscal_year']) ?></h2>

    <!-- ขั้นตอนโครงการ -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <span><i class="bi bi-diagram-3"></i> ขั้นตอนโครงการ</span>
            <a href="add_step.php?id_detail=<?= $id_detail ?>" class="btn btn-success btn-sm">➕ เพิ่ม</a>
        </div>
        <div class="card-body">
            <?php if($steps): ?>
            <ul class="list-group">
                <?php foreach($steps as $s): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($s['step_order']) ?>. <?= htmlspecialchars($s['step_name']) ?>
                    <span>
                        <a href="edit_step.php?id=<?= $s['id'] ?>" class="btn btn-warning btn-sm">แก้ไข</a>
                        <a href="delete_step.php?id=<?= $s['id'] ?>&id_detail=<?= $id_detail ?>" 
                           class="btn btn-danger btn-sm" 
                           onclick="return confirm('คุณต้องการลบขั้นตอนนี้ใช่ไหม?')">ลบ</a>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="text-muted">ยังไม่มีขั้นตอน</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- สัญญาและงวดงาน -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <span><i class="bi bi-file-earmark-text"></i> สัญญาและงวดงาน</span>
            <a href="add_contract.php?id_detail=<?= $id_detail ?>" class="btn btn-success btn-sm">➕ เพิ่ม</a>
        </div>
        <div class="card-body">
            <?php if($contracts): ?>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>เลขที่สัญญา</th><th>ผู้รับจ้าง</th><th>งวดงาน</th>
                        <th>วันที่เริ่ม</th><th>วันที่เสร็จสิ้น</th><th>จำนวนเงิน</th><th>สถานะ</th><th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($contracts as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['contract_number']) ?></td>
                        <td><?= htmlspecialchars($c['contractor_name']) ?></td>
                        <td><?= htmlspecialchars($c['phase_name']) ?></td>
                        <td><?= $c['due_date'] ?></td>
                        <td><?= $c['completion_date'] ?></td>
                        <td><?= number_format($c['amount'],2) ?> บาท</td>
                        <td><?= htmlspecialchars($c['status']) ?></td>
                        <td>
                            <a href="edit_contract.php?id=<?= $c['contract_id'] ?>" class="btn btn-warning btn-sm">แก้ไข</a>
                            <a href="delete_contract.php?id=<?= $c['contract_id'] ?>&id_detail=<?= $id_detail ?>" 
                               class="btn btn-danger btn-sm" 
                               onclick="return confirm('คุณต้องการลบสัญญานี้ใช่ไหม?')">ลบ</a>
                        </td>
                    </tr>
                    <?php endforeach;?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-muted">ยังไม่มีสัญญา</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ปัญหา -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <span><i class="bi bi-exclamation-triangle"></i> ปัญหา</span>
            <a href="add_issue.php?id_detail=<?= $id_detail ?>" class="btn btn-success btn-sm">➕ เพิ่ม</a>
        </div>
        <div class="card-body">
            <?php if($issues): ?>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>วันที่</th><th>รายละเอียด</th><th>การแก้ไข</th><th>สถานะ</th><th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($issues as $i): ?>
                    <tr>
                        <td><?= $i['issue_date'] ?></td>
                        <td><?= htmlspecialchars($i['description']) ?></td>
                        <td><?= htmlspecialchars($i['solution']) ?></td>
                        <td><?= htmlspecialchars($i['status']) ?></td>
                        <td>
                            <a href="edit_issue.php?id=<?= $i['id'] ?>" class="btn btn-warning btn-sm">แก้ไข</a>
                            <a href="delete_issue.php?id=<?= $i['id'] ?>&id_detail=<?= $id_detail ?>" 
                               class="btn btn-danger btn-sm" 
                               onclick="return confirm('คุณต้องการลบปัญหานี้ใช่ไหม?')">ลบ</a>
                        </td>
                    </tr>
                    <?php endforeach;?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-muted">ยังไม่มีปัญหา</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
