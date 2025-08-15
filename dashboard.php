<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ดึงปีทั้งหมดใน DB สำหรับ Dropdown
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_items ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// ปีที่เลือก (ถ้าไม่มีให้ใช้ปีล่าสุด)
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : max($years);

// ดึงข้อมูล budget_items ของปีที่เลือก
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE fiscal_year = ? ORDER BY id ASC");
$stmt->execute([$selectedYear]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ข้อมูลสำหรับกราฟ
$itemNames = json_encode(array_column($items, 'item_name'));
$requested = json_encode(array_column($items, 'requested_amount'));
$approved = json_encode(array_column($items, 'approved_amount'));
$percentage = json_encode(array_column($items, 'percentage'));

// คำนวณสรุป
$totalRequested = array_sum(array_column($items, 'requested_amount'));
$totalApproved = array_sum(array_column($items, 'approved_amount'));
$avgPercent = count($items) ? round(array_sum(array_column($items, 'percentage')) / count($items), 2) : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Dashboard งบประมาณ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800&display=swap" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .card { border-radius: 15px; }
        .chart-container { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px; }
        .chart-box { background: #fff; padding: 15px; border-radius: 15px; flex: 1; }
        .modal-lg { max-width: 90% !important; }
        
        
    </style>
    
    
</head>
<body>
<div class="container my-4">
    <h2 class="text-center mb-4">📊 Dashboard งบประมาณ (ปี <?php echo $selectedYear; ?>)</h2>

    <!-- Filter ปี -->
   <form method="GET" class="mb-3 text-center">
    <label for="year">เลือกปี:</label>
    <select name="year" onchange="this.form.submit()" class="form-select w-auto d-inline-block">
        <?php foreach($years as $year): ?>
            <option value="<?php echo $year; ?>" <?php echo ($year == $selectedYear) ? 'selected' : ''; ?>>
                <?php echo $year; ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- ปุ่ม Export -->
    <!-- <div class="btn-group ms-2">
        <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown">Export Excel</button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="export_excel.php?year=<?php echo $selectedYear; ?>&type=items">เฉพาะ Items</a></li>
            <li><a class="dropdown-item" href="export_excel.php?year=<?php echo $selectedYear; ?>&type=detail">เฉพาะ Detail</a></li>
            <li><a class="dropdown-item" href="export_excel.php?year=<?php echo $selectedYear; ?>&type=full">รวม Items + Detail</a></li>
        </ul>
    </div> -->

   
</form>


    <!-- Summary Cards -->
    <div class="row text-center mb-4">
        <div class="col-md-4"><div class="card p-3 bg-primary text-white"><h4>รวมวงเงินที่ขอ</h4><h2><?php echo number_format($totalRequested, 2); ?> บาท</h2></div></div>
        <div class="col-md-4"><div class="card p-3 bg-success text-white"><h4>รวมวงเงินที่อนุมัติ</h4><h2><?php echo number_format($totalApproved, 2); ?> บาท</h2></div></div>
        <div class="col-md-4"><div class="card p-3 bg-warning text-white"><h4>ค่าเฉลี่ยร้อยละ</h4><h2><?php echo $avgPercent; ?>%</h2></div></div>
    </div>

    <!-- ตาราง budget_items -->
    <div class="card p-3 mb-4">
        <h4>📋 รายการงบประมาณ</h4>  <button class="btn btn-info btn-sm" onclick="window.location.href='index.php'">รายละเอียดโครงการ</button>
        <table class="table table-bordered table-striped mt-3">
            <thead class="table-dark"><tr><th>หมวด</th><th>ขอ</th><th>อนุมัติ</th><th>%</th><th>รายละเอียด</th></tr></thead>
            <tbody>
            <?php foreach($items as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                    <td><?php echo number_format($row['requested_amount'], 2); ?></td>
                    <td><?php echo number_format($row['approved_amount'], 2); ?></td>
                    <td><?php echo $row['percentage']; ?>%</td>
                    <td><button class="btn btn-info btn-sm" onclick="loadDetail(<?php echo $row['id']; ?>)">ดู</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- กราฟ -->
    <div class="chart-container">
        <div class="chart-box" style="flex: 2;">
            <h5 class="text-center">งบประมาณ (Bar + Line)</h5>
            <canvas id="budgetChart"></canvas>
        </div>
        <div class="chart-box" style="flex: 1;">
            <h5 class="text-center">สัดส่วน (%)</h5>
            <canvas id="pieChart"></canvas>
        </div>
    </div>
</div>

<!-- Modal สำหรับรายละเอียด -->
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
const labels = <?php echo $itemNames; ?>;
const requested = <?php echo $requested; ?>;
const approved = <?php echo $approved; ?>;
const percentage = <?php echo $percentage; ?>;

// ✅ Bar + Line Chart
new Chart(document.getElementById('budgetChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            { label: 'ขอ', data: requested, backgroundColor: '#42A5F5', borderRadius: 10 },
            { label: 'อนุมัติ', data: approved, backgroundColor: '#66BB6A', borderRadius: 10 },
            { label: '%', data: percentage, type: 'line', borderColor: '#FFA726', yAxisID: 'y1' }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'บาท' } },
            y1: { beginAtZero: true, position: 'right', title: { display: true, text: '%' } }
        }
    }
});

// ✅ Pie Chart
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: labels,
        datasets: [{ data: percentage, backgroundColor: ['#42A5F5','#66BB6A','#FFA726','#AB47BC','#26C6DA'] }]
    }
});

// ✅ โหลดรายละเอียดผ่าน AJAX
function loadDetail(itemId){
    fetch('load_detail.php?id='+itemId)
        .then(res => res.text())
        .then(html => {
            document.getElementById('detailContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('detailModal')).show();
        });
}
</script>
</body>
</html>