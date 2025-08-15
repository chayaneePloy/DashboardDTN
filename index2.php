<?php
include 'db.php';

// ดึงปีทั้งหมด
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_items ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$year = isset($_GET['year']) ? intval($_GET['year']) : (count($years) ? $years[0] : date('Y'));

// ดึงข้อมูล budget_items
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE fiscal_year = ?");
$stmt->execute([$year]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Dashboard งบประมาณ</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { font-family: Tahoma; margin: 20px; }
table { border-collapse: collapse; width: 100%; margin-top: 20px; }
th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
th { background: #eee; }
.controls { margin-bottom: 15px; }
button { padding: 8px 12px; margin: 5px; }
canvas { background: #fff; border: 1px solid #ccc; margin-top: 20px; border-radius: 10px; }
</style>
</head>
<body>
<h2>Dashboard งบประมาณ ปี <?php echo $year; ?></h2>

<div class="controls">
<form method="get" style="display:inline-block;">
    <label>เลือกปี:</label>
    <select name="year" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= ($y == $year) ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
    </select>
</form>
<a href="export_pdf.php?year=<?= $year ?>&type=full&mode=preview" target="_blank"><button>แสดงตัวอย่าง PDF</button></a>
<a href="export_pdf.php?year=<?= $year ?>&type=full&mode=download"><button>ดาวน์โหลด PDF</button></a>
<a href="export_excel.php?year=<?= $year ?>&type=items"><button>Excel (Items)</button></a>
<a href="export_excel.php?year=<?= $year ?>&type=detail"><button>Excel (Detail)</button></a>
<a href="export_excel.php?year=<?= $year ?>&type=full"><button>Excel (รวม)</button></a>
</div>

<h3>ตารางงบประมาณ</h3>
<table>
<tr><th>ชื่อโครงการ</th><th>งบที่ขอ</th><th>งบที่ได้</th><th>%</th></tr>
<?php foreach ($items as $item): ?>
<tr>
    <td><?= $item['item_name'] ?></td>
    <td><?= number_format($item['requested_amount'],2) ?></td>
    <td><?= number_format($item['approved_amount'],2) ?></td>
    <td><?= $item['percentage'] ?>%</td>
</tr>
<?php endforeach; ?>
</table>

<canvas id="barChart" width="400" height="200"></canvas>
<canvas id="pieChart" width="400" height="200"></canvas>

<script>
const labels = <?= json_encode(array_column($items, 'item_name')) ?>;
const requested = <?= json_encode(array_map('floatval', array_column($items, 'requested_amount'))) ?>;
const approved = <?= json_encode(array_map('floatval', array_column($items, 'approved_amount'))) ?>;
const percentage = <?= json_encode(array_map('floatval', array_column($items, 'percentage'))) ?>;

new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            { label: 'งบที่ขอ', data: requested, backgroundColor: 'rgba(54,162,235,0.6)', borderRadius: 10 },
            { label: 'งบที่ได้', data: approved, backgroundColor: 'rgba(75,192,192,0.6)', borderRadius: 10 }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top' } } }
});

new Chart(document.getElementById('pieChart'), {
    type: 'pie',
    data: {
        labels: labels,
        datasets: [{ data: percentage, backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#4BC0C0'] }]
    }
});
</script>
</body>
</html>
