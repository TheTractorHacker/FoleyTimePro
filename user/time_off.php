<?php
// Start session and check auth BEFORE including header
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../auth/db.php';

$userId = $_SESSION['EmployeeID'] ?? null;

if (!$userId) {
    header('Location: ../error.php?code=401&message=' . urlencode('Employee ID not found. Please log in again.'));
    exit;
}

// Handle new request submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_request'])) {
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $type = $_POST['request_type'] ?? '';
        $reason = $_POST['reason'] ?? '';

        // Validate required fields
        if (empty($startDate) || empty($endDate) || empty($type)) {
            $_SESSION['timeoff_message'] = 'Please fill in all required fields.';
            $_SESSION['timeoff_message_type'] = 'error';
            header('Location: time_off.php');
            exit;
        }

        // Validate dates
        $start = strtotime($startDate);
        $end = strtotime($endDate);

        if (!$start || !$end) {
            $_SESSION['timeoff_message'] = 'Invalid date format.';
            $_SESSION['timeoff_message_type'] = 'error';
        } elseif ($end < $start) {
            $_SESSION['timeoff_message'] = 'End date must be after start date.';
            $_SESSION['timeoff_message_type'] = 'error';
        } else {
            // Check for overlapping requests
            $stmt = $conn->prepare("
                SELECT ID FROM time_off_requests
                WHERE EmployeeID = ?
                AND Status IN ('Pending', 'Approved')
                AND (
                    (StartDate <= ? AND EndDate >= ?)
                    OR (StartDate <= ? AND EndDate >= ?)
                    OR (StartDate >= ? AND EndDate <= ?)
                )
            ");
            $stmt->bind_param("issssss", $userId, $endDate, $startDate, $endDate, $startDate, $startDate, $endDate);
            $stmt->execute();

            if ($stmt->get_result()->num_rows > 0) {
                $_SESSION['timeoff_message'] = 'You already have a time-off request for this date range.';
                $_SESSION['timeoff_message_type'] = 'error';
            } else {
                // Insert request
                $stmt = $conn->prepare("
                    INSERT INTO time_off_requests (EmployeeID, StartDate, EndDate, RequestType, Reason, Status, RequestedAt)
                    VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
                ");

                if (!$stmt) {
                    $_SESSION['timeoff_message'] = 'Database error: ' . $conn->error;
                    $_SESSION['timeoff_message_type'] = 'error';
                } else {
                    $stmt->bind_param("issss", $userId, $startDate, $endDate, $type, $reason);

                    if ($stmt->execute()) {
                        $_SESSION['timeoff_message'] = 'Time-off request submitted successfully! Awaiting approval.';
                        $_SESSION['timeoff_message_type'] = 'success';
                    } else {
                        $_SESSION['timeoff_message'] = 'Failed to submit request: ' . $stmt->error;
                        $_SESSION['timeoff_message_type'] = 'error';
                    }
                }
            }
        }
        
        // Redirect to prevent form resubmission
        header('Location: time_off.php');
        exit;
        
    } elseif (isset($_POST['cancel_request'])) {
        $requestId = (int)$_POST['request_id'];

        // Verify ownership and status
        $stmt = $conn->prepare("SELECT Status FROM time_off_requests WHERE ID = ? AND EmployeeID = ?");
        $stmt->bind_param("ii", $requestId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $request = $result->fetch_assoc();
            if ($request['Status'] === 'Pending') {
                $stmt = $conn->prepare("DELETE FROM time_off_requests WHERE ID = ?");
                $stmt->bind_param("i", $requestId);
                if ($stmt->execute()) {
                    $_SESSION['timeoff_message'] = 'Request cancelled successfully.';
                    $_SESSION['timeoff_message_type'] = 'success';
                } else {
                    $_SESSION['timeoff_message'] = 'Failed to cancel request.';
                    $_SESSION['timeoff_message_type'] = 'error';
                }
            } else {
                $_SESSION['timeoff_message'] = 'Only pending requests can be cancelled.';
                $_SESSION['timeoff_message_type'] = 'error';
            }
        }
        
        // Redirect to prevent form resubmission
        header('Location: time_off.php');
        exit;
    }
}

// Now include header after POST processing
$pageTitle = 'Time Off';
require_once 'header.php';

// Check for messages in session
$message = $_SESSION['timeoff_message'] ?? '';
$messageType = $_SESSION['timeoff_message_type'] ?? '';
unset($_SESSION['timeoff_message'], $_SESSION['timeoff_message_type']);

// Get user's requests
$stmt = $conn->prepare("
    SELECT ID, StartDate, EndDate, RequestType, Reason, Status, RequestedAt, ReviewedAt, ReviewNotes
    FROM time_off_requests
    WHERE EmployeeID = ?
    ORDER BY RequestedAt DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>


<div class="u-page-header"><h1>Time Off Requests</h1></div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Request Form -->
    <div class="u-card">
        <h2>New Request</h2>
        <form method="POST">
            <div class="u-form-row" style="flex-wrap:wrap">
                <div class="u-form-group">
                    <label for="start_date">Start Date *</label>
                    <input type="date" name="start_date" id="start_date" required min="<?= date('Y-m-d') ?>">
                </div>

                <div class="u-form-group">
                    <label for="end_date">End Date *</label>
                    <input type="date" name="end_date" id="end_date" required min="<?= date('Y-m-d') ?>">
                </div>

                <div class="u-form-group">
                    <label for="request_type">Request Type *</label>
                    <select name="request_type" id="request_type" required>
                        <option value="">Select Type</option>
                        <option value="Vacation">Vacation</option>
                        <option value="Sick">Sick Leave</option>
                        <option value="Personal">Personal</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="u-form-group" style="margin-bottom: 1.5rem;">
                <label for="reason">Reason (Optional)</label>
                <textarea name="reason" id="reason" rows="3" placeholder="Add any additional details about your request..."></textarea>
            </div>

            <button type="submit" name="submit_request" class="u-btn u-btn-primary">Submit Request</button>
        </form>
    </div>

    <!-- Requests History -->
    <div class="u-card" style="padding:0;overflow:hidden">
        <table class="u-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:40px;color:#9ca3af;font-size:13px">No time-off requests yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <?php
                        $start = new DateTime($req['StartDate']);
                        $end = new DateTime($req['EndDate']);
                        $days = $start->diff($end)->days + 1;

                        $statusClass = match($req['Status']) {
                            'Pending' => 'status-pending',
                            'Approved' => 'status-approved',
                            'Rejected' => 'status-rejected',
                            default => ''
                        };
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($req['RequestType']) ?></td>
                            <td><?= date('M d, Y', strtotime($req['StartDate'])) ?></td>
                            <td><?= date('M d, Y', strtotime($req['EndDate'])) ?></td>
                            <td><?= $days ?> day<?= $days > 1 ? 's' : '' ?></td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($req['Status']) ?>
                                </span>
                                <?php if ($req['ReviewedAt']): ?>
                                    <br><small style="color: #666;">Reviewed: <?= date('M d, Y', strtotime($req['ReviewedAt'])) ?></small>
                                <?php endif; ?>
                                <?php if ($req['ReviewNotes']): ?>
                                    <br><small style="color: #666; font-style: italic;">
                                        Note: <?= htmlspecialchars($req['ReviewNotes']) ?>
                                    </small>
                                <?php endif; ?>
                                <?php if ($req['Reason']): ?>
                                    <br><small style="color: #666;">
                                        Your note: <?= htmlspecialchars($req['Reason']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($req['RequestedAt'])) ?></td>
                            <td>
                                <?php if ($req['Status'] === 'Pending'): ?>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to cancel this request?');">
                                        <input type="hidden" name="request_id" value="<?= $req['ID'] ?>">
                                        <button type="submit" name="cancel_request" class="u-btn u-btn-danger u-btn-sm">
                                            Cancel
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<script>
// Auto-update end date when start date changes
document.getElementById('start_date').addEventListener('change', function() {
    const endDateInput = document.getElementById('end_date');
    if (!endDateInput.value || new Date(endDateInput.value) < new Date(this.value)) {
        endDateInput.value = this.value;
    }
    endDateInput.min = this.value;
});
</script>

<?php include 'footer.php'; ?>
