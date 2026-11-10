<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "HR Reports & Analytics";
require_once "../partials/header.php";

$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');

// Calculate HR metrics
$metrics = [
    'total_employees' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status=1")->fetchColumn(),
    'new_hires' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE YEAR(date_of_joining) = $year AND status=1")->fetchColumn(),
    'avg_attendance' => $pdo->query("SELECT AVG(CASE WHEN a.status='present' OR " . attendance_paid_leave_predicate('a', 'e') . " THEN 1 ELSE 0 END) * 100 FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE YEAR(a.attendance_date) = $year AND MONTH(a.attendance_date) = $month AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)")->fetchColumn() ?: 0,
    'total_leave_days' => (int) $pdo->query("SELECT SUM(total_days) FROM leave_requests WHERE YEAR(from_date) = $year AND status='approved'")->fetchColumn() ?: 0,
    'avg_salary' => (float) $pdo->query("SELECT AVG(basic_salary) FROM employees WHERE status=1")->fetchColumn() ?: 0,
    'overtime_hours' => (float) $pdo->query("SELECT SUM(hours) FROM overtime_requests WHERE YEAR(overtime_date) = $year AND status='approved'")->fetchColumn() ?: 0,
];

// Department distribution
$departments = $pdo->query("SELECT COALESCE(NULLIF(department, ''), 'Unassigned') AS department, COUNT(*) AS count FROM employees WHERE status=1 GROUP BY department ORDER BY count DESC")->fetchAll();

// Attendance trend (last 6 months)
$attendance_trend = [];
for($i = 5; $i >= 0; $i--) {
    $trend_month = date('m', strtotime("-$i months"));
    $trend_year = date('Y', strtotime("-$i months"));
    $present = (int) $pdo->query("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date) = $trend_month AND YEAR(a.attendance_date) = $trend_year AND (a.status='present' OR " . attendance_paid_leave_predicate('a', 'e') . ") AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)")->fetchColumn();
    $total = (int) $pdo->query("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date) = $trend_month AND YEAR(a.attendance_date) = $trend_year AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)")->fetchColumn();
    $attendance_trend[] = [
        'month' => date('M Y', strtotime("$trend_year-$trend_month-01")),
        'rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0
    ];
}

// Leave statistics
$leave_stats = $pdo->query("SELECT leave_type, COUNT(*) as count, SUM(total_days) as total_days FROM leave_requests WHERE YEAR(from_date) = $year AND status='approved' GROUP BY leave_type")->fetchAll();

// Training completion
$training_stats = $pdo->query("SELECT COUNT(CASE WHEN status='completed' THEN 1 END) as completed, COUNT(*) as total FROM employee_training WHERE YEAR(enrollment_date) = $year")->fetch();

// Performance distribution
$performance_dist = $pdo->query("SELECT 
    COUNT(CASE WHEN overall_rating >= 4.5 THEN 1 END) as excellent,
    COUNT(CASE WHEN overall_rating >= 3.5 AND overall_rating < 4.5 THEN 1 END) as good,
    COUNT(CASE WHEN overall_rating >= 2.5 AND overall_rating < 3.5 THEN 1 END) as satisfactory,
    COUNT(CASE WHEN overall_rating < 2.5 THEN 1 END) as needs_improvement
    FROM performance_reviews WHERE YEAR(review_date) = $year")->fetch();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>📊 HR Reports & Analytics</h1>
    <form method="get" style="display:flex; gap:10px; align-items:center;">
        <select name="month" onchange="this.form.submit()" style="padding:8px; border:1px solid #ddd; border-radius:6px;">
            <?php for($m=1; $m<=12; $m++): ?>
            <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
        </select>
        <select name="year" onchange="this.form.submit()" style="padding:8px; border:1px solid #ddd; border-radius:6px;">
            <?php for($y=date('Y'); $y>=date('Y')-5; $y--): ?>
            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<!-- Key Metrics -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-bottom:20px;">
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#1c2250;"><?= number_format($metrics['total_employees']) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Total Employees</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#7a5cd6;"><?= number_format($metrics['new_hires']) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">New Hires (<?= $year ?>)</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#22b8cf;"><?= number_format($metrics['avg_attendance'], 1) ?>%</div>
        <div style="color:#646b8c; font-size:0.85rem;">Avg Attendance (<?= date('F', mktime(0,0,0,$month,1)) ?>)</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#4f4fc9;"><?= number_format($metrics['total_leave_days']) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Total Leave Days (<?= $year ?>)</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#64748b;"><?= e(currency_symbol()) . number_format($metrics['avg_salary'], 0) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Avg Salary</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#d97706;"><?= number_format($metrics['overtime_hours'], 1) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Overtime Hours (<?= $year ?>)</div>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px;">
    <!-- Department Distribution -->
    <div class="panel">
        <h3>Department Distribution</h3>
        <?php if(!$departments): ?>
        <p>No department data available.</p>
        <?php else: ?>
        <?php foreach($departments as $dept): ?>
        <div style="margin-bottom:15px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                <span><?= htmlspecialchars($dept['department']) ?></span>
                <strong><?= $dept['count'] ?></strong>
            </div>
            <div style="background:#e5e7eb; height:20px; border-radius:10px;">
                <div style="background:#4f4fc9; height:100%; border-radius:10px; width:<?= ($dept['count'] / $metrics['total_employees']) * 100 ?>%;"></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Attendance Trend -->
    <div class="panel">
        <h3>Attendance Trend (Last 6 Months)</h3>
        <div style="margin-top:15px;">
            <?php foreach($attendance_trend as $trend): ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <span><?= $trend['month'] ?></span>
                    <strong><?= $trend['rate'] ?>%</strong>
                </div>
                <div style="background:#e5e7eb; height:20px; border-radius:10px;">
                    <div style="background:<?= $trend['rate'] >= 90 ? '#10b981' : ($trend['rate'] >= 75 ? '#3b82f6' : '#f59e0b') ?>; height:100%; border-radius:10px; width:<?= $trend['rate'] ?>%;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px;">
    <!-- Leave Statistics -->
    <div class="panel">
        <h3>Leave Statistics (<?= $year ?>)</h3>
        <?php if(!$leave_stats): ?>
        <p>No leave data available.</p>
        <?php else: ?>
        <table style="width:100%; margin-top:10px;">
            <thead>
                <tr>
                    <th>Leave Type</th>
                    <th>Requests</th>
                    <th>Total Days</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($leave_stats as $stat): ?>
                <tr>
                    <td><?= htmlspecialchars($stat['leave_type']) ?></td>
                    <td><?= $stat['count'] ?></td>
                    <td><?= $stat['total_days'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Training Completion -->
    <div class="panel">
        <h3>Training Completion (<?= $year ?>)</h3>
        <?php if($training_stats['total'] > 0): ?>
        <div style="margin-top:15px;">
            <div style="margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <span>Completed</span>
                    <strong><?= $training_stats['completed'] ?> / <?= $training_stats['total'] ?></strong>
                </div>
                <div style="background:#e5e7eb; height:20px; border-radius:10px;">
                    <div style="background:#10b981; height:100%; border-radius:10px; width:<?= ($training_stats['completed'] / $training_stats['total']) * 100 ?>%;"></div>
                </div>
            </div>
            <div style="text-align:center; margin-top:10px;">
                <span style="font-size:1.5rem; font-weight:bold; color:#10b981;"><?= round(($training_stats['completed'] / $training_stats['total']) * 100, 1) ?>%</span>
                <div style="color:#646b8c; font-size:0.85rem;">Completion Rate</div>
            </div>
        </div>
        <?php else: ?>
        <p>No training data available.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Performance Distribution -->
<div class="panel">
    <h3>Performance Distribution (<?= $year ?>)</h3>
    <?php if(array_sum($performance_dist) > 0): ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f0fdf4; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#10b981;"><?= $performance_dist['excellent'] ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Excellent (4.5+)</div>
        </div>
        <div style="text-align:center; padding:15px; background:#eff6ff; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#3b82f6;"><?= $performance_dist['good'] ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Good (3.5-4.4)</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fffbeb; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#f59e0b;"><?= $performance_dist['satisfactory'] ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Satisfactory (2.5-3.4)</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef2f2; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#ef4444;"><?= $performance_dist['needs_improvement'] ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Needs Improvement (<2.5)</div>
        </div>
    </div>
    <?php else: ?>
    <p>No performance data available.</p>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
