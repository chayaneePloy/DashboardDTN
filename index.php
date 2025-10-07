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
$remaining = json_encode(array_map(function($row){
    return $row['requested_amount'] - $row['approved_amount'];
}, $items));

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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
      <div class="container-fluid">
        <a class="navbar-brand fs-3"  href="#" >Dashboard</a>
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
              <li class="nav-item">
                <a class="nav-link active fs-5 text-white " href="dashboard_report.php">การจ่ายงวด (Phases)</a>
            </li>

        <!-- ถ้าต้องการเพิ่มเมนูอื่น ก็เพิ่ม <li> ได้ -->
      </ul>
        <div class="d-flex">
          <a href="dashboard.php" class="btn btn-success btn-lg">เพิ่มงบประมาณ</a>
        </div>
      </div>
    </nav>

<div class="container my-4">
    <h2 class="text-center mb-4">📊 Dashboard งบประมาณโครงการ IT (ปี <?php echo $selectedYear; ?>)</h2>

    <!-- Filter ปี -->
   <form method="GET" class="mb-3 text-center">
    <label for="year">ปีงบประมาณ:</label>
    <select name="year" onchange="this.form.submit()" class="form-select w-auto d-inline-block">
        <?php foreach($years as $year): ?>
            <option value="<?php echo $year; ?>" <?php echo ($year == $selectedYear) ? 'selected' : ''; ?>>
                <?php echo $year; ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<div class="row text-center mb-4">
     <div class="col-md-12"><div class="card p-3 bg-blue-800 text-white"><h4>งบตาม พรบ.</h4><h2><?php echo number_format($totalRequested); ?> บาท</h2></div></div>
        </div>

    <!-- Summary Cards -->
    <div class="row text-center mb-4">
        <div class="col-md-3"><div class="card p-3 bg-blue-600 text-white"><h4>งบตามโครงการ</h4><h2><?php echo number_format($totalRequested); ?> บาท</h2></div></div>
        <div class="col-md-3"><div class="card p-3 bg-blue-500 text-white"><h4>ใช้จ่ายตามโครงการ</h4><h2><?php echo number_format($totalApproved); ?> บาท</h2></div></div>
        <div class="col-md-3"><div class="card p-3 bg-blue-400 text-white"><h4>คงเหลือ</h4><h2><?php echo number_format($totalRequested - $totalApproved); ?> บาท</h2></div></div>
        <div class="col-md-3"><div class="card p-3 bg-blue-300 text-white"><h4>% ใช้จ่ายจริง</h4><h2>
            <?php 
                $percentUsed = $totalRequested > 0 ? ($totalApproved / $totalRequested) * 100 : 0;
                echo number_format($percentUsed, 2);
            ?>%
        </h2></div></div>
    </div>

    <!-- ตาราง budget_items -->
    <div class="card p-3 mb-4">
        <h4>📋 รายการงบประมาณ</h4>  
        <table class="table table-bordered table-striped mt-3">
            <thead class="table-dark"><tr><th>ประเภท</th><th>งบประมาณ</th><th>ใช้จ่ายแล้ว</th><th>คงเหลือ</th><th>% ใช้จ่าย</th><th>รายละเอียด</th></tr></thead>
            <tbody>
            <?php foreach($items as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                    <td><?php echo number_format($row['requested_amount'], 2); ?></td>
                    <td><?php echo number_format($row['approved_amount'], 2); ?></td>
                    <td><?php echo number_format($row['requested_amount'] - $row['approved_amount'], 2); ?> </td>
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
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">งบประมาณเปรียบเทียบกับการใช้จ่ายจริง</h5>
                <select id="chartType" class="form-select w-auto">
                    <option value="all">รวมทั้งหมด</option>
                    <option value="requested">งบประมาณ</option>
                    <option value="approved">ใช้จ่ายแล้ว</option>
                    <option value="remaining">คงเหลือ</option>
                    
                </select>
            </div>
            <canvas id="budgetChart"></canvas>
        </div>
        <div class="chart-box" style="flex: 1;">
            <h5 class="text-center">สัดส่วนงบประมาณตามประเภท (%)</h5>
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
const remaining = <?php echo $remaining; ?>;

// ✅ สร้างกราฟเริ่มต้น
const ctx = document.getElementById('budgetChart');
let budgetChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            { label: 'งบประมาณ', data: requested, backgroundColor: '#42A5F5', borderRadius: 10 },
            { label: 'ใช้จ่ายแล้ว', data: approved, backgroundColor: '#66BB6A', borderRadius: 10 },
            { label: 'คงเหลือ', data: remaining, backgroundColor: '#FFA726', borderRadius: 10 }
        ]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true, title: { display: true, text: 'บาท' } } }
    }
});

// ✅ เปลี่ยน dataset เมื่อเลือก dropdown
document.getElementById('chartType').addEventListener('change', function(){
    const type = this.value;
    let dataset = [];

    if(type === 'requested'){
        dataset = [{ label: 'งบประมาณ', data: requested, backgroundColor: '#42A5F5', borderRadius: 10 }];
    } 
    else if(type === 'approved'){
        dataset = [{ label: 'ใช้จ่ายแล้ว', data: approved, backgroundColor: '#66BB6A', borderRadius: 10 }];
    } 
    else if(type === 'remaining'){
        dataset = [{ label: 'คงเหลือ', data: remaining, backgroundColor: '#FFA726', borderRadius: 10 }];
    }
    else if(type === 'all'){
        dataset = [
            { label: 'งบประมาณ', data: requested, backgroundColor: '#42A5F5', borderRadius: 10 },
            { label: 'ใช้จ่ายแล้ว', data: approved, backgroundColor: '#66BB6A', borderRadius: 10 },
            { label: 'คงเหลือ', data: remaining, backgroundColor: '#FFA726', borderRadius: 10 }
        ];
    }

    budgetChart.data.datasets = dataset;
    budgetChart.update();
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
