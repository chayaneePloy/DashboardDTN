<?php
$pdo = new PDO("mysql:host=localhost;dbname=budget_dtn;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = $_POST['id'];
$step_name = $_POST['step_name'];
$step_date = $_POST['step_date'];
$step_description = $_POST['step_description'];
$sub_steps = $_POST['sub_steps'];
$is_completed = isset($_POST['is_completed']) ? 1 : 0;

// อัปโหลดไฟล์
$document_path = null;
if (!empty($_FILES['document']['name'])) {
    $filename = time() . "_" . basename($_FILES['document']['name']);
    $target = "documents/" . $filename;
    if (move_uploaded_file($_FILES['document']['tmp_name'], $target)) {
        $document_path = $filename;
    }
}

// Update ข้อมูล
if ($document_path) {
    $sql = "UPDATE project_steps SET step_name=?, step_date=?, step_description=?, sub_steps=?, document_path=?, is_completed=? WHERE id=?";
    $params = [$step_name, $step_date, $step_description, $sub_steps, $document_path, $is_completed, $id];
} else {
    $sql = "UPDATE project_steps SET step_name=?, step_date=?, step_description=?, sub_steps=?, is_completed=? WHERE id=?";
    $params = [$step_name, $step_date, $step_description, $sub_steps, $is_completed, $id];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// redirect กลับ
header("Location: index.php?id_detail=" . $_GET['id_detail']);
exit;
?>
