<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'LMS'; ?> - Library Management System</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide/dist/umd/lucide.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f1f5f9;
            background-image: radial-gradient(circle at top right, #ef444420, transparent 40%),
                              radial-gradient(circle at bottom left, #3b82f620, transparent 40%);
            overflow-x: hidden;
        }
        .sidebar-icon { stroke-width: 1.5; }
        .sidebar-active a { color: #dc2626; font-weight: 600; }
        .sidebar-active > div { background-color: #fef2f2; border-right: 3px solid #dc2626; }
        .sidebar-link:hover > div { background-color: #fef2f2; }
        .sidebar-link:hover a { color: #dc2626; }

        aside, main {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        aside {
            position: fixed;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 40;
        }

        #sidebar.collapsed { width: 5rem; }
        #sidebar.collapsed .nav-text, #sidebar.collapsed .logo-text { display: none; }
        #sidebar.collapsed .logo-icon-wrapper { justify-content: center; }
        #sidebar.collapsed .nav-link { justify-content: center; }

        main {
            padding-left: 16rem; /* Space for the expanded sidebar */
        }

        main.collapsed {
            padding-left: 5rem; /* Space for the collapsed sidebar */
        }
        
        .glass-card {
            background-color: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .stat-icon-bg {
            background-image: linear-gradient(to top right, var(--tw-gradient-stops));
        }
    </style>
</head>
<body class="bg-slate-100">
    <div class="relative min-h-screen">
        
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <main id="main-content" class="p-6 md:p-8 overflow-y-auto expanded">