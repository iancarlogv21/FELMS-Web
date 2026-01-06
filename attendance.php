<?php
// attendance.php (With link to Exit page)
require_once __DIR__ . '/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Monitoring (Entrance)</title>
    <link rel="icon" type="image/png" href="assets/images/haha.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        html, body { font-family: 'Inter', sans-serif; }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }
        .fade-in { animation: fadeIn 0.4s ease-out forwards; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-2xl mx-auto">
        <header class="text-center mb-8">
            <h1 class="text-4xl md:text-5xl font-black tracking-tighter text-slate-800">ATTENDANCE MONITORING</h1>
            <p class="text-lg text-slate-500 tracking-wider">(ENTRANCE)</p>
        </header>

        <div class="bg-white p-8 rounded-2xl shadow-xl space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                <div>
                    <label for="location" class="block text-sm font-medium text-slate-600 mb-1">Location</label>
                    <select id="location" name="location" class="w-full p-3 bg-white border border-slate-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 text-slate-900 transition duration-300">
                        <option>BSIT Library</option>
                        <option>Main Campus Library</option>
                        <option>Archives Section</option>
                    </select>
                </div>
                <div id="clock" class="text-center md:text-right">
                    <p class="text-4xl font-extrabold text-orange-600 tracking-wider"></p>
                    <p class="text-sm text-slate-500"></p>
                </div>
            </div>

            <form id="attendance-form" class="relative">
                <i data-lucide="scan-line" class="absolute left-5 top-1/2 -translate-y-1/2 w-7 h-7 text-slate-400"></i>
                <input type="text" id="student-no-input" name="student_no" placeholder="Enter Student Number..."
                       class="w-full text-center text-2xl font-semibold p-5 pl-16 pr-6 bg-white border-2 border-slate-300 rounded-xl focus:ring-4 focus:ring-orange-500/50 focus:border-orange-500 transition duration-300 placeholder-slate-400"
                       autocomplete="off" autofocus>
            </form>

            <div id="status-message" class="min-h-[140px] flex items-center justify-center p-4 bg-slate-50 rounded-lg transition-all duration-300">
                <div class="text-center text-slate-400">
                    <i data-lucide="user-round-check" class="w-12 h-12 mx-auto"></i>
                    <p class="mt-2 font-medium">Waiting for student check-in...</p>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-6">
            <a href="attendance_exit.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-orange-600 font-semibold transition-colors">
                <span>Go to Exit Page</span>
                <i data-lucide="arrow-right-circle"></i>
            </a>
        </div>
    </div>

    <script>
        // --- JAVASCRIPT IS IDENTICAL TO THE PREVIOUS VERSION ---
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('attendance-form');
            const input = document.getElementById('student-no-input');
            const statusMessageDiv = document.getElementById('status-message');

            function updateClock() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
                const dateString = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                document.querySelector('#clock p:first-child').textContent = timeString;
                document.querySelector('#clock p:last-child').textContent = dateString;
            }
            setInterval(updateClock, 1000);
            updateClock();

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const studentNo = input.value.trim();
                const location = document.getElementById('location').value;
                if (!studentNo) return;
                const formData = new FormData();
                formData.append('student_no', studentNo);
                formData.append('location', location);
                try {
                    const response = await fetch('api_log_attendance.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.status === 'success') {
                        handleSuccess(result.data);
                    } else {
                        handleError(result.message);
                    }
                } catch (error) {
                    handleError('Network connection error.');
                }
                setTimeout(() => { input.value = ''; input.focus(); }, 1000); 
            });

            function handleSuccess(data) {
                statusMessageDiv.className = 'min-h-[140px] p-4 rounded-lg bg-orange-50 border-2 border-orange-200 text-orange-900 shadow-lg fade-in';
                statusMessageDiv.innerHTML = `<div class="flex items-center gap-5 w-full"><img src="${data.photo_url}" class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-md flex-shrink-0"><div class="flex-grow"><p class="text-sm font-bold text-orange-700">WELCOME!</p><h3 class="text-3xl font-black tracking-tight text-orange-900">${data.full_name}</h3><p class="text-md font-semibold text-orange-800">${data.student_no}</p><p class="text-lg font-bold text-slate-700 mt-1">${data.program} ${data.year}-${data.section}</p></div><div class="text-right flex-shrink-0"><p class="font-bold text-lg text-slate-700">${data.location}</p><p class="text-3xl font-extrabold text-red-600">${data.time_in}</p></div></div>`;
            }

            function handleError(message) {
                statusMessageDiv.className = 'min-h-[140px] flex items-center justify-center p-4 bg-red-100 border-2 border-red-300 rounded-lg fade-in';
                statusMessageDiv.innerHTML = `<div class="text-center text-red-800"><i data-lucide="alert-triangle" class="w-10 h-10 mx-auto"></i><h3 class="mt-2 text-xl font-bold">Access Denied</h3><p>${message}</p></div>`;
                lucide.createIcons();
            }

            lucide.createIcons();
        });
    </script>
</body>
</html>