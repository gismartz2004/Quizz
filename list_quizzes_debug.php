<?php
require 'db.php';
try {
    $stmt = $pdo->query("SELECT id, titulo FROM quizzes ORDER BY id DESC");
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($quizzes, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
