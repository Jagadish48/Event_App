<?php
/**
 * API: Dashboard Summary
 * GET /api/dashboard         — Summary stats for current user (role-aware)
 * GET /api/dashboard/admin   — Admin-specific summary
 * GET /api/dashboard/employee — Employee-specific summary
 */

$user = requireAuth();
$role = normalizeUserRole($user['role'] ?? 'employee');
$userId = (int) $user['id'];

$action = $segments[1] ?? '';

try {
    if ($role === 'admin' || $action === 'admin') {
        // Admin summary
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        // Employee count
        $empCount = (int) $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();

        // Attendance today
        $stmt = $pdo->prepare("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN check_in IS NOT NULL AND check_out IS NULL THEN 1 ELSE 0 END) as checked_in,
            SUM(CASE WHEN check_in IS NOT NULL AND check_out IS NOT NULL THEN 1 ELSE 0 END) as checked_out,
            SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) as late
            FROM attendance WHERE date = ?");
        $stmt->execute([$today]);
        $attendanceToday = $stmt->fetch();

        // Pending expenses
        $pendingExpenses = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status = 'pending'")->fetchColumn();
        $pendingExpensesAmount = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'pending'")->fetchColumn();

        // Pending profile requests
        $pendingProfileReqs = 0;
        try {
            $pendingProfileReqs = (int) $pdo->query("SELECT COUNT(*) FROM profile_requests WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) {}

        // Pending password requests
        $pendingPwdReqs = 0;
        try {
            $pendingPwdReqs = (int) $pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) {}

        // Leave requests
        $pendingLeaves = 0;
        try {
            $pendingLeaves = (int) $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) {}

        // Events this month
        $events = 0;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE DATE_FORMAT(event_date, '%Y-%m') = ?");
            $stmt->execute([$thisMonth]);
            $events = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        apiResponse([
            'role'           => 'admin',
            'date'           => $today,
            'month'          => $thisMonth,
            'employees'      => $empCount,
            'events_month'   => $events,
            'attendance_today' => [
                'checked_in'  => (int) ($attendanceToday['checked_in'] ?? 0),
                'checked_out' => (int) ($attendanceToday['checked_out'] ?? 0),
                'absent'      => (int) ($attendanceToday['absent'] ?? 0),
                'late'        => (int) ($attendanceToday['late'] ?? 0),
            ],
            'pending_approvals' => [
                'expenses'         => $pendingExpenses,
                'expenses_amount'  => round($pendingExpensesAmount, 2),
                'profile_requests' => $pendingProfileReqs,
                'password_resets'  => $pendingPwdReqs,
                'leave_requests'   => $pendingLeaves,
                'total'            => $pendingExpenses + $pendingProfileReqs + $pendingPwdReqs + $pendingLeaves,
            ],
        ]);
    } else {
        // Employee summary
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        // Today's attendance
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId, $today]);
        $todayAtt = $stmt->fetch();

        // Monthly summary
        $summary = getMonthlyPolicySummary($userId, $thisMonth);

        // Pending expenses
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $myPendingExpenses = (int) $stmt->fetchColumn();

        // Pending leave requests
        $myPendingLeaves = 0;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'pending'");
            $stmt->execute([$userId]);
            $myPendingLeaves = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {}

        apiResponse([
            'role'   => 'employee',
            'date'   => $today,
            'month'  => $thisMonth,
            'today_attendance' => [
                'check_in'   => $todayAtt['check_in'] ?? null,
                'check_out'  => $todayAtt['check_out'] ?? null,
                'status'     => $todayAtt['attendance_status'] ?? 'not_started',
            ],
            'monthly_summary' => [
                'present_days'    => $summary['present_days'] ?? 0,
                'absent_days'     => $summary['absent_days'] ?? 0,
                'late_days'       => $summary['late_days'] ?? 0,
                'weekly_offs'     => $summary['weekly_offs'] ?? 0,
                'approved_leaves' => $summary['approved_leaves'] ?? 0,
                'remaining_leaves'=> $summary['remaining_leaves'] ?? 0,
            ],
            'pending_items' => [
                'expenses'     => $myPendingExpenses,
                'leave_requests' => $myPendingLeaves,
            ],
        ]);
    }
} catch (PDOException $e) {
    apiError('Database error.', 500);
}
