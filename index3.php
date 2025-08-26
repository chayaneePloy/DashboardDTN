<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

// ดึงชื่อโครงการจาก budget_detail
$stmtDetail = $pdo->prepare("SELECT detail_name FROM budget_detail WHERE id_detail = :id_detail");
$stmtDetail->execute([':id_detail' => $id_detail]);
$project_detail = $stmtDetail->fetch(PDO::FETCH_ASSOC);
$detail_name = $project_detail ? $project_detail['detail_name'] : '-';

// ดึงข้อมูลขั้นตอนโครงการ พร้อม id_butget_detail
$steps_stmt = $pdo->prepare("
    SELECT id, step_order, step_name, step_date, step_description, sub_steps, document_path, is_completed, id_butget_detail
    FROM project_steps
    WHERE id_butget_detail = :id_detail
    ORDER BY step_order
");
$steps_stmt->execute([':id_detail' => $id_detail]);
$steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลความคืบหน้า
$progress_stmt = $pdo->prepare("
    SELECT ps.step_name, pp.progress_percent 
    FROM project_progress pp
    JOIN project_steps ps ON pp.step_id = ps.id
    WHERE ps.id_butget_detail = :id_detail
    ORDER BY ps.step_order
");
$progress_stmt->execute([':id_detail' => $id_detail]);

$progress_data = ['labels' => [], 'values' => []];
while ($row = $progress_stmt->fetch(PDO::FETCH_ASSOC)) {
    $progress_data['labels'][] = $row['step_name'];
    $progress_data['values'][] = $row['progress_percent'];
}

// ฟังก์ชันแปลงวันที่ไทย
function thai_date($date) {
    if (!$date) return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    return date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบติดตามขั้นตอนโครงการ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Kanit', sans-serif;
            background-color: #f8f9fa;
        }
        .process-timeline {
            position: relative;
            padding-left: 50px;
            margin: 30px 0;
        }
        .process-timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #dee2e6;
        }
        .process-step {
            position: relative;
            margin-bottom: 30px;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .process-step.completed {
            border-left: 4px solid #28a745;
        }
        .step-number {
            position: absolute;
            left: -40px;
            top: 0;
            width: 40px;
            height: 40px;
            background: #3498db;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .step-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .step-header h3 {
            margin: 0;
            flex-grow: 1;
            color: #2c3e50;
        }
        .step-date {
            background: #f1c40f;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.9em;
        }
        .sub-steps {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .progress-chart {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar {
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        footer {
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="#">ระบบติดตามขั้นตอนโครงการ</a>
    </div>
</nav>

<div class="container my-4">
    <h1 class="text-center mb-2">ขั้นตอนการดำเนินโครงการ</h1>
    <h3 class="text-center mb-4 text-secondary"><?= htmlspecialchars($detail_name) ?></h3>

    <!-- Timeline -->
    <div class="process-timeline">
        <?php foreach($steps as $step): ?>
        <div class="process-step <?= $step['is_completed'] ? 'completed' : '' ?>">
            <div class="step-number"><?= $step['step_order'] ?></div>
            <div class="step-header">
                <h3><?= $step['step_name'] ?></h3>
                <span class="step-date"><?= thai_date($step['step_date']) ?></span>
            </div>
            <div class="step-content">
                <p><?= $step['step_description'] ?></p>
                <?php if(!empty($step['sub_steps'])): ?>
                    <div class="sub-steps">
                        <h4>ขั้นตอนย่อย:</h4>
                        <p><?= nl2br($step['sub_steps']) ?></p>
                    </div>
                <?php endif; ?>
                <?php if(!empty($step['document_path'])): ?>
                    <div class="step-documents">
                        <a href="documents/<?= $step['document_path'] ?>" class="btn btn-sm btn-outline-primary">ดูเอกสาร</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Chart -->
    <div class="progress-chart mt-5">
        <h2 class="text-center mb-4">ความคืบหน้าโครงการ</h2>
        <canvas id="progressChart" height="100"></canvas>
    </div>
</div>

<footer class="bg-dark text-white text-center py-3 mt-5">
    <p>&copy; <?= date('Y')+543 ?> ระบบติดตามขั้นตอนโครงการ</p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('progressChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($progress_data['labels']) ?>,
            datasets: [{
                label: 'ความคืบหน้า (%)',
                data: <?= json_encode($progress_data['values']) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: { beginAtZero: true, max: 100, ticks: { callback: value => value + '%' } }
            }
        }
    });
});
</script>
</body>
</html>
<?php $pdo = null; ?>