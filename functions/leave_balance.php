<?php
/**
 * Leave balance helpers
 */

function getLeaveBalance(mysqli $conn, int $employeeId, string $leaveType, int $year = 0): array
{
    if (!$year) $year = (int)date('Y');
    $stmt = $conn->prepare(
        "SELECT AccruedHours, UsedHours, CarryoverHours FROM leave_balances
         WHERE EmployeeID=? AND LeaveType=? AND YearNumber=?"
    );
    $stmt->bind_param("isi", $employeeId, $leaveType, $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return ['accrued'=>0,'used'=>0,'carryover'=>0,'available'=>0];
    $avail = $row['AccruedHours'] + $row['CarryoverHours'] - $row['UsedHours'];
    return ['accrued'=>(float)$row['AccruedHours'],'used'=>(float)$row['UsedHours'],'carryover'=>(float)$row['CarryoverHours'],'available'=>max(0,$avail)];
}

function getAllLeaveBalances(mysqli $conn, int $employeeId, int $year = 0): array
{
    if (!$year) $year = (int)date('Y');
    $types = ['Vacation','Sick','Personal','Other'];
    $result = [];
    foreach ($types as $t) { $result[$t] = getLeaveBalance($conn, $employeeId, $t, $year); }
    return $result;
}

function deductLeaveBalance(mysqli $conn, int $employeeId, string $leaveType, float $hours, int $year = 0): void
{
    if (!$year) $year = (int)date('Y');
    $stmt = $conn->prepare(
        "INSERT INTO leave_balances (EmployeeID,LeaveType,YearNumber,UsedHours)
         VALUES(?,?,?,?)
         ON DUPLICATE KEY UPDATE UsedHours = UsedHours + VALUES(UsedHours)"
    );
    $stmt->bind_param("isid", $employeeId, $leaveType, $year, $hours);
    $stmt->execute();
}

function restoreLeaveBalance(mysqli $conn, int $employeeId, string $leaveType, float $hours, int $year = 0): void
{
    if (!$year) $year = (int)date('Y');
    $stmt = $conn->prepare(
        "UPDATE leave_balances SET UsedHours = GREATEST(0, UsedHours - ?)
         WHERE EmployeeID=? AND LeaveType=? AND YearNumber=?"
    );
    $stmt->bind_param("disi", $hours, $employeeId, $leaveType, $year);
    $stmt->execute();
}

/** Business days between two dates (Mon–Fri, 8h/day). */
function businessHoursBetween(string $start, string $end): float
{
    $s = new DateTime($start); $e = new DateTime($end);
    $days = 0;
    while ($s <= $e) {
        $dow = (int)$s->format('N');
        if ($dow < 6) $days++;
        $s->modify('+1 day');
    }
    return $days * 8.0;
}
