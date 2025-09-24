<?php
require 'db.php';
session_start();

// รับค่าโครงการที่เลือก
$id_detail = isset($_GET['id_detail']) ? (int) $_GET['id_detail'] : 0;

// เพิ่มข้อมูลใหม่
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

// อัปเดตข้อมูล
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

// ลบข้อมูล
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM project_steps WHERE id = :id");
    $stmt->execute([':id' => $_GET['delete']]);
    header("Location: steps_edit.php?id_detail=".$id_detail);
    exit;
}

// ดึงรายการขั้นตอน
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
</head>
<body class="container py-4">

    <h2 class="mb-4">📌 จัดการขั้นตอนโครงการ</h2>

    <!-- ฟอร์มเพิ่มขั้นตอน -->
    <div class="card mb-4">
        <div class="card-header">เพิ่มขั้นตอนใหม่</div>
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

    <!-- ตารางขั้นตอน -->
    <div class="card">
        <div class="card-header">ขั้นตอนทั้งหมด</div>
        <div class="card-body">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ลำดับ</th>
                        <th>ชื่อขั้นตอน</th>
                        <th>วันที่</th>
                        <th>สถานะ</th>
                        <th width="200">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $step): ?>
                    <tr>
                        <form method="post">
                            <td>
                                <input type="number" name="step_order" value="<?= $step['step_order'] ?>" class="form-control" style="width:80px">
                            </td>
                            <td>
                                <input type="text" name="step_name" value="<?= htmlspecialchars($step['step_name']) ?>" class="form-control">
                            </td>
                            <td>
                                <input type="date" name="step_date" value="<?= $step['step_date'] ?>" class="form-control">
                            </td>
                            <td>
                                <input type="checkbox" name="is_completed" <?= $step['is_completed'] ? 'checked' : '' ?>>
                                เสร็จแล้ว
                            </td>
                            <td>
                                <input type="hidden" name="id" value="<?= $step['id'] ?>">
                                <button type="submit" name="update" class="btn btn-primary btn-sm">บันทึก</button>
                                <a href="?id_detail=<?= $id_detail ?>&delete=<?= $step['id'] ?>" 
                                   onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบขั้นตอนนี้?')" 
                                   class="btn btn-danger btn-sm">ลบ</a>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <a href="index.php" class="btn btn-secondary">⬅ กลับหน้า Dashboard</a>
    </div>

</body>
</html>
