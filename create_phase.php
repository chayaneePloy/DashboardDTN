<?php
// ===================== CONNECT =====================
// ปรับค่าตามการเชื่อมต่อจริงของคุณ
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ===================== UTIL =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$allowedStatus = ['รอดำเนินการ','อยู่ระหว่างดำเนินการ','เสร็จสิ้น','ยกเลิก'];

/**
 * แปลงวันที่รูปแบบ YYYY-MM-DD (ที่เก็บเป็น พ.ศ. ในฐานข้อมูล/POST)
 * ให้เป็น dd/mm/YYYY (พ.ศ.) เพื่อแสดงในช่อง fake
 * เช่น 2568-02-01 -> 01/02/2568
 */
function toThaiDisplay($dateStr){
    if (!$dateStr) return '';
    $parts = explode('-', $dateStr);
    if (count($parts) !== 3) return '';
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $d = (int)$parts[2];
    return sprintf('%02d/%02d/%04d', $d, $m, $y);
}

$method = $_SERVER['REQUEST_METHOD'];
$return_url = $_GET['return'] ?? $_POST['return_url'] ?? '';

$selected_year    = $_GET['year'] ?? ($_POST['year'] ?? '');
$selected_item    = $_GET['item'] ?? ($_POST['item'] ?? '');
$selected_detail  = $_POST['detail_id'] ?? '';     // โครงการ (budget_detail.id_detail)
$selected_contract= $_POST['contract_id'] ?? '';   // สัญญา (contracts.contract_id)

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
    $year        = $_POST['year'] ?? '';
    $item        = $_POST['item'] ?? '';
    $detail_id   = $_POST['detail_id'] ?? '';
    $contract_id = $_POST['contract_id'] ?? '';

    $phase_number    = (int)($_POST['phase_number'] ?? 0);
    $phase_name      = trim($_POST['phase_name'] ?? '');
    // amount
    $amountInput     = str_replace([',',' '], '', $_POST['amount'] ?? '0');

    // *** วันที่ที่ส่งมาคือ พ.ศ. ในรูปแบบ YYYY-MM-DD ***
    $due_date        = $_POST['due_date'] ?: null;         // e.g. 2568-01-31
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

    // เปรียบเทียบวันที่ (เทียบจากสตริง YYYY-MM-DD พ.ศ. ก็ยังเรียงได้เหมือนเดิม)
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
            $due_date,         // เก็บเป็น พ.ศ. เช่น 2568-01-31
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
  <link rel="icon" type="image/png" href="assets/logoio.ico">
  <link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
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


        <!-- Due Date (พ.ศ.) -->
        <div class="col-md-4">
          <label class="form-label">Due Date</label>
          <div class="input-group">
            <!-- ตัวจริงที่ส่งเข้า PHP/DB (เก็บเป็น พ.ศ. YYYY-MM-DD) -->
            <input type="hidden"
                   id="due_date_real"
                   name="due_date"
                   value="<?= h($_POST['due_date'] ?? '') ?>">

            <!-- ตัวที่ใช้เปิดปฏิทิน (ค.ศ.) -->
            <input type="date"
                   class="form-control d-none"
                   id="due_date_picker">

            <!-- ตัวที่ user เห็น (พ.ศ.) -->
            <input type="text"
                   class="form-control"
                   id="due_date_fake"
                   placeholder="เลือกวันที่ (พ.ศ.)"
                   value="<?= h(toThaiDisplay($_POST['due_date'] ?? '')) ?>"
                  >
          </div>
        </div>

        <!-- Completion Date (พ.ศ.) -->
        <div class="col-md-4">
          <label class="form-label">Completion Date</label>
          <div class="input-group">
            <input type="hidden"
                   id="completion_date_real"
                   name="completion_date"
                   value="<?= h($_POST['completion_date'] ?? '') ?>">

            <input type="date"
                   class="form-control d-none"
                   id="completion_date_picker">

            <input type="text"
                   class="form-control"
                   id="completion_date_fake"
                   placeholder="เลือกวันที่ (พ.ศ.)"
                   value="<?= h(toThaiDisplay($_POST['completion_date'] ?? '')) ?>"
                   >
          </div>
        </div>

        <!-- Payment Date (พ.ศ.) -->
        <div class="col-md-4">
          <label class="form-label">Payment Date</label>
          <div class="input-group">
            <input type="hidden"
                   id="payment_date_real"
                   name="payment_date"
                   value="<?= h($_POST['payment_date'] ?? '') ?>">

            <input type="date"
                   class="form-control d-none"
                   id="payment_date_picker">

            <input type="text"
                   class="form-control"
                   id="payment_date_fake"
                   placeholder="เลือกวันที่ (พ.ศ.)"
                   value="<?= h(toThaiDisplay($_POST['payment_date'] ?? '')) ?>"
                   >
          </div>
        </div>
<div class="col-md-6">
  <label class="form-label">หมายเหตุ</label>
  <textarea
    class="form-control"
    name="phase_name"
    rows="2"
    placeholder="เช่น ส่งมอบงานงวดแรก / งวดสุดท้าย / หักค่าปรับ"
  ><?= h($_POST['phase_name'] ?? '') ?></textarea>
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

<script>
// ฟังก์ชันผูก date picker (ค.ศ.) + hidden (พ.ศ.) + ช่องแสดง (พ.ศ.)
document.addEventListener('DOMContentLoaded', function () {

  function bindThaiDate(pickerId, realId, fakeId) {
    const picker = document.getElementById(pickerId); // type="date"
    const real   = document.getElementById(realId);   // hidden พ.ศ.
    const fake   = document.getElementById(fakeId);   // text แสดง พ.ศ.
    if (!picker || !real || !fake) return;

    // เมื่อคลิกช่อง fake -> เปิดปฏิทินของ picker
    fake.addEventListener('click', function () {
      if (typeof picker.showPicker === 'function') {
        picker.showPicker();
      } else {
        picker.focus();
      }
    });

    // เมื่อเลือกวันที่จาก picker (ค.ศ.)
    picker.addEventListener('change', function () {
      if (!picker.value) {
        real.value = "";
        fake.value = "";
        return;
      }
      const d = new Date(picker.value);
      if (isNaN(d)) return;

      const day   = String(d.getDate()).padStart(2, '0');
      const month = String(d.getMonth() + 1).padStart(2, '0');
      const yearCE  = d.getFullYear();
      const yearTH  = yearCE + 543;

      // เก็บลง hidden เป็น พ.ศ. YYYY-MM-DD
      real.value = `${yearTH}-${month}-${day}`;

      // แสดงใน fake เป็น dd/mm/ปีพ.ศ.
      fake.value = `${day}/${month}/${yearTH}`;
    });

    // ถ้ามีค่า พ.ศ. อยู่ใน hidden (เช่น submit แล้ว error) -> sync กลับมา picker/fake
    if (real.value) {
      const parts = real.value.split('-');
      if (parts.length === 3) {
        let yTH = parseInt(parts[0], 10);
        let m = parseInt(parts[1], 10);
        let d = parseInt(parts[2], 10);

        if (!isNaN(yTH) && !isNaN(m) && !isNaN(d)) {
          const yCE = yTH - 543;
          const mm  = String(m).padStart(2, '0');
          const dd  = String(d).padStart(2, '0');

          // เซ็ตค่าให้ picker (ค.ศ.)
          picker.value = `${yCE}-${mm}-${dd}`;

          // เซ็ตค่าให้ fake (พ.ศ.)
          fake.value   = `${dd}/${mm}/${yTH}`;
        }
      }
    }
  }

  bindThaiDate('due_date_picker',        'due_date_real',        'due_date_fake');
  bindThaiDate('completion_date_picker', 'completion_date_real', 'completion_date_fake');
  bindThaiDate('payment_date_picker',    'payment_date_real',    'payment_date_fake');
});
</script>

</body>
</html>
