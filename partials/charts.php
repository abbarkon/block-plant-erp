<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--bp-primary').trim() || '#4f4fc9';
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--bp-accent').trim() || '#2f7d4f';

    fetch('/block_plant/api/charts_data.php')
        .then(r => r.json())
        .then(data => {
            const fmt = (v) => (data.currency || '') + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });

            const salesEl = document.getElementById('chartSalesTrend');
            if (salesEl) {
                new Chart(salesEl, {
                    type: 'line',
                    data: {
                        labels: data.sales_monthly.map(x => x.label),
                        datasets: [{
                            label: <?= json_encode(t('sales_label')) ?>,
                            data: data.sales_monthly.map(x => x.value),
                            borderColor: primary,
                            backgroundColor: primary + '33',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }

            const prodEl = document.getElementById('chartProduction');
            if (prodEl) {
                new Chart(prodEl, {
                    type: 'bar',
                    data: {
                        labels: data.production_daily.map(x => x.label),
                        datasets: [{
                            label: <?= json_encode(t('good_blocks')) ?>,
                            data: data.production_daily.map(x => x.value),
                            backgroundColor: accent
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } }
                });
            }

            const topEl = document.getElementById('chartTopProducts');
            if (topEl) {
                new Chart(topEl, {
                    type: 'doughnut',
                    data: {
                        labels: data.top_products.map(x => x.label),
                        datasets: [{
                            data: data.top_products.map(x => x.value),
                            backgroundColor: ['#4f4fc9', '#2f7d4f', '#5c6bc0', '#26a69a', '#7e57c2', '#66bb6a']
                        }]
                    },
                    options: { responsive: true }
                });
            }

            const mixEl = document.getElementById('chartExpenseSales');
            if (mixEl && data.expense_vs_sales) {
                new Chart(mixEl, {
                    type: 'bar',
                    data: {
                        labels: [<?= json_encode(t('monthly_sales')) ?>, <?= json_encode(t('expenses_mtd')) ?>],
                        datasets: [{
                            data: [data.expense_vs_sales.sales_mtd, data.expense_vs_sales.expenses_mtd],
                            backgroundColor: [primary, '#ef5350']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: ctx => fmt(ctx.raw) } }
                        }
                    }
                });
            }
        })
        .catch(() => {});
})();
</script>
