<?php
require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// รับข้อมูลจากฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step_name = $_POST['step_name'];
    $step_description = $_POST['step_description'];
    $step_date = $_POST['step_date'];
    $sub_steps = $_POST['sub_steps'] ?? '';
    $is_completed = isset($_POST['is_completed']) ? 1 : 0;
    
    // อัปโหลดเอกสาร
    $document_path = '';
    if(isset($_FILES['document'])) {
        $target_dir = "documents/";
        $target_file = $target_dir . basename($_FILES["document"]["name"]);
        move_uploaded_file($_FILES["document"]["tmp_name"], $target_file);
        $document_path = basename($_FILES["document"]["name"]);
    }
    
    // บันทึกลงฐานข้อมูล
    $sql = "INSERT INTO project_steps (step_name, step_description, step_date, sub_steps, is_completed, document_path)
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssis", $step_name, $step_description, $step_date, $sub_steps, $is_completed, $document_path);
    $stmt->execute();
    
    header("Location: index.php");
    exit();
}
?>