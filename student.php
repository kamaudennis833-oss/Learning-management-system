<?php
session_start();
include 'db.php';
include 'advance.php';

/* ====
   AUTH CHECK
==== */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ====
   CSRF TOKEN
==== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ====
 validate CSRF
==== */
function validateCsrf(): void {
    if (
        empty($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die("Invalid CSRF token. Request blocked.");
    }
}

/* ====
   COMPUTE LIVE COURSE PROGRESS
==== */
function computeCourseProgress(mysqli $conn, int $user_id, int $course_id): int {
    $video_progress = 0;
    $video_count = 0;
    $stmt = $conn->prepare("
        SELECT cv.id, COALESCE(MAX(vp.watched_percentage), 0) AS pct
        FROM course_videos cv
        LEFT JOIN video_progress vp ON vp.content_id = cv.id AND vp.user_id = ?
        WHERE cv.course_id = ?
        GROUP BY cv.id
    ");
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $sum = 0;
    while ($row = $res->fetch_assoc()) {
        $video_count++;
        $sum += (int)$row['pct'];
    }
    $stmt->close();
    if ($video_count > 0) {
        $video_progress = (int)round($sum / $video_count);
    }
    /* Notes */
    $note_progress = 0;
    $note_count = 0;
    $stmt = $conn->prepare("
        SELECT n.id, COALESCE(MAX(np.completed), 0) AS completed
        FROM notes n
        LEFT JOIN note_progress np ON np.note_id = n.id AND np.user_id = ?
        WHERE n.course_id = ?
        GROUP BY n.id
    ");
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $doneNotes = 0;
    while ($row = $res->fetch_assoc()) {
        $note_count++;
        if ((int)$row['completed'] === 1) $doneNotes++;
    }
    $stmt->close();
    if ($note_count > 0) {
        $note_progress = (int)round(($doneNotes / $note_count) * 100);
    }

    /* Quizzes */
    $quiz_progress = 0;
    $stmt = $conn->prepare("SELECT id FROM quizzes WHERE course_id = ?");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $quizIds = array_map(fn($r) => (int)$r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    $quiz_count = count($quizIds);
    if ($quiz_count > 0) {
        $in_clause = implode(',', $quizIds);
        $statusMap = [];
        $r = $conn->query("
   SELECT quiz_id, status FROM quiz_results
   WHERE user_id={$user_id} AND quiz_id IN ($in_clause)
   ORDER BY completed_at DESC
        ");
        while ($row = $r->fetch_assoc()) {
   $qid = (int)$row['quiz_id'];
   if (!isset($statusMap[$qid])) $statusMap[$qid] = $row['status'];
        }
        $passed = count(array_filter($statusMap, fn($s) => $s === 'passed'));
        $quiz_progress = (int)round(($passed / $quiz_count) * 100);
    }

    /* Weighted overall progress */
    $w_video = ($video_count > 0) ? 0.60 : 0;
    $w_note  = ($note_count  > 0) ? 0.25 : 0;
    $w_quiz  = ($quiz_count  > 0) ? 0.15 : 0;
    $w_total = $w_video + $w_note + $w_quiz;
    if ($w_total <= 0) return 0;
    return (int)round(($video_progress * $w_video + $note_progress * $w_note + $quiz_progress * $w_quiz) / $w_total);
}

/* ====
   UPDATE PROFILE
==== */
if (isset($_POST['update_profile'])) {
    validateCsrf();
    $full_name = trim(strip_tags($_POST['full_name'] ?? ''));
    $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $phone     = trim(strip_tags($_POST['phone']    ?? ''));
    $location  = trim(strip_tags($_POST['location'] ?? ''));
    $bio       = trim(strip_tags($_POST['bio']      ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = "Invalid email address.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, location=?, bio=? WHERE id=?");
    $stmt->bind_param("sssssi", $full_name, $email, $phone, $location, $bio, $user_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE students SET full_name=?, email=?, phone=? WHERE user_id=?");
    $stmt->bind_param("sssi", $full_name, $email, $phone, $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash'] = "Profile updated successfully.";
    $_SESSION['active_section'] = 'profile';
    header("Location: student.php");
    exit;
}

/* ====
   SIGN ATTENDANCE
==== */
if (isset($_POST['sign_attendance'])) {
    validateCsrf();

    $att_course_id = (int)($_POST['course_id'] ?? 0);

    if ($att_course_id <= 0) {
        $_SESSION['flash'] = "Please select a course.";
    } else {
        $stmt = $conn->prepare("
   SELECT id FROM attendance
   WHERE user_id=? AND course_id=? AND DATE(attendance_date)=CURDATE()
        ");
        $stmt->bind_param("ii", $user_id, $att_course_id);
        $stmt->execute();
        $already = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($already) {
   $_SESSION['flash'] = "You already signed attendance today.";
        } else {
   $stmt = $conn->prepare("
       INSERT INTO attendance (user_id, course_id, attendance_date, status)
       VALUES (?, ?, CURDATE(), 'present')
   ");
   $stmt->bind_param("ii", $user_id, $att_course_id);
   $stmt->execute();
   $stmt->close();
   $_SESSION['flash'] = "Attendance signed successfully.";
        }
    }
    $_SESSION['active_section'] = 'attendance';
    header("Location: student.php");
    exit;
}

/* ====
   PAYMENT
==== */
if (isset($_POST['submit_payment'])) {
    validateCsrf();

    $pay_course_id = (int)($_POST['course_id'] ?? 0);
    $method        = trim(strip_tags($_POST['payment_method'] ?? ''));
    $phone_no      = trim(strip_tags($_POST['payer_phone']    ?? ''));

    if ($pay_course_id <= 0) {
        $_SESSION['flash'] = "Invalid payment data.";
        $_SESSION['active_section'] = 'payments';
        header("Location: student.php");
        exit;
    }
    /* Prevent paying for an already-enrolled course */
    $stmt = $conn->prepare("SELECT id FROM enrollments WHERE user_id=? AND course_id=?");
    $stmt->bind_param("ii", $user_id, $pay_course_id);
    $stmt->execute();
    $already_enrolled_check = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($already_enrolled_check) {
        $_SESSION['flash'] = "You are already enrolled in this course.";
        $_SESSION['active_section'] = 'payments';
        header("Location: student.php");
        exit;
    }
    $stmt = $conn->prepare("SELECT title, price, course_type, status FROM courses WHERE id=?");
    $stmt->bind_param("i", $pay_course_id);
    $stmt->execute();
    $pay_course = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pay_course) {
        $_SESSION['flash'] = "Selected course could not be found.";
        $_SESSION['active_section'] = 'payments';
        header("Location: student.php");
        exit;
    }

    if (($pay_course['course_type'] ?? '') !== 'Paid' || ($pay_course['status'] ?? '') !== 'Active') {
        $_SESSION['flash'] = "This course is not available for payment.";
        $_SESSION['active_section'] = 'payments';
        header("Location: student.php");
        exit;
    }
    $courseTitle = $pay_course['title'];
    $amount = (float)$pay_course['price']; 
    $invoiceNo   = "INV" . time();

    $stmt = $conn->prepare("
        INSERT INTO payments
   (user_id, course_id, payer_name, payer_phone, amount, payment_method, status, verification_status)
        VALUES (?, ?, 'Student', ?, ?, ?, 'success', 'verified')
    ");
    $stmt->bind_param("iisds", $user_id, $pay_course_id, $phone_no, $amount, $method);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO enrollments (user_id, course_id, progress, status) VALUES (?, ?, 0, 'approved')
    ");
    $stmt->bind_param("ii", $user_id, $pay_course_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO invoices (user_id, invoice_no, description, status) VALUES (?, ?, ?, 'paid')
    ");
    $stmt->bind_param("iss", $user_id, $invoiceNo, $courseTitle);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash'] = "Payment successful. You are now enrolled in \"" . htmlspecialchars($courseTitle) . "\".";
    $_SESSION['active_section'] = 'payments';
    header("Location: student.php");
    exit;
}

/* ====
   CHANGE PASSWORD
==== */
if (isset($_POST['change_password'])) {
    validateCsrf();
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password  = (string)($_POST['confirm_password'] ?? '');
    if ($new_password !== $confirm_password) {
        $_SESSION['flash'] = "New password and confirmation do not match.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }
    if (strlen($new_password) < 8) {
        $_SESSION['flash'] = "New password must be at least 8 characters.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    $stmt = $conn->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$userRow || !password_verify($current_password, $userRow['password'])) {
        $_SESSION['flash'] = "Current password is incorrect.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    $newHash = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
    $stmt->bind_param("si", $newHash, $user_id);
    $stmt->execute();
    $stmt->close();

    /* Keep user_settings.password_updated_at in sync, */
    $stmt = $conn->prepare("
        INSERT INTO user_settings (user_id, password_updated_at)
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE password_updated_at = NOW()
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash'] = "Password updated successfully.";
    $_SESSION['active_section'] = 'profile';
    header("Location: student.php");
    exit;
}

/* ====
   UPLOAD PROFILE PHOTO
==== */
if (isset($_POST['upload_photo'])) {
    validateCsrf();

    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = "Please choose a valid image to upload.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    $file = $_FILES['profile_photo'];

    /* 5 MB max */
    if ($file['size'] > 5 * 1024 * 1024) {
        $_SESSION['flash'] = "Image is too large. Max size is 2 MB.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    /* Verify actual image type server-side  */
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($file['tmp_name']);
    $detectedMime = $imageInfo['mime'] ?? null;

    if (!$detectedMime || !isset($allowedMimes[$detectedMime])) {
        $_SESSION['flash'] = "Invalid image file. Only JPG, PNG, and WEBP are allowed.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    $ext      = $allowedMimes[$detectedMime];
    $filename = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destDir  = __DIR__ . '/uploads';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $destPath = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $_SESSION['flash'] = "Failed to save uploaded image.";
        $_SESSION['active_section'] = 'profile';
        header("Location: student.php");
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET profile_image=? WHERE id=?");
    $stmt->bind_param("si", $filename, $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash'] = "Profile photo updated.";
    $_SESSION['active_section'] = 'profile';
    header("Location: student.php");
    exit;
}

/* ====
   ENROLLED COURSE IDs + LIVE PROGRESS MAP
==== */
$enrolledCourseMap = []; // course_id => status
$stmt = $conn->prepare("SELECT course_id, status FROM enrollments WHERE user_id=?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$enrResult = $stmt->get_result();
while ($r = $enrResult->fetch_assoc()) {
    $enrolledCourseMap[(int)$r['course_id']] = $r['status'];
}
$stmt->close();

$courseProgressMap = []; 
foreach (array_keys($enrolledCourseMap) as $cid) {
    $courseProgressMap[$cid] = computeCourseProgress($conn, $user_id, $cid);
}

/* ====
   FETCH ALL DATA
==== */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS enrolled,
  SUM(status='completed') AS completed,
  SUM(status='ongoing')   AS ongoing
    FROM enrollments WHERE user_id=?
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$data      = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();

$enrolled  = (int)($data['enrolled']     ?? 0);
$completed = (int)($data['completed']    ?? 0);
$ongoing   = (int)($data['ongoing']      ?? 0);

/* Average progress computed live across all enrolled courses */
$progress = $enrolled > 0
    ? (int)round(array_sum($courseProgressMap) / count($courseProgressMap))
    : 0;

/* Course stats */
$total_courses     = $enrolled;
$active_courses    = $ongoing;
$completed_courses = $completed;

/* Certificates */
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM certificates WHERE user_id=?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$certificates_earned = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

/* Dashboard widget courses */
$stmt = $conn->prepare("
    SELECT c.id, c.title, c.lessons, e.status
    FROM courses c
    INNER JOIN enrollments e ON c.id = e.course_id
    WHERE e.user_id = ?
      AND e.status IN ('ongoing', 'approved')
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$courses_widget = $stmt->get_result(); $stmt->close();
$courses_widget_rows = [];
while ($row = $courses_widget->fetch_assoc()) {
    $row['progress'] = $courseProgressMap[(int)$row['id']] ?? 0;
    $courses_widget_rows[] = $row;
}
usort($courses_widget_rows, fn($a, $b) => $b['progress'] <=> $a['progress']);
$courses_widget_rows = array_slice($courses_widget_rows, 0, 5);

/* Activities */
$stmt = $conn->prepare("
    SELECT message, created_at FROM activities WHERE user_id=? ORDER BY created_at DESC LIMIT 5
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$activities = $stmt->get_result(); $stmt->close();

/* Bookmarks */
$stmt = $conn->prepare("
    SELECT title, created_at FROM bookmarks WHERE user_id=? ORDER BY created_at DESC LIMIT 10
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$bookmarks = $stmt->get_result(); $stmt->close();

/* Certificates */
$stmt = $conn->prepare("SELECT * FROM certificates WHERE user_id=? ORDER BY issued_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$certs = $stmt->get_result(); $stmt->close();

/* Notifications */
$stmt = $conn->prepare("
    SELECT message, created_at AS notification_date, 'notification' AS type
    FROM notifications WHERE user_id=?
    UNION ALL
    SELECT CONCAT('[Announcement] ', a.title, ' - ', a.message), a.created_at, 'announcement'
    FROM announcements a
    INNER JOIN enrollments e ON e.course_id=a.course_id
    WHERE e.user_id=? AND e.status IN ('approved','ongoing','completed')
    ORDER BY notification_date DESC LIMIT 10
");
$stmt->bind_param("ii", $user_id, $user_id); $stmt->execute();
$notifications = $stmt->get_result(); $stmt->close();

/* User profile */
$stmt = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $user_id); $stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$profile) {
    $profile = ['full_name'=>'Unknown','email'=>'','phone'=>'','location'=>'','bio'=>'','profile_image'=>'','created_at'=>date('Y-m-d')];
}

/* Active courses */
$stmt = $conn->prepare("
    SELECT c.id, c.title, c.teacher_id, c.lessons
    FROM enrollments e INNER JOIN courses c ON e.course_id=c.id
    WHERE e.user_id=? AND e.status='ongoing' ORDER BY e.id DESC
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$active_courses_result = $stmt->get_result(); $stmt->close();
$active_courses_list = [];
while ($row = $active_courses_result->fetch_assoc()) {
    $row['progress'] = $courseProgressMap[(int)$row['id']] ?? 0;
    $active_courses_list[] = $row;
}

/* Completed courses */
$stmt = $conn->prepare("
    SELECT c.title, e.completed_at FROM enrollments e
    INNER JOIN courses c ON e.course_id=c.id
    WHERE e.user_id=? AND e.status='completed' ORDER BY e.completed_at DESC
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$completed_courses_list = $stmt->get_result(); $stmt->close();

/* Assignments */
$stmt = $conn->prepare("
    SELECT a.* FROM assignments a
    INNER JOIN enrollments e ON e.course_id=a.course_id
    WHERE e.user_id=? ORDER BY a.due_date ASC LIMIT 10
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$assignments = $stmt->get_result(); $stmt->close();

/* Materials */
$materials   = $conn->query("SELECT * FROM course_materials ORDER BY id DESC LIMIT 10");
$live_classes = $conn->query("SELECT * FROM live_classes ORDER BY schedule_time ASC LIMIT 10");

/* Browse courses */
$search = trim(strip_tags($_GET['search'] ?? ''));
if ($search !== '') {
    $like = "%{$search}%";
    $stmt = $conn->prepare("
        SELECT id, title, description, price, lessons, enrolled_students, teacher_id
        FROM courses WHERE status='Active' AND title LIKE ? ORDER BY created_at DESC
    ");
    $stmt->bind_param("s", $like);
} else {
    $stmt = $conn->prepare("
        SELECT id, title, description, price, lessons, enrolled_students, teacher_id
        FROM courses WHERE status='Active' ORDER BY created_at DESC
    ");
}
$stmt->execute();
$browseCourses = $stmt->get_result(); $stmt->close();

$browseCoursesData = [];
while ($course = $browseCourses->fetch_assoc()) {
    $course['instructor'] = 'Unknown';
    if (!empty($course['teacher_id'])) {
        $tid = (int)$course['teacher_id'];
        $s2  = $conn->prepare("
   SELECT u.full_name FROM teachers t INNER JOIN users u ON u.id=t.user_id WHERE t.id=? LIMIT 1
        ");
        $s2->bind_param("i", $tid); $s2->execute();
        $t = $s2->get_result()->fetch_assoc(); $s2->close();
        if ($t) { $course['instructor'] = $t['full_name']; }
    }
    $browseCoursesData[] = $course;
}

/* Trending / instructors / categories */
$trendingCourses = $conn->query("
    SELECT title, enrolled_students FROM courses WHERE status='Active'
    ORDER BY enrolled_students DESC LIMIT 5
");
$topInstructors = $conn->query("
    SELECT u.full_name, t.specialization, t.experience_years, COUNT(c.id) AS courses_count
    FROM teachers t INNER JOIN users u ON u.id=t.user_id
    LEFT JOIN courses c ON c.teacher_id=t.id
    GROUP BY t.id, u.full_name, t.specialization, t.experience_years
    ORDER BY courses_count DESC LIMIT 5
");
$categories = [];
$cat_result = $conn->query("SELECT DISTINCT category FROM courses WHERE status='Active' AND category IS NOT NULL AND category != ''");
while ($row = $cat_result->fetch_assoc()) { $categories[] = $row['category']; }
if (empty($categories)) {
    $categories = ["Web Development","Programming","UI/UX Design","Cyber Security","Data Science","Mobile Development"];
}

$courseBenefits = [
    ["title"=>"Learn Anytime",      "description"=>"Access courses 24/7 from any device."],
    ["title"=>"Expert Instructors", "description"=>"Learn from industry professionals."],
    ["title"=>"Certified Learning", "description"=>"Get certificates after completion."],
];

/* Progress */
$progressTotalCourses     = $enrolled;
$progressCompletedCourses = $completed;
$progressOngoingCourses   = $ongoing;
$overallProgress = ($progressTotalCourses > 0)
    ? (int)round(($progressCompletedCourses / $progressTotalCourses) * 100) : 0;

$stmt = $conn->prepare("
    SELECT c.id, c.title, e.status FROM enrollments e
    INNER JOIN courses c ON e.course_id=c.id
    WHERE e.user_id=?
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$courseProgressResult = $stmt->get_result(); $stmt->close();
$courseProgressRows = [];
while ($row = $courseProgressResult->fetch_assoc()) {
    $row['progress'] = $courseProgressMap[(int)$row['id']] ?? 0;
    $courseProgressRows[] = $row;
}
usort($courseProgressRows, fn($a, $b) => $b['progress'] <=> $a['progress']);

$stmt = $conn->prepare("SELECT * FROM quiz_results WHERE user_id=? ORDER BY completed_at DESC LIMIT 10");
$stmt->bind_param("i", $user_id); $stmt->execute();
$quizPerformance = $stmt->get_result(); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM certificates WHERE user_id=? ORDER BY issued_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$progressCertificates = $stmt->get_result(); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM learning_stats WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id); $stmt->execute();
$analytics = $stmt->get_result()->fetch_assoc() ?? ['study_hours'=>0,'lessons_completed'=>0,'assignments_submitted'=>0];
$stmt->close();

$progressAchievements = [];
if ($progressCompletedCourses > 0)
    $progressAchievements[] = ["title"=>"🏆 Completed Courses","description"=>"$progressCompletedCourses course(s) completed"];
if ($overallProgress >= 50)
    $progressAchievements[] = ["title"=>"🔥 Learning Progress","description"=>"You reached {$overallProgress}% overall"];
$stmt = $conn->prepare("SELECT * FROM quiz_results WHERE user_id=? ORDER BY score DESC LIMIT 1");
$stmt->bind_param("i", $user_id); $stmt->execute();
$topQuiz = $stmt->get_result()->fetch_assoc(); $stmt->close();
if ($topQuiz)
    $progressAchievements[] = ["title"=>"⭐ Top Quiz Performer","description"=>($topQuiz['quiz_title']??'Quiz')." — Score: ".$topQuiz['score']."%"];

/* Payments */
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE user_id=? AND status='success'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$totalPaid = $stmt->get_result()->fetch_assoc()['total'] ?? 0; $stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE user_id=? AND status='pending'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$pendingPayments = $stmt->get_result()->fetch_assoc()['total'] ?? 0; $stmt->close();

$stmt = $conn->prepare("SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$transactions = $stmt->get_result(); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM invoices WHERE user_id=? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$invoices = $stmt->get_result(); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM payment_methods WHERE user_id=? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$paymentMethods = $stmt->get_result(); $stmt->close();

$invoices_count     = $invoices->num_rows;
$transactions_count = $transactions->num_rows;

/* Payable courses  */
$stmt = $conn->prepare("
    SELECT id, title, price FROM courses
    WHERE course_type='Paid'
      AND status='Active'
      AND id NOT IN (
 SELECT course_id FROM enrollments WHERE user_id=?
      )
    ORDER BY title ASC
");
$stmt->bind_param("i", $user_id); $stmt->execute();
$payableCourses = $stmt->get_result(); $stmt->close();
$payableCoursesData = [];
while ($pc = $payableCourses->fetch_assoc()) {
    $payableCoursesData[] = $pc;
}
$hasPayableCourses = !empty($payableCoursesData);

/* Settings */
$stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id); $stmt->execute();
$settingsRow = $stmt->get_result()->fetch_assoc() ?? ['theme'=>'light','email_notifications'=>1,'sms_notifications'=>1,'password_updated_at'=>null];
$stmt->close();

/* Tickets */
$stmt = $conn->prepare("SELECT * FROM tickets WHERE user_id=? ORDER BY id DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$tickets_result = $stmt->get_result(); $stmt->close();

/* Flash */
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$active_section_map = [
    'profile'    => 'profileSection',
    'payments'   => 'paymentsSection',
    'attendance' => 'attendanceSection',
    'browse'     => 'browseCoursesSection',
];
$active_section_key = $_SESSION['active_section'] ?? '';
unset($_SESSION['active_section']);
if ($active_section_key === '' && isset($_GET['search'])) {
    $active_section_key = 'browse';
}

$active_section_id  = $active_section_map[$active_section_key] ?? '';
$active_section_js  = json_encode($active_section_id, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

/* Pass CSRF token to JS safely */
$csrf_js = json_encode($_SESSION['csrf_token'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard — LMS Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* =====
   CSS VARIABLES 
===== */
:root {
    --blue:      #2563eb;
    --blue-dark: #1d4ed8;
    --navy:      #0f172a;
    --slate:     #1e293b;
    --green:     #10b981;
    --red:       #ef4444;
    --gray-100:  #f1f5f9;
    --gray-200:  #e2e8f0;
    --gray-500:  #64748b;
    --gray-900:  #111827;
    --white:     #ffffff;
    --radius:    14px;
    --shadow:    0 4px 20px rgba(0,0,0,0.07);
    --sidebar-w: 260px;
    --transition: 0.25s ease;
}

/* =====
   RESET
===== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; min-height: 100vh; background: var(--gray-100); font-family: 'Segoe UI', Arial, sans-serif; color: var(--gray-900); }
a { text-decoration: none; color: inherit; }
img { max-width: 100%; }

/* =====
   LAYOUT SHELL
===== */
.layout { display: flex; min-height: 100vh; }

/* =====
   SIDEBAR
===== */
.sidebar {
    width: var(--sidebar-w);
    background: var(--navy);
    position: fixed;
    top: 0; left: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 900;
    display: flex;
    flex-direction: column;
    transition: transform var(--transition);
}
.sidebar-logo {
    padding: 28px 20px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.sidebar-logo h2 { color: var(--white); font-size: 1.6rem; font-weight: 700; }
.sidebar-logo p  { color: var(--gray-500); font-size: 0.78rem; margin-top: 4px; }

/* Nav menu */
.nav-menu { list-style: none; padding: 12px 0; flex: 1; }
.nav-menu li a {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 14px 22px;
    color: #94a3b8;
    font-size: 0.95rem;
    font-weight: 500;
    transition: all var(--transition);
    border-left: 3px solid transparent;
}
.nav-menu li a i { width: 20px; text-align: center; font-size: 0.95rem; }
.nav-menu li a:hover,
.nav-menu li.active a {
    background: rgba(255,255,255,0.06);
    color: var(--white);
    border-left-color: var(--blue);
}
.nav-menu li.logout a { color: #f87171; }
.nav-menu li.logout a:hover { background: rgba(239,68,68,0.12); border-left-color: var(--red); }

/* Sidebar overlay  */
.sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 899;
}

/* =====
   TOPBAR 
===== */
.topbar {
    display: none;
    position: sticky;
    top: 0;
    z-index: 800;
    background: var(--white);
    padding: 14px 18px;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.topbar h2 { font-size: 1.1rem; color: var(--navy); }
.hamburger {
    background: none; border: none;
    font-size: 1.4rem; color: var(--navy);
    cursor: pointer; padding: 4px;
}

/* =====
   MAIN CONTENT
===== */
.main {
    margin-left: var(--sidebar-w);
    flex: 1;
    padding: 28px 24px;
    min-height: 100vh;
}

/* =====
   CARDS / BOXES
===== */
.card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 26px;
    box-shadow: var(--shadow);
    margin-bottom: 22px;
}
.card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 20px;
}
.card-title i { color: var(--blue); }

/* Page header */
.page-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%);
    border-radius: var(--radius);
    padding: 28px;
    margin-bottom: 24px;
    color: var(--white);
}
.page-header h1 { font-size: 1.7rem; margin-bottom: 6px; }
.page-header p  { opacity: 0.8; font-size: 0.95rem; }

/* =====
   STAT CARDS GRID
===== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.stat-box {
    background: var(--white);
    border-radius: var(--radius);
    padding: 22px 18px;
    box-shadow: var(--shadow);
    border: 1px solid var(--blue);
    transition: transform var(--transition), box-shadow var(--transition);
}
.stat-box:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(0,0,0,0.10); }
.stat-box i { font-size: 1.6rem; color: var(--blue); margin-bottom: 12px; display: block; }
.stat-box h2 { font-size: 2rem; color: var(--navy); margin-bottom: 4px; }
.stat-box p  { color: var(--gray-500); font-size: 0.88rem; }

/* Colored accents */
.stat-box.green { border-top-color: var(--green); }
.stat-box.green i { color: var(--green); }
.stat-box.red   { border-top-color: var(--red); }
.stat-box.red i { color: var(--red); }

/* =====
   TWO-COL GRID 
===== */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}

/* =====
   ACTIVITY ITEMS
===== */
.activity-item {
    padding: 14px 16px;
    border-radius: 10px;
    border: 1px solid var(--blue);
    background: var(--gray-100);
    margin-bottom: 12px;
    transition: transform var(--transition);
}
.activity-item:hover { transform: translateX(4px); }
.activity-item p    { font-weight: 600; font-size: 0.95rem; color: var(--navy); margin-bottom: 4px; }
.activity-item span { color: var(--gray-500); font-size: 0.83rem; }

/* Course rows */
.course-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--gray-200);
}
.course-row:last-child { border-bottom: none; }
.course-info h4 { font-size: 0.97rem; margin-bottom: 4px; }
.course-info p  { color: var(--gray-500); font-size: 0.83rem; }
.progress-bar-wrap {
    width: 160px; height: 8px;
    background: var(--gray-200);
    border-radius: 99px; overflow: hidden;
    margin-top: 7px;
}
.progress-fill { height: 100%; background: var(--green); border-radius: 99px; }

/* =====
   BUTTONS
===== */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: var(--blue);
    color: var(--white);
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.88rem;
    font-weight: 600;
    transition: background var(--transition), transform var(--transition);
}
.btn:hover { background: var(--blue-dark); transform: translateY(-1px); }
.btn.green { background: var(--green); }
.btn.green:hover { background: #059669; }
.btn.dark  { background: var(--navy); }
.btn.dark:hover  { background: var(--slate); }
.btn.muted { background: var(--gray-200); color: var(--gray-500); cursor: default; }
.btn.muted:hover { background: var(--gray-200); transform: none; }
.btn.small { padding: 7px 13px; font-size: 0.82rem; }
.btn-row   { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

/* =====
   FORM INPUTS
===== */
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-weight: 600; font-size: 0.88rem; margin-bottom: 6px; color: var(--navy); }
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 11px 13px;
    border: 1px solid var(--gray-200);
    border-radius: 9px;
    font-size: 0.93rem;
    color: var(--gray-900);
    background: var(--white);
    transition: border-color var(--transition), box-shadow var(--transition);
    outline: none;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
}
.form-group textarea { resize: vertical; min-height: 90px; }

/* =====
   TABLE
===== */
.data-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.data-table th {
    background: var(--navy); color: var(--white);
    padding: 11px 13px; text-align: left; font-size: 0.85rem;
}
.data-table th:first-child { border-radius: 8px 0 0 0; }
.data-table th:last-child  { border-radius: 0 8px 0 0; }
.data-table td { padding: 11px 13px; font-size: 0.88rem; border-bottom: 1px solid var(--gray-200); }
.data-table tr:hover td { background: var(--gray-100); }

/* =====
   TAGS / BADGES
===== */
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 0.78rem;
    font-weight: 700;
}
.badge-open     { background:#fef3c7; color:#92400e; }
.badge-progress { background:#dbeafe; color:#1e40af; }
.badge-resolved { background:#d1fae5; color:#065f46; }
.badge-closed   { background:#f1f5f9; color:#475569; }

/* Category chips */
.chip-wrap { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 12px; }
.chip {
    padding: 7px 14px;
    background: var(--gray-100);
    border: 1px solid var(--gray-200);
    border-radius: 99px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--navy);
    cursor: pointer;
    transition: background var(--transition);
}
.chip:hover { background: var(--blue); color: var(--white); border-color: var(--blue); }

/* =====
   FLASH MESSAGE
===== */
.flash {
    background: #d1fae5; color: #065f46;
    border: 1px solid #6ee7b7;
    border-radius: 10px;
    padding: 13px 18px;
    margin-bottom: 20px;
    font-weight: 600;
}
.flash.error { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }

/* =====
   MODAL
===== */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 9000;
    align-items: center;
    justify-content: center;
    padding: 16px;
    overflow-y: auto;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: var(--white);
    border-radius: var(--radius);
    padding: 30px;
    width: 100%;
    max-width: 520px;
    position: relative;
    animation: popIn 0.2s ease;
}
@keyframes popIn { from { transform: scale(0.93); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-close {
    position: absolute; top: 14px; right: 16px;
    width: 30px; height: 30px;
    background: var(--gray-200); border: none;
    border-radius: 50%; cursor: pointer;
    font-size: 1rem; display: flex; align-items: center; justify-content: center;
    transition: background var(--transition);
}
.modal-close:hover { background: var(--red); color: var(--white); }
.modal-title { font-size: 1.1rem; font-weight: 700; color: var(--navy); margin-bottom: 20px; }

/* Enroll status label */
.enroll-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 13px;
    border-radius: 10px;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
}
.enroll-label.enrolled  { background: #d1fae5; color: #065f46; }
.enroll-label.completed { background: #dbeafe; color: #1e40af; }

/* =====
   PROFILE IMAGE
===== */
.profile-img {
    width: 90px; height: 90px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--blue);
    margin-bottom: 14px;
}

/* =====
   SECTION 
===== */
.section { display: none; }

/* =====
   RESPONSIVE 
===== */
@media (max-width: 900px) {
    .two-col { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* =====
   RESPONSIVE 
===== */
@media (max-width: 640px) {
    .topbar   { display: flex; }
    .sidebar  { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay.active { display: block; }

    .main { margin-left: 0; padding: 16px 14px; }

    .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .stat-box h2 { font-size: 1.6rem; }
    .card { padding: 18px; }
    .page-header h1 { font-size: 1.3rem; }
    .course-row { flex-direction: column; align-items: flex-start; }
    .progress-bar-wrap { width: 100%; }
    .data-table { font-size: 0.8rem; }
    .data-table th, .data-table td { padding: 8px; }
}
</style>
</head>
<body>

<!-- MOBILE TOPBAR -->
<div class="topbar">
    <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>
    <h2>LMS Portal</h2>
    <span style="width:36px;"></span>
</div>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="layout">
<!-- SIDEBAR -->
<nav class="sidebar" id="sidebar" role="navigation" aria-label="Main menu">
    <div class="sidebar-logo">
        <h2><i class="fas fa-book-open"></i> LMS</h2>
        <p>Learning Portal</p>
    </div>
    <ul class="nav-menu" id="navMenu">
        <li class="active"><a href="#" onclick="showSection('dashboardContent',this)"><i class="fas fa-home"></i>Dashboard</a></li>
        <li><a href="#" onclick="showSection('coursesSection',this)"><i class="fas fa-book-open"></i>My Courses</a></li>
        <li><a href="#" onclick="showSection('browseCoursesSection',this)"><i class="fas fa-search"></i>Browse Courses</a></li>
        <li><a href="#" onclick="showSection('progressSection',this)"><i class="fas fa-chart-line"></i>Progress</a></li>
        <li><a href="#" onclick="showSection('paymentsSection',this)"><i class="fas fa-credit-card"></i>Payments</a></li>
        <li><a href="#" onclick="showSection('attendanceSection',this)"><i class="fas fa-user-check"></i>Attendance</a></li>
        <li><a href="#" onclick="showSection('notificationsSection',this)"><i class="fas fa-bell"></i>Notifications</a></li>
        <li><a href="#" onclick="showSection('bookmarksSection',this)"><i class="fas fa-bookmark"></i>Bookmarks</a></li>
        <li><a href="#" onclick="showSection('ticketSection',this)"><i class="fas fa-ticket-alt"></i>Raise Ticket</a></li>
        <li><a href="#" onclick="showSection('profileSection',this)"><i class="fas fa-user"></i>Profile</a></li>
        <li><a href="#" onclick="showSection('settingsSection',this)"><i class="fas fa-cog"></i>Settings</a></li>
        <li class="logout"><a href="Login.php"><i class="fas fa-sign-out-alt"></i>Logout</a></li>
    </ul>
</nav>

<!-- MAIN -->
<main class="main">

<?php if ($flash): ?>
    <div class="flash"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- ===
     DASHBOARD
=== -->
<div id="dashboardContent" class="section">
    <div class="page-header">
        <h1>Welcome back, <?= htmlspecialchars($profile['full_name'] ?? 'Student') ?> </h1>
        <p>Track your learning progress and manage your courses.</p>
    </div>

    <div class="stats-grid">
        <div class="stat-box"><i class="fas fa-book"></i><h2><?= $enrolled ?></h2><p>Enrolled</p></div>
        <div class="stat-box green"><i class="fas fa-check-circle"></i><h2><?= $completed ?></h2><p>Completed</p></div>
        <div class="stat-box"><i class="fas fa-spinner"></i><h2><?= $ongoing ?></h2><p>Ongoing</p></div>
        <div class="stat-box"><i class="fas fa-chart-pie"></i><h2><?= $progress ?>%</h2><p>Progress</p></div>
    </div>

    <div class="two-col">
        <div class="card">
   <div class="card-title"><i class="fas fa-play-circle"></i>Continue Learning</div>
   <?php foreach ($courses_widget_rows as $row): ?>
       <div class="course-row">
  <div class="course-info">
      <h4><?= htmlspecialchars($row['title']) ?></h4>
      <p><?= (int)$row['progress'] ?>% completed</p>
      <div class="progress-bar-wrap"><div class="progress-fill" style="width:<?= (int)$row['progress'] ?>%"></div></div>
  </div>
       </div>
   <?php endforeach; ?>
        </div>

        <div class="card">
   <div class="card-title"><i class="fas fa-history"></i>Recent Activities</div>
   <?php while ($act = $activities->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($act['message']) ?></p>
  <span><?= htmlspecialchars($act['created_at']) ?></span>
       </div>
   <?php endwhile; ?>
        </div>
    </div>
</div>

<!-- ===
     MY COURSES
=== -->
<div id="coursesSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-book-open"></i>My Courses</div>
        <div class="stats-grid">
   <div class="stat-box"><i class="fas fa-book"></i><h2><?= $total_courses ?></h2><p>Total</p></div>
   <div class="stat-box"><i class="fas fa-play-circle"></i><h2><?= $active_courses ?></h2><p>Active</p></div>
   <div class="stat-box green"><i class="fas fa-check-circle"></i><h2><?= $completed_courses ?></h2><p>Completed</p></div>
   <div class="stat-box"><i class="fas fa-award"></i><h2><?= $certificates_earned ?></h2><p>Certificates</p></div>
        </div>
    </div>

    <div class="card">
    <div class="card-title"><i class="fas fa-play-circle"></i>Continue Learning</div>
    <?php if (!empty($courses_widget_rows)): ?>
        <?php foreach ($courses_widget_rows as $row):
   $pct = min(100, max(0, (int)($row['progress'] ?? 0)));
        ?>
   <div class="course-row">
       <div class="course-info" style="flex:1;">
  <h4><?= htmlspecialchars($row['title']) ?></h4>
  <p style="margin-bottom:6px;color:var(--gray-500);font-size:0.83rem;">
      <?= (int)($row['lessons'] ?? 0) ?> lessons
      &nbsp;•&nbsp;
      <strong style="color:var(--navy);"><?= $pct ?>%</strong> completed
  </p>
  <div class="progress-bar-wrap" style="width:100%;max-width:320px;">
      <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
  </div>
       </div>
       <a href="course1.php?id=<?= (int)$row['id'] ?>" class="btn small" style="flex-shrink:0;">
  <i class="fas fa-play"></i> Continue
       </a>
   </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="activity-item">
   <p>No courses in progress</p>
   <span>
       <a href="#" onclick="showSection('browseCoursesSection',
  document.querySelector('#navMenu li a[onclick*=browseCoursesSection]'))"
 style="color:var(--blue);font-weight:600;">
  Browse courses to get started →
       </a>
   </span>
        </div>
    <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-folder-open"></i>Course Materials</div>
        <?php if ($materials && $materials->num_rows > 0): ?>
   <?php while ($mat = $materials->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($mat['title'] ?? '') ?></p>
  <span>Downloaded <?= (int)($mat['downloads'] ?? 0) ?> times</span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No materials available</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-video"></i>Upcoming Live Classes</div>
        <?php if ($live_classes && $live_classes->num_rows > 0): ?>
   <?php while ($live = $live_classes->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($live['title'] ?? '') ?></p>
  <span><?= !empty($live['schedule_time']) ? date("d F Y • h:i A", strtotime($live['schedule_time'])) : 'N/A' ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No live classes scheduled</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- ===
     PROGRESS
=== -->
<div id="progressSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-chart-line"></i>Learning Progress</div>
        <div class="stats-grid">
   <div class="stat-box"><i class="fas fa-book-open"></i><h2><?= $progressTotalCourses ?></h2><p>Enrolled</p></div>
   <div class="stat-box green"><i class="fas fa-check-circle"></i><h2><?= $progressCompletedCourses ?></h2><p>Completed</p></div>
   <div class="stat-box"><i class="fas fa-spinner"></i><h2><?= $progressOngoingCourses ?></h2><p>Ongoing</p></div>
   <div class="stat-box"><i class="fas fa-chart-pie"></i><h2><?= $overallProgress ?>%</h2><p>Overall</p></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-laptop-code"></i>Course Progress</div>
        <?php if (!empty($courseProgressRows)): ?>
   <?php foreach ($courseProgressRows as $cp): ?>
       <div class="course-row">
  <div class="course-info">
      <h4><?= htmlspecialchars($cp['title'] ?? '') ?></h4>
      <p><?= (int)($cp['progress'] ?? 0) ?>% completed</p>
      <div class="progress-bar-wrap"><div class="progress-fill" style="width:<?= (int)($cp['progress'] ?? 0) ?>%"></div></div>
  </div>
  <span style="color:<?= ($cp['status'] ?? '') === 'completed' ? 'var(--blue)' : 'var(--green)' ?>;font-weight:700;">
      <?= ucfirst($cp['status'] ?? 'ongoing') ?>
  </span>
       </div>
   <?php endforeach; ?>
        <?php else: ?>
   <div class="activity-item"><p>No progress data</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-question-circle"></i>Quiz Performance</div>
        <?php if ($quizPerformance->num_rows > 0): ?>
   <?php while ($qp = $quizPerformance->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($qp['quiz_title'] ?? '') ?></p>
  <span>Score: <?= (int)($qp['score'] ?? 0) ?>% • <?= ucfirst($qp['status'] ?? '') ?> • <?= !empty($qp['created_at']) ? date("d M Y", strtotime($qp['created_at'])) : '' ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No quiz results yet</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-award"></i>Certificates</div>
        <?php if ($progressCertificates->num_rows > 0): ?>
   <?php while ($cert = $progressCertificates->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($cert['title'] ?? '') ?></p>
  <span>Issued <?= !empty($cert['issued_at']) ? date("d F Y", strtotime($cert['issued_at'])) : '' ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No certificates yet</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-chart-bar"></i>Learning Analytics</div>
        <div class="activity-item"><p>Total Study Hours</p><span><?= (int)($analytics['study_hours'] ?? 0) ?> hrs</span></div>
        <div class="activity-item"><p>Lessons Completed</p><span><?= (int)($analytics['lessons_completed'] ?? 0) ?></span></div>
        <div class="activity-item"><p>Assignments Submitted</p><span><?= (int)($analytics['assignments_submitted'] ?? 0) ?></span></div>
        <div class="activity-item"><p>Avg Daily Study</p><span><?= round(($analytics['study_hours'] ?? 0) / 30, 1) ?> hrs/day</span></div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-trophy"></i>Achievements</div>
        <?php if (!empty($progressAchievements)): ?>
   <?php foreach ($progressAchievements as $a): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($a['title']) ?></p>
  <span><?= htmlspecialchars($a['description']) ?></span>
       </div>
   <?php endforeach; ?>
        <?php else: ?>
   <div class="activity-item"><p>No achievements yet</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- ===
     BROWSE COURSES
=== -->
<div id="browseCoursesSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-search"></i>Browse Courses</div>
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
   <input type="hidden" name="section" value="browse">
   <div class="form-group" style="flex:1;margin:0;">
       <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search courses by title…">
   </div>
   <button type="submit" class="btn"><i class="fas fa-search"></i> Search</button>
        </form>

        <div class="card-title" style="font-size:1rem;"><i class="fas fa-layer-group"></i>Categories</div>
        <div class="chip-wrap">
   <?php foreach ($categories as $cat): ?>
       <span class="chip"><?= htmlspecialchars($cat) ?></span>
   <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-star"></i>Featured Courses</div>
        <?php if (!empty($browseCoursesData)): ?>
   <?php foreach ($browseCoursesData as $course):
       $cid = (int)$course['id'];
       $enrollStatus = $enrolledCourseMap[$cid] ?? null; // null = not enrolled
   ?>
       <div class="course-row">
  <div class="course-info">
      <h4><?= htmlspecialchars($course['title']) ?></h4>
      <p><?= htmlspecialchars(mb_strimwidth($course['description'], 0, 90, '…')) ?></p>
      <p style="color:var(--blue);font-weight:700;margin-top:6px;">KES <?= number_format($course['price']) ?></p>
      <p style="color:var(--gray-500);font-size:0.82rem;">
 <?= htmlspecialchars($course['instructor']) ?> • <?= (int)$course['lessons'] ?> lessons • <?= number_format($course['enrolled_students']) ?> students
      </p>
  </div>

  <?php if ($enrollStatus === null): ?>
      <a href="enroll.php?course_id=<?= $cid ?>" class="btn small">
 <i class="fas fa-plus"></i> Enroll
      </a>

  <?php elseif ($enrollStatus === 'ongoing'): ?>
      <a href="course1.php?id=<?= $cid ?>" class="btn small green">
 <i class="fas fa-play"></i> Continue
      </a>

  <?php elseif ($enrollStatus === 'completed'): ?>
     
      <span class="enroll-label completed">
 <i class="fas fa-check-double"></i> Completed
      </span>

  <?php else: ?>
      <span class="enroll-label enrolled">
 <i class="fas fa-check-circle"></i> Enrolled
      </span>

  <?php endif; ?>
       </div>
   <?php endforeach; ?>
        <?php else: ?>
   <div class="activity-item"><p>No courses found</p><span>Try a different keyword</span></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-fire"></i>Trending Courses</div>
        <?php while ($trend = $trendingCourses->fetch_assoc()): ?>
   <div class="activity-item">
       <p><?= htmlspecialchars($trend['title']) ?></p>
       <span><?= number_format($trend['enrolled_students']) ?> students</span>
   </div>
        <?php endwhile; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-chalkboard-teacher"></i>Top Instructors</div>
        <?php while ($inst = $topInstructors->fetch_assoc()): ?>
   <div class="activity-item">
       <p><?= htmlspecialchars($inst['full_name']) ?></p>
       <span><?= htmlspecialchars($inst['specialization'] ?? '') ?> • <?= (int)$inst['experience_years'] ?> yrs • <?= (int)$inst['courses_count'] ?> courses</span>
   </div>
        <?php endwhile; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-gift"></i>Why Learn With Us?</div>
        <?php foreach ($courseBenefits as $b): ?>
   <div class="activity-item">
       <p><?= htmlspecialchars($b['title']) ?></p>
       <span><?= htmlspecialchars($b['description']) ?></span>
   </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ===
     ATTENDANCE
=== -->
<div id="attendanceSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-user-check"></i>Sign Attendance</div>
        <form method="POST">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
   <input type="hidden" name="sign_attendance" value="1">
   <div class="form-group">
       <label>Select Course</label>
       <select name="course_id" required>
  <option value="">— Choose Course —</option>
  <?php
  $att_courses = $conn->query("SELECT id, title FROM courses");
  while ($c = $att_courses->fetch_assoc()):
  ?>
      <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
  <?php endwhile; ?>
       </select>
   </div>
   <button type="submit" class="btn"><i class="fas fa-signature"></i> Sign Attendance</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-calendar-check"></i>Attendance History</div>
        <table class="data-table">
   <thead>
       <tr><th>Course</th><th>Status</th><th>Date</th></tr>
   </thead>
   <tbody>
   <?php
   $stmt = $conn->prepare("
       SELECT a.*, c.title FROM attendance a
       JOIN courses c ON a.course_id=c.id
       WHERE a.user_id=? ORDER BY a.attendance_date DESC
   ");
   $stmt->bind_param("i", $user_id); $stmt->execute();
   $att_history = $stmt->get_result(); $stmt->close();
   $hasAtt = false;
   while ($row = $att_history->fetch_assoc()): $hasAtt = true; ?>
       <tr>
  <td><?= htmlspecialchars($row['title']) ?></td>
  <td><?= htmlspecialchars($row['status']) ?></td>
  <td><?= htmlspecialchars($row['attendance_date'] ?? '') ?></td>
       </tr>
   <?php endwhile; ?>
   <?php if (!$hasAtt): ?><tr><td colspan="3" style="color:var(--gray-500);">No attendance records</td></tr><?php endif; ?>
   </tbody>
        </table>
    </div>
</div>

<!-- ===
     PAYMENTS
=== -->
<div id="paymentsSection" class="section">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
   <div class="card-title" style="margin:0;"><i class="fas fa-credit-card"></i>Payments &amp; Billing</div>
   <?php if ($hasPayableCourses): ?>
       <button class="btn" onclick="openModal('paymentModal')"><i class="fas fa-plus"></i> Make Payment</button>
   <?php else: ?>
       <span class="btn muted"><i class="fas fa-check-circle"></i> All Courses Enrolled</span>
   <?php endif; ?>
        </div>
        <div class="stats-grid">
   <div class="stat-box"><i class="fas fa-wallet"></i><h2>KES <?= number_format($totalPaid) ?></h2><p>Total Paid</p></div>
   <div class="stat-box red"><i class="fas fa-clock"></i><h2>KES <?= number_format($pendingPayments) ?></h2><p>Pending</p></div>
   <div class="stat-box"><i class="fas fa-receipt"></i><h2><?= $invoices_count ?></h2><p>Invoices</p></div>
   <div class="stat-box green"><i class="fas fa-check-circle"></i><h2><?= $transactions_count ?></h2><p>Transactions</p></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-money-check-alt"></i>Payment Methods</div>
        <?php if ($paymentMethods->num_rows > 0): ?>
   <?php while ($m = $paymentMethods->fetch_assoc()): ?>
       <div class="activity-item"><p><?= htmlspecialchars($m['method_type'] ?? '') ?></p><span><?= htmlspecialchars($m['account_number'] ?? '') ?></span></div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No payment methods added</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-receipt"></i>Transactions</div>
        <?php if ($transactions->num_rows > 0): ?>
   <?php while ($t = $transactions->fetch_assoc()): ?>
       <div class="activity-item">
  <p>Course ID: <?= (int)$t['course_id'] ?></p>
  <span>KES <?= number_format($t['amount']) ?> • <?= ucfirst($t['status']) ?> • <?= date("d M Y", strtotime($t['created_at'])) ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No transactions yet</p></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-file-invoice"></i>Invoices</div>
        <?php if ($invoices->num_rows > 0): ?>
   <?php while ($inv = $invoices->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($inv['invoice_no'] ?? '') ?></p>
  <span><?= htmlspecialchars($inv['description'] ?? '') ?> • <?= ucfirst($inv['status'] ?? '') ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No invoices yet</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- ===
     NOTIFICATIONS
=== -->
<div id="notificationsSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-bell"></i>Notifications &amp; Announcements</div>
        <?php if ($notifications->num_rows > 0): ?>
   <?php while ($note = $notifications->fetch_assoc()): ?>
       <div class="activity-item" style="border-left-color:<?= $note['type']==='announcement' ? 'var(--green)' : 'var(--blue)' ?>">
  <p>
      <?= $note['type']==='announcement' ? '<i class="fas fa-bullhorn" style="color:var(--green)"></i> ' : '<i class="fas fa-bell" style="color:var(--blue)"></i> ' ?>
      <?= htmlspecialchars($note['message']) ?>
  </p>
  <span><?= date("F j, Y g:i A", strtotime($note['notification_date'])) ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No notifications</p><span>You're all caught up </span></div>
        <?php endif; ?>
    </div>
</div>

<!-- ===
     BOOKMARKS
=== -->
<div id="bookmarksSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-bookmark"></i>My Bookmarks</div>
        <?php if ($bookmarks->num_rows > 0): ?>
   <?php while ($bm = $bookmarks->fetch_assoc()): ?>
       <div class="activity-item">
  <p><?= htmlspecialchars($bm['title']) ?></p>
  <span><?= date("F j, Y H:i", strtotime($bm['created_at'])) ?></span>
       </div>
   <?php endwhile; ?>
        <?php else: ?>
   <div class="activity-item"><p>No bookmarks yet</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- ===
     RAISE TICKET
=== -->
<div id="ticketSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-ticket-alt"></i>Raise a Support Ticket</div>
        <form method="POST" action="create_ticket.php">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
   <div class="form-group">
       <label>Ticket Type</label>
       <select name="type" required>
  <option value="">— Select Type —</option>
  <option value="academic">Academic</option>
  <option value="technical">Technical</option>
  <option value="attendance">Attendance</option>
  <option value="general">General</option>
       </select>
   </div>
   <div class="form-group">
       <label>Subject</label>
       <input type="text" name="subject" required placeholder="Brief subject…">
   </div>
   <div class="form-group">
       <label>Message</label>
       <textarea name="message" required placeholder="Describe your issue…"></textarea>
   </div>
   <button type="submit" name="submit_ticket" class="btn"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-list-ul"></i>My Tickets</div>
        <table class="data-table">
   <thead><tr><th>Type</th><th>Subject</th><th>Status</th><th>Date</th></tr></thead>
   <tbody>
   <?php $hasTickets = false; while ($ticket = $tickets_result->fetch_assoc()): $hasTickets = true;
       $badge = match($ticket['status']) {
  'open'        => '<span class="badge badge-open">🟡 Open</span>',
  'in_progress' => '<span class="badge badge-progress">🔵 In Progress</span>',
  'resolved'    => '<span class="badge badge-resolved">🟢 Resolved</span>',
  'closed'      => '<span class="badge badge-closed">⚫ Closed</span>',
  default       => htmlspecialchars($ticket['status'])
       };
   ?>
       <tr>
  <td><?= htmlspecialchars($ticket['type']) ?></td>
  <td><?= htmlspecialchars($ticket['subject']) ?></td>
  <td><?= $badge ?></td>
  <td><?= htmlspecialchars($ticket['created_at']) ?></td>
       </tr>
   <?php endwhile; ?>
   <?php if (!$hasTickets): ?><tr><td colspan="4" style="color:var(--gray-500);">No tickets raised yet</td></tr><?php endif; ?>
   </tbody>
        </table>
    </div>
</div>

<!-- ===
     PROFILE
=== -->
<div id="profileSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-user"></i>My Profile</div>

        <?php
        $image = $profile['profile_image'] ?? '';
        $src   = "https://ui-avatars.com/api/?name=".urlencode($profile['full_name'] ?? 'S')."&background=2563eb&color=fff&size=200";
        if (!empty($image)) {
   $image = strip_tags(trim($image));
   if (filter_var($image, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $image)) {
       $src = $image;
   } else {
       $image = basename($image);
       $ext   = strtolower(pathinfo($image, PATHINFO_EXTENSION));
       if (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
  $fp = "uploads/" . $image;
  if (file_exists($fp)) { $src = $fp; }
       }
   }
        }
        ?>
        <img src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo" class="profile-img">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;">
   <div class="activity-item"><p>Full Name</p><span><?= htmlspecialchars($profile['full_name']) ?></span></div>
   <div class="activity-item"><p>Email</p><span><?= htmlspecialchars($profile['email']) ?></span></div>
   <div class="activity-item"><p>Phone</p><span><?= htmlspecialchars($profile['phone'] ?? '—') ?></span></div>
   <div class="activity-item"><p>Location</p><span><?= htmlspecialchars($profile['location'] ?? '—') ?></span></div>
   <div class="activity-item"><p>Joined</p><span><?= date("d F Y", strtotime($profile['created_at'])) ?></span></div>
   <div class="activity-item"><p>Courses</p><span><?= $total_courses ?></span></div>
        </div>

        <?php if (!empty($profile['bio'])): ?>
   <div class="activity-item" style="margin-bottom:16px;"><p>Bio</p><span><?= nl2br(htmlspecialchars($profile['bio'])) ?></span></div>
        <?php endif; ?>

        <div class="btn-row">
   <button class="btn" onclick="openModal('profileModal')"><i class="fas fa-user-edit"></i> Edit Profile</button>
   <button class="btn green" onclick="openModal('passwordModal')"><i class="fas fa-lock"></i> Change Password</button>
   <button class="btn dark" onclick="openModal('photoModal')"><i class="fas fa-image"></i> Upload Photo</button>
        </div>
    </div>
</div>

<!-- ===
     SETTINGS
=== -->
<div id="settingsSection" class="section">
    <div class="card">
        <div class="card-title"><i class="fas fa-cog"></i>Settings</div>
        <div class="activity-item"><p>Account Email</p><span><?= htmlspecialchars($profile['email'] ?? '') ?></span></div>
        <div class="activity-item"><p>Password Last Updated</p><span><?= !empty($settingsRow['password_updated_at']) ? date("d M Y", strtotime($settingsRow['password_updated_at'])) : 'Never' ?></span></div>
        <div class="activity-item"><p>Notifications</p><span>Email: <?= $settingsRow['email_notifications'] ? 'On ' : 'Off X ' ?> &nbsp;|&nbsp; SMS: <?= $settingsRow['sms_notifications'] ? 'On ' : 'Off  X' ?></span></div>
        <div class="activity-item"><p>Theme</p><span><?= ucfirst($settingsRow['theme'] ?? 'light') ?></span></div>
    </div>
</div>

</main>
</div>

<!-- ===
     PAYMENT MODAL
=== -->
<div id="paymentModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('paymentModal')">✕</button>
        <div class="modal-title"><i class="fas fa-credit-card"></i> Make a Payment</div>

        <?php if ($hasPayableCourses): ?>
        <form method="POST" style="display:flex;flex-direction:column;gap:0;">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
   <div class="form-group">
       <label>Course</label>
       <select name="course_id" id="paymentCourseSelect" onchange="fillAmount()" required>
  <option value="">— Select Course —</option>
  <?php foreach ($payableCoursesData as $pc): ?>
      <option value="<?= (int)$pc['id'] ?>" data-price="<?= (float)$pc['price'] ?>">
 <?= htmlspecialchars($pc['title']) ?> (KES <?= number_format($pc['price']) ?>)
      </option>
  <?php endforeach; ?>
       </select>
   </div>
   <div class="form-group">
       <label>Payment Method</label>
       <select name="payment_method" id="paymentMethod" onchange="toggleMpesa()" required>
  <option value="">— Select Method —</option>
  <option value="mpesa">M-Pesa</option>
  <option value="card">Card</option>
  <option value="bank_transfer">Bank Transfer</option>
       </select>
   </div>
   <div class="form-group" id="mpesaField" style="display:none;">
       <label>M-Pesa Phone</label>
       <input type="tel" name="payer_phone" placeholder="07XXXXXXXX" pattern="[0-9]{10,12}">
   </div>
   <div class="form-group">
       <label>Amount (KES)</label>
       <input type="number" id="paymentAmount" min="1" readonly placeholder="Select a course above" style="background:var(--gray-100);cursor:not-allowed;">
   </div>
   <button type="submit" name="submit_payment" class="btn" style="width:100%;justify-content:center;">
       <i class="fas fa-lock"></i> Pay &amp; Enroll
   </button>
        </form>
        <?php else: ?>
   <div class="activity-item" style="text-align:center;padding:30px 16px;">
       <p style="font-size:1.1rem;"> You are enrolled in all available paid courses!</p>
       <span>Check back later for new courses.</span>
   </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===
     PROFILE EDIT MODAL
=== -->
<div id="profileModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('profileModal')">✕</button>
        <div class="modal-title"><i class="fas fa-user-edit"></i> Update Profile</div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:0;">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
   <div class="form-group"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($profile['full_name'] ?? '') ?>" required></div>
   <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" required></div>
   <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>"></div>
   <div class="form-group"><label>Location</label><input type="text" name="location" value="<?= htmlspecialchars($profile['location'] ?? '') ?>"></div>
   <div class="form-group"><label>Bio</label><textarea name="bio"><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea></div>
   <button type="submit" name="update_profile" class="btn" style="width:100%;justify-content:center;"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>

<!-- ===
     CHANGE PASSWORD MODAL
=== -->
<div id="passwordModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('passwordModal')">✕</button>
        <div class="modal-title"><i class="fas fa-lock"></i> Change Password</div>
        <form method="POST" style="display:flex;flex-direction:column;gap:0;">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
   <div class="form-group"><label>Current Password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
   <div class="form-group"><label>New Password</label><input type="password" name="new_password" required autocomplete="new-password" minlength="8"></div>
   <div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" required autocomplete="new-password" minlength="8"></div>
   <button type="submit" name="change_password" class="btn" style="width:100%;justify-content:center;"><i class="fas fa-key"></i> Update Password</button>
        </form>
    </div>
</div>

<!-- ===
     UPLOAD PHOTO MODAL
=== -->
<div id="photoModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('photoModal')">✕</button>
        <div class="modal-title"><i class="fas fa-camera"></i> Upload Profile Photo</div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:0;">
   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
   <div class="form-group"><label>Choose Image (JPG / PNG / WEBP, max 2 MB)</label><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required></div>
   <button type="submit" name="upload_photo" class="btn" style="width:100%;justify-content:center;"><i class="fas fa-upload"></i> Upload Photo</button>
        </form>
    </div>
</div>

<script>
/* ==
   SIDEBAR 
== */
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

/* ==
   SECTION SWITCHING
== */
const ALL_SECTIONS = [
    'dashboardContent','coursesSection','browseCoursesSection',
    'progressSection','paymentsSection',
    'attendanceSection','notificationsSection','bookmarksSection',
    'ticketSection','profileSection','settingsSection'
];

function showSection(id, linkEl) {
    ALL_SECTIONS.forEach(s => {
        const el = document.getElementById(s);
        if (el) el.style.display = 'none';
    });
    const target = document.getElementById(id);
    if (target) target.style.display = 'block';

    document.querySelectorAll('#navMenu li').forEach(li => li.classList.remove('active'));
    if (linkEl) linkEl.closest('li').classList.add('active');

    closeSidebar();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const ACTIVE_SECTION = <?= $active_section_js ?>;

window.addEventListener('load', function () {
    const targetId = ACTIVE_SECTION || 'dashboardContent';
    const target   = document.getElementById(targetId);
    if (target) target.style.display = 'block';

    document.querySelectorAll('#navMenu li').forEach(li => li.classList.remove('active'));
    const navLink = document.querySelector(`#navMenu li a[onclick*="${targetId}"]`);
    if (navLink) navLink.closest('li').classList.add('active');
});

/* ==
   MODALS
== */
function openModal(id)  { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }

document.addEventListener('click', function(e) {
    ['paymentModal','profileModal','passwordModal','photoModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el && e.target === el) closeModal(id);
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['paymentModal','profileModal','passwordModal','photoModal'].forEach(id => {
   const el = document.getElementById(id);
   if (el && el.classList.contains('active')) closeModal(id);
        });
    }
});

/* ==
   PAYMENT MODAL HELPERS
== */
function toggleMpesa() {
    const method = document.getElementById('paymentMethod').value;
    document.getElementById('mpesaField').style.display = method === 'mpesa' ? 'block' : 'none';
}
function fillAmount() {
    const sel = document.getElementById('paymentCourseSelect');
    const amountInput = document.getElementById('paymentAmount');
    if (!sel || !amountInput) return;
    const selected = sel.options[sel.selectedIndex];
    const price = selected ? selected.getAttribute('data-price') : '';
    amountInput.value = price ? parseFloat(price).toFixed(0) : '';
}
</script>
</body>
</html>