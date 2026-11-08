<?php
// Sidebar extras: number badges on menu groups, a collapse button (desktop) and a bottom bar (phones).
// Included once from header.php, after the sidebar and its scripts.
$bpBadges = [];
$bpCount = function ($sql) use ($pdo) {
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
};
if (has_permission('purchases', 'view')) {
    $n = $bpCount("SELECT COUNT(*) FROM purchases WHERE COALESCE(approval_status, 'pending') = 'pending'");
    if ($n > 0) $bpBadges[] = ['href' => '/purchases/index.php', 'text' => (string) $n, 'title' => 'Purchases waiting for approval'];
}
if (has_permission('inventory', 'view')) {
    $n = $bpCount("SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id = rl.material_id WHERE rm.current_stock <= rl.reorder_point AND rm.status = 1");
    if ($n > 0) $bpBadges[] = ['href' => '/inventory/overview.php', 'text' => (string) $n, 'title' => 'Materials below reorder level'];
}
if (has_permission('expenses', 'view')) {
    $n = $bpCount("SELECT COUNT(*) FROM expenses WHERE approval_status = 'pending'");
    if ($n > 0) $bpBadges[] = ['href' => '/finance/cashbook.php', 'text' => (string) $n, 'title' => 'Expenses waiting for approval'];
}
if ((int) date('w') === 6 && has_permission('production', 'view')) {
    try {
        require_once __DIR__ . '/../config/labor_settings.php';
        [$bpLs, $bpLe] = labor_week_bounds(date('Y-m-d'));
        if (!labor_payment_for_week($bpLe) && labor_week_summary($bpLs, $bpLe)['due'] > 0) {
            $bpBadges[] = ['href' => '/production/index.php', 'text' => '!', 'title' => 'Weekly labor payment due today'];
        }
    } catch (Throwable $e) {
    }
}
$bpBottom = [];
$bpBottom[] = ['/block_plant/dashboard/index.php', '🏠', 'Home'];
if (has_permission('sales', 'view')) $bpBottom[] = ['/block_plant/sales/index.php', '💰', 'Sales'];
if (has_permission('production', 'view')) $bpBottom[] = ['/block_plant/production/index.php', '🏭', 'Production'];
if (has_permission('inventory', 'view')) $bpBottom[] = ['/block_plant/inventory/overview.php', '📦', 'Stock'];
$bpBottom = array_slice($bpBottom, 0, 4);
?>
<nav class="bottom-bar" id="bottomBar" aria-label="Quick navigation">
    <?php foreach ($bpBottom as $b): ?>
    <a href="<?= e($b[0]) ?>"><span class="bb-ico"><?= $b[1] ?></span><span class="bb-txt"><?= e($b[2]) ?></span></a>
    <?php endforeach; ?>
    <button type="button" id="bottomMore"><span class="bb-ico">☰</span><span class="bb-txt">More</span></button>
</nav>
<script>
(function () {
    var badges = <?= json_encode($bpBadges) ?>;
    var sidebar = document.getElementById('appSidebar');
    if (!sidebar) return;

    // Number badges on the menu group that contains the page
    badges.forEach(function (b) {
        var link = sidebar.querySelector('.dropdown-menu a[href*="' + b.href + '"]');
        var top = link ? link.closest('.dropdown') : null;
        var anchor = top ? top.querySelector(':scope > a') : null;
        if (!anchor || anchor.querySelector('.nav-badge')) return;
        var s = document.createElement('span');
        s.className = 'nav-badge';
        s.textContent = b.text;
        s.title = b.title;
        anchor.appendChild(s);
    });

    // Collapse button (desktop)
    var brand = sidebar.querySelector('.sidebar-brand');
    var body = document.body;
    try { if (localStorage.getItem('bp_sidebar_collapsed') === '1') body.classList.add('sidebar-collapsed'); } catch (e) {}
    if (brand) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sb-collapse';
        btn.setAttribute('aria-label', 'Collapse menu');
        btn.textContent = '«';
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var on = body.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('bp_sidebar_collapsed', on ? '1' : '0'); } catch (err) {}
        });
        brand.appendChild(btn);
    }
    // Clicking a group while collapsed expands the menu first
    sidebar.addEventListener('click', function (e) {
        if (!body.classList.contains('sidebar-collapsed')) return;
        var a = e.target.closest ? e.target.closest('.dropdown > a') : null;
        if (a) {
            body.classList.remove('sidebar-collapsed');
            try { localStorage.setItem('bp_sidebar_collapsed', '0'); } catch (err) {}
        }
    }, true);

    // Bottom bar: highlight current page, "More" opens the full menu
    var here = location.pathname.replace(/\/+$/, '');
    document.querySelectorAll('#bottomBar a').forEach(function (a) {
        var p = a.getAttribute('href').replace(/\/+$/, '');
        if (p === here) a.classList.add('active');
    });
    var more = document.getElementById('bottomMore');
    var toggle = document.getElementById('sidebarToggle');
    if (more && toggle) more.addEventListener('click', function () { toggle.click(); });
})();
</script>
