<?php
// ===================== CONNECT =====================
include 'db.php';

// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$method      = $_SERVER['REQUEST_METHOD'];
$return_url  = $_GET['return'] ?? ($_POST['return_url'] ?? '');
$selected_year   = $_GET['year']  ?? ($_POST['year']  ?? '');
$selected_item   = $_GET['item']  ?? ($_POST['item']  ?? '');
$selected_detail = $_POST['detail_id'] ?? ($_GET['detail_id'] ?? '');

$successMsg = $errorMsg = "";

// ===================== ดึงข้อมูลสำหรับ dropdown =====================
// ปีงบฯ (ที่มี budget_detail)
$years = $pdo->query("
    SELECT DISTINCT bi.fiscal_year 
    FROM budget_items bi
    INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
    ORDER BY bi.fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// งบประมาณตามปี
$items = [];
if ($selected_year !== '') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT bi.id, bi.item_name 
        FROM budget_items bi
        INNER JOIN budget_detail bd ON bi.id = bd.budget_item_id
        WHERE bi.fiscal_year = ?
        ORDER BY bi.item_name
    ");
    $stmt->execute([$selected_year]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// โครงการตามงบประมาณ (เอา requested_amount มาด้วย)
$details = [];
if ($selected_year !== '' && $selected_item !== '') {
    $stmt = $pdo->prepare("
        SELECT bd.id_detail, bd.detail_name, bd.requested_amount
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ? AND bd.budget_item_id = ?
        ORDER BY bd.detail_name
    ");
    $stmt->execute([$selected_year, $selected_item]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== บันทึก / แก้ไข / ลบ (POST) =====================
if ($method === 'POST') {

    $action = $_POST['action'] ?? 'create'; // create / update / delete

    if ($action === 'delete') {
        // ---------- ลบสัญญา ----------
        $contract_id = $_POST['contract_id'] ?? '';
        $detail_id   = $_POST['detail_id'] ?? '';

        if ($contract_id === '' || $detail_id === '') {
            $errorMsg = "ไม่สามารถลบได้: ข้อมูลอ้างอิงไม่ครบ";
        }

        if (!$errorMsg) {
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM contracts
                    WHERE contract_id = ? AND detail_item_id = ?
                ");
                $stmt->execute([$contract_id, $detail_id]);
                $successMsg = "ลบสัญญาเรียบร้อยแล้ว";
            } catch (Throwable $e) {
                $errorMsg = "ไม่สามารถลบได้: " . $e->getMessage();
            }
        }

    } elseif ($action === 'update') {
        // ---------- แก้ไขสัญญา ----------
        $year        = $_POST['year'] ?? '';
        $item        = $_POST['item'] ?? '';
        $detail_id   = $_POST['detail_id'] ?? '';
        $contract_id = $_POST['contract_id'] ?? '';
        $contract_no = trim($_POST['contract_number'] ?? '');
        $contractor  = trim($_POST['contractor_name'] ?? '');

        if ($contract_id === '' || $detail_id === '') {
            $errorMsg = "ไม่สามารถแก้ไขได้: ข้อมูลอ้างอิงไม่ครบ";
        } elseif ($contract_no === '') {
            $errorMsg = "กรุณากรอก เลขสัญญา";
        } elseif ($contractor === '') {
            $errorMsg = "กรุณากรอก ผู้รับจ้าง";
        }

        // ตรวจเลขสัญญาซ้ำ (ยกเว้น record ตัวเอง)
        if (!$errorMsg) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM contracts 
                WHERE detail_item_id = ? 
                  AND contract_number = ? 
                  AND contract_id <> ?
            ");
            $stmt->execute([$detail_id, $contract_no, $contract_id]);
            if ($stmt->fetchColumn() > 0) {
                $errorMsg = "พบเลขสัญญานี้ในโครงการที่เลือกอยู่แล้ว";
            }
        }

        if (!$errorMsg) {
            try {
                 $contract_date = thai_to_mysql_date($_POST['contract_date'] ?? '');
                 $contract_ends  = thai_to_mysql_date($_POST['contract_ends'] ?? '');


$stmt = $pdo->prepare("
    UPDATE contracts
    SET contract_number = ?, contractor_name = ?, contract_date = ?, contract_ends = ?
    WHERE contract_id = ?
");
$stmt->execute([
    $contract_no,
    $contractor,
    $contract_date,
    $contract_ends,
    $contract_id
]);

                $successMsg = "แก้ไขสัญญาเรียบร้อยแล้ว";
            } catch (Throwable $e) {
                $errorMsg = "ไม่สามารถแก้ไขได้: " . $e->getMessage();
            }
        }

    } else {
        // ---------- เพิ่มสัญญาใหม่ (create) ----------
        $year        = $_POST['year'] ?? '';
        $item        = $_POST['item'] ?? '';
        $detail_id   = $_POST['detail_id'] ?? '';
        $contract_no = trim($_POST['contract_number'] ?? '');
        $contractor  = trim($_POST['contractor_name'] ?? '');

        if ($year === '' || $item === '' || $detail_id === '') {
            $errorMsg = "กรุณาเลือก ปีงบประมาณ, งบประมาณ และ โครงการ ให้ครบ";
        } elseif ($contract_no === '') {
            $errorMsg = "กรุณากรอก เลขสัญญา";
        } elseif ($contractor === '') {
            $errorMsg = "กรุณากรอก ผู้รับจ้าง";
        }

        // ตรวจความสัมพันธ์ detail_id อยู่ใต้ปี/งบที่เลือกจริง
        if (!$errorMsg) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM budget_detail bd
                JOIN budget_items bi ON bd.budget_item_id = bi.id
                WHERE bd.id_detail = ? AND bi.fiscal_year = ? AND bi.id = ?
            ");
            $stmt->execute([$detail_id, $year, $item]);
            if ($stmt->fetchColumn() == 0) {
                $errorMsg = "โครงการที่เลือกไม่สัมพันธ์กับปีงบประมาณ/งบประมาณที่เลือก";
            }
        }

        // ป้องกันเลขสัญญาซ้ำภายใต้โครงการเดียวกัน
        if (!$errorMsg) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE detail_item_id = ? AND contract_number = ?");
            $stmt->execute([$detail_id, $contract_no]);
            if ($stmt->fetchColumn() > 0) {
                $errorMsg = "พบเลขสัญญานี้ในโครงการที่เลือกอยู่แล้ว";
            }
        }

        if (!$errorMsg) {
            try {
                $contract_date = thai_to_mysql_date($_POST['contract_date'] ?? '');
                $contract_ends = thai_to_mysql_date($_POST['contract_ends'] ?? '');


$stmt = $pdo->prepare("
    INSERT INTO contracts
    (detail_item_id, contract_number, contractor_name, contract_date, contract_ends)
    VALUES (?, ?, ?, ?, ?)
");
$stmt->execute([
    $detail_id,
    $contract_no,
    $contractor,
    $contract_date ?: null,
    $contract_ends ?: null
]);

                $successMsg = "บันทึกสัญญาเรียบร้อยแล้ว";

                // ถ้ามี return_url ให้ redirect กลับ
                if ($return_url) {
                    header("Location: " . $return_url);
                    exit;
                }

                // ให้ dropdown ยังอยู่ค่าเดิม
                $selected_detail = $detail_id;
                $_POST['contract_number'] = '';
                $_POST['contractor_name'] = '';

            } catch (Throwable $e) {
                $errorMsg = "ไม่สามารถบันทึกได้: " . $e->getMessage();
            }
        }
    }

    // refresh ค่า selected_* จาก POST เพื่อให้ dropdown อยู่ที่เดิม
    $selected_year   = $_POST['year']  ?? $selected_year;
    $selected_item   = $_POST['item']  ?? $selected_item;
    $selected_detail = $_POST['detail_id'] ?? $selected_detail;
}

// ===================== โหลด contracts ของโครงการที่เลือก =====================
$contracts = [];
if ($selected_detail) {
    $stmt = $pdo->prepare("
    SELECT c.contract_id, c.contract_number, c.contractor_name, c.contract_date, c.contract_ends
    FROM contracts c
    JOIN budget_detail bd ON c.detail_item_id = bd.id_detail
    JOIN budget_items bi ON bd.budget_item_id = bi.id
    WHERE c.detail_item_id = ?
      AND bi.fiscal_year = ?
");
$stmt->execute([$selected_detail, $selected_year]);

    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// 🔽 ใส่เพิ่มตรงนี้
function thai_date($date){
    if (!$date) return '';
    $t = strtotime($date);
    return date('d/m/', $t) . (date('Y', $t) + 543);
}
function thai_to_mysql_date($d){
    if (!$d) return null;

    // รองรับ dd/mm/yyyy (พ.ศ.)
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $d, $m)) {
        $day   = $m[1];
        $month = $m[2];
        $year  = (int)$m[3] - 543; // พ.ศ. → ค.ศ.
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    // ถ้าเป็น yyyy-mm-dd อยู่แล้ว
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) {
        return $d;
    }

    return null;
}


?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>เพิ่มสัญญา </title>
    <link rel="icon" type="image/png" href="assets/logoio.ico">
    <link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    .form-section-title {
        font-weight: 700;
        color: #0d6efd;
    }

    .number {
        text-align: right;
    }
    </style>
</head>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand fw-bold" href="index.php?year=<?= $selected_year ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>"">
      📊Dashboard งบประมาณโครงการ

    </a>

    <!-- Hamburger -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Menu -->
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

        <li class="nav-item">
          <a class="nav-link text-white" href="index.php?year=<?= h($selected_year) ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
            <i class="bi bi-house"></i> หน้าหลัก
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link text-white" href="dashboard_report.php?year=<?= h($selected_year) ?>&quarter=<?= $_GET['quarter'] ?? 1 ?>">
            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>

      </ul>
    </div>

  </div>
</nav>
<body class="bg-light">
    <div class="container my-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="form-section-title">📝 เพิ่มสัญญา </h3>
           <a class="btn btn-success"
                    href="create_phase.php?year=<?= urlencode($selected_year) ?>&item=<?= urlencode($selected_item) ?>&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>">
                    + เพิ่มงวดงาน
                </a>
        </div>

        <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= h($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= h($errorMsg) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                กรอกข้อมูลสัญญาใหม่
            </div>
            <div class="card-body">
                <form method="get" class="row g-3 mb-3">
  <div class="col-md-3">
    <label>ปีงบประมาณ</label>
    <select class="form-select" name="year" onchange="this.form.submit()">
      <option value="">-- เลือกปี --</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= h($y) ?>" <?= $y==$selected_year?'selected':'' ?>>
          <?= h($y) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-4">
    <label>งบประมาณ</label>
    <select class="form-select" name="item" onchange="this.form.submit()"
      <?= $selected_year===''?'disabled':'' ?>>
      <option value="">-- เลือกงบ --</option>
      
      <?php foreach ($items as $i): ?>
        <option value="<?= h($i['id']) ?>" <?= $i['id']==$selected_item?'selected':'' ?>>
          <?= h($i['item_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-5">
    <label>โครงการ</label>
    <select class="form-select" name="detail_id" onchange="this.form.submit()"
      <?= $selected_item===''?'disabled':'' ?>>
      <option value="">-- เลือกโครงการ --</option>
      <?php foreach ($details as $d): ?>
        <option value="<?= h($d['id_detail']) ?>"
          <?= $d['id_detail']==$selected_detail?'selected':'' ?>>
          <?= h($d['detail_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

            </div>
        </div>
<?php if ($selected_detail): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-primary text-white">
    เพิ่มสัญญาใหม่
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="year" value="<?= h($selected_year) ?>">
      <input type="hidden" name="item" value="<?= h($selected_item) ?>">
      <input type="hidden" name="detail_id" value="<?= h($selected_detail) ?>">

      <div class="col-md-4">
        <label class="form-label">เลขที่สัญญา</label>
        <input type="text" name="contract_number" class="form-control" required >
      </div>

      <div class="col-md-4">
        <label class="form-label">วันที่ลงนามสัญญา</label>
        <input name="contract_date" class="form-control" placeholder="วว/ดด/พศ">
      </div>

        <div class="col-md-4">
        <label class="form-label">วันที่สิ้นสุดสัญญา</label>
        <input name="contract_ends" class="form-control" placeholder="วว/ดด/พศ">
        
      </div>
      <div class="text-danger mb-2 text-end">
  <i class="bi bi-exclamation-triangle-fill me-1"></i>
  <strong>หมายเหตุ :</strong>
  โปรดระบุวันที่ในรูปแบบ ตัวเลข 2 หลัก (เช่น 05/01/2568)
</div>

      <div class="col-md-6">
        <label class="form-label">ผู้รับจ้าง</label>
        <input type="text" name="contractor_name" class="form-control" required>
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-success">
          💾 บันทึกสัญญา
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

        <!-- ตารางแสดงสัญญาที่มีอยู่ของโครงการที่เลือก -->
        <?php if ($selected_detail): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                สัญญาทั้งหมดของโครงการที่เลือก
            </div>
            <div class="card-body">
                <?php if ($contracts): ?>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:10%">#</th>
                                <th style="width:25%">เลขสัญญา</th>
                                <th>ผู้รับจ้าง</th>
                                <th>วันที่ลงนามสัญญา</th>
                                <th>วันที่สิ้นสุดสัญญา</th>
                                <th style="width:20%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contracts as $idx => $c): ?>
                            <tr>
                                <form method="post" class="row g-1 align-items-center">
                                    <input type="hidden" name="return_url" value="<?= h($return_url) ?>">
                                    <input type="hidden" name="year" value="<?= h($selected_year) ?>">
                                    <input type="hidden" name="item" value="<?= h($selected_item) ?>">
                                    <input type="hidden" name="detail_id" value="<?= h($selected_detail) ?>">
                                    <input type="hidden" name="contract_id" value="<?= h($c['contract_id']) ?>">

                                    <td class="align-middle"><?= $idx+1 ?></td>

                                    <td class="align-middle">
                                        <input type="text" name="contract_number"
                                            value="<?= h($c['contract_number']) ?>"
                                            class="form-control form-control-sm">
                                    </td>

                                    <td class="align-middle">
                                        <input type="text" name="contractor_name"
                                            value="<?= h($c['contractor_name']) ?>"
                                            class="form-control form-control-sm">
                                    </td>
                                    <td>
                                        <input type="text" name="contract_date"
                                            value="<?= h(thai_date($c['contract_date'])) ?>"
                                            class="form-control form-control-sm"
                                            placeholder="วว/ดด/พศ เช่น 15/01/2568">
                                    </td>
                                    <td>
                                        <input type="text" name="contract_ends"
                                        value="<?= h(thai_date($c['contract_ends'])) ?>"
                                     class="form-control form-control-sm">
                                    </td>

                                    <td class="align-middle">
                                        <div class="d-flex gap-1">
                                            <button type="submit" name="action" value="update"
                                                class="btn btn-sm btn-primary">
                                                บันทึก
                                            </button>
                                            <button type="submit" name="action" value="delete"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('ยืนยันการลบสัญญานี้?');">
                                                ลบ
                                            </button>
                                        </div>
                                    </td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="m-3 text-muted">ยังไม่มีสัญญาสำหรับโครงการนี้</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</body>

</html>