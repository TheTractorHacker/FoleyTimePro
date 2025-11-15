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

$action = $input['action'] ?? '';

switch ($action) {
    case 'delete':
        $punchId = $input['id'] ?? null;
        if ($punchId) {
            $stmt = $conn->prepare("DELETE FROM timepunches WHERE id = ?");
            $stmt->bind_param("i", $punchId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $response = ['success' => true, 'message' => 'Punch deleted successfully.'];
                } else {
                    $response = ['success' => false, 'message' => 'Punch not found or already deleted.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
            }
            $stmt->close();
        } else {
            $response['message'] = 'Punch ID not provided for deletion.';
        }
        break;

    case 'save':
        $punches = $input['punches'] ?? [];
        $errors = [];
        $successCount = 0;

        foreach ($punches as $punch) {
            $id = $punch['id'] ?? null;
            $date = $punch['date'] ?? null;
            $timeIn = $punch['time_in'] ?? null;
            $lunchOut = $punch['lunch_out'] ?? null;
            $lunchIn = $punch['lunch_in'] ?? null;
            $timeOut = $punch['time_out'] ?? null;
            $totalHours = $punch['total_hours'] ?? '0.00';

            // Basic validation
            if (!$date) {
                $errors[] = 'Date is required for a punch.';
                continue;
            }

            // Convert total_hours from HH:MM to decimal if necessary
            if (strpos($totalHours, ':') !== false) {
                list($h, $m) = explode(':', $totalHours);
                $totalHours = (float)$h + ((float)$m / 60);
            }

            if ($id) {
                // Update existing punch
                $stmt = $conn->prepare("UPDATE timepunches SET Date = ?, TimeIN = ?, LunchStart = ?, LunchEnd = ?, TimeOut = ?, TotalHours = ? WHERE id = ?");
                $stmt->bind_param("sssssdi", $date, $timeIn, $lunchOut, $lunchIn, $timeOut, $totalHours, $id);
            } else {
                // Insert new punch
                $employeeID = $input['emp'] ?? null; // Get emp from JSON payload

                if (!$employeeID) {
                    $errors[] = 'Employee ID not provided for new punch.';
                    continue;
                }

                $stmt = $conn->prepare("INSERT INTO timepunches (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, TotalHours) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssssd", $employeeID, $date, $timeIn, $lunchOut, $lunchIn, $timeOut, $totalHours);
            }

            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errors[] = 'Error processing punch (ID: ' . ($id ?? 'new') . '): ' . $stmt->error;
            }
            $stmt->close();
        }

        if (empty($errors)) {
            $response = ['success' => true, 'message' => 'All changes saved successfully.', 'saved_count' => $successCount];
        } else {
            $response = ['success' => false, 'message' => 'Some errors occurred: ' . implode('; ', $errors), 'saved_count' => $successCount, 'errors' => $errors];
        }
        break;

    default:
        $response['message'] = 'Invalid action.';
        break;
}

echo json_encode($response);

$conn->close();
?>
