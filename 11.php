<?php
// ---------------- เชื่อมต่อฐานข้อมูล ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// รับปีงบประมาณที่เลือก (default = ปีปัจจุบัน ค.ศ.)
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');

// ดึงปีงบประมาณทั้งหมดจาก budget_detail
$years = $pdo->query("
    SELECT DISTINCT fiscal_year
    FROM budget_detail 
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ดึงข้อมูลงบประมาณและขั้นตอน (Join)
$stmt = $pdo->prepare("
    SELECT bd.id_detail, bd.detail_name, bd.fiscal_year, bd.budget_item_id, bi.item_name,
       ps.id, ps.step_order, ps.step_name, ps.step_date, ps.step_description, 
       ps.sub_steps, ps.document_path, ps.is_completed
FROM budget_detail bd
LEFT JOIN project_steps ps ON bd.id_detail = ps.id_budget_detail
LEFT JOIN budget_item bi ON bd.budget_item_id = bi.id
WHERE bd.fiscal_year = :year
ORDER BY bd.budget_item_id, bd.id_detail, ps.step_order
");
$stmt->execute([':year' => $year]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// จัดกลุ่มข้อมูลเป็น BudgetType > Project > Steps
$projects = [];
foreach ($rows as $r) {
    $budget_type = $r['item_name'] ?: 'ไม่ระบุประเภทงบ';
    $projects[$budget_type][$r['id_detail']]['detail_name'] = $r['detail_name'];
    $projects[$budget_type][$r['id_detail']]['steps'][] = $r;
}

// ฟังก์ชันแปลงวันที่ไทย
function thai_date($date) {
    if (!$date) return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.",
               "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $ts = strtotime($date);
    return date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>ระบบติดตามโครงการ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f4f6f9; font-family:'Kanit',sans-serif; }
    .timeline-container { display:flex; overflow-x:auto; gap:1rem; padding-bottom:1rem; }
    .step-card { min-width:260px; flex-shrink:0; border:none; }
    .step-card:hover { transform:translateY(-4px); transition:0.2s; }
  </style>
</head>
<body>
<div class="container my-4">

  <!-- Filter -->
  <form method="get" class="mb-4">
    <label class="form-label fw-bold">เลือกปีงบประมาณ:</label>
    <select name="year" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
      <?php foreach($years as $y): ?>
        <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if(empty($projects)): ?>
    <div class="alert alert-warning">ไม่มีโครงการในปีงบ <?= $year+543 ?></div>
  <?php endif; ?>

  <?php foreach($projects as $budget_type => $pj_group): ?>
    <h3 class="mt-4 text-primary">📌 <?= htmlspecialchars($budget_type) ?></h3>
    <hr>
    <?php foreach($pj_group as $id_detail => $pj): 
      $steps = $pj['steps'];
      $completed = array_sum(array_column($steps, 'is_completed'));
      $total = count($steps);
      $percent = $total>0 ? round(($completed/$total)*100) : 0;
    ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h4 class="fw-bold"><?= htmlspecialchars($pj['detail_name']) ?></h4>
          <div class="progress my-2" style="height:20px;">
            <div class="progress-bar bg-success fw-bold" style="width:<?= $percent ?>%">
              <?= $percent ?>%
            </div>
          </div>
          <small class="text-muted">ดำเนินการแล้ว <?= $completed ?>/<?= $total ?> ขั้นตอน</small>

          <!-- Timeline -->
          <div class="timeline-container mt-3">
            <?php foreach($steps as $s): ?>
              <div class="card step-card shadow-sm <?= $s['is_completed']?'bg-light':'' ?>">
                <div class="card-body">
                  <h6 class="<?= $s['is_completed']?'text-success':'text-dark' ?>">
                    <?= $s['step_order'] ?>. <?= $s['step_name'] ?>
                  </h6>
                  <span class="badge bg-warning"><?= thai_date($s['step_date']) ?></span>
                  <p class="small text-muted mt-2">
                    <?= mb_strimwidth($s['step_description'],0,60,'...') ?>
                  </p>
                  <?php if(!empty($s['document_path'])): ?>
                    <a href="documents/<?= $s['document_path'] ?>" target="_blank" class="btn btn-sm btn-outline-success">เอกสาร</a>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>

</div>
</body>
</html>
<?php $pdo=null; ?>
