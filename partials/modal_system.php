<?php
/**
 * Beautiful Modal Popup System
 * Usage: Include this file and set modal variables before including header.php
 * 
 * Variables to set:
 * - $showModal = true
 * - $modalType = 'success', 'error', 'warning', 'info'
 * - $modalTitle = 'Modal Title'
 * - $modalMessage = 'Modal message content'
 * - $modalRedirect = 'redirect_url.php' (optional)
 */

if (isset($showModal) && $showModal): ?>
<div id="beautifulModal" class="modal-overlay">
    <div class="modal-content <?= $modalType ?>">
        <div class="modal-header">
            <h3><?= htmlspecialchars($modalTitle) ?></h3>
            <button onclick="handleModalClose()" class="modal-close-btn" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p><?= htmlspecialchars($modalMessage) ?></p>
        </div>
        <div class="modal-footer">
            <button onclick="handleModalClose()" class="modal-btn modal-btn-primary">OK</button>
        </div>
    </div>
</div>

<script>
function handleModalClose() {
    const modal = document.getElementById('beautifulModal');
    modal.style.opacity = '0';
    setTimeout(() => {
        <?php if (isset($modalRedirect) && $modalRedirect): ?>
        window.location.href = <?= json_encode($modalRedirect) ?>;
        <?php else: ?>
        modal.style.display = 'none';
        <?php endif; ?>
    }, 200);
}

// Auto-show modal
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('beautifulModal');
    if (modal) {
        setTimeout(() => {
            modal.style.opacity = '1';
        }, 100);
    }
});

// Close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        handleModalClose();
    }
});

// Prevent form resubmission when modal is shown
window.addEventListener('beforeunload', function() {
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<style>
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

.modal-content.success {
    border-top: 6px solid #10b981;
}

.modal-content.error {
    border-top: 6px solid #ef4444;
}

.modal-content.warning {
    border-top: 6px solid #f59e0b;
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

.modal-close-btn:active {
    transform: scale(0.95);
}

.modal-body {
    padding: 20px 24px;
    color: #475569;
    line-height: 1.6;
}

.modal-body p {
    margin: 0;
    font-size: 1rem;
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

.modal-content.success .modal-btn-primary {
    background: #10b981;
}

.modal-content.success .modal-btn-primary:hover {
    background: #059669;
}

.modal-content.error .modal-btn-primary {
    background: #ef4444;
}

.modal-content.error .modal-btn-primary:hover {
    background: #dc2626;
}

.modal-content.warning .modal-btn-primary {
    background: #f59e0b;
}

.modal-content.warning .modal-btn-primary:hover {
    background: #d97706;
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
    
    .modal-close-btn {
        font-size: 1.3rem;
        width: 28px;
        height: 28px;
    }
}
</style>
<?php endif; ?>