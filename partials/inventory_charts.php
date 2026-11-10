<script>
(function () {
    if (typeof Chart === 'undefined') return;
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--bp-primary').trim() || '#4f4fc9';
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--bp-accent').trim() || '#2f7d4f';

    fetch('/block_plant/api/inventory_charts_data.php')
        .then(r => r.json())
        .then(data => {
            const valEl = document.getElementById('chartInventoryValuation');
            if (valEl) {
                new Chart(valEl, {
                    type: 'doughnut',
                    data: {
                        // Cost basis on both slices — mixing cost with retail would
                        // make the split meaningless.
                        labels: ['Raw materials (at cost)', 'Finished goods (at cost)'],
                        datasets: [{ data: [data.valuation.raw, data.valuation.finished_cost ?? data.valuation.finished], backgroundColor: [primary, accent] }]
                    },
                    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
                });
            }
            const moveEl = document.getElementById('chartInventoryMovement');
            if (moveEl) {
                new Chart(moveEl, {
                    type: 'line',
                    data: {
                        labels: data.movement_daily.map(x => x.label),
                        datasets: [{ label: 'Movement qty', data: data.movement_daily.map(x => x.value), borderColor: accent, fill: false, tension: 0.3 }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }
            const typeEl = document.getElementById('chartInventoryByType');
            if (typeEl && data.movement_by_type.length) {
                new Chart(typeEl, {
                    type: 'bar',
                    data: {
                        labels: data.movement_by_type.map(x => x.label),
                        datasets: [{ data: data.movement_by_type.map(x => x.qty), backgroundColor: primary }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }
            const rawEl = document.getElementById('chartRawValue');
            if (rawEl) {
                new Chart(rawEl, {
                    type: 'bar',
                    data: {
                        labels: data.raw_by_material.map(x => x.label),
                        datasets: [{ data: data.raw_by_material.map(x => x.value), backgroundColor: '#5c6bc0' }]
                    },
                    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } }
                });
            }
        })
        .catch(() => {});
})();
</script>
