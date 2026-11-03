<script>
(function () {
    if (typeof Chart === 'undefined') return;
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--bp-primary').trim() || '#3949ab';
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--bp-accent').trim() || '#00897b';

    fetch('/block_plant/api/production_charts_data.php')
        .then(r => r.json())
        .then(data => {
            const dailyEl = document.getElementById('chartProductionDaily');
            if (dailyEl) {
                new Chart(dailyEl, {
                    type: 'bar',
                    data: {
                        labels: data.daily.map(x => x.label),
                        datasets: [
                            { label: 'Good', data: data.daily.map(x => x.good), backgroundColor: accent },
                            { label: 'Rejected', data: data.daily.map(x => x.rejected), backgroundColor: '#ef5350' }
                        ]
                    },
                    options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true } } }
                });
            }

            const blockEl = document.getElementById('chartProductionByBlock');
            if (blockEl) {
                new Chart(blockEl, {
                    type: 'doughnut',
                    data: {
                        labels: data.by_block.map(x => x.label),
                        datasets: [{ data: data.by_block.map(x => x.value), backgroundColor: [primary, accent, '#5c6bc0', '#26a69a', '#7e57c2', '#ffa726'] }]
                    },
                    options: { responsive: true }
                });
            }

            const costEl = document.getElementById('chartProductionCost');
            if (costEl) {
                new Chart(costEl, {
                    type: 'line',
                    data: {
                        labels: data.daily.map(x => x.label),
                        datasets: [{
                            label: 'Cost',
                            data: data.daily.map(x => x.cost),
                            borderColor: primary,
                            backgroundColor: primary + '22',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }
        })
        .catch(() => {});
})();
</script>
