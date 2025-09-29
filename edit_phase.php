<?php
// ===================== CONNECT =====================
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$allowedStatus = ['รอดำเนินการ','อยู่ระหว่างดำเนินการ','เสร็จสิ้น','ยกเลิก'];

// ===================== LOAD / UPDATE =====================
$phase_id   = $_GET['phase_id'] ?? $_POST['phase_id'] ?? null;
$return_url = $_GET['return']    ?? $_POST['return_url'] ?? '';

if (!$phase_id || !ctype_digit((string)$phase_id)) {
    http_response_code(400);
    exit("ไม่ระบุงวดงาน (phase_id) ถูกต้อง");
}

$successMsg = $errorMsg = "";

// เมื่อกดบันทึก (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รับค่า
    $phase_number    = $_POST['phase_number'] ?? '';
    $phase_name      = trim($_POST['phase_name'] ?? '');
    $amountInput     = str_replace([',',' '], '', $_POST['amount'] ?? '0');
    $due_date        = $_POST['due_date'] ?: null;
    $completion_date = $_POST['completion_date'] ?: null;
    $payment_date    = $_POST['payment_date'] ?: null;
    $status          = $_POST['status'] ?? $allowedStatus[0];

    // แปลง/ตรวจสอบ
    $phase_number = (int)$phase_number;
    if (!is_numeric($amountInput)) {
        $errorMsg = "จำนวนเงินไม่ถูกต้อง";
    } else {
        $amount = (float)$amountInput;
        if ($amount < 0) $errorMsg = "จำนวนเงินต้องเป็นค่าบวก";
    }
    if (!in_array($status, $allowedStatus, true)) {
        $status = $allowedStatus[0];
    }

    // แนะนำ: due_date <= completion_date
    if (!$errorMsg && $due_date && $completion_date && ($due_date > $completion_date)) {
        $errorMsg = "Due Date ต้องไม่เกิน Completion Date";
    }

    // บันทึก
    if (!$errorMsg) {
        $stmt = $pdo->prepare("
            UPDATE phases
            SET phase_number = ?,
                phase_name = ?,
                amount = ?,
                due_date = ?,
                completion_date = ?,
                status = ?,
                payment_date = ?
            WHERE phase_id = ?
        ");
        $stmt->execute([
            $phase_number,
            $phase_name,
            $amount,
            $due_date,
            $completion_date,
            $status,
            $payment_date,
            $phase_id
        ]);
        $successMsg = "บันทึกข้อมูลเรียบร้อยแล้ว";
    }
}

// โหลดข้อมูลปัจจุบัน (รวมรายละเอียดอ้างอิง)
$stmt = $pdo->prepare("
    SELECT 
        p.phase_id, p.contract_detail_id, p.phase_number, p.phase_name, p.amount,
        p.due_date, p.completion_date, p.status, p.payment_date,
        c.contract_number, c.contractor_name,
        bd.detail_name,
        bi.item_name, bi.fiscal_year
    FROM phases p
    JOIN contracts c      ON p.contract_detail_id = c.contract_id
    JOIN budget_detail bd ON c.detail_item_id     = bd.id_detail
    JOIN budget_items bi  ON bd.budget_item_id    = bi.id
    WHERE p.phase_id = ?
");
$stmt->execute([$phase_id]);
$phase = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$phase) {
    http_response_code(404);
    exit("ไม่พบนงวดงานที่ต้องการแก้ไข");
}

// ===================== VIEW =====================
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>แก้ไขงวดงาน #<?= h($phase['phase_id']) ?></title>
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
    <h3 class="form-section-title">🛠️ แก้ไขงวดงาน (Phase)</h3>
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
      ข้อมูลงวดงาน #<?= h($phase['phase_id']) ?>
    </div>
    <div class="card-body">
      <!-- ข้อมูลอ้างอิง (อ่านอย่างเดียว) -->
      <div class="row g-3 mb-4">
        <div class="col-md-3">
          <label class="form-label">ปีงบประมาณ</label>
          <input type="text" class="form-control" value="<?= h($phase['fiscal_year']) ?>" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">งบประมาณ</label>
          <input type="text" class="form-control" value="<?= h($phase['item_name']) ?>" readonly>
        </div>
        <div class="col-md-5">
          <label class="form-label">โครงการ</label>
          <input type="text" class="form-control" value="<?= h($phase['detail_name']) ?>" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">เลขสัญญา</label>
          <input type="text" class="form-control" value="<?= h($phase['contract_number']) ?>" readonly>
        </div>
        <div class="col-md-8">
          <label class="form-label">ผู้รับจ้าง</label>
          <input type="text" class="form-control" value="<?= h($phase['contractor_name']) ?>" readonly>
        </div>
      </div>

      <!-- ฟอร์มแก้ไข -->
      <form method="post" class="row g-3">
        <input type="hidden" name="phase_id" value="<?= h($phase['phase_id']) ?>">
        <input type="hidden" name="return_url" value="<?= h($return_url) ?>">

        <div class="col-md-2">
          <label class="form-label">งวดที่</label>
          <input type="number" name="phase_number" class="form-control" value="<?= h($phase['phase_number']) ?>" min="1" required>
        </div>

        <div class="col-md-5">
          <label class="form-label">ชื่อ (Phase Name)</label>
          <input type="text" name="phase_name" class="form-control" value="<?= h($phase['phase_name']) ?>">
        </div>

        <div class="col-md-5">
          <label class="form-label">สถานะ</label>
          <select name="status" class="form-select">
            <?php foreach ($allowedStatus as $st): ?>
              <option value="<?= h($st) ?>" <?= ($st === $phase['status']) ? 'selected' : '' ?>><?= h($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">จำนวนเงิน (บาท)</label>
          <input type="text" name="amount" class="form-control number" value="<?= number_format((float)$phase['amount'], 2, '.', '') ?>" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">Due Date</label>
          <input type="date" name="due_date" class="form-control" value="<?= h($phase['due_date']) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Completion Date</label>
          <input type="date" name="completion_date" class="form-control" value="<?= h($phase['completion_date']) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Payment Date</label>
          <input type="date" name="payment_date" class="form-control" value="<?= h($phase['payment_date']) ?>">
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button type="submit" class="btn btn-primary">บันทึก</button>
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
