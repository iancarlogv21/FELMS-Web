</main> </div> <script>
        // --- RENDER ICONS ---
        lucide.createIcons();

        // --- CHART CONFIGURATION & RENDERING ---
        // This script block expects chart data variables to be defined in the main PHP file (e.g., dashboard.php)
        (function() {
            if (typeof Chart === 'undefined') return;

            const chartColors = {
                red: 'rgb(220, 38, 38)', redRgba: 'rgba(220, 38, 38, 0.3)',
                blue: 'rgb(59, 130, 246)', blueRgba: 'rgba(59, 130, 246, 0.3)',
                green: 'rgb(22, 163, 74)', greenRgba: 'rgba(22, 163, 74, 0.3)',
                yellow: 'rgb(245, 158, 11)', yellowRgba: 'rgba(245, 158, 11, 0.3)',
                purple: 'rgb(139, 92, 246)', purpleRgba: 'rgba(139, 92, 246, 0.3)',
                slate: 'rgb(100, 116, 139)', slateRgba: 'rgba(100, 116, 139, 0.3)'
            };

            // 1. Monthly Borrowing Trends (Line Chart)
            const monthlyChartCtx = document.getElementById('monthlyBorrowingChart');
            if (monthlyChartCtx && typeof monthlyLabels !== 'undefined') {
                new Chart(monthlyChartCtx.getContext('2d'), {
                    type: 'line', data: { labels: monthlyLabels, datasets: [{ label: 'Books Borrowed', data: monthlyData, backgroundColor: chartColors.redRgba, borderColor: chartColors.red, borderWidth: 2, pointBackgroundColor: chartColors.red, pointRadius: 4, tension: 0.4 }] },
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
                });
            }

            // 2. Genre Popularity (Donut Chart)
            const genreChartCtx = document.getElementById('genrePopularityChart');
            if (genreChartCtx && typeof genreLabels !== 'undefined') {
                new Chart(genreChartCtx.getContext('2d'), {
                    type: 'doughnut', data: { labels: genreLabels, datasets: [{ data: genreData, backgroundColor: [chartColors.red, chartColors.blue, chartColors.yellow, chartColors.green, chartColors.purple], borderColor: '#fff', borderWidth: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { padding: 15 } } }, cutout: '70%', hoverOffset: 10 }
                });
            }

            // 3. Book Status Overview (Pie Chart)
            const statusChartCtx = document.getElementById('bookStatusChart');
            if (statusChartCtx && typeof bookStatusLabels !== 'undefined') {
                new Chart(statusChartCtx.getContext('2d'), {
                    type: 'pie', data: { labels: bookStatusLabels, datasets: [{ data: bookStatusData, backgroundColor: [chartColors.green, chartColors.blue, chartColors.yellow], borderColor: '#fff', borderWidth: 4, }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { padding: 15 } } }, hoverOffset: 10 }
                });
            }

            // 4. Overdue Books by Program (Bar Chart)
            const overdueChartCtx = document.getElementById('overdueByProgramChart');
            if (overdueChartCtx && typeof overdueByProgramLabels !== 'undefined') {
                new Chart(overdueChartCtx.getContext('2d'), {
                    type: 'bar', data: { labels: overdueByProgramLabels, datasets: [{ label: 'Overdue Books', data: overdueByProgramData, backgroundColor: chartColors.yellowRgba, borderColor: chartColors.yellow, borderWidth: 2 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
                });
            }
        })();
    </script>
</body>
</html>