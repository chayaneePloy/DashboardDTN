<?php
require 'db.php';
session_start();

$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

// ------------------ เพิ่มข้อมูลใหม่ ------------------
if (isset($_POST['add'])) {
    $stmt = $pdo->prepare("
        INSERT INTO project_steps (id_budget_detail, step_name, step_order, step_date, is_completed) 
        VALUES (:id_detail, :step_name, :step_order, :step_date, 0)
    ");
    $stmt->execute([
        ':id_detail' => $id_detail,
        ':step_name' => $_POST['step_name'],
        ':step_order' => $_POST['step_order'],
        ':step_date' => $_POST['step_date']
    ]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ อัปเดตทีละแถว ------------------
if (isset($_POST['update'])) {
    $stmt = $pdo->prepare("
        UPDATE project_steps 
        SET step_name = :step_name, step_order = :step_order, step_date = :step_date, is_completed = :is_completed 
        WHERE id = :id
    ");
    $stmt->execute([
        ':step_name' => $_POST['step_name'],
        ':step_order' => $_POST['step_order'],
        ':step_date' => $_POST['step_date'],
        ':is_completed' => isset($_POST['is_completed']) ? 1 : 0,
        ':id' => $_POST['id']
    ]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ ลบข้อมูล ------------------
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM project_steps WHERE id = :id");
    $stmt->execute([':id' => $_GET['delete']]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ อัปเดตหลายแถว (บันทึกทั้งหมด) ------------------
if (isset($_POST['save_all'])) {
    if (isset($_POST['steps']) && is_array($_POST['steps'])) {
        $stmt = $pdo->prepare("
            UPDATE project_steps 
            SET step_name = :step_name, step_order = :step_order, step_date = :step_date, is_completed = :is_completed
            WHERE id = :id
        ");
        foreach ($_POST['steps'] as $step) {
            $stmt->execute([
                ':step_name' => $step['step_name'],
                ':step_order' => $step['step_order'],
                ':step_date' => $step['step_date'],
                ':is_completed' => isset($step['is_completed']) ? 1 : 0,
                ':id' => $step['id']
            ]);
        }
    }
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ------------------ ดึงรายการขั้นตอน ------------------
$stmt = $pdo->prepare("SELECT * FROM project_steps WHERE id_budget_detail = :id_detail ORDER BY step_order ASC");
$stmt->execute([':id_detail' => $id_detail]);
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการขั้นตอนโครงการ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Sarabun', sans-serif; background:#f7f9fc; }
        table input[type="text"], table input[type="number"], table input[type="date"] {
            width: 100%; font-size: 0.9rem;
        }
    </style>
</head>
<body class="container py-4">

    <h2 class="mb-4">📌 จัดการขั้นตอนโครงการ</h2>

    <!-- ฟอร์มเพิ่มขั้นตอนใหม่ -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-success text-white fw-bold">➕ เพิ่มขั้นตอนใหม่</div>
        <div class="card-body">
            <form method="post">
                <div class="row g-2">
                    <div class="col-md-3">
                        <input type="text" name="step_name" class="form-control" placeholder="ชื่อขั้นตอน" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="step_order" class="form-control" placeholder="ลำดับ" required>
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="step_date" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add" class="btn btn-success w-100">เพิ่ม</button>
                    </div>
                </div>
            </form>
        </div>
    </div> 

    <!-- ฟอร์มหลัก (สำหรับบันทึกทั้งหมด) -->
    <form method="post">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
            <span>🧾 ขั้นตอนทั้งหมด</span>
            <button type="submit" name="save_all" class="btn btn-warning btn-sm">💾 บันทึกทั้งหมด</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered align-middle m-0">
                <thead class="table-light">
                    <tr>
                        <th>ลำดับ</th>
                        <th>ชื่อขั้นตอน</th>
                        <th>วันที่</th>
                        <th>สถานะ</th>
                        <th width="180">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($steps): foreach ($steps as $index => $step): ?>
                    <tr>
                        <td>
                            <input type="number" name="steps[<?= $index ?>][step_order]" 
                                   value="<?= $step['step_order'] ?>" class="form-control text-center">
                        </td>
                        <td>
                            <input type="text" name="steps[<?= $index ?>][step_name]" 
                                   value="<?= htmlspecialchars($step['step_name']) ?>" class="form-control">
                        </td>
                        <td>
                            <input type="date" name="steps[<?= $index ?>][step_date]" 
                                   value="<?= $step['step_date'] ?>" class="form-control">
                        </td>
                        <td class="text-center">
                            <input type="checkbox" name="steps[<?= $index ?>][is_completed]" 
                                   <?= $step['is_completed'] ? 'checked' : '' ?>> เสร็จแล้ว
                        </td>
                        <td class="text-center">
                            <input type="hidden" name="steps[<?= $index ?>][id]" value="<?= $step['id'] ?>">
                            <a href="?id_detail=<?= $id_detail ?>&delete=<?= $step['id'] ?>" 
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบขั้นตอนนี้?')">ลบ</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted">ยังไม่มีขั้นตอนในโครงการนี้</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </form>

    <div class="mt-4">
        <a href="steps.php?id_detail=<?= urlencode($id_detail) ?>" class="btn btn-secondary">⬅ กลับหน้า Dashboard</a>
    </div>

</body>
</html>
