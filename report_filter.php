<?php
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------- รับค่าฟิลเตอร์ ----------------
$selected_year   = $_GET['year'] ?? '';
$selected_item   = $_GET['item'] ?? '';
$filter_type     = $_GET['filter_type'] ?? ''; // day / month / range
$filter_date     = $_GET['filter_date'] ?? '';
$filter_month    = $_GET['filter_month'] ?? '';
$filter_start    = $_GET['filter_start'] ?? '';
$filter_end      = $_GET['filter_end'] ?? '';

// ---------------- ดึงปีงบประมาณ ----------------
$years = $pdo->query("
    SELECT DISTINCT bi.fiscal_year 
    FROM budget_items bi
    INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
    ORDER BY bi.fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ---------------- ดึงงบประมาณ ----------------
$items = $pdo->query("
    SELECT DISTINCT bi.id, bi.item_name 
    FROM budget_items bi
    INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
    ORDER BY bi.item_name
")->fetchAll(PDO::FETCH_ASSOC);

// ---------------- Query หลัก ----------------
$query = "
    SELECT bi.item_name, bi.fiscal_year, 
           bd.detail_name, 
           c.contract_number, c.contractor_name, c.total_amount,
           p.phase_number, p.phase_name, p.amount, p.payment_date, p.status
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    JOIN budget_detail bd ON c.detail_item_id = bd.id_detail
    JOIN budget_items bi ON bd.budget_item_id = bi.id
    WHERE 1=1
";
$params = [];

if ($selected_year) {
    $query .= " AND bi.fiscal_year = ? ";
    $params[] = $selected_year;
}
if ($selected_item) {
    $query .= " AND bi.id = ? ";
    $params[] = $selected_item;
}

$query .= " ORDER BY p.payment_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------- สรุป ----------------
$total_amount = array_sum(array_column($rows, 'total_amount'));
$paid         = array_sum(array_column($rows, 'amount'));
$remain       = $total_amount - $paid;
$installments = count($rows);
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Dashboard รายงานการจ่ายเงินโครงการ</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    body { font-family: Tahoma, sans-serif; margin: 20px; background: #f4f6f8; }
    h2 { color: #333; }
    form { margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 10px; }
    select, input, button { padding: 6px; margin: 5px; }
    .cards { display: flex; gap: 20px; margin-bottom: 20px; }
    .card { flex: 1; background: #fff; padding: 15px; border-radius: 10px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
    .card h3 { margin: 0; color: #666; }
    .card p { font-size: 18px; font-weight: bold; margin: 5px 0 0; }
    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background: #f0f0f0; }
    tr:nth-child(even) { background: #fafafa; }
</style>
<script>
function toggleFilter() {
    let type = document.querySelector("select[name=filter_type]").value;
    document.getElementById("filter_day").style.display   = (type === "day") ? "inline" : "none";
    document.getElementById("filter_month").style.display = (type === "month") ? "inline" : "none";
    document.getElementById("filter_range").style.display = (type === "range") ? "inline" : "none";
}
</script>
</head>
<body>
    <h2>📊 Dashboard รายงานการจ่ายเงินโครงการ</h2>

    <!-- ฟอร์มค้นหา -->
    <form method="get">
        <label>ปีงบประมาณ:
            <select name="year">
                <option value="">-- เลือกปี --</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>งบประมาณ:
            <select name="item">
                <option value="">-- เลือกงบประมาณ --</option>
                <?php foreach ($items as $i): ?>
                    <option value="<?= $i['id'] ?>" <?= $i['id'] == $selected_item ? 'selected' : '' ?>>
                        <?= htmlspecialchars($i['item_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>กรองตาม:
            <select name="filter_type" onchange="toggleFilter()">
                <option value="">-- ไม่เลือก --</option>
                <option value="day"   <?= $filter_type==='day'?'selected':'' ?>>วันที่</option>
                <option value="month" <?= $filter_type==='month'?'selected':'' ?>>เดือน</option>
                <option value="range" <?= $filter_type==='range'?'selected':'' ?>>ช่วงวันที่</option>
            </select>
        </label>
        <input type="date" name="filter_date" id="filter_day" style="display:<?= $filter_type==='day'?'inline':'none' ?>" value="<?= $filter_date ?>">
        <input type="month" name="filter_month" id="filter_month" style="display:<?= $filter_type==='month'?'inline':'none' ?>" value="<?= $filter_month ?>">
        <span id="filter_range" style="display:<?= $filter_type==='range'?'inline':'none' ?>">
            <input type="date" name="filter_start" value="<?= $filter_start ?>"> -
            <input type="date" name="filter_end" value="<?= $filter_end ?>">
        </span>
        <button type="submit">ค้นหา</button>
    </form>

    <!-- การ์ดสรุป -->
    <div class="cards">
        <div class="card"><h3>วงเงินสัญญารวม</h3><p><?= number_format($total_amount, 2) ?> บาท</p></div>
        <div class="card"><h3>ชำระแล้ว</h3><p><?= number_format($paid, 2) ?> บาท</p></div>
        <div class="card"><h3>คงเหลือ</h3><p><?= number_format($remain, 2) ?> บาท</p></div>
        <div class="card"><h3>จำนวนงวดที่จ่าย</h3><p><?= $installments ?> งวด</p></div>
    </div>

    <!-- ตารางรายละเอียด -->
    <?php if ($rows): ?>
        <table>
            <thead>
                <tr>
                    <th>งบประมาณ</th>
                    <th>โครงการ</th>
                    <th>เลขที่สัญญา</th>
                    <th>ผู้รับจ้าง</th>
                    <th>งวดที่</th>
                    <th>ชื่อ</th>
                    <th>จำนวนเงิน (บาท)</th>
                    <th>วันที่จ่าย</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['item_name']) ?></td>
                        <td><?= htmlspecialchars($r['detail_name']) ?></td>
                        <td><?= htmlspecialchars($r['contract_number']) ?></td>
                        <td><?= htmlspecialchars($r['contractor_name']) ?></td>
                        <td><?= $r['phase_number'] ?></td>
                        <td><?= htmlspecialchars($r['phase_name']) ?></td>
                        <td><?= number_format($r['amount'], 2) ?></td>
                        <td><?= $r['payment_date'] ? date("d/m/Y", strtotime($r['payment_date'])) : '-' ?></td>
                        <td><?= $r['status'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: gray;">❌ ไม่พบข้อมูล</p>
    <?php endif; ?>

    <!-- กราฟ (การจ่ายรายเดือน) -->
    <canvas id="paymentChart" height="100"></canvas>
    <script>
    const ctx = document.getElementById('paymentChart');
    const data = {
        labels: <?= json_encode(array_map(fn($r) => date("m/Y", strtotime($r['payment_date'])), $rows)) ?>,
        datasets: [{
            label: 'จำนวนเงินที่จ่าย',
            data: <?= json_encode(array_map(fn($r) => (float)$r['amount'], $rows)) ?>,
            borderColor: 'blue',
            backgroundColor: 'rgba(54, 162, 235, 0.5)',
            fill: true,
            tension: 0.3
        }]
    };
    new Chart(ctx, { type: 'line', data });
    </script>
</body>
</html>
