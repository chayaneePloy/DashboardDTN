<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

/**
 * Executive Minimal Dashboard (No tables)
 * - Filter: year (budget_items.fiscal_year)
 * - Search: project (budget_detail.detail_name LIKE %q%)
 * - KPIs:
 *   1) Completed amount: SUM(phases.amount) where status='เสร็จสิ้น'
 *   2) Not completed amount: SUM(phases.amount) where status IN ('รอดำเนินการ','อยู่ระหว่างดำเนินการ')
 *   3) Project count + project names list
 *   4) Paid phases count (payment_date IS NOT NULL) / Total phases count
 *
 * JOIN: budget_items -> budget_detail -> contracts -> phases
 */

// ---------------- DB ----------------
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function n0($v): float { return (float)($v ?? 0); }
function money($v): string { return number_format((float)($v ?? 0), 2); }

// ---------------- Years (ตามโค้ดเดิมคุณใช้ budget_act) ----------------
$years = $pdo->query("SELECT DISTINCT fiscal_year FROM budget_act ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) { $years = [date('Y')]; }
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)max($years);

$q = trim((string)($_GET['q'] ?? ''));

// ---------------- Projects list (budget_detail ของปีนั้น) ----------------
$sqlProjects = "
  SELECT DISTINCT bd.id_detail, bd.detail_name
  FROM budget_detail bd
  JOIN budget_items bi ON bi.id = bd.budget_item_id
  WHERE bi.fiscal_year = :fy
" . ($q !== '' ? " AND bd.detail_name LIKE :q " : "") . "
  ORDER BY bd.detail_name ASC
";
$st = $pdo->prepare($sqlProjects);
$params = [':fy' => $selectedYear];
if ($q !== '') $params[':q'] = "%{$q}%";
$st->execute($params);
$projects = $st->fetchAll();

$projectCount = count($projects);
$projectIds = array_map(fn($r) => (int)$r['id_detail'], $projects);

// ถ้าไม่มีโครงการในปีนี้ (หรือผลค้นหาไม่เจอ) ให้แสดงศูนย์หมด
$completedAmount = 0.0;
$notCompletedAmount = 0.0;
$paidPhasesCount = 0;
$totalPhasesCount = 0;
$completedPhasesCount = 0;
$notCompletedPhasesCount = 0;

// ---------------- Aggregate from phases (ตามรายชื่อโครงการที่กรองแล้ว) ----------------
if ($projectCount > 0) {
  $in = implode(',', array_fill(0, count($projectIds), '?'));

  // นับจำนวนงวดทั้งหมด/งวดที่มี payment_date/งวดเสร็จสิ้น/งวดยังไม่เสร็จสิ้น
  $sqlPhaseCounts = "
    SELECT
      COUNT(p.phase_id) AS total_phases,
      SUM(CASE WHEN p.payment_date IS NOT NULL THEN 1 ELSE 0 END) AS paid_phases,
      SUM(CASE WHEN p.status = 'เสร็จสิ้น' THEN 1 ELSE 0 END) AS completed_phases,
      SUM(CASE WHEN p.status IN ('รอดำเนินการ','อยู่ระหว่างดำเนินการ') THEN 1 ELSE 0 END) AS not_completed_phases
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    JOIN budget_detail bd ON c.detail_item_id = bd.id_detail
    WHERE bd.id_detail IN ($in)
  ";
  $stC = $pdo->prepare($sqlPhaseCounts);
  $stC->execute($projectIds);
  $cnt = $stC->fetch();

  $totalPhasesCount = (int)($cnt['total_phases'] ?? 0);
  $paidPhasesCount = (int)($cnt['paid_phases'] ?? 0);
  $completedPhasesCount = (int)($cnt['completed_phases'] ?? 0);
  $notCompletedPhasesCount = (int)($cnt['not_completed_phases'] ?? 0);

  // รวมเงิน “เสร็จสิ้น”
  $sqlCompletedAmt = "
    SELECT COALESCE(SUM(p.amount),0) AS amt
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    JOIN budget_detail bd ON c.detail_item_id = bd.id_detail
    WHERE bd.id_detail IN ($in)
      AND p.status = 'เสร็จสิ้น'
  ";
  $stA = $pdo->prepare($sqlCompletedAmt);
  $stA->execute($projectIds);
  $completedAmount = (float)$stA->fetchColumn();

  // รวมเงิน “รอดำเนินการ + อยู่ระหว่างดำเนินการ”
  $sqlNotCompletedAmt = "
    SELECT COALESCE(SUM(p.amount),0) AS amt
    FROM phases p
    JOIN contracts c ON p.contract_detail_id = c.contract_id
    JOIN budget_detail bd ON c.detail_item_id = bd.id_detail
    WHERE bd.id_detail IN ($in)
      AND p.status IN ('รอดำเนินการ','อยู่ระหว่างดำเนินการ')
  ";
  $stB = $pdo->prepare($sqlNotCompletedAmt);
  $stB->execute($projectIds);
  $notCompletedAmount = (float)$stB->fetchColumn();
}

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Executive Dashboard (Minimal)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f6f7fb; --card:#fff; --text:#0f172a; --muted:#64748b;
      --line:rgba(15,23,42,.10); --shadow: 0 10px 28px rgba(15,23,42,.06);
    }
    body{ background:var(--bg); color:var(--text); }
    .wrap{ max-width: 1100px; }
    .cardx{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:18px;
      box-shadow: var(--shadow);
    }
    .title{ font-weight:900; letter-spacing:-.4px; margin:0; }
    .subtitle{ color:var(--muted); font-size:13px; margin-top:6px; }
    .kpi{ padding:16px 18px; }
    .kpi .label{ color:var(--muted); font-size:12px; }
    .kpi .big{ font-size:36px; font-weight:900; letter-spacing:-.9px; line-height:1.05; }
    .kpi .sub{ color:var(--muted); font-size:12px; margin-top:6px; }
    .mono{ font-variant-numeric: tabular-nums; font-family: ui-monospace, Menlo, Consolas, monospace; }

    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px; border-radius:999px;
      border:1px solid var(--line); color:var(--muted); font-size:12px;
      background:#fff;
    }
    .listbox{
      padding: 14px 16px;
      max-height: 320px;
      overflow:auto;
    }
    .proj{
      padding:10px 12px;
      border:1px solid var(--line);
      border-radius:14px;
      background:#fff;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:10px;
    }
    .proj .name{ font-weight:700; }
    .proj .meta{ color:var(--muted); font-size:12px; }
  </style>
</head>

<body>
<div class="container wrap py-4">

  <!-- Header + Filters -->
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <div>
      <h2 class="title">Executive Dashboard</h2>
      <div class="subtitle">มินิมอล • เน้นตัวเลข • กรองตามปีงบประมาณและค้นหาโครงการ</div>
    </div>

    <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
      <div>
        <div class="small text-muted mb-1">ปีงบประมาณ</div>
        <select name="year" class="form-select">
          <?php foreach ($years as $y): ?>
            <option value="<?= h((string)$y) ?>" <?= ((int)$y === $selectedYear) ? 'selected' : '' ?>>
              <?= h((string)$y) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div class="small text-muted mb-1">ค้นหาโครงการ</div>
        <input name="q" class="form-control" value="<?= h($q) ?>" placeholder="พิมพ์ชื่อโครงการ...">
      </div>

      <div>
        <button class="btn btn-dark">ค้นหา</button>
      </div>
    </form>
  </div>

  <!-- Quick chips -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <span class="chip">ปีงบ: <b><?= h((string)$selectedYear) ?></b></span>
    <span class="chip">โครงการ: <b class="mono"><?= (int)$projectCount ?></b></span>
    <span class="chip">งวดทั้งหมด: <b class="mono"><?= (int)$totalPhasesCount ?></b></span>
    <span class="chip">จ่ายแล้ว: <b class="mono"><?= (int)$paidPhasesCount ?></b> งวด</span>
  </div>

  <!-- Big KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="cardx kpi h-100">
        <div class="label">ราคาที่ “เสร็จสิ้น” (รวม amount ของงวด)</div>
        <div class="big mono"><?= money($completedAmount) ?></div>
        <div class="sub">จำนวนงวดเสร็จสิ้น: <b class="mono"><?= (int)$completedPhasesCount ?></b></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="cardx kpi h-100">
        <div class="label">ราคาที่ “รอดำเนินการ + กำลังดำเนินการ” (รวม amount ของงวด)</div>
        <div class="big mono"><?= money($notCompletedAmount) ?></div>
        <div class="sub">จำนวนงวดยังไม่เสร็จสิ้น: <b class="mono"><?= (int)$notCompletedPhasesCount ?></b></div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="cardx kpi h-100">
        <div class="label">จ่ายไปแล้วกี่งวด</div>
        <div class="big mono"><?= (int)$paidPhasesCount ?></div>
        <div class="sub">นิยาม: payment_date ไม่เป็นค่าว่าง</div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="cardx kpi h-100">
        <div class="label">มีทั้งหมดกี่งวด</div>
        <div class="big mono"><?= (int)$totalPhasesCount ?></div>
        <div class="sub">นับจาก phases ของโครงการที่อยู่ในปีงบและตามผลค้นหา</div>
      </div>
    </div>
  </div>

  <!-- Project list -->
  <div class="cardx">
    <div class="p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <div class="fw-bold">รายชื่อโครงการ</div>
        <div class="text-muted small">
          แสดงตามปีงบ <?= h((string)$selectedYear) ?><?= $q !== '' ? ' • ค้นหา: “'.h($q).'”' : '' ?>
        </div>
      </div>
      <div class="text-muted small">ทั้งหมด <b class="mono"><?= (int)$projectCount ?></b> โครงการ</div>
    </div>

    <div class="listbox">
      <?php if ($projectCount === 0): ?>
        <div class="text-muted">ไม่พบโครงการ (ลองเปลี่ยนปีงบ หรือคำค้นหา)</div>
      <?php else: ?>
        <?php foreach ($projects as $p): ?>
          <div class="proj">
            <div>
              <div class="name"><?= h((string)$p['detail_name']) ?></div>
              <div class="meta">id_detail: <span class="mono"><?= (int)$p['id_detail'] ?></span></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="text-muted small mt-3">
    หมายเหตุ: ตัวเลขทั้งหมดคำนวณจาก phases (join ผ่าน contracts → budget_detail → budget_items) ตามปีงบและคำค้นหา
  </div>

</div>
</body>
</html>
