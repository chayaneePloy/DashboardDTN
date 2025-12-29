<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------- รับพารามิเตอร์ ----------------
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ---------------- ดึงข้อมูล budget_items (หัวข้อหลักประเภทโครงการ) ----------------
$stmtItem = $pdo->prepare("
    SELECT item_name, requested_amount
    FROM budget_items
    WHERE id = ?
");
$stmtItem->execute([$id]);
$item = $stmtItem->fetch(PDO::FETCH_ASSOC);

// ถ้าไม่พบโครงการ ให้ตั้ง flag ไว้ใช้ในหน้า HTML
$notFound = !$item;

// เตรียมตัวแปรเริ่มต้น
$details          = [];
$phaseSumByDetail = [];
$totalRequested   = 0.0;
$totalPhases      = 0.0;
$topRequested     = 0.0;
$topApproved      = 0.0;
$topRemaining     = 0.0;
$topPercent       = 0.0;
$bottomRemaining  = 0.0;
$bottomPercent    = 0.0;

// ถ้าพบโครงการ ค่อยไปดึงรายละเอียดและคำนวณ
if (!$notFound) {

    // ---------------- ดึงรายการย่อย (budget_detail) ----------------
    $stmt = $pdo->prepare("
        SELECT id_detail, detail_name, requested_amount
        FROM budget_detail
        WHERE budget_item_id = ?
        ORDER BY id_detail ASC
    ");
    $stmt->execute([$id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------------- ดึงยอดใช้จ่ายจาก phases (รวมตามโครงการย่อย) ----------------
    if ($details) {
        // ใช้ LEFT JOIN เพื่อให้รายละเอียดที่ยังไม่มี phase ก็ยังแสดง (ยอด = 0)
        $stmtPhase = $pdo->prepare("
            SELECT 
                bd.id_detail,
                COALESCE(SUM(p.amount), 0) AS phase_sum
            FROM budget_detail bd
            LEFT JOIN contracts c 
                ON c.detail_item_id = bd.id_detail
            LEFT JOIN phases p 
                ON p.contract_detail_id = c.contract_id
               AND p.status = 'เสร็จสิ้น'
            WHERE bd.budget_item_id = ?
            GROUP BY bd.id_detail
        ");
        $stmtPhase->execute([$id]);
        $rowsPhase = $stmtPhase->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rowsPhase as $r) {
            $phaseSumByDetail[(int)$r['id_detail']] = (float)$r['phase_sum'];
        }
    }

    // ---------------- คำนวณสรุปจากรายละเอียด + phases ----------------
    if ($details) {
        foreach ($details as $d) {
            $detailId   = (int)$d['id_detail'];
            $detailReq  = (float)$d['requested_amount'];
            $detailUsed = $phaseSumByDetail[$detailId] ?? 0.0;

            $totalRequested += $detailReq;
            $totalPhases    += $detailUsed;
        }
    }

    // ---------------- ค่าแสดง "สรุปด้านบน" ----------------
    $topRequested = $totalRequested;   // ใช้งบรวมจาก budget_detail
    $topApproved  = $totalPhases;      // ใช้ยอดจ่ายจาก phases รวม
    $topRemaining = $topRequested - $topApproved;
    $topPercent   = $topRequested > 0 ? ($topApproved / $topRequested) * 100 : 0.0;

    // ---------------- ค่าแถวรวมล่าง ----------------
    $bottomRemaining = $totalRequested - $totalPhases;
    $bottomPercent   = $totalRequested > 0 ? ($totalPhases / $totalRequested) * 100 : 0.0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สรุปโครงการ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- สำคัญสำหรับมือถือ -->

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">

    <!-- Icons (ถ้าอยากใช้) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f7f9fc;
        }
        .card {
            border-radius: 0.75rem;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        /* ทำให้หัวข้อกับตัวเลขไม่ติดขอบจอในมือถือ */
        .page-header {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: center;
            justify-content: space-between;
        }
    </style>
</head>
<body>

<div class="container my-3 my-md-4">

    <div class="page-header mb-3">
        <h4 class="mb-0">
            📊 สรุปโครงการ
        </h4>
        
    </div>

    <?php if ($notFound): ?>
        <div class="alert alert-danger">
            ไม่พบโครงการนี้
        </div>
    <?php else: ?>

        <!-- การ์ดสรุปด้านบน -->
        <div id="detailModal"  class="card mb-3 shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    📌 ประเภทโครงการ: 
                    <span class="text-primary">
                        <?= htmlspecialchars($item['item_name']) ?>
                    </span>
                </h5>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-dark">
                            <tr class="text-center">
                                <th>ประเภท</th>
                                <th>งบประมาณ</th>
                                <th>ใช้จ่ายแล้ว (จากงวดงาน)</th>
                                <th>คงเหลือ</th>
                                <th>% ใช้จ่าย</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?= htmlspecialchars($item['item_name']) ?></td>
                                <td class="text-end"><?= number_format($topRequested, 2) ?></td>
                                <td class="text-end"><?= number_format($topApproved, 2) ?></td>
                                <td class="text-end"><?= number_format($topRemaining, 2) ?></td>
                                <td class="text-end"><?= number_format($topPercent, 2) ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if (!$details): ?>

            <div class="alert alert-warning">
                ยังไม่มีรายละเอียดในโครงการนี้
            </div>

        <?php else: ?>

            <!-- การ์ดตารางรายละเอียด -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="mb-3">🔎 รายการย่อย (Detail)</h6>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-sm mb-0">
                            <thead class="table-secondary">
                                <tr class="text-center">
                                    <th>รายละเอียด</th>
                                    <th>งบที่จ้าง</th>
                                    <th>ใช้จ่ายแล้ว (จากงวดงาน)</th>
                                    <th>คงเหลือ</th>
                                    <th>% ใช้จ่าย</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($details as $d): ?>
                                    <?php
                                        $detailId        = (int)$d['id_detail'];
                                        $detailName      = (string)$d['detail_name'];
                                        $detailRequested = (float)$d['requested_amount'];

                                        // ยอดใช้จ่ายจาก phases ของโครงการย่อยนี้
                                        $detailUsed    = $phaseSumByDetail[$detailId] ?? 0.0;
                                        $detailRemain  = $detailRequested - $detailUsed;
                                        $detailPercent = $detailRequested > 0 ? ($detailUsed / $detailRequested) * 100 : 0.0;

                                        $link = "steps.php?id_detail=" . urlencode($detailId);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="<?= $link ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($detailName) ?>
                                            </a>
                                        </td>
                                        <td class="text-end"><?= number_format($detailRequested, 2) ?></td>
                                        <td class="text-end"><?= number_format($detailUsed, 2) ?></td>
                                        <td class="text-end"><?= number_format($detailRemain, 2) ?></td>
                                        <td class="text-end"><?= number_format($detailPercent, 2) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- แถวรวมทั้งหมด -->
                                <tr class="fw-bold table-info">
                                    <td>รวมทั้งหมด</td>
                                    <td class="text-end"><?= number_format($totalRequested, 2) ?></td>
                                    <td class="text-end"><?= number_format($totalPhases, 2) ?></td>
                                    <td class="text-end"><?= number_format($bottomRemaining, 2) ?></td>
                                    <td class="text-end"><?= number_format($bottomPercent, 2) ?>%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
