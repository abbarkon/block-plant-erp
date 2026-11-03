<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

// Create performance_reviews table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS performance_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    review_period VARCHAR(20) NOT NULL,
    review_date DATE NOT NULL,
    reviewer_id INT NOT NULL,
    overall_rating DECIMAL(3,2) NOT NULL,
    strengths TEXT,
    areas_for_improvement TEXT,
    goals TEXT,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (reviewer_id) REFERENCES users(id)
)");

$employee_id = $_GET['employee_id'] ?? null;
$period = $_GET['period'] ?? date('Y-m');

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to add performance reviews.');
    }
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    require_active_employee($employeeId);
    $period = trim((string) ($_POST['review_period'] ?? ''));
    $reviewDate = trim((string) ($_POST['review_date'] ?? ''));
    $rating = round((float) ($_POST['overall_rating'] ?? 0), 2);
    $strengths = trim((string) ($_POST['strengths'] ?? ''));
    $improvements = trim((string) ($_POST['areas_for_improvement'] ?? ''));
    $goals = trim((string) ($_POST['goals'] ?? ''));
    $comments = trim((string) ($_POST['comments'] ?? ''));
    if ($period === '' || mb_strlen($period) > 20 || !is_valid_date_string($reviewDate) || $rating < 0 || $rating > 5) {
        exit('Enter a valid review period, date, and rating between 0 and 5.');
    }
    if (mb_strlen($strengths) > 5000 || mb_strlen($improvements) > 5000 || mb_strlen($goals) > 5000 || mb_strlen($comments) > 5000) {
        exit('Review text must be 5,000 characters or fewer per field.');
    }
    $stmt = $pdo->prepare("INSERT INTO performance_reviews(employee_id, review_period, review_date, reviewer_id, overall_rating, strengths, areas_for_improvement, goals, comments) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $employeeId,
        $period,
        $reviewDate,
        $_SESSION['user_id'],
        $rating,
        $strengths,
        $improvements,
        $goals,
        $comments
    ]);
    set_modal('success', '✅ Review Saved', 'Performance review saved successfully!', 'performance.php');
}

$pageTitle = "Performance Reviews";
require_once "../partials/header.php";

// Get recent reviews
$reviews = $pdo->query("SELECT pr.*, e.name as employee_name, e.employee_code, u.name as reviewer_name FROM performance_reviews pr JOIN employees e ON pr.employee_id = e.id JOIN users u ON pr.reviewer_id = u.id ORDER BY pr.review_date DESC LIMIT 20")->fetchAll();

// Get employees for dropdown
$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>⭐ Performance Reviews</h1>
    <div style="display: flex; gap: 10px;">
        <button onclick="testModal()" class="btn btn-secondary" style="font-size: 0.8rem;">Test Modal</button>
        <button onclick="document.getElementById('reviewForm').style.display='block'" class="btn btn-accent">+ New Review</button>
    </div>
</div>

<!-- Review Form Modal -->
<div id="reviewForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow-y:auto; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">New Performance Review</h2>
            <button onclick="document.getElementById('reviewForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <div style="margin-bottom:15px;">
                <label>Employee:</label>
                <select name="employee_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['employee_code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Review Period:</label>
                <input type="month" name="review_period" value="<?= date('Y-m') ?>" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Review Date:</label>
                <input type="date" name="review_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Overall Rating (1-5):</label>
                <input type="number" name="overall_rating" min="1" max="5" step="0.1" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Strengths:</label>
                <textarea name="strengths" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <div style="margin-bottom:15px;">
                <label>Areas for Improvement:</label>
                <textarea name="areas_for_improvement" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <div style="margin-bottom:15px;">
                <label>Goals for Next Period:</label>
                <textarea name="goals" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <div style="margin-bottom:15px;">
                <label>Additional Comments:</label>
                <textarea name="comments" rows="2" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Save Review</button>
        </form>
    </div>
</div>

<!-- Recent Reviews -->
<div class="panel">
    <h3>Recent Performance Reviews</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Period</th>
                    <th>Review Date</th>
                    <th>Rating</th>
                    <th>Reviewer</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$reviews): ?>
                <tr><td colspan="6">No performance reviews yet.</td></tr>
                <?php endif; ?>
                <?php foreach($reviews as $review): ?>
                <tr>
                    <td><?= htmlspecialchars($review['employee_name']) ?> (<?= $review['employee_code'] ?>)</td>
                    <td><?= htmlspecialchars($review['review_period']) ?></td>
                    <td><?= date('d M Y', strtotime($review['review_date'])) ?></td>
                    <td>
                        <span class="badge <?= $review['overall_rating'] >= 4 ? 'badge-success' : ($review['overall_rating'] >= 3 ? 'badge-info' : 'badge-warning') ?>">
                            <?= number_format($review['overall_rating'], 1) ?>/5
                        </span>
                    </td>
                    <td><?= htmlspecialchars($review['reviewer_name']) ?></td>
                    <td>
                        <button onclick="viewReview(<?= $review['id'] ?>)" class="btn-sm">View Details</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Performance Summary -->
<div class="grid-2" style="margin-top:20px;">
    <div class="panel">
        <h3>Performance Distribution</h3>
        <?php
        $ratings = $pdo->query("SELECT 
            COUNT(CASE WHEN overall_rating >= 4.5 THEN 1 END) as excellent,
            COUNT(CASE WHEN overall_rating >= 3.5 AND overall_rating < 4.5 THEN 1 END) as good,
            COUNT(CASE WHEN overall_rating >= 2.5 AND overall_rating < 3.5 THEN 1 END) as satisfactory,
            COUNT(CASE WHEN overall_rating < 2.5 THEN 1 END) as needs_improvement
            FROM performance_reviews")->fetch();
        $total = array_sum($ratings);
        ?>
        <div style="margin-top:15px;">
            <div style="margin-bottom:10px;">
                <span>Excellent (4.5+):</span>
                <div style="background:#e5e7eb; height:20px; border-radius:10px; margin-top:5px;">
                    <div style="background:#10b981; height:100%; border-radius:10px; width:<?= $total > 0 ? ($ratings['excellent'] / $total * 100) : 0 ?>%;"></div>
                </div>
                <small><?= $ratings['excellent'] ?> reviews</small>
            </div>
            <div style="margin-bottom:10px;">
                <span>Good (3.5-4.4):</span>
                <div style="background:#e5e7eb; height:20px; border-radius:10px; margin-top:5px;">
                    <div style="background:#3b82f6; height:100%; border-radius:10px; width:<?= $total > 0 ? ($ratings['good'] / $total * 100) : 0 ?>%;"></div>
                </div>
                <small><?= $ratings['good'] ?> reviews</small>
            </div>
            <div style="margin-bottom:10px;">
                <span>Satisfactory (2.5-3.4):</span>
                <div style="background:#e5e7eb; height:20px; border-radius:10px; margin-top:5px;">
                    <div style="background:#f59e0b; height:100%; border-radius:10px; width:<?= $total > 0 ? ($ratings['satisfactory'] / $total * 100) : 0 ?>%;"></div>
                </div>
                <small><?= $ratings['satisfactory'] ?> reviews</small>
            </div>
            <div style="margin-bottom:10px;">
                <span>Needs Improvement (<2.5):</span>
                <div style="background:#e5e7eb; height:20px; border-radius:10px; margin-top:5px;">
                    <div style="background:#ef4444; height:100%; border-radius:10px; width:<?= $total > 0 ? ($ratings['needs_improvement'] / $total * 100) : 0 ?>%;"></div>
                </div>
                <small><?= $ratings['needs_improvement'] ?> reviews</small>
            </div>
        </div>
    </div>
    <div class="panel">
        <h3>Top Performers</h3>
        <?php
        $top_performers = $pdo->query("SELECT e.name, e.employee_code, AVG(pr.overall_rating) as avg_rating FROM performance_reviews pr JOIN employees e ON pr.employee_id = e.id GROUP BY pr.employee_id ORDER BY avg_rating DESC LIMIT 5")->fetchAll();
        ?>
        <?php if(!$top_performers): ?>
        <p>No performance data available.</p>
        <?php else: ?>
        <?php foreach($top_performers as $performer): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #e5e7eb;">
            <span><?= htmlspecialchars($performer['name']) ?> (<?= $performer['employee_code'] ?>)</span>
            <span class="badge badge-success"><?= number_format($performer['avg_rating'], 1) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Store review data for modal display
const reviewData = <?= json_encode(array_column($reviews, null, 'id')) ?>;

// Debug: Log the review data
console.log('Available review data:', reviewData);
console.log('Number of reviews:', Object.keys(reviewData).length);

function testModal() {
    console.log('Testing modal system...');
    
    // Create a test modal
    const testModalHTML = `
        <div id="testModal" class="modal-overlay">
            <div class="modal-content info" style="max-width: 400px;">
                <div class="modal-header">
                    <h3>🧪 Modal Test</h3>
                    <button onclick="closeTestModal()" class="modal-close-btn" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Modal system is working! This confirms that:</p>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <li>JavaScript is loading correctly</li>
                        <li>CSS styles are applied</li>
                        <li>Click events are working</li>
                    </ul>
                    <p><strong>Review data available:</strong> ${Object.keys(reviewData).length} reviews</p>
                </div>
                <div class="modal-footer">
                    <button onclick="closeTestModal()" class="modal-btn modal-btn-primary">Close Test</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', testModalHTML);
    
    setTimeout(() => {
        const modal = document.getElementById('testModal');
        if (modal) {
            modal.style.opacity = '1';
        }
    }, 10);
}

function closeTestModal() {
    const modal = document.getElementById('testModal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.remove();
        }, 200);
    }
}

function viewReview(id) {
    console.log('Viewing review ID:', id); // Debug log
    const review = reviewData[id];
    if (!review) {
        console.error('Review not found for ID:', id);
        console.log('Available review IDs:', Object.keys(reviewData));
        alert('Review not found! Available review IDs: ' + Object.keys(reviewData).join(', '));
        return;
    }
    
    console.log('Review data:', review); // Debug log
    
    // Remove any existing modal first
    const existingModal = document.getElementById('reviewModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal content
    const modalHTML = `
        <div id="reviewModal" class="modal-overlay">
            <div class="modal-content info" style="max-width: 600px;">
                <div class="modal-header">
                    <h3>📋 Performance Review Details</h3>
                    <button onclick="closeReviewModal()" class="modal-close-btn" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div style="display: grid; gap: 15px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div><strong>Employee:</strong> ${review.employee_name} (${review.employee_code})</div>
                            <div><strong>Review Period:</strong> ${review.review_period}</div>
                            <div><strong>Review Date:</strong> ${new Date(review.review_date).toLocaleDateString()}</div>
                            <div><strong>Reviewer:</strong> ${review.reviewer_name}</div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #f8fafc; border-radius: 8px;">
                            <strong>Overall Rating:</strong> 
                            <span style="font-size: 1.5rem; color: #059669;">${review.overall_rating}/5.00</span>
                            <div style="margin-top: 5px;">
                                ${'★'.repeat(Math.floor(review.overall_rating))}${'☆'.repeat(5 - Math.floor(review.overall_rating))}
                            </div>
                        </div>
                        <div>
                            <strong>Strengths:</strong>
                            <div style="background: #f0fdf4; padding: 10px; border-radius: 6px; margin-top: 5px; border-left: 4px solid #22c55e;">
                                ${review.strengths || 'Not specified'}
                            </div>
                        </div>
                        <div>
                            <strong>Areas for Improvement:</strong>
                            <div style="background: #fff7ed; padding: 10px; border-radius: 6px; margin-top: 5px; border-left: 4px solid #f97316;">
                                ${review.areas_for_improvement || 'Not specified'}
                            </div>
                        </div>
                        <div>
                            <strong>Goals:</strong>
                            <div style="background: #eff6ff; padding: 10px; border-radius: 6px; margin-top: 5px; border-left: 4px solid #3b82f6;">
                                ${review.goals || 'Not specified'}
                            </div>
                        </div>
                        <div>
                            <strong>Additional Comments:</strong>
                            <div style="background: #fafafa; padding: 10px; border-radius: 6px; margin-top: 5px; border-left: 4px solid #6b7280;">
                                ${review.comments || 'No additional comments'}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button onclick="closeReviewModal()" class="modal-btn modal-btn-primary">Close</button>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal with animation
    setTimeout(() => {
        const modal = document.getElementById('reviewModal');
        if (modal) {
            modal.style.opacity = '1';
        }
    }, 10);
}

function closeReviewModal() {
    const modal = document.getElementById('reviewModal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.remove();
        }, 200);
    }
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('reviewModal')) {
        closeReviewModal();
    }
});
</script>

<style>
/* Modal styles for review details */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal-content {
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    transform: scale(0.9);
    transition: all 0.3s ease;
    animation: modalSlideIn 0.4s ease-out forwards;
}

@keyframes modalSlideIn {
    0% {
        transform: translateY(-30px) scale(0.9);
        opacity: 0;
    }
    100% {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

.modal-content.info {
    border-top: 6px solid #3b82f6;
}

.modal-header {
    padding: 24px 24px 16px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    color: #1e293b;
}

.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    line-height: 1;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close-btn:hover {
    background: #f1f5f9;
    color: #64748b;
    transform: scale(1.1);
}

.modal-body {
    padding: 20px 24px;
    color: #475569;
    line-height: 1.6;
}

.modal-footer {
    padding: 16px 24px 24px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.modal-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-btn-primary {
    background: #3b82f6;
    color: white;
}

.modal-btn-primary:hover {
    background: #2563eb;
    transform: translateY(-1px);
}

@media (max-width: 640px) {
    .modal-content {
        margin: 20px;
        width: calc(100% - 40px);
    }
    
    .modal-header, .modal-body, .modal-footer {
        padding-left: 20px;
        padding-right: 20px;
    }
    
    .modal-header h3 {
        font-size: 1.1rem;
    }
}
</style>

<?php require_once "../partials/footer.php"; ?>
