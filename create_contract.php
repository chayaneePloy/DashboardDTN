<?php
// ===================== CONNECT =====================
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$method = $_SERVER['REQUEST_METHOD'];
$return_url = $_GET['return'] ?? ($_POST['return_url'] ?? '');

// รับค่าที่ใช้ควบคุม dropdowns
$selected_year   = $_GET['year']  ?? ($_POST['year']  ?? '');
$selected_item   = $_GET['item']  ?? ($_POST['item']  ?? '');
$selected_detail = $_POST['detail_id'] ?? ''; // budget_detail.id_detail

$successMsg = $errorMsg = "";

// ===================== ดึงข้อมูลสำหรับ dropdown =====================
// ปีงบฯ
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

// โครงการตามงบประมาณ (ดึง requested_amount เท่านั้น)
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

// ===================== บันทึก (POST) =====================
if ($method === 'POST') {
    // ค่าจากฟอร์ม
    $year        = $_POST['year'] ?? '';
    $item        = $_POST['item'] ?? '';
    $detail_id   = $_POST['detail_id'] ?? '';
    $contract_no = trim($_POST['contract_number'] ?? '');
    $contractor  = trim($_POST['contractor_name'] ?? '');

    // ตรวจค่าพื้นฐาน
    if ($year === '' || $item === '' || $detail_id === '') {
        $errorMsg = "กรุณาเลือก ปีงบประมาณ, งบประมาณ และ โครงการ ให้ครบ";
    } elseif ($contract_no === '') {
        $errorMsg = "กรุณากรอก เลขสัญญา";
    } elseif ($contractor === '') {
        $errorMsg = "กรุณากรอก ชื่อบริษัท";
    }

    // ตรวจความสัมพันธ์: detail_id ต้องอยู่ใต้ปี/งบที่เลือกจริง
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

    // Insert
    if (!$errorMsg) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO contracts (detail_item_id, contract_number, contractor_name)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$detail_id, $contract_no, $contractor]);
            $successMsg = "บันทึกสัญญาเรียบร้อยแล้ว";

            // กลับไปหน้าที่มา ถ้ามี return
            if ($return_url) {
                header("Location: " . $return_url);
                exit;
            }

            // ล้างค่า input หลังบันทึกสำเร็จ (กรณีไม่มี redirect)
            $selected_detail = '';
            $_POST = [];
        } catch (Throwable $e) {
            $errorMsg = "ไม่สามารถบันทึกได้: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>เพิ่มสัญญา (Contract)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .form-section-title { font-weight: 700; color:#0d6efd; }
    .number { text-align: right; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="form-section-title">📝 เพิ่มสัญญา (Contract)</h3>
    <div>
      <?php if ($return_url): ?>
        <a href="<?= h($return_url) ?>" class="btn btn-outline-secondary">กลับ</a>
      <?php else: ?>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">กลับ</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($successMsg): ?>
    <div class="alert alert-success"><?= h($successMsg) ?></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?= h($errorMsg) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
      กรอกข้อมูลสัญญาใหม่
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="return_url" value="<?= h($return_url) ?>">

        <!-- เลือกลำดับ: ปีงบฯ -> งบประมาณ -> โครงการ -->
        <div class="col-md-3">
          <label class="form-label">ปีงบประมาณ</label>
          <select class="form-select" name="year" onchange="this.form.submit()">
            <option value="">-- เลือกปี --</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= h($y) ?>" <?= ($y==$selected_year)?'selected':'' ?>><?= h($y) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">งบประมาณ</label>
          <select class="form-select" name="item" onchange="this.form.submit()">
            <option value="">-- เลือกงบประมาณ --</option>
            <?php foreach ($items as $i): ?>
              <option value="<?= h($i['id']) ?>" <?= ($i['id']==$selected_item)?'selected':'' ?>>
                <?= h($i['item_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-5">
          <label class="form-label">โครงการ</label>
          <select class="form-select" name="detail_id" onchange="this.form.submit()" <?= ($selected_item==='')?'disabled':'' ?>>
            <option value="">-- เลือกโครงการ --</option>
            <?php foreach ($details as $d): ?>
              <?php
                // เก็บ requested_amount เผื่ออยากใช้ใน client-side อื่น ๆ ต่อไป (ไม่แสดงผล)
                $requestedRaw = is_null($d['requested_amount'])
                                ? ''
                                : number_format((float)$d['requested_amount'], 2, '.', '');
              ?>
              <option 
                value="<?= h($d['id_detail']) ?>" 
                <?= ($d['id_detail']==$selected_detail)?'selected':'' ?>
                data-requested="<?= h($requestedRaw) ?>"
              >
                <?= h($d['detail_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- ไม่มีช่องแสดงงบอนุมัติ/งบที่ขอ ตามที่ร้องขอ -->

        <!-- ข้อมูลสัญญา -->
        <div class="col-md-6">
          <label class="form-label">เลขสัญญา (contract_number)</label>
          <input type="text" class="form-control" name="contract_number" value="<?= h($_POST['contract_number'] ?? '') ?>" required>
        </div>

        <div class="col-md-6">
          <label class="form-label">ชื่อบริษัท (contractor_name)</label>
          <input type="text" class="form-control" name="contractor_name" value="<?= h($_POST['contractor_name'] ?? '') ?>" required>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button type="submit" class="btn btn-success" <?= ($selected_detail==='')?'disabled':'' ?>>บันทึก</button>
          <?php if ($return_url): ?>
            <a href="<?= h($return_url) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
          <?php else: ?>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">ยกเลิก</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
