<?php
include_once '../auth/db.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $response['message'] = 'Invalid JSON input.';
    echo json_encode($response);
    exit();
}

$punchId = $input['id'] ?? null;
$fieldsToDelete = $input['fields'] ?? [];

if (!$punchId || empty($fieldsToDelete)) {
    $response['message'] = 'Missing punch ID or fields to delete.';
    echo json_encode($response);
    exit();
}

$updateFields = [];
foreach ($fieldsToDelete as $field) {
    // Basic validation to prevent SQL injection for column names
    if (in_array($field, ['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut'])) {
        $updateFields[] = "`" . $field . "` = NULL";
    }
}

if (empty($updateFields)) {
    $response['message'] = 'No valid fields to delete.';
    echo json_encode($response);
    exit();
}

$sql = "UPDATE timepunches SET " . implode(", ", $updateFields) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $punchId);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        $response = ['success' => true, 'message' => 'Selected punch data deleted successfully.'];
    } else {
        $response = ['success' => false, 'message' => 'Punch not found or no changes made.'];
    }
} else {
    $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
}

$stmt->close();
$conn->close();

echo json_encode($response);
?>
