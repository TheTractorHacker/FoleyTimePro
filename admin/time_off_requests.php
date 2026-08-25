<?php
ini_set('display_errors', 0);

session_start();
require_once '../auth/db.php';
require_once '../functions/leave_balance.php';

// Verify database connection
if (!isset($conn) || !$conn) {
    die("Database connection not established");
}

require_once '../api/services/NotificationService.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/csrf.php';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); die('Invalid CSRF token.'); }
    if (isset($_POST['approve'])) {
        $requestId = (int)$_POST['request_id'];
        $notes = $_POST['notes'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? 1;

        // Get request details before updating
        $stmt = $conn->prepare("SELECT EmployeeID, StartDate, EndDate FROM time_off_requests WHERE ID = ?");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();

        // Update request
        $stmt = $conn->prepare("UPDATE time_off_requests SET Status = 'Approved', ReviewedAt = NOW(), ReviewedBy = ?, ReviewNotes = ? WHERE ID = ?");
        $stmt->bind_param("isi", $adminId, $notes, $requestId);
        $stmt->execute();

        // Deduct leave balance
        if ($request) {
            $typeStmt = $conn->prepare("SELECT RequestType, StartDate, EndDate FROM time_off_requests WHERE ID=?");
            $typeStmt->bind_param("i", $requestId);
            $typeStmt->execute();
            $typeRow = $typeStmt->get_result()->fetch_assoc();
            if ($typeRow) {
                $hours = businessHoursBetween($typeRow['StartDate'], $typeRow['EndDate']);
                // leave_balances.LeaveType only tracks Vacation/Sick/Personal/Other —
                // migration 017 added 'Unpaid' to time_off_requests.RequestType
                // (for the Android app's new Unpaid option) deliberately WITHOUT
                // adding it here too, since unpaid time off has no accrued balance
                // to draw down. deductLeaveBalance()'s INSERT ... LeaveType=? was
                // still being called unconditionally for every approval, so any
                // Unpaid request crashed this whole request with a 500 the moment
                // it hit that enum mismatch (Status was already committed to
                // Approved by the UPDATE above before the crash, so the approval
                // itself took effect — only the confirmation and this ledger step
                // were lost). Vacation/Sick/Personal/Other keep deducting exactly
                // as before; only the type with no ledger row skips it.
                if (in_array($typeRow['RequestType'], ['Vacation', 'Sick', 'Personal', 'Other'], true)) {
                    // Explicit $year: deductLeaveBalance() defaults to the
                    // CURRENT calendar year when omitted, which is the year
                    // this approval happens to run in — not the year the
                    // leave was actually requested for. A request spanning a
                    // year boundary (or just approved a few days into the
                    // next year) would otherwise silently post its deduction
                    // against the wrong year's ledger.
                    $balanceYear = (int)date('Y', strtotime($typeRow['StartDate']));
                    deductLeaveBalance($conn, $request['EmployeeID'], $typeRow['RequestType'], $hours, $balanceYear);
                }
                $conn->prepare("UPDATE time_off_requests SET HoursRequested=? WHERE ID=?")->execute([$hours, $requestId]);
            }
        }

        // Send push notification
        if ($request) {
            $notificationService = new NotificationService($conn);
            $notificationService->sendTimeOffApproval(
                $request['EmployeeID'],
                date('M d', strtotime($request['StartDate'])),
                date('M d, Y', strtotime($request['EndDate']))
            );
        }

        $success = "Request approved successfully.";
    } elseif (isset($_POST['reject'])) {
        $requestId = (int)$_POST['request_id'];
        $notes = $_POST['notes'] ?? '';
        $adminId = $_SESSION['admin_id'] ?? 1;

        if (empty($notes)) {
            $error = "Rejection reason is required.";
        } else {
            // Get request details before updating
            $stmt = $conn->prepare("SELECT EmployeeID, StartDate, EndDate FROM time_off_requests WHERE ID = ?");
            $stmt->bind_param("i", $requestId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();

            // Update request
            $stmt = $conn->prepare("UPDATE time_off_requests SET Status = 'Rejected', ReviewedAt = NOW(), ReviewedBy = ?, ReviewNotes = ? WHERE ID = ?");
            $stmt->bind_param("isi", $adminId, $notes, $requestId);
            $stmt->execute();

            // Send push notification
            if ($request) {
                $notificationService = new NotificationService($conn);
                $notificationService->sendTimeOffRejection(
                    $request['EmployeeID'],
                    date('M d', strtotime($request['StartDate'])),
                    date('M d, Y', strtotime($request['EndDate'])),
                    $notes
                );
            }

            $success = "Request rejected successfully.";
        }
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'Pending';
$userFilter = $_GET['user_id'] ?? '';

// Build query
$query = "SELECT tor.*, u.FirstName, u.LastName, u.Email,
          CONCAT(admin.username) as ReviewedByEmail
          FROM time_off_requests tor
          JOIN users u ON tor.EmployeeID = u.ID
          LEFT JOIN admins admin ON tor.ReviewedBy = admin.ID
          WHERE 1=1";

$params = [];
$types = "";

if ($statusFilter && $statusFilter !== 'All') {
    $query .= " AND tor.Status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($userFilter) {
    $query .= " AND tor.EmployeeID = ?";
    $params[] = (int)$userFilter;
    $types .= "i";
}

$query .= " ORDER BY
    CASE tor.Status
        WHEN 'Pending' THEN 1
        WHEN 'Approved' THEN 2
        WHEN 'Rejected' THEN 3
    END,
    tor.RequestedAt DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all users for filter dropdown
$usersStmt = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY FirstName, LastName");
$users = $usersStmt->fetch_all(MYSQLI_ASSOC);

// Setup for header
$pageTitle = 'Time-Off Requests';
$headerTitle = 'Time-Off Requests Management';
?>
<?php include 'includes/header.php'; ?>


        <?php if (isset($error)): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="alert success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" class="filter-bar">
            <div class="form-group">
                <label>Status:</label>
                <select name="status">
                    <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <div class="form-group">
                <label>Employee:</label>
                <select name="user_id">
                    <option value="">All Employees</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['ID'] ?>" <?= $userFilter == $u['ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="time_off_requests.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>

        <!-- Requests Table -->
        <div class="card" style="padding:0;overflow:hidden">
        <table class="data-table" style="margin:0">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Reason</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No time-off requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <?php
                        $start = new DateTime($req['StartDate']);
                        $end   = new DateTime($req['EndDate']);
                        $days  = $start->diff($end)->days + 1;
                        $badgeClass = match($req['Status']) {
                            'Pending'  => 'badge-warning',
                            'Approved' => 'badge-success',
                            'Rejected' => 'badge-danger',
                            default    => ''
                        };
                        $empName = htmlspecialchars($req['FirstName'] . ' ' . $req['LastName']);
                        ?>
                        <tr>
                            <td>
                                <strong><?= $empName ?></strong>
                                <?php if ($req['Email']): ?>
                                <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($req['Email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;background:#f0f2f5;color:#374151">
                                    <?= htmlspecialchars($req['RequestType']) ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap">
                                <div><?= date('M d, Y', strtotime($req['StartDate'])) ?></div>
                                <?php if ($req['StartDate'] !== $req['EndDate']): ?>
                                <div style="font-size:11px;color:var(--text-muted)">to <?= date('M d, Y', strtotime($req['EndDate'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;font-weight:700"><?= $days ?></td>
                            <td style="max-width:200px;font-size:12px;color:var(--text-muted)">
                                <?= htmlspecialchars($req['Reason'] ?: '—') ?>
                            </td>
                            <td style="white-space:nowrap;font-size:12px;color:var(--text-muted)">
                                <?= date('M d, Y', strtotime($req['RequestedAt'])) ?>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($req['Status']) ?></span>
                                <?php if ($req['ReviewedAt']): ?>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                                    by <?= htmlspecialchars($req['ReviewedByEmail'] ?? 'Admin') ?><br>
                                    <?= date('M d', strtotime($req['ReviewedAt'])) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($req['ReviewNotes']): ?>
                                <div style="font-size:11px;color:var(--text-muted);font-style:italic;margin-top:2px;max-width:160px">
                                    <?= htmlspecialchars($req['ReviewNotes']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;white-space:nowrap">
                                <?php if ($req['Status'] === 'Pending'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="openReviewModal(<?= $req['ID'] ?>, 'approve', '<?= $empName ?>')">Approve</button>
                                    <button class="btn btn-sm btn-danger" onclick="openReviewModal(<?= $req['ID'] ?>, 'reject', '<?= $empName ?>')" style="margin-top:4px">Reject</button>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

<!-- Review Modal -->
<div id="reviewModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeReviewModal()">&times;</span>
        <h3 id="modalTitle">Review Request</h3>
        <p id="modalEmployeeName"></p>

        <form method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="request_id" id="modalRequestId">

            <label for="notes">Notes:</label>
            <textarea name="notes" id="modalNotes" rows="4"></textarea>
            <small id="notesHelp" style="color: #666;"></small>

            <div class="modal-actions">
                <button type="submit" name="approve" id="approveBtn" class="btn btn-primary" style="display: none;">
                    Approve Request
                </button>
                <button type="submit" name="reject" id="rejectBtn" class="btn btn-danger" style="display: none;">
                    Reject Request
                </button>
                <button type="button" class="btn" onclick="closeReviewModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>


<script>
function openReviewModal(requestId, action, employeeName) {
    const modal = document.getElementById('reviewModal');
    const title = document.getElementById('modalTitle');
    const employeeNameEl = document.getElementById('modalEmployeeName');
    const requestIdInput = document.getElementById('modalRequestId');
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');
    const notesHelp = document.getElementById('notesHelp');
    const notesField = document.getElementById('modalNotes');

    requestIdInput.value = requestId;
    employeeNameEl.textContent = `Employee: ${employeeName}`;

    if (action === 'approve') {
        title.textContent = 'Approve Time-Off Request';
        approveBtn.style.display = 'block';
        rejectBtn.style.display = 'none';
        notesHelp.textContent = 'Optional: Add notes about this approval';
        notesField.required = false;
    } else {
        title.textContent = 'Reject Time-Off Request';
        approveBtn.style.display = 'none';
        rejectBtn.style.display = 'block';
        notesHelp.textContent = 'Required: Explain why this request is being rejected';
        notesField.required = true;
    }

    notesField.value = '';
    modal.style.display = 'block';
}

function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('reviewModal');
    if (event.target === modal) {
        closeReviewModal();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
