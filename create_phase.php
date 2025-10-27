<?php
// ===================== CONNECT =====================
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$allowedStatus = ['รอดำเนินการ','อยู่ระหว่างดำเนินการ','เสร็จสิ้น','ยกเลิก'];

$method = $_SERVER['REQUEST_METHOD'];
$return_url = $_GET['return'] ?? $_POST['return_url'] ?? '';

$selected_year = $_GET['year'] ?? ($_POST['year'] ?? '');
$selected_item = $_GET['item'] ?? ($_POST['item'] ?? '');
$selected_detail = $_POST['detail_id'] ?? '';   // โครงการ (budget_detail.id_detail)
$selected_contract = $_POST['contract_id'] ?? ''; // สัญญา (contracts.contract_id)

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

// โครงการตามงบประมาณ
$details = [];
if ($selected_year !== '' && $selected_item !== '') {
    $stmt = $pdo->prepare("
        SELECT bd.id_detail, bd.detail_name
        FROM budget_detail bd
        JOIN budget_items bi ON bd.budget_item_id = bi.id
        WHERE bi.fiscal_year = ? AND bd.budget_item_id = ?
        ORDER BY bd.detail_name
    ");
    $stmt->execute([$selected_year, $selected_item]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// สัญญาตามโครงการที่เลือก
$contracts = [];
if ($selected_detail !== '') {
    $stmt = $pdo->prepare("
        SELECT c.contract_id, c.contract_number, c.contractor_name
        FROM contracts c
        WHERE c.detail_item_id = ?
        ORDER BY c.contract_number
    ");
    $stmt->execute([$selected_detail]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== บันทึก (POST) =====================
if ($method === 'POST') {
    // รับค่า
    $year  = $_POST['year'] ?? '';
    $item  = $_POST['item'] ?? '';
    $detail_id   = $_POST['detail_id'] ?? '';
    $contract_id = $_POST['contract_id'] ?? '';

    $phase_number    = (int)($_POST['phase_number'] ?? 0);
    $phase_name      = trim($_POST['phase_name'] ?? '');
    $amountInput     = str_replace([',',' '], '', $_POST['amount'] ?? '0');
    $due_date        = $_POST['due_date'] ?: null;
    $completion_date = $_POST['completion_date'] ?: null;
    $payment_date    = $_POST['payment_date'] ?: null;
    $status          = $_POST['status'] ?? $allowedStatus[0];

    // ตรวจค่าพื้นฐาน
    if ($year === '' || $item === '' || $detail_id === '' || $contract_id === '') {
        $errorMsg = "กรุณาเลือก ปีงบประมาณ, งบประมาณ, โครงการ และ สัญญา ให้ครบ";
    } elseif (!is_numeric($amountInput)) {
        $errorMsg = "จำนวนเงินไม่ถูกต้อง";
    } else {
        $amount = (float)$amountInput;
        if ($amount < 0) $errorMsg = "จำนวนเงินต้องเป็นค่าบวก";
    }
    if (!$errorMsg && !in_array($status, $allowedStatus, true)) {
        $status = $allowedStatus[0];
    }
    if (!$errorMsg && $due_date && $completion_date && ($due_date > $completion_date)) {
        $errorMsg = "Due Date ต้องไม่เกิน Completion Date";
    }

    // ตรวจความสัมพันธ์: contract_id ต้องอยู่ใต้ detail_id ที่เลือก
    if (!$errorMsg) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE contract_id = ? AND detail_item_id = ?");
        $stmt->execute([$contract_id, $detail_id]);
        if ($stmt->fetchColumn() == 0) {
            $errorMsg = "สัญญาไม่อยู่ในโครงการที่เลือก";
        }
    }

    // ตรวจเลขงวดซ้ำภายใต้สัญญาเดียวกัน
    if (!$errorMsg && $phase_number > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM phases WHERE contract_detail_id = ? AND phase_number = ?");
        $stmt->execute([$contract_id, $phase_number]);
        if ($stmt->fetchColumn() > 0) {
            $errorMsg = "งวดที่ {$phase_number} มีอยู่แล้วในสัญญานี้";
        }
    }

    // Insert
    if (!$errorMsg) {
        $stmt = $pdo->prepare("
            INSERT INTO phases (contract_detail_id, phase_number, phase_name, amount, due_date, completion_date, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $contract_id,
            $phase_number,
            $phase_name,
            $amount,
            $due_date,
            $completion_date,
            $status,
            $payment_date
        ]);
        $successMsg = "เพิ่มงวดงานเรียบร้อยแล้ว";

        // ถ้ามี return_url ส่งกลับหน้าที่มา
        if ($return_url) {
            header("Location: " . $return_url);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>เพิ่มงวดงานใหม่</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .form-section-title { font-weight: 700; color:#198754; }
    .number { text-align: right; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="form-section-title">➕ เพิ่มงวดงาน (Phase)</h3>
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
    <div class="card-header bg-success text-white">
      กรอกข้อมูลงวดงานใหม่
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="return_url" value="<?= h($return_url) ?>">

        <!-- เลือกลำดับ: ปีงบฯ -> งบประมาณ -> โครงการ -> สัญญา -->
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
          <select class="form-select" name="detail_id" onchange="this.form.submit()">
            <option value="">-- เลือกโครงการ --</option>
            <?php foreach ($details as $d): ?>
              <option value="<?= h($d['id_detail']) ?>" <?= ($d['id_detail']==$selected_detail)?'selected':'' ?>>
                <?= h($d['detail_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">สัญญา</label>
          <select class="form-select" name="contract_id" <?= $selected_detail? '' : 'disabled' ?>>
            <option value="">-- เลือกสัญญา --</option>
            <?php foreach ($contracts as $c): ?>
              <option value="<?= h($c['contract_id']) ?>" <?= ($c['contract_id']==$selected_contract)?'selected':'' ?>>
                <?= h($c['contract_number'].' — '.$c['contractor_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">งวดที่</label>
          <input type="number" class="form-control" name="phase_number" min="1" value="<?= h($_POST['phase_number'] ?? '') ?>" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">จำนวนเงิน (บาท)</label>
          <input type="text" class="form-control number" name="amount" value="<?= h($_POST['amount'] ?? '0.00') ?>" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">สถานะ</label>
          <select class="form-select" name="status">
            <?php foreach ($allowedStatus as $st): ?>
              <option value="<?= h($st) ?>" <?= (($st==($_POST['status'] ?? ''))?'selected':'') ?>>
                <?= h($st) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Due Date</label>
          <input type="date" class="form-control" name="due_date" value="<?= h($_POST['due_date'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Completion Date</label>
          <input type="date" class="form-control" name="completion_date" value="<?= h($_POST['completion_date'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Payment Date</label>
          <input type="date" class="form-control" name="payment_date" value="<?= h($_POST['payment_date'] ?? '') ?>">
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button type="submit" class="btn btn-success">บันทึก</button>
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
