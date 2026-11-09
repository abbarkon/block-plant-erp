<script>
(function () {
    if (typeof Chart === 'undefined') return;
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--bp-primary').trim() || '#b8481b';
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--bp-accent').trim() || '#2f7d4f';

    fetch('/block_plant/api/purchasing_charts_data.php')
        .then(r => r.json())
        .then(data => {
            const mEl = document.getElementById('chartPurchaseMonthly');
            if (mEl) {
                new Chart(mEl, {
                    type: 'line',
                    data: {
                        labels: data.monthly.map(x => x.label),
                        datasets: [{ label: 'Purchases', data: data.monthly.map(x => x.value), borderColor: primary, backgroundColor: primary + '33', fill: true, tension: 0.3 }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }
            const matEl = document.getElementById('chartPurchaseByMaterial');
            if (matEl) {
                new Chart(matEl, {
                    type: 'bar',
                    data: {
                        labels: data.by_material.map(x => x.label),
                        datasets: [{ data: data.by_material.map(x => x.value), backgroundColor: accent }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }
            const supEl = document.getElementById('chartPurchaseBySupplier');
            if (supEl) {
                new Chart(supEl, {
                    type: 'doughnut',
                    data: {
                        labels: data.by_supplier.map(x => x.label),
                        datasets: [{ data: data.by_supplier.map(x => x.value), backgroundColor: [primary, accent, '#5c6bc0', '#26a69a', '#ffa726', '#78909c'] }]
                    },
                    options: { responsive: true }
                });
            }
        })
        .catch(() => {});
})();
</script>
