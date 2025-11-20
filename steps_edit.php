<?php
require 'db.php';
session_start();

$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

/**
 * แปลงวันที่ระหว่าง พ.ศ. <-> ค.ศ.
 */
function normalize_step_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    // รองรับ dd/mm/yyyy หรือ d/m/yyyy
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $raw, $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $y = (int)$m[3];
        if ($y >= 2400) $y -= 543; // แปลงจาก พ.ศ. -> ค.ศ.
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    return null;
}

function display_thai_date($iso) {
    if (!$iso || $iso === '0000-00-00') return '';
    [$y,$m,$d] = explode('-', $iso);
    $y = (int)$y + 543;
    return sprintf('%02d/%02d/%04d', (int)$d, (int)$m, $y);
}

// ------------------ เพิ่ม ------------------
if (isset($_POST['add'])) {
    $iso_date = normalize_step_date($_POST['step_date'] ?? '');
    $stmt = $pdo->prepare("
        INSERT INTO project_steps (id_budget_detail, step_name, step_order, step_date, is_completed)
        VALUES (:id_detail, :step_name, :step_order, :step_date, 0)
    ");
    $stmt->execute([
        ':id_detail' => $id_detail,
        ':step_name' => $_POST['step_name'],
        ':step_order' => (int)$_POST['step_order'],
        ':step_date' => $iso_date
    ]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ อัปเดต ------------------
if (isset($_POST['update'])) {
    $iso_date = normalize_step_date($_POST['step_date'] ?? '');
    $stmt = $pdo->prepare("
        UPDATE project_steps 
        SET step_name=:step_name, step_order=:step_order, step_date=:step_date, is_completed=:is_completed
        WHERE id=:id
    ");
    $stmt->execute([
        ':step_name' => $_POST['step_name'],
        ':step_order' => (int)$_POST['step_order'],
        ':step_date' => $iso_date,
        ':is_completed' => isset($_POST['is_completed']) ? 1 : 0,
        ':id' => (int)$_POST['id']
    ]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ ลบ ------------------
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM project_steps WHERE id=:id");
    $stmt->execute([':id'=>(int)$_GET['delete']]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ บันทึกทั้งหมด ------------------
if (isset($_POST['save_all'])) {
    if (!empty($_POST['steps'])) {
        $stmt = $pdo->prepare("
            UPDATE project_steps 
            SET step_name=:step_name, step_order=:step_order, step_date=:step_date, is_completed=:is_completed
            WHERE id=:id
        ");
        foreach ($_POST['steps'] as $s) {
            $iso_date = normalize_step_date($s['step_date'] ?? '');
            $stmt->execute([
                ':step_name'=>$s['step_name'],
                ':step_order'=>(int)$s['step_order'],
                ':step_date'=>$iso_date,
                ':is_completed'=>isset($s['is_completed'])?1:0,
                ':id'=>(int)$s['id']
            ]);
        }
    }
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ ดึงข้อมูล ------------------
$stmt = $pdo->prepare("SELECT * FROM project_steps WHERE id_budget_detail=:id ORDER BY step_order ASC");
$stmt->execute([':id'=>$id_detail]);
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จัดการขั้นตอนโครงการ (ปี พ.ศ.)</title>
<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logoio.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body{font-family:'Sarabun',sans-serif;background:#f7f9fc;}
table input{width:100%;font-size:.9rem;}
.form-text code{background:#f1f3f5;padding:0 .25rem;border-radius:.25rem;}
</style>
</head>
<body class="container py-4">

<h2 class="mb-4">📌 จัดการขั้นตอนโครงการ (ปี พ.ศ.)</h2>

<!-- เพิ่มขั้นตอนใหม่ -->
<div class="card mb-4 shadow-sm">
  <div class="card-header bg-success text-white fw-bold">➕ เพิ่มขั้นตอนใหม่</div>
  <div class="card-body">
    <form method="post" id="add-form">
      <div class="row g-2">
        <div class="col-md-3">
          <input type="text" name="step_name" class="form-control" placeholder="ชื่อขั้นตอน" required>
        </div>
        <div class="col-md-2">
          <input type="number" name="step_order" class="form-control" placeholder="ลำดับ" required>
        </div>
        <div class="col-md-3">
          <input type="text" name="step_date" id="step_date" class="form-control" placeholder="วว/ดด/พ.ศ.">
          <div class="form-text">ตัวอย่าง: <code>10/11/2568</code> หรือเลือกจากปฏิทิน</div>
        </div>
        <div class="col-md-2">
          <button type="submit" name="add" class="btn btn-success w-100">เพิ่ม</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ตารางขั้นตอน -->
<form method="post">
<div class="card shadow-sm">
  <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
    <span>🧾 ขั้นตอนทั้งหมด</span>
    <button type="submit" name="save_all" class="btn btn-warning btn-sm">💾 บันทึกทั้งหมด</button>
  </div>
  <div class="card-body p-0">
    <table class="table table-bordered align-middle m-0">
      <thead class="table-light">
        <tr><th>ลำดับ</th><th>ชื่อขั้นตอน</th><th>วันที่ (พ.ศ.)</th><th>สถานะ</th><th width="150">จัดการ</th></tr>
      </thead>
      <tbody>
      <?php if($steps): foreach($steps as $i=>$s): ?>
        <tr>
          <td><input type="number" name="steps[<?= $i ?>][step_order]" value="<?= (int)$s['step_order'] ?>" class="form-control text-center"></td>
          <td><input type="text" name="steps[<?= $i ?>][step_name]" value="<?= htmlspecialchars($s['step_name']) ?>" class="form-control"></td>
          <td><input type="text" name="steps[<?= $i ?>][step_date]" value="<?= display_thai_date($s['step_date']) ?>" class="form-control"></td>
          <td class="text-center"><input type="checkbox" name="steps[<?= $i ?>][is_completed]" <?= $s['is_completed']?'checked':'' ?>> เสร็จแล้ว</td>
          <td class="text-center">
            <input type="hidden" name="steps[<?= $i ?>][id]" value="<?= (int)$s['id'] ?>">
            <a href="?id_detail=<?= $id_detail ?>&delete=<?= $s['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('ลบขั้นตอนนี้หรือไม่?')">ลบ</a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5" class="text-center text-muted">ยังไม่มีขั้นตอน</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</form>

<div class="mt-4">
  <a href="steps.php?id_detail=<?= $id_detail ?>" class="btn btn-secondary">⬅ กลับ</a>
</div>

<!-- JS: แปลงปฏิทิน/แสดง พ.ศ. -->
<script>
(function(){
  const input = document.getElementById('step_date');
  if(!input) return;

  // เพิ่ม datepicker HTML5 ธรรมดา
  input.type = 'date';

  input.addEventListener('change',()=>{
    // เมื่อเลือกจากปฏิทิน -> แสดง พ.ศ.
    const val = input.value;
    if(!val) return;
    const [y,m,d] = val.split('-').map(v=>parseInt(v,10));
    const by = y+543;
    input.type='text';
    input.value=`${String(d).padStart(2,'0')}/${String(m).padStart(2,'0')}/${by}`;
    input.blur();
    input.type='text';
  });

  input.addEventListener('focus',()=>{
    if(input.type==='text'){ input.type='date'; input.showPicker?.(); }
  });
})();
</script>

</body>
</html>
