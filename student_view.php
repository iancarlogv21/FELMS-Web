<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php'; // **REQUIRED**
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\BSON\ObjectId;

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$currentPage = 'students';
$pageTitle = 'Student Profile - FELMS';
$student = null;
$studentId = $_GET['id'] ?? null;
$defaultPhoto = 'https://placehold.co/150x150/e2e8f0/475569?text=Photo';

if (!$studentId) {
    $_SESSION['error_message'] = "Student ID is missing.";
    header("location: student.php");
    exit;
}

try {
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();

    $student = $studentsCollection->findOne(['_id' => new ObjectId($studentId)]);
    
    if (!$student) {
        $_SESSION['error_message'] = "Student not found.";
        header("location: student.php");
        exit;
    }
    
    $student = (array) $student;
    $student['photoUrl'] = getStudentPhotoUrl($student);

} catch (Exception $e) {
    $_SESSION['error_message'] = "Database Error: " . $e->getMessage();
    header("location: student.php");
    exit;
}

// =================================================================
// DATA PROCESSING FOR DISPLAY
// =================================================================

// 1. Birthday/Age Calculation
$displayDob = 'N/A';
$displayAge = 'N/A';
if (isset($student['dob']) && ($student['dob'] instanceof MongoDB\BSON\UTCDateTime)) {
    $dobDateTime = $student['dob']->toDateTime();
    $displayDob = $dobDateTime->format('F d, Y');
    
    $now = new DateTime();
    $interval = $now->diff($dobDateTime);
    $displayAge = $interval->y . ' years old';
}

// 2. Main Student Information Data
$infoData = [
    'student_no' => [
        'label' => 'Student No.',
        'value' => htmlspecialchars($student['student_no'] ?? 'N/A'),
        'icon' => 'fingerprint',
        'class' => 'font-mono' // Monospace for the ID
    ],
    'email' => [
        'label' => 'Email',
        'value' => htmlspecialchars($student['email'] ?? 'N/A'),
        'icon' => 'mail',
        'class' => ''
    ],
    'dob' => [
        'label' => 'Birthday',
        'value' => $displayDob,
        'icon' => 'cake',
        'class' => ''
    ],
    'age' => [
        'label' => 'Age',
        'value' => $displayAge,
        'icon' => 'smile',
        'class' => ''
    ],
    'program' => [
        'label' => 'Program',
        'value' => htmlspecialchars($student['program'] ?? 'N/A'),
        'icon' => 'graduation-cap',
        'class' => ''
    ],
    'year_section' => [
        'label' => 'Year & Section',
        'value' => htmlspecialchars($student['year'] ?? 'N/A') . ' - ' . htmlspecialchars($student['section'] ?? 'N/A'),
        'icon' => 'calendar-check',
        'class' => ''
    ],
    'gender' => [
        'label' => 'Gender',
        'value' => htmlspecialchars($student['gender'] ?? 'N/A'),
        'icon' => 'user',
        'class' => ''
    ],
    'created_at' => [
        'label' => 'Date Added',
        'value' => formatDate($student['created_at'] ?? null),
        'icon' => 'clock',
        'class' => ''
    ],
];


// 3. Address Construction (Structured for display with icons)
// The names (province_name, city_name, barangay_name) are saved during the student_actions.php save/update.
$addressData = [
    'street' => [
        'label' => 'Street Name',
        'value' => htmlspecialchars($student['street_name'] ?? 'N/A'),
        'icon' => 'home'
    ],
    'barangay' => [
        'label' => 'Barangay',
        // FIX: Prioritize name, then fall back to code/label if name is missing
        'value' => htmlspecialchars($student['barangay_name'] ?? ('Brgy. Code: ' . ($student['barangay_code'] ?? 'N/A'))),
        'icon' => 'building-2'
    ],
    'city' => [
        'label' => 'City/Municipality',
        'value' => htmlspecialchars($student['city_name'] ?? ('City Code: ' . ($student['city_code'] ?? 'N/A'))),
        'icon' => 'map-pin'
    ],
    'province' => [
        'label' => 'Province',
        // FIX: Prioritize name, then fall back to code/label if name is missing
        'value' => htmlspecialchars($student['province_name'] ?? ('Prov. Code: ' . ($student['province_code'] ?? 'N/A'))),
        'icon' => 'map'
    ],
];

// Combine all parts into a single string for the 'Combined Address' field
$addressParts = [];
if (!empty($student['street_name'])) { 
    $addressParts[] = htmlspecialchars($student['street_name']); 
}
if (!empty($student['barangay_name'])) { 
    $addressParts[] = htmlspecialchars($student['barangay_name']); 
}
if (!empty($student['city_name'])) { 
    $addressParts[] = htmlspecialchars($student['city_name']); 
}
if (!empty($student['province_name'])) { 
    $addressParts[] = htmlspecialchars($student['province_name']); 
}

$fullAddressString = implode(', ', $addressParts);


require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';

// Helper for displaying dates, assumes a function like this exists in helpers.php or is defined globally
function formatDate($date) {
// ... (function definition)
    return $date; // return raw if format is unexpected
}
?>

<?php if (isset($_SESSION['success_message'])): ?>
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show" x-transition.duration.300ms 
        class="fixed top-4 left-1/2 transform -translate-x-1/2 z-50 p-4 rounded-lg shadow-xl bg-green-500 text-white flex items-center gap-3 max-w-sm w-full" 
        role="alert">
        <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0"></i>
        <p class="font-medium flex-grow"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
        <button @click="show = false" class="text-white/80 hover:text-white transition-colors">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>
    <?php 
        // CRUCIAL STEP: Clear the message immediately after displaying it
        unset($_SESSION['success_message']); 
    ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show" x-transition.duration.300ms 
        class="fixed top-4 left-1/2 transform -translate-x-1/2 z-50 p-4 rounded-lg shadow-xl bg-red-600 text-white flex items-center gap-3 max-w-sm w-full" 
        role="alert">
        <i data-lucide="alert-triangle" class="w-5 h-5 flex-shrink-0"></i>
        <p class="font-medium flex-grow"><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
        <button @click="show = false" class="text-white/80 hover:text-white transition-colors">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>
    <?php 
        // Also clear the error message
        unset($_SESSION['error_message']); 
    ?>
<?php endif; ?>

<style>
    /* 1. Base Button Styles (Use theme variables for dark mode support) */
    .btn { 
        display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; 
        padding: 0.65rem 1rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s; 
        cursor: pointer; border: none; line-height: 1.5; 
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    }
    .btn:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.06); }
    
    /* Primary (Accent/Orange) - Used for Edit Profile */
    /* Assumes var(--accent-color) is set in header.php/global styles, likely orange */
    .btn-primary { background-color: var(--accent-color); color: white; }
.btn-primary:hover { 
    /* FIX: Force the primary color and darken it using a filter */
    background-color: var(--accent-color); 
    filter: brightness(0.85); 
}

    /* Success (Green) - Used for View Library Card */
    .btn-success { background-color: #22c55e; color: white; }
    .btn-success:hover { background-color: #16a34a; }

    /* Neutral (Theme-Agnostic Gray) - Used for Back to List */
    .btn-neutral { background-color: #e2e8f0; color: #334155; }
    .btn-neutral:hover { background-color: #cbd5e1; }
    html.dark .btn-neutral { 
        background-color: #374151 !important; /* slate-700 */
        color: #e2e8f0 !important; /* slate-200 */
    }
    html.dark .btn-neutral:hover { 
        background-color: #4b5563 !important; /* slate-600 */
    }

    /* 2. Tertiary Button for View History (Modern, Subtle, and not dark on hover) */
    .btn-tertiary { 
        background-color: #f1f5f9; /* Light Gray */
        color: #475569; /* Slate-600 */
        border: 1px solid #cbd5e1; /* Slate-300 */
        box-shadow: none;
    }
    .btn-tertiary:hover { 
        background-color: #e2e8f0; /* Slightly darker hover */
        box-shadow: none;
    }
    /* Dark mode override for the tertiary button - retains a light background in a dark context */
    html.dark .btn-tertiary { 
        background-color: #1e293b; /* slate-800 */
        color: #94a3b8; /* slate-400 */
        border-color: #475569; /* slate-600 */
    }
    html.dark .btn-tertiary:hover { 
        background-color: #334155; /* slate-700 hover */
    }

    /* 3. Modern Data Field Styles */
    .data-field {
        padding: 0.75rem 0;
        /* Use theme border for cleaner look */
        border-bottom: 1px solid var(--border-color); 
    }
    .data-label {
        color: var(--text-secondary); 
        font-size: 0.875rem; /* text-sm */
        font-weight: 500;
        margin-bottom: 0.25rem;
    }
    .data-value {
        color: var(--text-primary); 
        font-size: 1.125rem; /* text-lg */
        font-weight: 600;
    }
    
    /* Styling for the address block to integrate icons */
    .address-detail-field {
        padding: 0.5rem 0; /* Slightly less padding to stack them neatly */
        border-bottom: 1px dashed var(--border-color); /* Use a dashed line for separation */
    }

    /* Misc */
    [x-cloak] { display: none !important; }

    /* Print styles from original student.php to ensure the card prints correctly */
    @media print {
        body > * { display: none !important; }
        #card-modal-wrapper, #library-card-container { display: flex !important; align-items: center; justify-content: center; }
        #library-card { margin: 0; box-shadow: none; border: 1px solid #ccc; }
    }
</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto" x-data="studentPage('<?php echo $studentId; ?>')">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
        <div>
            <h1 class="text-4xl font-bold tracking-tight text-primary">Student Profile</h1>
            <p class="text-secondary mt-2">Details for <?php echo htmlspecialchars($student['first_name'] ?? 'N/A') . ' ' . htmlspecialchars($student['last_name'] ?? ''); ?></p>
        </div>
        <div class="flex items-center gap-4 mt-4 md:mt-0">
            <a href="student_edit.php?id=<?php echo $studentId; ?>" class="btn btn-primary w-40 justify-center">
                <i data-lucide="edit-3"></i> Edit Profile
            </a>
            <a href="student.php" class="btn btn-neutral w-40 justify-center">
                <i data-lucide="list"></i> Back to List
            </a>
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-1 bg-card p-8 rounded-2xl border border-theme shadow-sm flex flex-col items-center">
            <img src="<?php echo htmlspecialchars($student['photoUrl'] ?? $defaultPhoto); ?>" alt="Student Photo" class="w-48 h-48 rounded-full object-cover mb-6 border-4 border-theme shadow-md">
            <h2 class="text-3xl font-bold mb-1 text-center text-primary"><?php echo htmlspecialchars($student['first_name'] ?? '') . ' ' . htmlspecialchars($student['middle_name'] ?? '') . ' ' . htmlspecialchars($student['last_name'] ?? ''); ?></h2>
            <p class="text-lg text-secondary mb-6 font-mono"><?php echo htmlspecialchars($student['student_no'] ?? 'N/A'); ?></p>

            <div class="w-full space-y-3">
                <button @click="openCardModal()" class="btn btn-success w-full justify-center">
                    <i data-lucide="id-card"></i> View Library Card
                </button>
                
                <button 
                    @click="sendCardEmail('<?php echo htmlspecialchars($student['email'] ?? ''); ?>')" 
                    :disabled="isEmailLoading" 
                    class="btn btn-primary w-full justify-center bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
                >
                    <template x-if="!isEmailLoading"><i data-lucide="send"></i></template>
                    <template x-if="isEmailLoading">
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <span x-text="isEmailLoading ? 'Sending...' : 'Email Library Card'"></span>
                </button>
                
                <a href="student_borrow_history.php?id=<?php echo $studentId; ?>" class="btn btn-tertiary w-full justify-center">
                    <i data-lucide="history"></i> View Borrow History
                </a>
            </div>
        </div>

        <div class="lg:col-span-2 bg-card p-8 rounded-2xl border border-theme shadow-sm">
            <h3 class="text-2xl font-bold mb-6 border-b border-theme pb-3 text-primary">Student Information</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-0">
                
                <?php foreach ($infoData as $key => $data): ?>
                <div class="data-field flex items-center gap-4">
                    <i data-lucide="<?php echo $data['icon']; ?>" class="w-6 h-6 text-primary flex-shrink-0"></i>
                    <div class="flex-grow">
                        <p class="data-label m-0 text-sm"><?php echo $data['label']; ?></p>
                        <p class="data-value text-base font-semibold <?php echo $data['class']; ?>"><?php echo $data['value'] ?? 'N/A'; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="md:col-span-2">
                <h4 class="text-xl font-bold border-t border-theme pt-6 mt-6 mb-4 text-primary">Residential Address</h4>
                
                <?php foreach ($addressData as $data): ?>
                <div class="address-detail-field flex items-center gap-4">
                    <i data-lucide="<?php echo $data['icon']; ?>" class="w-6 h-6 text-primary flex-shrink-0"></i>
                    <div class="flex-grow">
                        <p class="data-label m-0 text-sm"><?php echo $data['label']; ?></p>
                        <p class="data-value text-base font-semibold"><?php echo $data['value'] ?? 'N/A'; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="address-detail-field md:col-span-2 text-sm text-secondary pt-2">
                    <p class="data-label">Combined Address (for reference)</p>
                    <p class="text-primary font-medium"><?php echo $fullAddressString ?: 'N/A (All fields empty)'; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div id="card-modal-wrapper" x-show="isModalOpen" x-cloak class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 z-50" @keydown.escape.window="closeModal()">
        <div @click.away="closeModal()" class="bg-card rounded-2xl shadow-2xl p-6 md:p-8 w-full max-w-lg mx-auto">
            <h3 class="text-2xl font-bold text-primary mb-6 text-center">Library Card</h3>
            
            <template x-if="isLoading"><p class="text-secondary my-10 text-center">Generating card, please wait...</p></template>
            <template x-if="error"><p class="text-red-600 bg-red-100 p-4 rounded-lg my-6 text-center" x-text="error"></p></template>

            <template x-if="cardData">
                <div id="library-card-container" class="flex justify-center mb-8">
                    <div id="library-card" class="w-[350px] h-auto rounded-xl shadow-2xl p-4 flex flex-col gap-3 font-sans relative overflow-hidden bg-gradient-to-br from-red-600 to-red-800 text-white select-none">
                        <div class="absolute -top-4 -right-12 w-32 h-32 border-4 border-white/10 rounded-full z-0"></div>
                        <div class="absolute top-16 -right-4 w-16 h-16 bg-white/5 rounded-full z-0"></div>

                        <div class="flex items-center gap-3 z-10">
                            <div class="bg-white/90 p-2 rounded-lg shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-red-600 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                            </div>
                            <div>
                                <h1 class="font-bold text-base leading-tight tracking-wide">Fast & Efficient LMS</h1>
                                <p class="text-sm text-red-200">Student Library Card</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-4 z-10 mt-2">
                            <div class="w-20 h-20 rounded-full bg-slate-200/80 flex items-center justify-center border-4 border-white/40 shadow-lg flex-shrink-0">
                                <img :src="cardData.photoUrl" alt="Student Photo" class="w-full h-full rounded-full object-cover" crossorigin="anonymous">
                            </div>
                            <div>
                                <p class="font-bold text-xl leading-tight" x-text="cardData.fullName"></p>
                                <div class="mt-1">
                                    <p class="text-xs font-semibold text-red-200 uppercase tracking-wider">Program</p>
                                    <p class="text-base font-medium" x-text="cardData.program"></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg p-2 flex items-center gap-3 z-10 shadow-inner mt-2">
                            <div class="flex-grow text-center">
                                <img :src="cardData.barcodeBase64" alt="Barcode" class="h-10 object-contain mx-auto" x-show="cardData.barcodeBase64">
                                <p class="text-xs font-mono font-semibold text-slate-700 text-center" x-text="cardData.student_no"></p>
                            </div>
                            <div class="border-l border-slate-300 pl-3 pr-1 text-right self-stretch flex flex-col justify-center">
                                <p class="text-[9px] font-bold text-slate-500 tracking-wider">STUDENT NO.</p>
                                <p class="text-sm font-mono font-semibold text-slate-800" x-text="cardData.student_no"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            
            <div class="flex items-center justify-center gap-4 mt-6">
                <button type="button" x-show="cardData" @click="downloadCard()" class="btn btn-success"><i data-lucide="download"></i>Download</button>
                <button type="button" @click="closeModal()" class="btn btn-neutral">Close</button>
            </div>
        </div>
    </div>
</main>


<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    // --- AlpineJS Data for Modal ---
    function studentPage(studentId) {
        return {
            isModalOpen: false, isLoading: false, error: '', cardData: null,
            isEmailLoading: false, // <-- NEW STATE
            
            // openCardModal remains the same...
            openCardModal() {
                this.isModalOpen = true; this.isLoading = true; this.cardData = null; this.error = '';
                fetch(`api_student_card.php?id=${studentId}`)
                    .then(res => res.ok ? res.json() : Promise.reject(new Error('Network response was not ok.')))
                    .then(data => { 
                        if (data.error) throw new Error(data.error); 
                        this.cardData = data; 
                        // Fix to ensure barcode is drawn first
                        setTimeout(() => { this.isLoading = false; }, 100); 
                    })
                    .catch(err => {
                        this.error = 'Failed to generate card: ' + err.message;
                        this.isLoading = false;
                    })
            },

            // UPDATED: Function to send the library card via email
        sendCardEmail(studentEmail) {
            if (!studentEmail || studentEmail.includes('N/A')) {
                alert('Error: Student email address is missing or invalid. Please update the profile.');
                return;
            }

            // 1. Set loading state
            this.isEmailLoading = true;
            
            // 2. The email_card.php script will handle fetching data, sending the email, and setting a session message
            fetch(`email_card.php?id=${studentId}`, { method: 'POST' })
                .then(res => res.ok ? res.json() : Promise.reject(new Error('Network response not ok.')))
                .then(data => {
                    if (data.success) {
                        // 3. Instead of an alert, we redirect to trigger a full page load
                        // The server-side script must have set a success message in $_SESSION['success_message']
                        window.location.href = `student_view.php?id=${studentId}`;
                    } else {
                        throw new Error(data.error || 'Failed to send email. Check server logs.');
                    }
                })
                .catch(err => {
                    console.error('Email failed:', err);
                    alert('Error: ' + err.message);
                })
                .finally(() => {
                    this.isEmailLoading = false;
                });
        },
            
            closeModal() { this.isModalOpen = false; },
            
            downloadCard() {
                const cardElement = document.getElementById('library-card');

                html2canvas(cardElement, { 
                    scale: 3,
                    useCORS: true  
                }).then(canvas => {
                    const link = document.createElement('a');
                    const fileName = this.cardData.fullName ? this.cardData.fullName.replace(/\s+/g, '_') : 'student';
                    link.download = `library-card-${fileName}.png`;
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                }).catch(err => {
                    console.error('Error generating card image:', err);
                    alert('An error occurred while trying to download the card. Please check the console for details.');
                });
            }
        }
    }

    // Initialize Lucide icons
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>