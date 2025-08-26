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
    SELECT id, step_name, step_order, id_butget_detail
    FROM project_steps
    WHERE id_butget_detail = :id_detail
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายละเอียดโครงการ: <?= htmlspecialchars($project['detail_name']) ?></title>
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
<body>

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

<div class="container">
    <!-- ปุ่มดูขั้นตอน Timeline -->
    <?php if(!empty($steps)): ?>
         
        <a href="index3.php?id_detail=<?= $steps[0]['id_butget_detail'] ?>" 
           class="btn btn-sm btn-primary mb-3">
           <h4><i class="bi bi-diagram-3"></i> ดูขั้นตอน (Timeline) </h4>
        </a>
      
    <?php else: ?>
        <span class="text-muted mb-3 d-block"> <h4>ยังไม่มีขั้นตอนโครงการ</h4> </span>
    <?php endif; ?>
    

    <!-- ข้อมูลหลักโครงการ -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="mb-0"><i class="bi bi-info-circle"></i> ข้อมูลหลักโครงการ</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong><i class="bi bi-tag"></i> ชื่อโครงการ:</strong> <?= htmlspecialchars($project['detail_name']) ?></p>
                    <p><strong><i class="bi bi-grid"></i> ประเภท:</strong> <?= htmlspecialchars($project['item_name']) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong><i class="bi bi-calendar"></i> ปีงบประมาณ:</strong> <?= htmlspecialchars($project['fiscal_year']) ?></p>
                    <p><strong><i class="bi bi-journal-text"></i> รายละเอียด:</strong></p>
                    <div class="bg-light p-3 rounded"><?= nl2br(htmlspecialchars($project['description'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ข้อมูลงบประมาณ -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h3 class="mb-0"><i class="bi bi-cash-stack"></i> ข้อมูลงบประมาณ</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>งบที่ได้รับ</th>
                            <th>งบที่ทำสัญญา</th>
                            <th>ปีงบประมาณ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $budgets->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td class="text-success fw-bold"><?= number_format($row['requested_amount'], 2) ?> บาท</td>
                            <td class="text-primary fw-bold"><?= number_format($row['approved_amount'], 2) ?> บาท</td>
                            <td><?= htmlspecialchars($row['fiscal_year']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- สัญญาและงวดงาน -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h3 class="mb-0"><i class="bi bi-file-earmark-text"></i> สัญญาและงวดงาน</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>เลขที่สัญญา</th>
                            <th>ผู้รับจ้าง</th>
                            <th>งวดงาน</th>
                            <th>วันที่เริ่ม</th>
                            <th>วันที่เสร็จสิ้น</th>
                            <th>จำนวนเงิน</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $contracts->fetch(PDO::FETCH_ASSOC)): 
                            $statusClass = ($row['status'] == 'เสร็จสิ้น') ? 'status-completed' : (($row['status'] == 'กำลังดำเนินการ') ? 'status-inprogress' : 'status-pending');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['contract_number']) ?></td>
                            <td><?= htmlspecialchars($row['contractor_name']) ?></td>
                            <td><?= htmlspecialchars($row['phase_name']) ?></td>
                            <td><?= htmlspecialchars($row['due_date']) ?></td>
                            <td><?= htmlspecialchars($row['completion_date']) ?></td>
                            <td><?= number_format($row['amount'], 2) ?> บาท</td>
                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ปัญหาและอุปสรรค -->
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h3 class="mb-0"><i class="bi bi-exclamation-triangle"></i> ปัญหาและอุปสรรค</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>วันที่พบปัญหา</th>
                            <th>รายละเอียด</th>
                            <th>การแก้ไข</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $issues->fetch(PDO::FETCH_ASSOC)):
                            $statusClass = ($row['status'] == 'แก้ไขแล้ว') ? 'status-completed' : (($row['status'] == 'กำลังแก้ไข') ? 'status-inprogress' : 'status-pending');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['issue_date']) ?></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                            <td><?= $row['solution'] ? htmlspecialchars($row['solution']) : '-' ?></td>
                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-5">
        <div class="btn-group" role="group">
            <!-- ปุ่มกลับหน้าหลัก -->
            <a href="index.php" class="btn btn-dark back-btn">
                <i class="bi bi-house"></i> หน้าหลัก
            </a>

            <!-- ปุ่มกลับหน้าก่อนหน้า -->
            <a href="javascript:history.back()" class="btn btn-dark back-btn">
                <i class="bi bi-arrow-left"></i> กลับหน้าก่อนหน้า
            </a>
            </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// เพิ่มเอฟเฟกต์เมื่อโหลดหน้าเว็บ
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>
</body>
</html>