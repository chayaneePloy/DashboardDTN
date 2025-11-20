<?php
//session_start();
//if(!isset($_SESSION['user'])){ header("Location: login.php"); exit; }
include 'db.php';

$cid = intval($_GET['contract_id'] ?? 0);
if($cid<=0){ header("Location: admin_home.php"); exit; }

$c = $pdo->prepare("SELECT * FROM contracts WHERE contract_id=?");
$c->execute([$cid]);
$contract = $c->fetch(PDO::FETCH_ASSOC);
if(!$contract){ header("Location: admin_home.php"); exit; }

$ph = $pdo->prepare("SELECT * FROM phases WHERE contract_detail_id=? ORDER BY phase_number");
$ph->execute([$cid]);
$phases = $ph->fetchAll(PDO::FETCH_ASSOC);

// details สำหรับเลือกเปลี่ยน binding
$details = $pdo->query("SELECT d.id_detail, d.detail_name, i.item_name, d.fiscal_year
                        FROM budget_detail d
                        LEFT JOIN budget_items i ON i.id = d.budget_item_id
                        ORDER BY d.fiscal_year DESC, d.id_detail DESC")->fetchAll(PDO::FETCH_ASSOC);

if($_SERVER['REQUEST_METHOD']=='POST'){
  $contract_number = trim($_POST['contract_number']);
  $contractor_name = trim($_POST['contractor_name']);
  $detail_item_id  = intval($_POST['detail_item_id']);

  $u = $pdo->prepare("UPDATE contracts SET contract_number=?, contractor_name=?, detail_item_id=? WHERE contract_id=?");
  $u->execute([$contract_number, $contractor_name, $detail_item_id, $cid]);

  // อัปเดต/เพิ่ม phases
  if(!empty($_POST['phase_name'])){
    foreach($_POST['phase_name'] as $k=>$pname){
      $pid  = intval($_POST['phase_id'][$k] ?? 0);
      $pnum = intval($_POST['phase_number'][$k] ?? 0);
      $due  = $_POST['due_date'][$k] ?? null;
      $comp = $_POST['completion_date'][$k] ?? null;
      $amt  = floatval($_POST['amount'][$k] ?? 0);
      $stat = trim($_POST['status'][$k] ?? '');

      if($pid>0){
        $up = $pdo->prepare("UPDATE phases SET phase_number=?, phase_name=?, due_date=?, completion_date=?, amount=?, status=? WHERE phase_id=?");
        $up->execute([$pnum,$pname,$due,$comp,$amt,$stat,$pid]);
      }else{
        if($pname!==''){
          $ins = $pdo->prepare("INSERT INTO phases (contract_detail_id, phase_number, phase_name, due_date, completion_date, amount, status)
                                VALUES (?,?,?,?,?,?,?)");
          $ins->execute([$cid,$pnum,$pname,$due,$comp,$amt,$stat]);
        }
      }
    }
  }

  header("Location: admin_home.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แก้ไขสัญญา</title>
<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
function addPhaseRow(){
  document.getElementById('phases').insertAdjacentHTML('beforeend', `
  <div class="row g-2 align-items-end phase-row mb-2">
    <input type="hidden" name="phase_id[]" value="0">
    <div class="col-2"><label class="form-label">งวด</label><input type="number" name="phase_number[]" class="form-control" required></div>
    <div class="col-3"><label class="form-label">ชื่องวด</label><input name="phase_name[]" class="form-control" required></div>
    <div class="col-2"><label class="form-label">ครบกำหนด</label><input type="date" name="due_date[]" class="form-control"></div>
    <div class="col-2"><label class="form-label">เสร็จสิ้น</label><input type="date" name="completion_date[]" class="form-control"></div>
    <div class="col-2"><label class="form-label">จำนวนเงิน</label><input type="number" step="0.01" name="amount[]" class="form-control" required></div>
    <div class="col-1"><label class="form-label">สถานะ</label><input name="status[]" class="form-control" placeholder="กำลังดำเนินการ/เสร็จสิ้น"></div>
    <div class="col-12 text-end"><button type="button" class="btn btn-outline-danger" onclick="this.closest('.phase-row').remove()">✖</button></div>
  </div>`);
}
</script>
</head>
<body class="p-4">
<div class="container">
  <h3>✏️ แก้ไขสัญญา</h3>
  <form method="post" class="card p-3">
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">เลขที่สัญญา</label>
        <input name="contract_number" class="form-control" value="<?= htmlspecialchars($contract['contract_number']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">ผู้รับจ้าง</label>
        <input name="contractor_name" class="form-control" value="<?= htmlspecialchars($contract['contractor_name']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">รายละเอียดที่ผูก</label>
        <select name="detail_item_id" class="form-select" required>
          <?php foreach($details as $d): ?>
          <option value="<?= $d['id_detail'] ?>" <?= $contract['detail_item_id']==$d['id_detail']?'selected':'' ?>>
            [FY<?= $d['fiscal_year'] ?>] <?= htmlspecialchars($d['item_name']) ?> - <?= htmlspecialchars($d['detail_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h5 class="mb-2">งวดงาน (Phases)</h5>
    <div id="phases">
      <?php foreach($phases as $p): ?>
      <div class="row g-2 align-items-end phase-row mb-2">
        <input type="hidden" name="phase_id[]" value="<?= $p['phase_id'] ?>">
        <div class="col-2"><label class="form-label">งวด</label><input type="number" name="phase_number[]" class="form-control" value="<?= $p['phase_number'] ?>" required></div>
        <div class="col-3"><label class="form-label">ชื่องวด</label><input name="phase_name[]" class="form-control" value="<?= htmlspecialchars($p['phase_name']) ?>" required></div>
        <div class="col-2"><label class="form-label">ครบกำหนด</label><input type="date" name="due_date[]" class="form-control" value="<?= $p['due_date'] ?>"></div>
        <div class="col-2"><label class="form-label">เสร็จสิ้น</label><input type="date" name="completion_date[]" class="form-control" value="<?= $p['completion_date'] ?>"></div>
        <div class="col-2"><label class="form-label">จำนวนเงิน</label><input type="number" step="0.01" name="amount[]" class="form-control" value="<?= $p['amount'] ?>" required></div>
        <div class="col-1"><label class="form-label">สถานะ</label><input name="status[]" class="form-control" value="<?= htmlspecialchars($p['status']) ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-outline-secondary mb-3" onclick="addPhaseRow()">➕ เพิ่มงวด</button>

    <div class="d-flex gap-2">
      <button class="btn btn-primary">บันทึก</button>
      <a class="btn btn-secondary" href="admin_home.php">ยกเลิก</a>
      <a class="btn btn-outline-danger ms-auto" href="admin_remove_contract.php?contract_id=<?= $contract['contract_id'] ?>" onclick="return confirm('ลบสัญญานี้และงวดทั้งหมด?')">🗑️ ลบสัญญา</a>
    </div>
  </form>
</div>
</body>
</html>
