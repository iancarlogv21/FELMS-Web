<?php
session_start();
// Include the Guzzle autoloader 
require_once __DIR__ . '/vendor/autoload.php'; 
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers.php'; // **Using the DEFINITIVE photo path fix**

use MongoDB\BSON\ObjectId;
use GuzzleHttp\Client; 

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$currentPage = 'students';
$pageTitle = 'Add/Edit Student - FELMS';
$student = null;
$isEditMode = false;
$defaultPhoto = 'https://placehold.co/150x150/e2e8f0/475569?text=Photo'; // Ultimate fallback
$initialPhotoUrl = $defaultPhoto; // NEW: Store the initial photo URL separately
$hasExistingPhoto = false; // <<< NEW FLAG ADDED

try {
    $dbInstance = Database::getInstance();
    $studentsCollection = $dbInstance->students();

    // Check for student ID in GET params to determine mode
    $studentId = $_GET['id'] ?? null;
    if ($studentId) {
        $isEditMode = true;
        $student = $studentsCollection->findOne(['_id' => new ObjectId($studentId)]);
        
        if (!$student) {
            $_SESSION['error_message'] = "Student not found.";
            header("location: student.php");
            exit;
        }
        
        $student = (array) $student;
        // This line uses your (now fixed) helpers.php function
        $initialPhotoUrl = getStudentPhotoUrl($student); 
        // <<< FIX: Check if the photo is NOT the ultimate fallback
        $hasExistingPhoto = ($initialPhotoUrl !== $defaultPhoto); 
        $student['photoUrl'] = $initialPhotoUrl;
    } else {
        // For Add Mode
        $initialPhotoUrl = getStudentPhotoUrl([]);
        $student = ['photoUrl' => $initialPhotoUrl];
    }

} catch (Exception $e) {
    $_SESSION['error_message'] = "Database Error: " . $e->getMessage();
    header("location: student.php");
    exit;
}

// =================================================================
// PHP: Define Dropdown Lists (Academic Data)
// ... (rest of PHP code remains the same)
// =================================================================
$programs_list = [
    'Bachelor of Elementary Education (BEED)',
    'Bachelor of Secondary Education Major in English (BSED - English)',
    'Bachelor of Secondary Education Major in Filipino (BSED - Filipino)',
    'Bachelor of Secondary Education Major in Mathematics (BSED - Math)',
    'Bachelor of Science in Information Technology (BSIT)',
    'Bachelor of Science in Computer Science (BSCS)',
    'Bachelor of Science in Business Administration (BSBA)',
    'Bachelor of Science in Accountancy (BSA)',
    'Bachelor of Science in Civil Engineering (BSCE)',
    'Bachelor of Science in Electrical Engineering (BSEE)',
    'Bachelor of Science in Mechanical Engineering (BSME)',
    'Bachelor of Science in Architecture (BS Arch)',
    'Bachelor of Science in Tourism Management (BSTM)',
    'Bachelor of Science in Hospitality Management (BSHM)',
    'Bachelor of Arts in Communication (BA Comm)',
    'Bachelor of Arts in Psychology (BAPSych)',
    'Bachelor of Science in Nursing (BSN)',
    'Bachelor of Science in Pharmacy (BSPharm)',
    'Bachelor of Science in Criminology (BSCrim)',
    'Bachelor of Science in Marine Engineering (BSMarE)',
    'Bachelor of Science in Fisheries (BSF)',
    'Bachelor of Fine Arts (BFA)',
    'Bachelor of Library and Information Science (BLIS)',
    'Bachelor of Science in Biology (BSBio)',
    'Bachelor of Science in Mathematics (BS Math)',
    'Bachelor of Science in Agricultural Technology (BSAT)',
];

$years_list = range(1, 4);
$sections_list = range('A', 'K');


// =================================================================
// PHP: API UTILITY FUNCTION (fetches initial Province list)
// =================================================================

/**
 * Fetches the initial list of Philippine provinces from the PSGC API using Guzzle.
 * @return array List of provinces with structure: ['code' => 'name', ...] OR ['API_ERR' => 'Error...']
 */
function fetchProvinces(): array {
    $client = new Client([
        'base_uri' => 'https://psgc.gitlab.io/api/', 
        'verify' => false,
    ]);
    $provinces = [];
    try {
        // Adding the trailing slash / is CRITICAL for speed on this API
        $response = $client->request('GET', 'provinces/', ['timeout' => 10]);
        $data = json_decode($response->getBody(), true);
        
        if (is_array($data)) {
            foreach ($data as $item) {
                // The new API uses 'code' and 'name'
                if (isset($item['code'], $item['name'])) {
                    $provinces[$item['code']] = $item['name'];
                }
            }
            asort($provinces); // Sorts A-Z so it's easier to find provinces
        }
    } catch (\Exception $e) {
        error_log("PSGC API Error: " . $e->getMessage());
        return ['API_ERR' => 'Connection slow. Please refresh.'];
    }
    return $provinces;
}

// Fetch the list of provinces for the initial dropdown population
$provinces_list = fetchProvinces();

// Get current values from student data
$current_program = $student['program'] ?? '';
$current_year = $student['year'] ?? '';
$current_section = $student['section'] ?? '';

// **FIXED DOB HANDLING**: Ensure DOB is stored/retrieved as YYYY-MM-DD for the input[type="date"]
$current_dob = $student['dob'] ?? '';
if ($current_dob && ($student['dob'] instanceof MongoDB\BSON\UTCDateTime)) {
    // If MongoDB stores it as BSON date, format it for the HTML date input
    $current_dob = $student['dob']->toDateTime()->format('Y-m-d');
} elseif ($current_dob && !empty($current_dob) && strtotime($current_dob) !== false) {
     // If stored as a string, re-format to be safe
     $current_dob = date('Y-m-d', strtotime($current_dob));
} else {
    $current_dob = '';
}


// IMPORTANT: Get the stored PSGC codes AND NAMES
$current_province_code = $student['province_code'] ?? ''; 
$current_city_code = $student['city_code'] ?? '';         
$current_barangay_code = $student['barangay_code'] ?? ''; 
$current_street_name = $student['street_name'] ?? '';

// NEW: Location names for hidden inputs (populated from DB or left blank if adding new)
$current_province_name = $student['province_name'] ?? ''; 
$current_city_name = $student['city_name'] ?? '';         
$current_barangay_name = $student['barangay_name'] ?? ''; 


require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/sidebar.php';
?>

<style>
/* 1. Form Element Styles - Theme Aware */
.form-label { 
    display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500; 
    color: #475569; /* Light Mode Text */
}
html.dark .form-label { 
    color: #94a3b8; /* Dark Mode Text */
}

/* Base style for all inputs/selects */
.form-input { 
    display: block; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.5rem; 
    padding: 0.65rem 0.85rem; background-color: #fff; color: #1e293b; 
    transition: border-color 0.2s, box-shadow 0.2s; 
}
.form-input:focus { 
    border-color: #0ea5e9; /* Sky Blue Focus */ 
    box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.4); outline: none; 
}

/* Dark Mode Overrides for Inputs */
html.dark .form-input {
    background-color: #1e293b; /* slate-800 */
    border-color: #334155; /* slate-700 */
    color: #f1f5f9; /* slate-100 */
}
html.dark .form-input:focus {
    border-color: #0ea5e9; 
    box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.4);
}

/* Readonly fields (like age) in dark mode */
html.dark input[readonly] {
    background-color: #334155 !important; 
    color: #94a3b8 !important;
}
input[readonly] {
    background-color: #f1f5f9 !important; 
    cursor: default;
}


/* 1b. Enhanced Input/Select Styling */
.input-icon-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.input-icon-wrapper .form-input {
    padding-left: 2.5rem; 
}
.input-icon {
    position: absolute;
    left: 0.75rem;
    color: var(--text-secondary); 
    width: 1.25rem;
    height: 1.25rem;
    z-index: 10;
}

/* Custom styling for SELECT elements (to remove native arrow) */
.form-input-select {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    padding-right: 2.5rem !important; 
    padding-left: 1rem !important; 
}

/* Disabled select field look */
.form-input-select:disabled {
    opacity: 0.7;
    background-color: #f1f5f9 !important;
}
html.dark .form-input-select:disabled {
    background-color: #334155 !important;
}

/* Custom arrow icon for the select fields */
.select-icon {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none; 
    width: 1.25rem;
    height: 1.25rem;
    color: #94a3b8; 
    z-index: 5; 
}
html.dark .select-icon {
    color: #64748b; 
}

/* 2. Button Styles */
.btn { 
    display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; 
    padding: 0.65rem 1rem; border-radius: 0.5rem; font-weight: 600; 
    transition: all 0.2s; cursor: pointer; border: none; line-height: 1.5;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}
.btn:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

.btn-primary { background-color: #0ea5e9; color: white; }
.btn-primary:hover { background-color: #0284c7; }

.btn-success { background-color: #22c55e; color: white; }
.btn-success:hover { background-color: #16a34a; }

.btn-danger { background-color: #ef4444; color: white; }
.btn-danger:hover { background-color: #dc2626; }

.btn-neutral { background-color: #e2e8f0; color: #334155; }
.btn-neutral:hover { background-color: #cbd5e1; }
html.dark .btn-neutral { 
    background-color: #374151 !important; 
    color: #e2e8f0 !important; 
}
html.dark .btn-neutral:hover { 
    background-color: #4b5563 !important;
}

</style>

<main id="main-content" class="flex-1 p-6 md:p-10 overflow-y-auto">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
        <div>
            <h1 class="text-4xl font-bold tracking-tight text-primary"><?php echo $isEditMode ? 'Edit Student Details' : 'Add New Student'; ?></h1>
            <p class="text-secondary mt-2"><?php echo $isEditMode ? 'Modify student information for ' . htmlspecialchars($student['last_name'] ?? '') : 'Enter the details for a new student.'; ?></p>
        </div>
        <a href="student.php" class="btn btn-neutral w-40 justify-center mt-4 md:mt-0">
            <i data-lucide="list"></i> Back to List
        </a>
    </header>
    
    <div id="status-messages" class="mb-6">
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-4 rounded-r-lg" role="alert"><p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-4 rounded-r-lg" role="alert"><p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p></div>
        <?php endif; ?>
    </div>
    
    <?php 
    // Display API Error prominently if provinces failed to load
    if (array_key_exists('API_ERR', $provinces_list)): 
    ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-4 rounded-r-lg" role="alert">
        <p class="font-bold">Location Data Error</p>
        <p><?php echo htmlspecialchars($provinces_list['API_ERR']); ?>. Please check the network connection or ensure the `fetch_locations.php` file is correctly configured and accessible.</p>
    </div>
    <?php endif; ?>

    <div class="bg-card p-8 rounded-2xl border border-theme shadow-lg mb-8">
        <h3 class="text-2xl font-bold text-primary mb-6 border-b border-theme pb-4"><?php echo $isEditMode ? 'Student Record' : 'Enrollment Form'; ?></h3>
        
        <form id="studentForm" action="student_actions.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="student_id" id="student_id" value="<?php echo $isEditMode ? htmlspecialchars($student['_id']->__toString()) : ''; ?>">
            
            <input type="hidden" name="province_name" id="province_name" value="<?php echo htmlspecialchars($current_province_name); ?>">
            <input type="hidden" name="city_name" id="city_name" value="<?php echo htmlspecialchars($current_city_name); ?>">
            <input type="hidden" name="barangay_name" id="barangay_name" value="<?php echo htmlspecialchars($current_barangay_name); ?>">
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                    
                    <div>
                        <label for="student_no" class="form-label">Student No. <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="fingerprint" class="input-icon"></i>
                            <input type="text" name="student_no" id="student_no" class="form-input" value="<?php echo htmlspecialchars($student['student_no'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="first_name" class="form-label">First Name <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="user" class="input-icon"></i>
                            <input type="text" name="first_name" id="first_name" class="form-input" value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="middle_name" class="form-label">Middle Name</label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="user" class="input-icon"></i>
                            <input type="text" name="middle_name" id="middle_name" class="form-input" value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div>
                        <label for="last_name" class="form-label">Last Name <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="user" class="input-icon"></i>
                            <input type="text" name="last_name" id="last_name" class="form-input" value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="dob" class="form-label">Birthday <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="cake" class="input-icon"></i>
                            <input type="date" name="dob" id="dob" class="form-input" value="<?php echo htmlspecialchars($current_dob); ?>" required onchange="calculateAge()">
                        </div>
                    </div>

                    <div>
                        <label for="age" class="form-label">Age</label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="smile" class="input-icon"></i>
                            <input type="text" id="age" name="age_display" class="form-input" placeholder="Age" readonly>
                        </div>
                    </div>
                    
                    <div>
                        <label for="email" class="form-label">Email <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="mail" class="input-icon"></i>
                            <input type="email" name="email" id="email" class="form-input" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="gender" class="form-label">Gender</label>
                        <div class="input-icon-wrapper">
                            <select name="gender" id="gender" class="form-input form-input-select" onchange="updateDefaultPhoto(this.value)">
                                <option value="" disabled <?php echo empty($student['gender']) ? 'selected' : ''; ?>>Select Gender</option>
                                <option value="Male" <?php echo (($student['gender'] ?? '') == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (($student['gender'] ?? '') == 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                            <i data-lucide="user-cog" class="select-icon"></i>
                        </div>
                    </div>

                    <div>
                        <label for="program" class="form-label">Program <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <select name="program" id="program" class="form-input form-input-select" required>
                                <option value="" disabled <?php echo empty($current_program) ? 'selected' : ''; ?>>Select Program</option>
                                <?php foreach ($programs_list as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program); ?>" 
                                        <?php echo ($current_program === $program) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($program); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i data-lucide="graduation-cap" class="select-icon"></i>
                        </div>
                    </div>

                    <div>
                        <label for="year" class="form-label">Year <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <select name="year" id="year" class="form-input form-input-select" required>
                                <option value="" disabled <?php echo empty($current_year) ? 'selected' : ''; ?>>Select Year</option>
                                <?php foreach ($years_list as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" 
                                        <?php echo ($current_year == $year) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i data-lucide="calendar-check" class="select-icon"></i>
                        </div>
                    </div>

                    <div>
                        <label for="section" class="form-label">Section <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <select name="section" id="section" class="form-input form-input-select" required>
                                <option value="" disabled <?php echo empty($current_section) ? 'selected' : ''; ?>>Select Section</option>
                                <?php foreach ($sections_list as $section): ?>
                                    <option value="<?php echo htmlspecialchars($section); ?>" 
                                        <?php echo ($current_section === $section) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i data-lucide="hash" class="select-icon"></i>
                        </div>
                    </div>
                    
                    <div>
                        <label for="province_code" class="form-label">Province <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <select name="province_code" id="province_code" class="form-input form-input-select" required onchange="fetchLocationData('city', this.value)">
                                <option value="" disabled selected>Select Province</option>
                                
                                <?php 
                                if (!array_key_exists('API_ERR', $provinces_list)): 
                                    foreach ($provinces_list as $code => $name): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>" 
                                        <?php echo ($current_province_code === $code) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                    <option value="" disabled><?php echo htmlspecialchars($provinces_list['API_ERR']); ?></option>
                                <?php endif; ?>
                            </select>
                            <i data-lucide="map" class="select-icon"></i>
                        </div>
                    </div>

                    <div>
                        <label for="city_code" class="form-label">City/Municipality <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <select name="city_code" id="city_code" class="form-input form-input-select" required disabled onchange="fetchLocationData('barangay', this.value)">
                                <option value="" disabled selected>Select City/Municipality</option>
                                <?php if ($current_city_code && $isEditMode): ?>
                                    <option value="<?php echo htmlspecialchars($current_city_code); ?>" selected>Loading...</option>
                                <?php endif; ?>
                            </select>
                            <i data-lucide="map-pin" class="select-icon"></i>
                        </div>
                    </div>

                    <div>
                        <label for="barangay_code" class="form-label">Barangay <span class="text-red-500">*</span></label>
                        <div class="input-icon-wrapper">
                            <select name="barangay_code" id="barangay_code" class="form-input form-input-select" required disabled>
                                <option value="" disabled selected>Select Barangay</option>
                                <?php if ($current_barangay_code && $isEditMode): ?>
                                    <option value="<?php echo htmlspecialchars($current_barangay_code); ?>" selected>Loading...</option>
                                <?php endif; ?>
                            </select>
                            <i data-lucide="building-2" class="select-icon"></i>
                        </div>
                    </div>

                    <div>
                        <label for="street_name" class="form-label">Street Name</label>
                        <div class="input-icon-wrapper">
                             <i data-lucide="home" class="input-icon"></i>
                            <input type="text" name="street_name" id="street_name" class="form-input" placeholder="e.g., 123 Main Street" value="<?php echo htmlspecialchars($current_street_name); ?>">
                        </div>
                    </div>

                </div>
                
                <div class="flex flex-col items-center">
                    <label class="form-label self-start">Student Photo</label>
                    <img id="photo_preview" src="<?php echo htmlspecialchars($initialPhotoUrl); ?>" alt="Student Photo" class="w-40 h-40 rounded-full object-cover mb-4 border-4 border-theme shadow-md">
                    <label for="image" class="btn btn-neutral w-full text-center">
                        <i data-lucide="upload"></i><span>Choose File</span>
                        <input type="file" name="image" id="image" class="sr-only">
                    </label>
                    <p class="text-xs text-secondary mt-2">Max file size 2MB (JPG, PNG)</p>
                    <p id="current-photo-note" class="text-xs text-secondary mt-1 hidden">Current photo will be replaced upon saving.</p>
                </div>
            </div>
            
            <div id="form-actions" class="mt-8 flex items-center gap-4 border-t border-theme pt-6">
                <?php if ($isEditMode): ?>
                    <button id="btn-update" type="submit" name="action" value="update" class="btn btn-primary w-48 justify-center">
                        <i data-lucide="save"></i> Update Student
                    </button>
                    <button id="btn-delete" type="button" onclick="handleDeleteAction()" class="btn btn-danger w-48 justify-center">
                        <i data-lucide="trash-2"></i> Delete
                    </button>
                <?php else: ?>
                    <button id="btn-save" type="submit" name="action" value="save" class="btn btn-success w-48 justify-center">
                        <i data-lucide="plus-circle"></i> Save Student
                    </button>
                    <button id="btn-clear" type="button" onclick="resetFormState()" class="btn btn-neutral w-48 justify-center">
                        <i data-lucide="x"></i> Clear Form
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</main>

<script>
    // FIX: Pass the initial photo URL and a flag for if an *actual* photo exists
    const initialPhotoUrl = '<?php echo $initialPhotoUrl; ?>'; 
    const defaultFallbackPhoto = '<?php echo $defaultPhoto; ?>'; 
    const isEditMode = <?php echo json_encode($isEditMode); ?>;
    const hasExistingPhoto = <?php echo json_encode($hasExistingPhoto); ?>; 
    
    // PHP variables containing the saved address codes
    // 035400000 is the hardcoded PSGC code for Pampanga
    const currentProvinceCode = '035400000'; 
    const currentCityCode = '<?php echo $current_city_code; ?>';
    const currentBarangayCode = '<?php echo $current_barangay_code; ?>';
    
    // Elements
    const provinceNameInput = document.getElementById('province_name');
    const cityNameInput = document.getElementById('city_name');
    const barangayNameInput = document.getElementById('barangay_name');
    const provinceSelect = document.getElementById('province_code');
    const citySelect = document.getElementById('city_code');
    const barangaySelect = document.getElementById('barangay_code');

    /**
     * Resets dropdowns to default disabled state
     */
    function resetDropdown(type, message = 'Select ' + (type === 'city' ? 'City/Municipality' : 'Barangay')) {
        const select = document.getElementById(type + '_code');
        select.innerHTML = `<option value="" disabled selected>${message}</option>`;
        select.disabled = true;
        document.getElementById(type + '_name').value = '';

        if (type === 'city') {
            resetDropdown('barangay');
        }
    }

    /**
     * Populates dropdowns and handles A-Z sorting
     */
    function populateDropdown(type, data, currentValue) {
        const select = document.getElementById(type + '_code');
        select.innerHTML = `<option value="" disabled selected>Select ${type}</option>`;
        
        if (!data || data.length === 0) {
            select.innerHTML = `<option value="">No data found</option>`;
            return;
        }

        // Sort A-Z for completeness
        data.sort((a, b) => a.name.localeCompare(b.name));

        data.forEach(item => {
            const option = document.createElement('option');
            option.value = item.code;
            option.textContent = item.name;
            if (item.code === currentValue) option.selected = true;
            select.appendChild(option);
        });
        select.disabled = false;
    }

    /**
     * FIXED AJAX FETCH: Optimized to use only one fetch call and handle GitLab mirror
     */
    window.fetchLocationData = async function(targetType, parentCode) {
        if (!parentCode) {
            resetDropdown(targetType);
            return;
        }

        // Standard API endpoint for the GitLab mirror
        const endpoint = targetType === 'city' 
            ? `provinces/${parentCode}/cities-municipalities` 
            : `cities-municipalities/${parentCode}/barangays`;

        resetDropdown(targetType, `Loading ${targetType}...`);
        
        try {
            // Only ONE fetch call to your bridge file
            const response = await fetch(`fetch_locations.php?endpoint=${endpoint}`);
            if (!response.ok) throw new Error('Network response was not ok');
            
            let data = await response.json();

            // Handle unwrapped array or wrapped 'data' key
            const finalData = Array.isArray(data) ? data : (data.data || []); 
            
            let currentValue = null;
            if (isEditMode) {
                if (targetType === 'city' && parentCode === currentProvinceCode) {
                    currentValue = currentCityCode;
                } else if (targetType === 'barangay' && parentCode === currentCityCode) {
                    currentValue = currentBarangayCode;
                }
            }

            populateDropdown(targetType, finalData, currentValue);
            
            // Cascade: If city was pre-selected, trigger barangay load
            if (targetType === 'city' && currentValue) {
                fetchLocationData('barangay', currentValue);
            }

        } catch (error) {
            console.error(`Error fetching ${targetType} data:`, error);
            resetDropdown(targetType, `Error loading ${targetType}.`);
        }
    };

    /**
     * Birthday to Age Calculation
     */
    window.calculateAge = function() {
        const dobInput = document.getElementById('dob');
        const ageInput = document.getElementById('age');
        const dobValue = dobInput.value;

        if (!dobValue) {
            ageInput.value = '';
            return;
        }

        const birthDate = new Date(dobValue);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        ageInput.value = age >= 0 ? age : 0;
    }

    /**
     * Gender-based default photos
     */
    window.updateDefaultPhoto = function(gender) {
        const photoPreview = document.getElementById('photo_preview');
        const currentFile = document.getElementById('image').files[0];
        
        if (currentFile) return;
        if (isEditMode && hasExistingPhoto) return; 

        let newUrl = defaultFallbackPhoto; 
        if (gender) {
            const lowerGender = gender.toLowerCase();
            if (lowerGender === 'female') {
                newUrl = 'pictures/girl.png';
            } else if (lowerGender === 'male') {
                newUrl = 'pictures/boy.png';
            }
        }
        photoPreview.src = newUrl;
    }
    
    // --- EVENT LISTENERS ---
    
    provinceSelect.addEventListener('change', function() {
        provinceNameInput.value = this.options[this.selectedIndex].text;
        fetchLocationData('city', this.value);
    });

    citySelect.addEventListener('change', function() {
        cityNameInput.value = this.options[this.selectedIndex].text;
        fetchLocationData('barangay', this.value);
    });

    barangaySelect.addEventListener('change', function() {
        barangayNameInput.value = this.options[this.selectedIndex].text;
    });

    document.getElementById('image').addEventListener('change', function(event) {
        const photoPreview = document.getElementById('photo_preview');
        if (event.target.files && event.target.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { photoPreview.src = e.target.result; };
            reader.readAsDataURL(event.target.files[0]);
            document.getElementById('current-photo-note').style.display = 'block'; 
        } else {
             photoPreview.src = initialPhotoUrl;
             document.getElementById('current-photo-note').style.display = 'none';
        }
    });

    window.resetFormState = function() {
        document.getElementById('studentForm').reset();
        updateDefaultPhoto(document.getElementById('gender').value);
        document.getElementById('age').value = '';
        resetDropdown('city');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };
    
    window.handleDeleteAction = function() {
        if (!confirm('Are you sure you want to delete this student?')) return;
        const studentId = document.getElementById('student_id').value;
        const tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = 'student_actions.php';
        
        const inputs = { 'student_id': studentId, 'action': 'delete' };
        for (let key in inputs) {
            let input = document.createElement('input');
            input.type = 'hidden'; input.name = key; input.value = inputs[key];
            tempForm.appendChild(input);
        }
        document.body.appendChild(tempForm);
        tempForm.submit();
    }

    /**
     * INITIALIZE
     */
    document.addEventListener('DOMContentLoaded', () => {
        // Calculate Age
        if (document.getElementById('dob').value) calculateAge();

        // PAMPANGA HARDCODE: Auto-trigger City fetch on load
        // This makes the cities of Pampanga load instantly without clicking anything
        if (provinceSelect.value === currentProvinceCode) {
            fetchLocationData('city', currentProvinceCode);
        }
        
        // Gender Photo Init
        updateDefaultPhoto(document.getElementById('gender').value);

        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
?>