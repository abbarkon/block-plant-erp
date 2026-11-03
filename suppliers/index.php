<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('suppliers', 'view');

$pageTitle = "Suppliers Management";
require_once "../partials/header.php";

$rows = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();
?>
<style>
    .page-head {
        display: flex; justify-content: space-between; align-items: center;
        background: linear-gradient(135deg, rgba(230,81,0,0.1) 0%, rgba(255,160,0,0.1) 100%);
        padding: 25px 30px; border-radius: 16px; margin-bottom: 30px; border: 1px solid rgba(0,0,0,0.05);
    }
    .page-head h1 { margin: 0 0 5px 0; font-size: 1.8rem; color: #e65100; }
    .page-head p { margin: 0; color: #546e7a; font-size: 0.95rem; }
    
    .panel-modern {
        background: #fff; border-radius: 16px; padding: 25px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.02); border: 1px solid #edf2f9;
    }
    .modern-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .modern-table th { background: #f8fafc; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0; }
    .modern-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.95rem; }
    .modern-table tbody tr { transition: background 0.2s; }
    .modern-table tbody tr:hover { background: #fbfbfc; }
    
    .badge-active { background: rgba(67,160,71,0.1); color: #43a047; padding: 4px 10px; border-radius: 20px; font-weight:600; font-size:0.8rem; text-transform:uppercase; }
    .badge-inactive { background: rgba(229,57,53,0.1); color: #e53935; padding: 4px 10px; border-radius: 20px; font-weight:600; font-size:0.8rem; text-transform:uppercase; }
    
    .action-bar { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-export { background: #fff; border: 1px solid #cbd5e1; color: #475569; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
    .btn-export:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; }
    .btn-primary { background: linear-gradient(90deg, #e65100, #ffa000); color: #fff; padding: 8px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; }
    .btn-primary:hover { filter: brightness(1.05); }
    
    /* Dark mode overrides */
    body.dark-theme .page-head { background: rgba(230,81,0,0.15); border-color: rgba(255,255,255,0.05); }
    body.dark-theme .page-head h1 { color: #ffa000; }
    body.dark-theme .panel-modern { background: #1e1e1e; border-color: #333; }
    body.dark-theme .modern-table th { background: #2a2a2a; border-color: #444; color: #aaa; }
    body.dark-theme .modern-table td { border-color: #333; color: #eee; }
    body.dark-theme .modern-table tbody tr:hover { background: #2c2c2c; }
    body.dark-theme .btn-export { background: #2a2a2a; border-color: #444; color: #e2e8f0; }
    body.dark-theme .btn-export:hover { background: #333; border-color: #64748b; }
    
    @media (max-width: 768px) {
        .page-head { flex-direction: column; align-items: flex-start; gap: 20px; }
    }
</style>

<div class="page-head">
    <div>
        <h1>🚚 Suppliers Directory</h1>
        <p>Manage supplier profiles, contact details, and account balances.</p>
    </div>
    <div class="action-bar">
        <button onclick="exportExcel()" class="btn-export">📊 Export Excel</button>
        <button onclick="exportPDF()" class="btn-export">📄 Export PDF</button>
        <a href="create.php" class="btn-primary">+ Add New Supplier</a>
    </div>
</div>

<div class="panel-modern">
    <div style="overflow-x: auto;">
        <table class="modern-table" id="suppliersTable">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Supplier Name</th>
                    <th>Phone Number</th>
                    <th>Opening Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                <tr>
                    <td style="font-weight: 600; color: #64748b;"><?= htmlspecialchars($r['supplier_code'] ?? '') ?></td>
                    <td style="font-weight: 700; font-size: 1.05rem;"><?= htmlspecialchars($r['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['phone'] ?? '-') ?></td>
                    <td style="font-family: monospace; font-size: 1.1rem;"><?= number_format($r['opening_balance'] ?? 0, 2) ?></td>
                    <td>
                        <?php if(isset($r['status']) && $r['status'] == 1): ?>
                            <span class="badge-active">Active</span>
                        <?php else: ?>
                            <span class="badge-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($rows)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:30px; color:#64748b;">No suppliers found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<script>
function exportExcel() {
    var wb = XLSX.utils.table_to_book(document.getElementById('suppliersTable'));
    XLSX.writeFile(wb, 'Suppliers_Directory.xlsx');
}
function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.text("Suppliers Directory", 14, 15);
    doc.autoTable({ html: '#suppliersTable', startY: 20 });
    doc.save('Suppliers_Directory.pdf');
}
</script>

<?php require_once "../partials/footer.php"; ?>