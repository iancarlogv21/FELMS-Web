<?php
// templates/header.php
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?= $pageTitle ?? 'LMS' ?></title>
     <link rel="icon" type="image/png" href="assets/images/haha.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide/dist/umd/lucide.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">


    

    <style>
        

        /* ... (all your existing styles) ... */

    .sidebar-active > .sidebar-button {
        background-color: var(--accent-color-light);
        color: var(--accent-color);
        font-weight: 600;
    }

    /* === ADD THIS NEW STYLE === */
    .sidebar-active-parent {
        color: var(--accent-color);
        font-weight: 600;
    }
    /* === END OF NEW STYLE === */

    /* ✨ --- Form & Modal Styles (Now Theme-Aware) --- ✨ */
    /* ... (rest of your styles) ... */
    /* --- IMPROVED THEME STYLES --- */
    :root {
        --bg-color: #f8fafc; /* slate-50 */
        --card-bg-color: #ffffff; /* white */
        --text-primary: #0f172a; /* slate-900 */
        --text-secondary: #64748b; /* slate-500 */
        --border-color: #e2e8f0; /* slate-200 */
        --accent-color: #dc2626; /* red-600 */
        --accent-color-light: #fef2f2; /* red-50 */
        --logo-icon-color: var(--accent-color);
    }

    html.dark {
        --bg-color: #0f172a; /* slate-900 */
        --card-bg-color: #1e293b; /* slate-800 */
        --text-primary: #f1f5f9; /* slate-100 */
        --text-secondary: #94a3b8; /* slate-400 */
        --border-color: #334155; /* slate-700 */
        --accent-color: #f87171; /* red-400 */
        --accent-color-light: rgba(248, 113, 113, 0.1); /* red-400 with alpha */
        --logo-icon-color: var(--accent-color);
    }

    /* --- Universal Component Styles --- */
    body { 
        font-family: 'Inter', sans-serif; 
        background-color: var(--bg-color); 
        color: var(--text-primary);
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* Custom classes to use our theme variables */
    .bg-card { background-color: var(--card-bg-color); }
    .border-theme { border-color: var(--border-color); }
    .text-secondary { color: var(--text-secondary); }
    
    .shadow-theme {
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.07), 0 1px 2px -1px rgba(0, 0, 0, 0.07);
    }
    html.dark .shadow-theme {
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.2), 0 1px 2px -1px rgba(0, 0, 0, 0.2);
    }

    /* --- Sidebar Styles --- */
    #sidebar { 
        background-color: var(--card-bg-color); 
        border-right-color: var(--border-color);
        width: 16rem; 
        transition: width 0.3s ease-in-out, background-color 0.3s ease, border-color 0.3s ease;
        will-change: width;
    }

    .sidebar-button {
        display: flex;
        align-items: center;
        width: 100%;
        padding: 0.75rem;
        font-weight: 500;
        color: var(--text-secondary);
        border-radius: 0.5rem;
        transition: color 0.2s ease-in-out, background-color 0.2s ease-in-out;
    }

    .sidebar-button:hover {
        background-color: var(--accent-color-light);
        color: var(--accent-color);
    }

    .sidebar-active > .sidebar-button {
        background-color: var(--accent-color-light);
        color: var(--accent-color);
        font-weight: 600;
    }

    /* ✨ --- Form & Modal Styles (Now Theme-Aware) --- ✨ */
.form-label {
    display: block;
    margin-bottom: 0.25rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-secondary); /* Was #475569 */
}
/* In templates/header.php */

.form-input {
    display: block;
    width: 100%;
    border-radius: 0.5rem;
    padding: 0.65rem 0.85rem;
    transition: all 0.2s;

    /* ✨ ALWAYS USE LIGHT THEME STYLES ✨ */
    background-color: #ffffff !important; /* Always white background */
    color: #0f172a !important;          /* Always black text */
    border: 1px solid #e2e8f0 !important; /* Always light border */
}
.form-input::placeholder {
    color: var(--text-secondary); /* Was #94a3b8 */
    opacity: 0.7;
}
.form-input:focus {
    border-color: var(--accent-color); /* Was #38bdf8 */
    box-shadow: 0 0 0 2px var(--accent-color-light);
    outline: none;
}

/* In header.php, inside the <style> tag */

/* ✨ Makes the text inside date/time inputs dark gray in dark mode */
html.dark input[type="datetime-local"],
html.dark input[type="date"] {
    color: #121518ff; /* A dark gray color */
}

/* Fallback for browsers like Chrome/Edge */
html.dark input::-webkit-datetime-edit-fields-wrapper,
html.dark input::-webkit-datetime-edit-text,
html.dark input::-webkit-datetime-edit-month-field,
html.dark input::-webkit-datetime-edit-day-field,
html.dark input::-webkit-datetime-edit-year-field,
html.dark input::-webkit-datetime-edit-hour-field,
html.dark input::-webkit-datetime-edit-minute-field,
html.dark input::-webkit-datetime-edit-ampm-field {
    color: #121518ff;
}




/* In templates/header.php */

.btn-danger:hover:not(:disabled) { background-color: #dc2626; }
.btn-secondary { background-color: #64748b; color: white; }
.btn-secondary:hover:not(:disabled) { background-color: #475569; }

/* ✨ ADD THIS NEW STYLE ✨ */
.btn-accent {
    background-color: var(--accent-color);
    color: white;
}
.btn-accent:hover:not(:disabled) {
    filter: brightness(90%);
}


/* In templates/header.php */

/* ✨ --- Student Page Styles --- ✨ */
.search-input-fix {
    padding-left: 2.75rem !important;
}

[x-cloak] { 
    display: none !important; 
}
/* ... and so on for all other styles ... */

/* In templates/header.php */

html.dark {
    --bg-color: #111827; /* Dark navy background */
    --card-bg-color: #1f2937; /* Slightly lighter card background */
    --input-bg-color: #374151; /* "Dirty white" for textboxes */

    /* ✨ SOFTER & MORE READABLE FONT COLORS ✨ */
    --text-primary: #f1f5f9;   /* A soft, off-white (instead of pure white) */
    --text-secondary: #9ca3af; /* A brighter, clearer gray */

    --border-color: #4b5563; 
    --accent-color: #f87171;
    --accent-color-light: rgba(248, 113, 113, 0.1);
    --logo-icon-color: var(--accent-color);
}

/* --- Light Mode Styles (Default) --- */
    .preview-heading {
        font-size: 1.25rem; /* text-xl */
        font-weight: 700; /* font-bold */
        color: #1e293b; /* A dark slate color, looks like black */
        letter-spacing: -0.025em; /* tracking-tight */
        margin-bottom: 1rem; /* mb-4 */
        padding-bottom: 0.5rem; /* pb-2 */
        border-bottom-width: 2px; /* border-b-2 */
        border-color: #e2e8f0; /* border-slate-200 */
    }

    /* --- Dark Mode Styles --- */
    /* This rule applies only when a parent element has the 'dark' class */
    .dark .preview-heading {
        color: #ffffff; /* Sets text to pure white */
        border-color: #475569; /* A visible gray for the border */
    }
     
    
    /* --- Universal Layout Styles --- */
    #main-content { 
        margin-left: 16rem; 
        transition: margin-left 0.3s ease-in-out;
        will-change: margin-left;
    }
    #sidebar.collapsed { width: 5rem; }
    #sidebar.collapsed .nav-text, #sidebar.collapsed .logo-text { display: none; }
    #sidebar.collapsed .logo-icon-wrapper, #sidebar.collapsed .sidebar-button { justify-content: center; }
    #main-content.collapsed { margin-left: 5rem; }
    
    /* --- (Your existing theme toggle styles can remain here) --- */
    #theme-toggle-checkbox { opacity: 0; height: 0; width: 0; }
    .theme-toggle { position: relative; cursor: pointer; display: inline-block; width: 60px; height: 30px; background: #211042; border-radius: 15px; transition: 500ms; overflow: hidden; }
    .toggle-button { position: absolute; display: inline-block; top: 2px; left: 2px; width: 26px; height: 26px; border-radius: 50%; background: #FAEAF1; overflow: hidden; box-shadow: 0 0 10.5px 1.2px rgba(255, 255, 255); transition: all 500ms ease-out; }
    .crater { position: absolute; display: inline-block; background: #FAEAF1; border-radius: 50%; transition: 500ms; }
    .crater-1 { background: #FFFFF9; width: 26px; height: 26px; left: 3px; bottom: 3px; }
    .crater-2 { width: 6px; height: 6px; top: -2.1px; left: 13.2px; }
    .crater-3 { width: 4.8px; height: 4.8px; top: 6px; right: -1.2px; }
    .crater-4 { width: 3px; height: 3px; top: 7.2px; left: 9px; }
    .crater-5 { width: 4.5px; height: 4.5px; top: 12px; left: 14.4px; }
    .crater-6 { width: 3px; height: 3px; top: 14.4px; left: 6px; }
    .crater-7 { width: 3.6px; height: 3.6px; bottom: 1.5px; left: 10.5px; }
    .star { position: absolute; display: inline-block; border-radius: 50%; background: #FFF; box-shadow: 0.3px 0 0.6px 0.6px rgba(255, 255, 255); }
    .star-1 { width: 1.8px; height: 1.8px; right: 27px; bottom: 12px; }
    .star-2 { width: 2.4px; height: 2.4px; right: 21px; top: 3px; }
    .star-3 { width: 1.5px; height: 1.5px; right: 18px; bottom: 4.5px; }
    .star-4 { width: 0.9px; height: 0.9px; right: 12px; bottom: 15px; }
    .star-5 { width: 1.2px; height: 1.2px; right: 3px; bottom: 10.5px; }
    .star-6, .star-7, .star-8 { width: 3px; height: 0.6px; border-radius: 0.6px; transform: rotate(-45deg); box-shadow: 1.5px 0px 1.2px 0.3px #FFF; animation-name: travel; animation-duration: 1.5s; animation-timing-function: ease-out; animation-iteration-count: infinite; }
    .star-6 { right: 9px; bottom: 9px; animation-delay: -2s; }
    .star-7 { right: 15px; bottom: 18px; }
    .star-8 { right: 27px; top: 3px; animation-delay: -4s; }
    @keyframes travel { 0% { transform: rotate(-45deg) translateX(21px); } 50% { transform: rotate(-45deg) translateX(-6px); box-shadow: 1.5px 0px 1.8px 0.3px #FFF; } 100% { transform: rotate(-45deg) translateX(-9px); width: 0.6px; height: 0.6px; opacity: 0; box-shadow: none; } }
    #theme-toggle-checkbox:checked + .theme-toggle { background: #24D7F7; }
    #theme-toggle-checkbox:checked + .theme-toggle .toggle-button { background: #F7FFFF; transform: translateX(30.6px); box-shadow: 0 0 10.5px 1.5px rgba(255, 255, 255); }
    #theme-toggle-checkbox:checked + .theme-toggle .toggle-button .crater { transform: rotate(-45deg) translateX(21px); }
    #theme-toggle-checkbox:checked + .theme-toggle .star { animation: move 2s infinite; transform: none; box-shadow: none; }
    #theme-toggle-checkbox:checked + .theme-toggle .star-1 { width: 12px; height: 3px; border-radius: 3px; background: #FFF; left: 6px; top: 7.5px; box-shadow: none; }
    #theme-toggle-checkbox:checked + .theme-toggle .star-2 { width: 3.6px; height: 3.6px; background: #FFF; left: 7.8px; top: 6.9px; box-shadow: -0.3px 0 0.6px 0 rgba(0, 0 , 0, 0.1); }
    #theme-toggle-checkbox:checked + .theme-toggle .star-3 { width: 4.8px; height: 4.8px; background: #FFF; left: 10.5px; top: 5.7px; box-shadow: -0.3px 0 0.6px 0 rgba(0, 0 , 0, 0.1); }
    #theme-toggle-checkbox:checked + .theme-toggle .star-4 { width: 4.2px; height: 4.2px; background: #FFF; left: 13.8px; top: 6.3px; box-shadow: -0.3px 0 0.6px 0 rgba(0, 0 , 0, 0.1); }
    #theme-toggle-checkbox:checked + .theme-toggle .star-5 { width: 18px; height: 4.5px; border-radius: 4.5px; background: #FFF; left: 9px; bottom: 6px; box-shadow: none; }
    #theme-toggle-checkbox:checked + .theme-toggle .star-6 { width: 5.4px; height: 5.4px; background: #FFF; border-radius: 50%; left: 11.4px; bottom: 6px; box-shadow: -0.3px 0 0.6px 0 rgba(0, 0 , 0, 0.1); }
    #theme-toggle-checkbox:checked + .theme-toggle .star-7 { width: 7.2px; height: 7.2px; background: #FFF; border-radius: 50%; left: 15.6px; bottom: 6px; box-shadow: -0.3px 0 0.6px 0 rgba(0, 0 , 0, 0.1); }
    #theme-toggle-checkbox:checked + .theme-toggle .star-8 { width: 6.3px; height: 6.3px; background: #FFF; border-radius: 50%; left: 21px; top: 17.7px; box-shadow: -0.3px 0 0.6px 0 rgba(0, 0 , 0, 0.1); }
    @keyframes move { 0% { transform: none; } 25% { transform: translateX(0.6px); } 100% { transform: translateX(-0.6px); } }
    </style>
    

    <script>
        // Set theme on initial load to prevent FOUC (Flash of Unstyled Content)
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        


        
    </script>
</head>
<body class="bg-[var(--bg-color)]">
    <div class="relative min-h-screen flex">
        
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        <script src="js/vendor/chart.min.js"></script>

        