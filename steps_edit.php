<?php
session_start();
include 'db.php';



function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
    $y = (int)$y;

    // ถ้าเป็น ค.ศ. (<2400) ค่อย +543, แต่ถ้าเป็น พ.ศ. อยู่แล้ว ไม่ต้องบวก
    if ($y < 2400) $y += 543;

    return sprintf('%02d/%02d/%04d', (int)$d, (int)$m, $y);
}


// ------------------ เพิ่ม ------------------
if (isset($_POST['add'])) {
    $iso_date = normalize_step_date($_POST['step_date'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO project_steps (
            id_budget_detail,
            step_name,
            step_order,
            step_date,
            step_description,
            sub_steps,
            is_completed,
            document_path
        )
        VALUES (
            :id_detail,
            :step_name,
            :step_order,
            :step_date,
            :step_description,
            :sub_steps,
            :is_completed,
            :document_path
        )
    ");

    $stmt->execute([
        ':id_detail'         => $id_detail,
        ':step_name'         => trim($_POST['step_name']),
        ':step_order'        => (int)$_POST['step_order'],
        ':step_date'         => $iso_date,
        ':step_description'  => '',
        ':sub_steps'         => '',
        ':is_completed'      => 0,
        ':document_path'     => null
    ]);

    $_SESSION['flash_success'] = "เพิ่มขั้นตอนใหม่เรียบร้อยแล้ว!";
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ อัปเดตทีละแถว (ปุ่มไอเท็มเดี่ยว – ถ้ามี) ------------------
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

    $_SESSION['flash_success'] = "บันทึกการแก้ไขสำเร็จแล้ว!";
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ ลบ ------------------
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM project_steps WHERE id=:id");
    $stmt->execute([':id'=>(int)$_GET['delete']]);

    $_SESSION['flash_success'] = "ลบขั้นตอนเรียบร้อยแล้ว!";
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

    $_SESSION['flash_success'] = "บันทึกข้อมูลทั้งหมดเรียบร้อยแล้ว!";
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
<title>Dashboard งบประมาณ</title>
<link rel="icon" type="image/png" href="assets/logoio.ico">
<link rel="shortcut icon" type="image/png" href="assets/logo3.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> 
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="styles.css">
<style>
body{font-family:'Sarabun',sans-serif;background:#f7f9fc;}
table input{width:100%;font-size:.9rem;}
.form-text code{background:#f1f3f5;padding:0 .25rem;border-radius:.25rem;}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand fw-bold" href="index.php">
      📊 Dashboard การจ่ายงวดงาน
    </a>

    <!-- Hamburger -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Menu -->
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

        <!-- หน้าแรก -->
        <li class="nav-item">
          <a class="nav-link text-white" href="index.php">
            <i class="bi bi-house"></i> หน้าหลัก
          </a>
        </li>

        <!-- กลับไป steps.php?id_detail=... -->
        <li class="nav-item">
          <a class="nav-link text-white" href="steps.php?id_detail=<?= h($id_detail) ?>">
            <i class="bi bi-arrow-left"></i> กลับ
          </a>
        </li>

      </ul>
    </div>

  </div>
</nav>

<div class="container">
  <h2 class="mb-4">📌 จัดการขั้นตอนโครงการ (ปี พ.ศ.)</h2>

  <?php if(!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= h($_SESSION['flash_success']); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <!-- เพิ่มขั้นตอนใหม่ -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header bg-success text-white fw-bold">➕ เพิ่มขั้นตอนใหม่</div>
    <div class="card-body">
      <form method="post" id="add-form" class="row g-2">
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
      </form>
    </div>
  </div>

  <!-- ตารางขั้นตอน -->
  <form method="post">
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
        <span>🧾 ขั้นตอนทั้งหมด</span>
        <div class="d-flex gap-2">
          <a href="steps.php?id_detail=<?= h($id_detail) ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการขั้นตอน
          </a>
          <button type="submit" name="save_all" class="btn btn-warning btn-sm">
            💾 บันทึกทั้งหมด
          </button>
        </div>
      </div>
      <div class="card-body p-0">
        <table class="table table-bordered align-middle m-0">
          <thead class="table-light">
            <tr>
              <th>ลำดับ</th>
              <th>ชื่อขั้นตอน</th>
              <th>วันที่ (พ.ศ.)</th>
              <th>สถานะ</th>
              <th width="150">จัดการ</th>
            </tr>
          </thead>
          <tbody>
          <?php if($steps): foreach($steps as $i=>$s): ?>
            <tr>
              <td>
                <input type="number" name="steps[<?= $i ?>][step_order]" value="<?= (int)$s['step_order'] ?>" class="form-control text-center">
              </td>
              <td>
                <input type="text" name="steps[<?= $i ?>][step_name]" value="<?= h($s['step_name']) ?>" class="form-control">
              </td>
              <td>
                <input type="text" name="steps[<?= $i ?>][step_date]" value="<?= display_thai_date($s['step_date']) ?>" class="form-control">
              </td>
              <td class="text-center">
                <input type="checkbox" name="steps[<?= $i ?>][is_completed]" <?= $s['is_completed']?'checked':'' ?>> เสร็จแล้ว
              </td>
              <td class="text-center">
                <input type="hidden" name="steps[<?= $i ?>][id]" value="<?= (int)$s['id'] ?>">
                <a href="?id_detail=<?= h($id_detail) ?>&delete=<?= (int)$s['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('ลบขั้นตอนนี้หรือไม่?')">
                  ลบ
                </a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr>
              <td colspan="5" class="text-center text-muted">ยังไม่มีขั้นตอน</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </form>

  <!-- ปุ่มกลับด้านล่างอีกจุด เผื่อเลื่อนมาล่างสุด -->
  <div class="mt-3 text-end">
    <a href="steps.php?id_detail=<?= h($id_detail) ?>" class="btn btn-secondary">
      <i class="bi bi-arrow-left"></i> กลับไปหน้า steps
    </a>
  </div>

</div>

<!-- JS: แปลงปฏิทิน/แสดง พ.ศ. -->
<script>
(function(){
  const input = document.getElementById('step_date');
  if(!input) return;

  // เริ่มเป็น date เพื่อเรียก datepicker
  input.type = 'date';

  input.addEventListener('change',()=>{
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
