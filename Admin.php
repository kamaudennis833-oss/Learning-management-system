<?php
session_start();
include "db.php";
include "advance.php";

/* ==
   DASHBOARD STATISTICS
== */

// Total Students
$total_students = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role='student'")
)['total'] ?? 0;

// Active Students — BUG FIX: must also filter role='student'
$active_students = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role='student' AND status='active'")
)['total'] ?? 0;

// Total Courses
$total_courses = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM courses"))['total'] ?? 0;

// Free Courses
$free_courses = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM courses WHERE course_type='free'"))['total'] ?? 0;

// Paid Courses
$paid_courses = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM courses WHERE course_type='paid'"))['total'] ?? 0;

// Total Enrollments
$total_enrollments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM enrollments"))['total'] ?? 0;

// Revenue
$revenue = 0;
$result = mysqli_query($conn, "SELECT SUM(amount) AS total FROM payments WHERE status='success'");
if ($row = mysqli_fetch_assoc($result)) { $revenue = $row['total'] ?? 0; }

// Completion Rate
$completion_rate = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT AVG(progress) AS avg_progress FROM enrollments"))['avg_progress'] ?? 0;

// Payment counts
$pending_payments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM payments WHERE status='pending'"))['total'] ?? 0;

$successful_payments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM payments WHERE status='success'"))['total'] ?? 0;

$failed_payments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM payments WHERE status='failed'"))['total'] ?? 0;

/* ==
   MOST POPULAR COURSES
== */
$popular_courses = mysqli_query($conn,
    "SELECT courses.id, courses.title,
        COUNT(enrollments.id) AS users,
        COALESCE(SUM(payments.amount),0) AS revenue,
        COALESCE(AVG(enrollments.progress),0) AS completion_rate
    FROM courses
    LEFT JOIN enrollments ON courses.id = enrollments.course_id
    LEFT JOIN payments ON enrollments.user_id = payments.user_id AND payments.status='success'
    GROUP BY courses.id, courses.title
    ORDER BY users DESC LIMIT 5"
);

/* ==
   RECENT STUDENTS
== */
$recent_students = mysqli_query($conn,
    "SELECT users.id, users.full_name, users.email,
        COALESCE(enrollments.status,'Not Enrolled') AS status,
        courses.title AS course_name
    FROM users
    LEFT JOIN enrollments ON users.id = enrollments.user_id
    LEFT JOIN courses ON enrollments.course_id = courses.id
    ORDER BY users.id DESC LIMIT 10"
);

/* ==
   RECENT ACTIVITIES
== */
$activities = mysqli_query($conn, "SELECT * FROM activities ORDER BY id DESC LIMIT 10");

/* ==
   ALLOWED FILE TYPES
== */
$allowed_images = ['jpg','jpeg','png','gif','webp'];
$allowed_docs   = ['pdf','doc','docx'];
$upload_dir     = "uploads/";

/* ==
   HANDLE UPLOAD MATERIAL
== */
if(isset($_POST['upload_material'])){
    $title     = trim($_POST['title']);
    $course_id = intval($_POST['course_id']);
    if($title == "" || $course_id == 0){ die("Invalid input"); }
    if(!empty($_FILES['file']['name'])){
        $fileName = $_FILES['file']['name'];
        $fileTmp  = $_FILES['file']['tmp_name'];
        $fileSize = $_FILES['file']['size'];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if(!in_array($ext, array_merge($allowed_images, $allowed_docs))){ die("Only PDF, DOC, DOCX, and Images allowed"); }
        if($fileSize > 10 * 1024 * 1024){ die("File too large (max 10MB)"); }
        $newFile = time().'_'.rand(1000,9999).'.'.$ext;
        move_uploaded_file($fileTmp, $upload_dir.$newFile);
        $type = in_array($ext, $allowed_docs) ? strtoupper($ext) : "IMAGE";
        mysqli_query($conn, "INSERT INTO course_contents(course_id,content_title,content_type,content_file) VALUES('$course_id','$title','$type','$newFile')");
        mysqli_query($conn, "INSERT INTO activities(user_id,message) VALUES(1,'Uploaded material: $title')");
    }
}

/* ==
   ENROLLMENT ACTIONS
== */
if(isset($_POST['enroll_action'])){
    $enroll_id = intval($_POST['enroll_id']);
    $action    = $_POST['action'];
    if($action == "approve"){ mysqli_query($conn, "UPDATE enrollments SET status='approved' WHERE id='$enroll_id'"); }
    if($action == "reject"){  mysqli_query($conn, "UPDATE enrollments SET status='rejected' WHERE id='$enroll_id'"); }
    if($action == "delete"){  mysqli_query($conn, "UPDATE enrollments SET status='deleted'  WHERE id='$enroll_id'"); }
}

/* ==
   FETCH DATA
== */
$students = mysqli_query($conn, "SELECT * FROM users ORDER BY id DESC");

// BUG FIX: $enrollments used in TWO places — fetch once as array, use twice
$enrollments_result = mysqli_query($conn,
    "SELECT e.*, u.full_name, c.title
     FROM enrollments e
     JOIN users u ON e.user_id = u.id
     JOIN courses c ON e.course_id = c.id
     ORDER BY e.id DESC"
);
$enrollments_data = [];
while($row = mysqli_fetch_assoc($enrollments_result)){ $enrollments_data[] = $row; }

/* ==
   TEACHERS
== */
$teachers = mysqli_query($conn,
    "SELECT t.id, u.full_name FROM teachers t JOIN users u ON t.user_id = u.id ORDER BY u.full_name ASC"
);

$courses = mysqli_query($conn,
    "SELECT c.*, u.full_name FROM courses c
     LEFT JOIN teachers t ON c.teacher_id = t.id
     LEFT JOIN users u ON t.user_id = u.id
     ORDER BY c.id DESC"
);

/* ==
   NOTES
== */
$teacher_id = 1;
$user_id    = 1;

$upload_dir = "uploads/notes/";
if(!file_exists($upload_dir)){ mkdir($upload_dir, 0777, true); }

if(isset($_POST['add_note'])){
    $course_id   = $_POST['course_id'];
    $title       = $_POST['title'];
    $content     = $_POST['content'];
    $access_type = $_POST['access_type'];
    $file_path   = "";
    if(!empty($_FILES['file']['name'])){
        $file_name = time() . "_" . basename($_FILES['file']['name']);
        $target    = $upload_dir . $file_name;
        move_uploaded_file($_FILES['file']['tmp_name'], $target);
        $file_path = $target;
    }
    $conn->query("INSERT INTO notes(course_id,title,content,access_type,created_by,file_path) VALUES('$course_id','$title','$content','$access_type','$teacher_id','$file_path')");
}

if(isset($_GET['delete_note'])){
    $id  = intval($_GET['delete_note']);
    $old = $conn->query("SELECT file_path FROM notes WHERE id=$id")->fetch_assoc();
    if(!empty($old['file_path']) && file_exists($old['file_path'])){ unlink($old['file_path']); }
    $conn->query("DELETE FROM notes WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

if(isset($_GET['view'])){
    $note_id = intval($_GET['view']);
    $note    = $conn->query("SELECT n.*, c.course_type FROM notes n JOIN courses c ON c.id=n.course_id WHERE n.id=$note_id")->fetch_assoc();
    $check   = $conn->query("SELECT * FROM enrollments WHERE user_id=$user_id AND course_id={$note['course_id']} AND status IN ('ongoing','completed','approved')");
    $isEnrolled = $check->num_rows > 0;
    if($note['access_type'] == 'paid' && !$isEnrolled){ die("<h2>Access Denied</h2><p>You must enroll to access this note.</p>"); }
    ?>
    <div style="padding:20px;font-family:Arial;user-select:none;">
        <h2><?= $note['title']; ?></h2>
        <?php if($note['file_path']){ ?>
            <iframe src="<?= $note['file_path']; ?>" width="100%" height="500px"></iframe>
        <?php } ?>
        <p style="white-space:pre-line;"><?= $note['content']; ?></p>
    </div>
    <script>
        document.addEventListener("contextmenu", e => e.preventDefault());
        document.addEventListener("copy",  e => e.preventDefault());
        document.addEventListener("cut",   e => e.preventDefault());
        document.addEventListener("paste", e => e.preventDefault());
    </script>
    <?php exit;
}

$notes = $conn->query(
    "SELECT n.*, c.title AS course_title FROM notes n
     JOIN courses c ON c.id = n.course_id
     JOIN course_teachers ct ON ct.course_id = c.id
     WHERE ct.teacher_id = $teacher_id"
);

/* ==
   QUIZ
== */
if(isset($_POST['create_quiz'])){
    $course_id    = intval($_POST['course_id']);
    $title        = trim($_POST['title']);
    $description  = trim($_POST['description']);
    $passing_marks= intval($_POST['passing_marks']);
    $duration     = intval($_POST['duration']);
    $stmt = $conn->prepare("INSERT INTO quizzes(course_id,title,description,passing_score,duration) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issii", $course_id, $title, $description, $passing_marks, $duration);
    $stmt->execute();
}

if(isset($_POST['add_question'])){
    $quiz_id       = intval($_POST['quiz_id']);
    $question      = trim($_POST['question']);
    $question_type = $_POST['question_type'];
    $option_a      = $_POST['option_a'] ?? null;
    $option_b      = $_POST['option_b'] ?? null;
    $option_c      = $_POST['option_c'] ?? null;
    $option_d      = $_POST['option_d'] ?? null;
    $correct_answer= trim($_POST['correct_answer']);
    $marks         = intval($_POST['marks']);
    $check = mysqli_query($conn, "SELECT id FROM quizzes WHERE id='$quiz_id'");
    if(mysqli_num_rows($check) == 0){ die("Error: Selected quiz does not exist."); }
    $stmt = $conn->prepare("INSERT INTO quiz_questions(quiz_id,question,question_type,option_a,option_b,option_c,option_d,correct_answer,marks) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssssi", $quiz_id, $question, $question_type, $option_a, $option_b, $option_c, $option_d, $correct_answer, $marks);
    $stmt->execute() ? print("Question added") : print("Error inserting question");
}

$total_quizzes   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM quizzes"))['total'] ?? 0;
$total_questions = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM quiz_questions"))['total'] ?? 0;
$total_attempts  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM quiz_attempts"))['total'] ?? 0;
$passed          = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM quiz_attempts WHERE result='Pass'"))['total'] ?? 0;
$failed_quiz     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM quiz_attempts WHERE result='Fail'"))['total'] ?? 0;
$average_score   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(score) avg_score FROM quiz_attempts"))['avg_score'] ?? 0;

/* ==
   ENROLLMENT BULK ACTIONS
== */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(isset($_GET['approve'])){ $id = intval($_GET['approve']); $conn->query("UPDATE enrollments SET status='approved' WHERE id=$id"); }
    if(isset($_GET['reject'])){  $id = intval($_GET['reject']);  $conn->query("UPDATE enrollments SET status='rejected' WHERE id=$id"); }
    if(isset($_POST['approve_all'])){ $conn->query("UPDATE enrollments SET status='approved' WHERE status IN ('pending','ongoing')"); }
    if(isset($_POST['reject_all'])){  $conn->query("UPDATE enrollments SET status='rejected' WHERE status IN ('pending','ongoing')"); }
    if(isset($_POST['clear_all'])){   $conn->query("DELETE FROM enrollments"); }
}

/* ==
   PAYMENT
== */
if(isset($_GET['action']) && isset($_GET['id'])){
    $id     = intval($_GET['id']);
    $action = $_GET['action'];
    if(in_array($action, ['success','failed','pending'])){
        $stmt = $conn->prepare("UPDATE payments SET status=? WHERE id=?");
        $stmt->bind_param("si", $action, $id);
        $stmt->execute();
    }
}

$successful = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM payments WHERE status='success'"))['total'];
$failed     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM payments WHERE status='failed'"))['total'];
$pending    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM payments WHERE status='pending'"))['total'];

$daily   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(amount) AS total FROM payments WHERE status='success' AND DATE(created_at)=CURDATE()"))['total'] ?? 0;
$monthly = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(amount) AS total FROM payments WHERE status='success' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"))['total'] ?? 0;
$yearly  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(amount) AS total FROM payments WHERE status='success' AND YEAR(created_at)=YEAR(CURDATE())"))['total'] ?? 0;

$methods       = mysqli_query($conn, "SELECT payment_method, COUNT(*) AS total, SUM(amount) AS revenue FROM payments WHERE status='success' GROUP BY payment_method");
$courseRevenue = mysqli_query($conn, "SELECT c.title, SUM(p.amount) AS total FROM payments p JOIN courses c ON p.course_id=c.id WHERE p.status='success' GROUP BY p.course_id, c.title ORDER BY total DESC");
$payments      = mysqli_query($conn, "SELECT * FROM payments ORDER BY created_at DESC LIMIT 20");

/* ==
   ANNOUNCEMENTS
== */
if(isset($_POST['add'])){
    $course_id = intval($_POST['course_id']);
    $ann_title = trim($_POST['title']);
    $ann_msg   = trim($_POST['message']);
    if($course_id > 0 && $ann_title !== '' && $ann_msg !== ''){
        $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=?");
        $check->bind_param("ii", $course_id, $teacher_id); $check->execute();
        if($check->get_result()->num_rows > 0){
            $stmt = $conn->prepare("INSERT INTO announcements(course_id,teacher_id,title,message) VALUES(?,?,?,?)");
            $stmt->bind_param("iiss", $course_id, $teacher_id, $ann_title, $ann_msg);
            $stmt->execute();
        }
    }
}

if(isset($_GET['delete_ann'])){
    $id = intval($_GET['delete_ann']);
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id=? AND teacher_id=?");
    $stmt->bind_param("ii", $id, $teacher_id); $stmt->execute();
}

$ann_courses    = $conn->query("SELECT c.id,c.title FROM courses c INNER JOIN course_teachers ct ON ct.course_id=c.id WHERE ct.teacher_id=$teacher_id");
$announcements  = $conn->query("SELECT a.*, c.title AS course FROM announcements a INNER JOIN courses c ON c.id=a.course_id WHERE a.teacher_id=$teacher_id ORDER BY a.created_at DESC");

/* ==
   ADD TEACHER
== */
date_default_timezone_set("Africa/Nairobi");
include "sendmail1.php";

$message     = "";
$messageType = "";

if(empty($_SESSION['csrf_token'])){ $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if(isset($_POST['add_teacher'])){
    if(!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']){ die("Invalid CSRF token."); }
    $full_name        = trim($_POST['full_name']);
    $email            = strtolower(trim($_POST['email']));
    $phone            = trim($_POST['phone'] ?? '');
    $password         = $_POST['password'];
    $employee_no      = trim($_POST['employee_no'] ?? '');
    $specialization   = trim($_POST['specialization'] ?? '');
    $qualification    = trim($_POST['qualification'] ?? '');
    $experience_years = (int)($_POST['experience_years'] ?? 0);

    if(empty($full_name) || empty($email) || empty($password)){
        $message = "Full name, email and password are required!"; $messageType = "error";
    } else {
        $check = $conn->prepare("SELECT id FROM pending_teachers WHERE email=?");
        $check->bind_param("s", $email); $check->execute(); $check->store_result();
        if($check->num_rows > 0){
            $message = "Email already exists!"; $messageType = "error";
        } else {
            $role_stmt = $conn->prepare("SELECT id FROM roles WHERE role_name='teacher'");
            $role_stmt->execute();
            $role_data = $role_stmt->get_result()->fetch_assoc();
            if(!$role_data){
                $message = "Role 'teacher' not found!"; $messageType = "error";
            } else {
                $role_id       = $role_data['id'];
                $otp           = rand(100000,999999);
                $expiry        = date("Y-m-d H:i:s", strtotime("+10 minutes"));
                $hashedPassword= password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO pending_teachers(full_name,email,phone,password,role_id,employee_no,specialization,qualification,experience_years,otp,otp_expires) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("ssssisssiss", $full_name,$email,$phone,$hashedPassword,$role_id,$employee_no,$specialization,$qualification,$experience_years,$otp,$expiry);
                if($stmt->execute()){ sendOTP($email,$full_name,$otp); header("Location: verify2.php?email=".urlencode($email)); exit; }
                else { $message = "Database error: ".$stmt->error; $messageType = "error"; }
            }
        }
    }
}

/* ==
   PAYMENT STATUS COUNTS (for charts)
== */
$successful_payments = 0; $pending_payments = 0; $failed_payments = 0;
$result = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM payments GROUP BY status");
while($row = mysqli_fetch_assoc($result)){
    if($row['status']=='success'){ $successful_payments = $row['total']; }
    if($row['status']=='pending'){ $pending_payments    = $row['total']; }
    if($row['status']=='failed'){  $failed_payments     = $row['total']; }
}

/* ==
   CHART DATA
== */
$revenue_labels = []; $revenue_values = [];
$rq = mysqli_query($conn, "SELECT DATE_FORMAT(MIN(payment_date),'%b %Y') AS month_name, SUM(amount) AS total FROM payments WHERE status='success' AND payment_date IS NOT NULL GROUP BY YEAR(payment_date), MONTH(payment_date) ORDER BY YEAR(payment_date), MONTH(payment_date)");
if($rq){ while($row = mysqli_fetch_assoc($rq)){ $revenue_labels[] = $row['month_name']; $revenue_values[] = (float)$row['total']; } }

$student_labels = []; $student_values = []; $running_total = 0;
$sq = mysqli_query($conn, "SELECT DATE_FORMAT(MIN(created_at),'%b %Y') AS month_name, COUNT(*) AS total FROM users WHERE role='student' GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY YEAR(created_at), MONTH(created_at)");
if($sq){ while($row = mysqli_fetch_assoc($sq)){ $running_total += $row['total']; $student_labels[] = $row['month_name']; $student_values[] = $running_total; } }

$enrollment_labels = []; $enrollment_values = [];
$eq = mysqli_query($conn, "SELECT DATE_FORMAT(MIN(enrolled_at),'%b %Y') AS month_name, COUNT(*) AS total FROM enrollments GROUP BY YEAR(enrolled_at), MONTH(enrolled_at) ORDER BY YEAR(enrolled_at), MONTH(enrolled_at)");
if($eq){ while($row = mysqli_fetch_assoc($eq)){ $enrollment_labels[] = $row['month_name']; $enrollment_values[] = (int)$row['total']; } }

if(empty($revenue_labels)){    $revenue_labels    = ['No Data']; $revenue_values    = [0]; }
if(empty($student_labels)){    $student_labels    = ['No Data']; $student_values    = [0]; }
if(empty($enrollment_labels)){ $enrollment_labels = ['No Data']; $enrollment_values = [0]; }

/* ==
   STUDENT PAYMENT MONITORING
== */
$student_payments = mysqli_query($conn,
    "SELECT p.id, u.full_name AS student_name, u.email, c.title AS course,
        p.amount, p.payment_method, p.status, p.created_at
     FROM payments p
     LEFT JOIN users u ON p.user_id = u.id
     LEFT JOIN courses c ON p.course_id = c.id
     ORDER BY p.created_at DESC"
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LMS Admin Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --primary:      #4f46e5;
    --primary-light:#818cf8;
    --primary-dark: #3730a3;
    --success:      #10b981;
    --warning:      #f59e0b;
    --danger:       #ef4444;
    --info:         #0ea5e9;
    --bg:           #f1f5f9;
    --sidebar-bg:   #0f172a;
    --card-bg:      #ffffff;
    --text:         #0f172a;
    --muted:        #64748b;
    --border:       #e2e8f0;
    --radius:       14px;
    --shadow:       0 4px 20px rgba(0,0,0,0.06);
}

* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: 'Inter', Arial, sans-serif;
    background: var(--bg);
    display: flex;
    min-height: 100vh;
    overflow-x: hidden;
    color: var(--text);
}

/* - SIDEBAR - */
.sidebar {
    width: 260px;
    background: var(--sidebar-bg);
    position: fixed;
    top: 0; left: 0; bottom: 0;
    overflow-y: auto;
    z-index: 1000;
    transition: width .3s;
}

.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }

.logo {
    padding: 24px 20px 18px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    display: flex;
    align-items: center;
    gap: 12px;
}

.logo-icon {
    width: 38px; height: 38px;
    background: var(--primary);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #fff; flex-shrink: 0;
}

.logo h2 { font-size: 18px; font-weight: 700; color: #fff; letter-spacing: -.3px; }
.logo span { font-size: 11px; color: #94a3b8; font-weight: 400; }

.menu { padding: 12px 0; }

.menu-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 1px;
    color: #475569;
    padding: 14px 22px 6px;
    text-transform: uppercase;
}

.menu ul { list-style: none; }

.menu ul li a {
    text-decoration: none;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 22px;
    border-radius: 0;
    transition: all .2s;
    font-size: 14px;
    font-weight: 500;
    border-left: 3px solid transparent;
    margin: 1px 0;
}

.menu ul li a:hover,
.menu ul li a.active {
    color: #fff;
    background: rgba(79,70,229,.15);
    border-left-color: var(--primary-light);
}

.menu ul li a i { width: 18px; text-align: center; font-size: 15px; }

.menu ul li a .badge-pill {
    margin-left: auto;
    background: var(--primary);
    color: #fff;
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 20px;
    font-weight: 600;
}

/* - MAIN ─ */
.main {
    margin-left: 260px;
    width: calc(100% - 260px);
    padding: 24px;
    min-height: 100vh;
}

/* - TOPBAR -─ */
.topbar {
    background: var(--card-bg);
    padding: 16px 24px;
    border-radius: var(--radius);
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow);
    margin-bottom: 24px;
    border: 1px solid var(--border);
}

.topbar h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
}

.topbar-right { display: flex; align-items: center; gap: 16px; }

.topbar-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: var(--bg);
    display: flex; align-items: center; justify-content: center;
    color: var(--muted);
    cursor: pointer;
    border: 1px solid var(--border);
    transition: .2s;
}
.topbar-icon:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

.admin-profile {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 12px 6px 6px;
    border-radius: 40px;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: .2s;
}
.admin-profile:hover { border-color: var(--primary-light); }
.admin-profile img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
.admin-profile .info h4 { font-size: 13px; font-weight: 600; color: var(--text); }
.admin-profile .info p  { font-size: 11px; color: var(--muted); }

/* - STAT CARDS ─ */
.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.card {
    background: var(--card-bg);
    padding: 20px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    transition: transform .25s, box-shadow .25s;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.card:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,.1); }

.card-top { display: flex; align-items: center; justify-content: space-between; }

.card-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #fff;
}

.c-blue   { background: linear-gradient(135deg,#4f46e5,#818cf8); }
.c-green  { background: linear-gradient(135deg,#10b981,#34d399); }
.c-orange { background: linear-gradient(135deg,#f59e0b,#fbbf24); }
.c-red    { background: linear-gradient(135deg,#ef4444,#f87171); }
.c-cyan   { background: linear-gradient(135deg,#0ea5e9,#38bdf8); }
.c-purple { background: linear-gradient(135deg,#8b5cf6,#a78bfa); }

.card-trend {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 20px;
    font-weight: 600;
}
.trend-up   { background: #dcfce7; color: #166534; }
.trend-down { background: #fee2e2; color: #991b1b; }

.card h3 { font-size: 26px; font-weight: 700; color: var(--text); line-height: 1; }
.card p  { font-size: 13px; color: var(--muted); font-weight: 500; margin: 0; }

/* - CHARTS - */
.charts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}

.chart-box {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 22px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.chart-box h2 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-box h2 i { color: var(--primary); }

.chart-box canvas { width: 100% !important; height: 260px !important; }

.payment-summary {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 10px;
    margin-bottom: 16px;
}

.pay-stat {
    text-align: center;
    padding: 12px;
    border-radius: 10px;
    background: var(--bg);
}
.pay-stat span { font-size: 11px; color: var(--muted); font-weight: 500; display: block; margin-bottom: 4px; }
.pay-stat strong { font-size: 22px; font-weight: 700; color: var(--text); }
.pay-stat.s-success strong { color: var(--success); }
.pay-stat.s-pending strong { color: var(--warning); }
.pay-stat.s-failed  strong { color: var(--danger);  }

/* - TABLE SECTION - */
.table-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    margin-bottom: 22px;
    overflow: hidden;
}

.table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
}

.table-header h2 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.table-header h2 i { color: var(--primary); }

.table-wrap { overflow-x: auto; }

table { width: 100%; border-collapse: collapse; }

table thead { background: #f8fafc; }
table th {
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

table td {
    padding: 13px 16px;
    font-size: 13.5px;
    color: var(--text);
    border-bottom: 1px solid #f1f5f9;
}

table tbody tr:last-child td { border-bottom: none; }
table tbody tr:hover { background: #fafbff; }

/* - STATUS BADGES - */
.badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}
.badge-success, .badge-active, .badge-approved { background: #dcfce7; color: #166534; }
.badge-warning, .badge-pending, .badge-ongoing  { background: #fef3c7; color: #92400e; }
.badge-danger,  .badge-failed,  .badge-rejected { background: #fee2e2; color: #991b1b; }
.badge-info,    .badge-open                     { background: #e0f2fe; color: #0369a1; }
.badge-secondary, .badge-not-enrolled           { background: #f1f5f9; color: #475569; }
.badge-in-progress                              { background: #ede9fe; color: #5b21b6; }

/* - ACTION BOXES - */
.actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin-top: 24px;
    margin-bottom: 10px;
}

.action-box {
    background: var(--card-bg);
    padding: 22px;
    border-radius: var(--radius);
    text-align: center;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: .25s;
}

.action-box:hover {
    transform: translateY(-4px);
    border-color: var(--primary-light);
    box-shadow: 0 8px 30px rgba(79,70,229,.12);
}

.action-box .abox-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg,#ede9fe,#ddd6fe);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-size: 22px;
    color: var(--primary);
    transition: .25s;
}

.action-box:hover .abox-icon { background: var(--primary); color: #fff; }
.action-box h4 { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
.action-box p  { font-size: 12px; color: var(--muted); }

/* - MODAL -─ */
.modal {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15,23,42,.55);
    backdrop-filter: blur(4px);
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.modal-content {
    background: var(--card-bg);
    width: 480px;
    max-width: 95vw;
    max-height: 90vh;
    overflow-y: auto;
    padding: 28px;
    border-radius: 18px;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: modalIn .25s ease;
}

@keyframes modalIn {
    from { transform: translateY(-16px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}

.modal-content h3 {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 20px;
    color: var(--text);
}

.close-modal {
    position: absolute;
    right: 18px; top: 18px;
    width: 32px; height: 32px;
    border-radius: 8px;
    background: var(--bg);
    border: none;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: var(--muted);
    transition: .2s;
}
.close-modal:hover { background: #fee2e2; color: var(--danger); }

/* - FORMS -─ */
.form-group { margin-bottom: 14px; }
.form-group label { font-size: 12px; font-weight: 600; color: var(--muted); display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .4px; }

input[type=text], input[type=email], input[type=password],
input[type=number], input[type=file], textarea, select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg);
    color: var(--text);
    outline: none;
    transition: .2s;
    font-family: inherit;
}

input:focus, textarea:focus, select:focus {
    border-color: var(--primary);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(79,70,229,.1);
}

textarea { resize: vertical; min-height: 90px; }

/* - BUTTONS - */
.btn {
    padding: 9px 18px;
    border: none;
    border-radius: 9px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    transition: .2s;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none;
}
.btn-primary  { background: var(--primary);  color: #fff; }
.btn-primary:hover { background: var(--primary-dark); color: #fff; }
.btn-success  { background: var(--success); color: #fff; }
.btn-success:hover { background: #059669; }
.btn-warning  { background: var(--warning); color: #fff; }
.btn-warning:hover { background: #d97706; }
.btn-danger   { background: var(--danger);  color: #fff; }
.btn-danger:hover  { background: #dc2626; }
.btn-outline  { background: transparent; border: 1.5px solid var(--border); color: var(--text); }
.btn-outline:hover { border-color: var(--primary); color: var(--primary); background: rgba(79,70,229,.05); }
.btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 7px; }
.btn-full { width: 100%; justify-content: center; padding: 12px; font-size: 14px; }

/* - SECTION TITLES - */
.section-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-title i { color: var(--primary); font-size: 18px; }

/* - FORM CARDS  */
.form-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    margin-bottom: 22px;
}

.form-card h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-card h3 i { color: var(--primary); }

/* - ALERT -─ */
.alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid var(--danger); }
.alert-success { background: #dcfce7; color: #166534; border-left: 4px solid var(--success); }

/* - STATS MINI GRID - */
.mini-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px,1fr));
    gap: 14px;
    margin-bottom: 22px;
}

.mini-stat {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.mini-stat h2 { font-size: 26px; font-weight: 700; color: var(--primary); }
.mini-stat p  { font-size: 12px; color: var(--muted); margin-top: 4px; }

/* - ENROLLMENT BULK ACTIONS - */
.bulk-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

/* - RESPONSIVE - */
@media(max-width: 1024px){
    .charts { grid-template-columns: 1fr; }
}

@media(max-width: 860px){
    .sidebar { width: 68px; }
    .logo h2, .logo span, .menu ul li a span, .menu-label { display: none; }
    .logo-icon { margin: 0 auto; }
    .logo { justify-content: center; padding: 18px 0; }
    .menu ul li a { justify-content: center; padding: 14px 0; border-left: none; }
    .menu ul li a.active, .menu ul li a:hover { border-left: none; border-radius: 0; }
    .main { margin-left: 68px; width: calc(100% - 68px); padding: 16px; }
    .badge-pill { display: none; }
}

@media(max-width: 600px){
    .sidebar { display: none; }
    .main { margin-left: 0; width: 100%; padding: 12px; }
    .topbar { flex-direction: column; gap: 12px; align-items: flex-start; }
    .cards { grid-template-columns: repeat(2,1fr); }
}
</style>
</head>
<body>

<!-- ====
     SIDEBAR
=== -->
<div class="sidebar">
    <div class="logo">
        <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
        <div>
            <h2>LMS Admin</h2>
            <span>Management Panel</span>
        </div>
    </div>

    <div class="menu">
        <div class="menu-label">Main</div>
        <ul>
            <li><a href="#" onclick="showSection('dashboardSection',this)" class="active">
                <i class="fas fa-chart-line"></i><span>Dashboard</span>
            </a></li>
        </ul>

        <div class="menu-label">Management</div>
        <ul>
            <li><a href="#" onclick="showSection('studentsSection',this)">
                <i class="fas fa-user-graduate"></i><span>Students</span>
            </a></li>
            <li><a href="#" onclick="showSection('coursesSection',this)">
                <i class="fas fa-book"></i><span>Courses</span>
            </a></li>
            <li><a href="#" onclick="showSection('roleSection',this)">
                <i class="fas fa-user-shield"></i><span>Add Teacher</span>
            </a></li>
            <li><a href="#" onclick="showSection('videoSection',this)">
                <i class="fas fa-video"></i><span>Videos</span>
            </a></li>
            <li><a href="#" onclick="showSection('noteSection',this)">
                <i class="fas fa-file-alt"></i><span>Notes</span>
            </a></li>
            <li><a href="#" onclick="showSection('quizeSection',this)">
                <i class="fas fa-question-circle"></i><span>Quizzes</span>
            </a></li>
        </ul>

        <div class="menu-label">Operations</div>
        <ul>
            <li><a href="#" onclick="showSection('enrollmentSection',this)">
                <i class="fas fa-user-check"></i><span>Enrollments</span>
            </a></li>
            <li><a href="cpassword.php">
                <i class="fas fa-key"></i><span>Assign</span>
            </a></li>
            <li><a href="#" onclick="showSection('paymentSection',this)">
                <i class="fas fa-credit-card"></i><span>Payments</span>
            </a></li>
            <li><a href="#" onclick="showSection('announcementSection',this)">
                <i class="fas fa-bell"></i><span>Announcements</span>
            </a></li>
            <li><a href="#" onclick="showSection('ticketSection',this)">
                <i class="fas fa-headset"></i><span>Tickets</span>
            </a></li>
        </ul>

        <div class="menu-label">Account</div>
        <ul>
            <li><a href="login.php">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a></li>
        </ul>
    </div>
</div>

<!-- ====
     MAIN CONTENT
====═ -->
<div class="main">

<!-- ====
     DASHBOARD SECTION
==== -->
<div id="dashboardSection">

    <div class="topbar">
        <h1>Admin Dashboard</h1>
        <div class="topbar-right">
            <div class="topbar-icon"><i class="fas fa-search"></i></div>
            <div class="topbar-icon"><i class="fas fa-bell"></i></div>
            <div class="admin-profile">
                <img src="https://i.pravatar.cc/100" alt="Admin">
                <div class="info">
                    <h4>System Admin</h4>
                    <p>Administrator</p>
                </div>
            </div>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="cards">
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-blue"><i class="fas fa-user-graduate"></i></div>
                <span class="card-trend trend-up">+12%</span>
            </div>
            <h3><?= $total_students ?></h3>
            <p>Total Students</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-green"><i class="fas fa-user-check"></i></div>
                <span class="card-trend trend-up">Active</span>
            </div>
            <h3><?= $active_students ?></h3>
            <p>Active Users</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-purple"><i class="fas fa-book"></i></div>
            </div>
            <h3><?= $total_courses ?></h3>
            <p>Total Courses</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-cyan"><i class="fas fa-unlock"></i></div>
            </div>
            <h3><?= $free_courses ?></h3>
            <p>Free Courses</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-orange"><i class="fas fa-lock"></i></div>
            </div>
            <h3><?= $paid_courses ?></h3>
            <p>Paid Courses</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-blue"><i class="fas fa-user-plus"></i></div>
            </div>
            <h3><?= $total_enrollments ?></h3>
            <p>Total Enrollments</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-red"><i class="fas fa-dollar-sign"></i></div>
                <span class="card-trend trend-up">KES</span>
            </div>
            <h3><?= number_format($revenue) ?></h3>
            <p>Revenue Generated</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-green"><i class="fas fa-chart-line"></i></div>
            </div>
            <h3><?= round($completion_rate) ?>%</h3>
            <p>Completion Rate</p>
        </div>
        <div class="card">
            <div class="card-top">
                <div class="card-icon c-orange"><i class="fas fa-clock"></i></div>
                <span class="card-trend trend-down">Pending</span>
            </div>
            <h3><?= $pending_payments ?></h3>
            <p>Pending Payments</p>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="charts">
        <div class="chart-box">
            <h2><i class="fas fa-chart-area"></i> Revenue Graph</h2>
            <canvas id="revenueChart"></canvas>
        </div>
        <div class="chart-box">
            <h2><i class="fas fa-chart-line"></i> Student Growth</h2>
            <canvas id="studentChart"></canvas>
        </div>
        <div class="chart-box">
            <h2><i class="fas fa-chart-bar"></i> Enrollment Trends</h2>
            <canvas id="enrollmentChart"></canvas>
        </div>
        <div class="chart-box">
            <h2><i class="fas fa-money-bill-wave"></i> Payment Status</h2>
            <div class="payment-summary">
                <div class="pay-stat s-success"><span>Success</span><strong><?= $successful_payments ?></strong></div>
                <div class="pay-stat s-pending"><span>Pending</span><strong><?= $pending_payments ?></strong></div>
                <div class="pay-stat s-failed"><span>Failed</span><strong><?= $failed_payments ?></strong></div>
            </div>
            <canvas id="paymentChart"></canvas>
        </div>
    </div>

    <!-- POPULAR COURSES TABLE -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-fire"></i> Most Popular Courses</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Course</th><th>Students</th><th>Revenue</th><th>Completion</th></tr></thead>
                <tbody>
                <?php while($course = mysqli_fetch_assoc($popular_courses)): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($course['title']) ?></strong></td>
                        <td><?= $course['users'] ?></td>
                        <td>KES <?= number_format($course['revenue']) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                                    <div style="width:<?= round($course['completion_rate']) ?>%;height:100%;background:var(--success);border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px;color:var(--muted);"><?= round($course['completion_rate']) ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- RECENT STUDENTS TABLE -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-users"></i> Recent Registrations</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Course</th><th>Status</th></tr></thead>
                <tbody>
                <?php while($student = mysqli_fetch_assoc($recent_students)): ?>
                    <tr>
                        <td>#<?= $student['id'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">
                                    <?= strtoupper(substr($student['full_name'],0,1)) ?>
                                </div>
                                <?= htmlspecialchars($student['full_name']) ?>
                            </div>
                        </td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($student['email']) ?></td>
                        <td><?= htmlspecialchars($student['course_name'] ?? 'No Course') ?></td>
                        <td>
                            <?php $st = strtolower($student['status'] ?? 'pending'); ?>
                            <span class="badge badge-<?= $st == 'not enrolled' ? 'secondary' : $st ?>">
                                <?= ucfirst($student['status'] ?? 'Pending') ?>
                            </span>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- RECENT ACTIVITIES TABLE -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-history"></i> Recent Activities</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Activity</th><th>User</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php while($activity = mysqli_fetch_assoc($activities)): ?>
                    <tr>
                        <td><?= htmlspecialchars($activity['activity_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></td>
                        <td style="color:var(--muted);"><?= date("d M Y", strtotime($activity['created_at'])) ?></td>
                        <td><span class="badge badge-success"><?= ucfirst($activity['status'] ?? 'Completed') ?></span></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="actions">
        <div class="action-box" onclick="openModal('courseModal')">
            <div class="abox-icon"><i class="fas fa-plus-circle"></i></div>
            <h4>Create Course</h4>
            <p>Add and publish new courses for students.</p>
        </div>
        <div class="action-box" onclick="openModal('uploadModal')">
            <div class="abox-icon"><i class="fas fa-upload"></i></div>
            <h4>Upload Materials</h4>
            <p>Upload notes, PDFs and videos securely.</p>
        </div>
        <div class="action-box" onclick="showSection('paymentSection')">
            <div class="abox-icon"><i class="fas fa-money-check-alt"></i></div>
            <h4>Monitor Payments</h4>
            <p>Track paid courses and student payments.</p>
        </div>
        <div class="action-box" onclick="window.location.href='reports.php'">
            <div class="abox-icon"><i class="fas fa-chart-bar"></i></div>
            <h4>View Reports</h4>
            <p>Analyze enrollments and performance.</p>
        </div>
    </div>

    <!-- MODAL: CREATE COURSE -->
    <div id="courseModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('courseModal')"><i class="fas fa-times"></i></button>
            <h3><i class="fas fa-book" style="color:var(--primary);margin-right:8px;"></i>Create Course</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group"><label>Course Title</label><input type="text" name="title" placeholder="e.g. Introduction to Python" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" placeholder="Course description..." required></textarea></div>
                <div class="form-group"><label>Category</label><input type="text" name="category" placeholder="e.g. Web Development" required></div>
                <div class="form-group"><label>Instructor</label><input type="text" name="instructor" placeholder="Instructor name" required></div>
                <div class="form-group"><label>Price (leave 0 for free)</label><input type="number" name="price" placeholder="0.00" min="0"></div>
                <div class="form-group"><label>Thumbnail</label><input type="file" name="thumbnail" accept="image/*"></div>
                <button type="submit" name="create_course" class="btn btn-primary btn-full">Create Course</button>
            </form>
        </div>
    </div>

    <!-- MODAL: UPLOAD MATERIAL -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('uploadModal')"><i class="fas fa-times"></i></button>
            <h3><i class="fas fa-upload" style="color:var(--primary);margin-right:8px;"></i>Upload Materials</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group"><label>Material Title</label><input type="text" name="title" placeholder="Material title" required></div>
                <div class="form-group">
                    <label>Course</label>
                    <select name="course_id" required>
                        <option value="">Select Course</option>
                        <?php
                        $c_list = mysqli_query($conn,"SELECT id,title FROM courses");
                        while($c = mysqli_fetch_assoc($c_list)):
                        ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group"><label>File (PDF, DOC, Image — max 10MB)</label><input type="file" name="file" required accept=".pdf,.doc,.docx,image/*"></div>
                <button type="submit" name="upload_material" class="btn btn-primary btn-full">Upload</button>
            </form>
        </div>
    </div>
</div>

<!-- ====
     ROLE / ADD TEACHER SECTION
==== -->
<div id="roleSection" style="display:none;">
    <div class="topbar">
        <h1>Add Teacher</h1>
        <div class="topbar-right">
            <div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div>
        </div>
    </div>

    <?php if(!empty($message)): ?>
        <div class="alert alert-<?= $messageType ?>"><i class="fas fa-<?= $messageType=='error'?'exclamation-circle':'check-circle' ?>"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="form-card" style="max-width:600px;">
        <h3><i class="fas fa-user-plus"></i> New Teacher Account</h3>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" required maxlength="100" pattern="[A-Za-z\s]+" title="Only letters allowed"></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" required maxlength="150"></div>
            <div class="form-group"><label>Password *</label><input type="password" name="password" required minlength="6"></div>
            <div class="form-group"><label>Employee Number</label><input type="text" name="employee_no" maxlength="50"></div>
            <div class="form-group"><label>Phone Number</label><input type="text" name="phone" maxlength="20" pattern="[0-9+]+"></div>
            <div class="form-group"><label>Specialization</label><input type="text" name="specialization" maxlength="100"></div>
            <div class="form-group"><label>Qualification</label><input type="text" name="qualification" maxlength="100"></div>
            <div class="form-group"><label>Experience (Years)</label><input type="number" name="experience_years" min="0" max="60"></div>
            <button type="submit" name="add_teacher" class="btn btn-primary btn-full">Add Teacher</button>
        </form>
    </div>
</div>

<!-- ====
     COURSES SECTION
==== -->
<div id="coursesSection" style="display:none;">
    <div class="topbar">
        <h1>Course Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px;">
        <div class="form-card">
            <h3><i class="fas fa-plus-circle"></i> Create Course</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-group"><label>Title</label><input type="text" name="title" placeholder="Course Title" required maxlength="150"></div>
                <div class="form-group"><label>Description</label><textarea name="description" placeholder="Course Description" required maxlength="2000"></textarea></div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" required>
                        <option value="">Select Category</option>
                        <option value="web_dev">Web Development</option>
                        <option value="mobile_dev">Mobile Development</option>
                        <option value="data_science">Data Science</option>
                        <option value="ui_ux">UI/UX Design</option>
                        <option value="cyber_security">Cyber Security</option>
                        <option value="ai_ml">AI / ML</option>
                    </select>
                </div>
                <div class="form-group"><label>Custom Category</label><input type="text" name="custom_category" placeholder="Optional" maxlength="100"></div>
                <div class="form-group"><label>Thumbnail</label><input type="file" name="thumbnail" accept="image/*"></div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="course_type"><option value="Free">Free</option><option value="Paid">Paid</option></select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" required><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
                </div>
                <div class="form-group">
                    <label>Assign Teacher</label>
                    <select name="teacher_id" required>
                        <option value="">Select Teacher</option>
                        <?php $t_list = mysqli_query($conn,"SELECT id,full_name FROM users WHERE role='teacher'");
                        while($t = mysqli_fetch_assoc($t_list)): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group"><label>Price</label><input type="number" name="price" placeholder="0.00" step="0.01" min="0"></div>
                <button class="btn btn-primary btn-full" name="create_course">Create Course</button>
            </form>
        </div>

        <div class="form-card">
            <h3><i class="fas fa-archive"></i> Delete / Archive Course</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-group">
                    <label>Select Course</label>
                    <select name="course_id" required>
                        <option value="">Select Course</option>
                        <?php $cl = mysqli_query($conn,"SELECT id,title FROM courses ORDER BY id DESC");
                        while($c = mysqli_fetch_assoc($cl)): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Action</label>
                    <select name="action" required><option value="archive">Archive</option><option value="delete">Delete</option></select>
                </div>
                <button class="btn btn-danger btn-full" name="delete_course">Apply</button>
            </form>
        </div>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-list"></i> Course List</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Thumbnail</th><th>Price</th><th>Status</th></tr></thead>
                <tbody>
                <?php $courses2 = mysqli_query($conn,"SELECT * FROM courses ORDER BY id DESC");
                while($row = mysqli_fetch_assoc($courses2)): ?>
                    <tr>
                        <td>#<?= (int)$row['id'] ?></td>
                        <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                        <td><?= htmlspecialchars($row['category']) ?></td>
                        <td><?php if(!empty($row['thumbnail'])): ?><img src="<?= htmlspecialchars($row['thumbnail']) ?>" style="width:60px;height:40px;object-fit:cover;border-radius:6px;" alt=""><?php endif; ?></td>
                        <td>$<?= htmlspecialchars($row['price']) ?></td>
                        <td><span class="badge badge-<?= strtolower($row['status'])=='active' ? 'success' : 'danger' ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     VIDEO SECTION
==== -->
<div id="videoSection" style="display:none;">
    <div class="topbar">
        <h1>Video Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <div class="form-card">
        <h3><i class="fas fa-video"></i> <?= isset($editVideo) && $editVideo ? "Edit Video" : "Upload Video" ?></h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="video_id" value="<?= isset($editVideo) ? $editVideo['id'] : '' ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label>Course</label>
                    <select name="course_id" required>
                        <option value="">Select Course</option>
                        <?php $vc = $conn->query("SELECT id,title FROM courses ORDER BY title ASC");
                        while($c = $vc->fetch_assoc()):
                            $sel = (isset($editVideo) && $editVideo && $editVideo['course_id']==$c['id']) ? "selected" : "";
                        ?>
                        <option value="<?= $c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['title']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group"><label>Title</label><input type="text" name="title" value="<?= isset($editVideo) ? htmlspecialchars($editVideo['title'] ?? '') : '' ?>" required></div>
                <div class="form-group"><label>Video File</label><input type="file" name="video" accept="video/*"></div>
                <div class="form-group"><label>Cloud Link (Drive/YouTube)</label><input type="text" name="cloud_url" value="<?= isset($editVideo) ? htmlspecialchars($editVideo['cloud_url'] ?? '') : '' ?>"></div>
                <div class="form-group">
                    <label>Access Type</label>
                    <select name="access_type">
                        <option value="free" <?= (isset($editVideo) && ($editVideo['access_type']??'')=='free')?'selected':'' ?>>Free</option>
                        <option value="paid" <?= (isset($editVideo) && ($editVideo['access_type']??'')=='paid')?'selected':'' ?>>Paid</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Description</label><textarea name="description"><?= isset($editVideo) ? htmlspecialchars($editVideo['description'] ?? '') : '' ?></textarea></div>
            <?php if(isset($editVideo) && $editVideo): ?>
                <button type="submit" name="update_video" class="btn btn-warning">Update Video</button>
            <?php else: ?>
                <button type="submit" name="upload_video" class="btn btn-primary">Upload Video</button>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-play-circle"></i> Video Library</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Course</th><th>Title</th><th>Access</th><th>Description</th><th>Actions</th></tr></thead>
                <tbody>
                <?php
                $vr = $conn->query("SELECT v.*, c.title AS course FROM course_videos v JOIN courses c ON c.id=v.course_id ORDER BY v.id DESC");
                while($v = $vr->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($v['course']) ?></td>
                        <td><strong><?= htmlspecialchars($v['title']) ?></strong></td>
                        <td><span class="badge badge-<?= $v['access_type']=='free'?'success':'warning' ?>"><?= ucfirst($v['access_type']) ?></span></td>
                        <td style="color:var(--muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($v['description'] ?? 'No description available') ?></td>
                        <td>
                            <a href="?view=<?= $v['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Watch</a>
                            <?php if(in_array($user_role ?? 'admin',['admin','teacher'])): ?>
                            <a href="?edit=<?= $v['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-edit"></i></a>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="video_id" value="<?= $v['id'] ?>">
                                <button type="submit" name="delete_video" class="btn btn-danger btn-sm" onclick="return confirm('Delete this video?')"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     QUIZ SECTION
==== -->
<div id="quizeSection" style="display:none;">
    <div class="topbar">
        <h1>Quiz Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <div class="mini-stats">
        <div class="mini-stat"><h2><?= $total_quizzes ?></h2><p>Total Quizzes</p></div>
        <div class="mini-stat"><h2><?= $total_questions ?></h2><p>Questions</p></div>
        <div class="mini-stat"><h2><?= $total_attempts ?></h2><p>Attempts</p></div>
        <div class="mini-stat"><h2 style="color:var(--success);"><?= $passed ?></h2><p>Passed</p></div>
        <div class="mini-stat"><h2 style="color:var(--danger);"><?= $failed_quiz ?></h2><p>Failed</p></div>
        <div class="mini-stat"><h2><?= round($average_score,1) ?></h2><p>Avg Score</p></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px;">
        <div class="form-card">
            <h3><i class="fas fa-plus"></i> Create Quiz</h3>
            <form method="POST">
                <div class="form-group"><label>Course</label>
                    <select name="course_id" required>
                        <option value="">Select Course</option>
                        <?php $qc = mysqli_query($conn,"SELECT * FROM courses"); while($c=mysqli_fetch_assoc($qc)): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group"><label>Quiz Title</label><input type="text" name="title" placeholder="Quiz Title" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" placeholder="Description"></textarea></div>
                <div class="form-group"><label>Passing Marks (%)</label><input type="number" name="passing_marks" placeholder="e.g. 70" required></div>
                <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration" placeholder="e.g. 30" required></div>
                <button type="submit" name="create_quiz" class="btn btn-primary btn-full">Create Quiz</button>
            </form>
        </div>

        <div class="form-card">
            <h3><i class="fas fa-question"></i> Add Question</h3>
            <form method="POST">
                <div class="form-group"><label>Quiz</label>
                    <select name="quiz_id" required>
                        <option value="">Select Quiz</option>
                        <?php $ql = mysqli_query($conn,"SELECT * FROM quizzes ORDER BY id DESC"); while($q=mysqli_fetch_assoc($ql)): ?>
                        <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['title']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group"><label>Question</label><textarea name="question" placeholder="Enter question..." required></textarea></div>
                <div class="form-group"><label>Type</label>
                    <select name="question_type"><option value="mcq">Multiple Choice</option><option value="short_answer">Short Answer</option></select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="form-group"><label>Option A</label><input type="text" name="option_a"></div>
                    <div class="form-group"><label>Option B</label><input type="text" name="option_b"></div>
                    <div class="form-group"><label>Option C</label><input type="text" name="option_c"></div>
                    <div class="form-group"><label>Option D</label><input type="text" name="option_d"></div>
                </div>
                <div class="form-group"><label>Correct Answer</label><input type="text" name="correct_answer" required></div>
                <div class="form-group"><label>Marks</label><input type="number" name="marks" value="1"></div>
                <button type="submit" name="add_question" class="btn btn-success btn-full">Add Question</button>
            </form>
        </div>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-list"></i> Quiz List</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Course</th><th>Quiz</th><th>Passing</th><th>Duration</th><th>Status</th></tr></thead>
                <tbody>
                <?php $qlist = mysqli_query($conn,"SELECT q.*, c.title AS course_name FROM quizzes q LEFT JOIN courses c ON c.id=q.course_id ORDER BY q.id DESC");
                while($row = mysqli_fetch_assoc($qlist)): ?>
                    <tr>
                        <td>#<?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['course_name']) ?></td>
                        <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                        <td><?= $row['passing_marks'] ?? $row['passing_score'] ?>%</td>
                        <td><?= $row['duration'] ?> mins</td>
                        <td><span class="badge badge-<?= strtolower($row['status'] ?? 'active')=='active' ? 'success' : 'secondary' ?>"><?= ucfirst($row['status'] ?? 'Active') ?></span></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-users"></i> Student Attempts</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Quiz</th><th>Score</th><th>%</th><th>Result</th><th>Date</th></tr></thead>
                <tbody>
                <?php $atts = mysqli_query($conn,"SELECT qa.*, u.full_name, q.title AS quiz_title FROM quiz_attempts qa LEFT JOIN users u ON u.id=qa.user_id LEFT JOIN quizzes q ON q.id=qa.quiz_id ORDER BY qa.id DESC");
                while($att = mysqli_fetch_assoc($atts)):
                    $qid = $att['quiz_id'];
                    $tm  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(marks),0) AS total_marks FROM quiz_questions WHERE quiz_id='$qid'"))['total_marks'];
                    $pct = $tm > 0 ? round(($att['score']/$tm)*100) : 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($att['full_name']) ?></td>
                        <td><?= htmlspecialchars($att['quiz_title']) ?></td>
                        <td><?= $att['score'] ?></td>
                        <td><?= $pct ?>%</td>
                        <td><span class="badge badge-<?= strtolower($att['result'])=='pass'?'success':'danger' ?>"><?= htmlspecialchars($att['result']) ?></span></td>
                        <td style="color:var(--muted);"><?= !empty($att['finished_at']) ? $att['finished_at'] : $att['started_at'] ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     NOTE SECTION
==== -->
<div id="noteSection" style="display:none;">
    <div class="topbar">
        <h1>Notes Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <div class="form-card" style="max-width:600px;">
        <h3><i class="fas fa-file-alt"></i> Create Note</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group"><label>Course</label>
                <select name="course_id" required>
                    <option value="">Select Course</option>
                    <?php $nc = $conn->query("SELECT * FROM courses"); while($c=$nc->fetch_assoc()): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group"><label>Title</label><input name="title" placeholder="Note Title" required></div>
            <div class="form-group"><label>Content</label><textarea name="content" placeholder="Write notes here..." rows="4"></textarea></div>
            <div class="form-group"><label>Attach File (PDF, Doc, Image)</label><input type="file" name="file"></div>
            <div class="form-group"><label>Access Type</label>
                <select name="access_type"><option value="free">Free Course</option><option value="paid">Paid Course</option></select>
            </div>
            <button name="add_note" class="btn btn-primary btn-full">Upload Note</button>
        </form>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-book-open"></i> Notes Library</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Course</th><th>Title</th><th>Access</th><th>File</th><th>Actions</th></tr></thead>
                <tbody>
                <?php while($n = $notes->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($n['course_title']) ?></td>
                        <td><strong><?= htmlspecialchars($n['title']) ?></strong></td>
                        <td><span class="badge badge-<?= $n['access_type']=='free'?'success':'warning' ?>"><?= ucfirst($n['access_type']) ?></span></td>
                        <td><?= $n['file_path'] ? '<span class="badge badge-info">Uploaded</span>' : '<span class="badge badge-secondary">Text Only</span>' ?></td>
                        <td>
                            <a href="?view=<?= $n['id'] ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                            <a href="?delete_note=<?= $n['id'] ?>" onclick="return confirm('Delete note?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     ANNOUNCEMENT SECTION
==== -->
<div id="announcementSection" style="display:none;">
    <div class="topbar">
        <h1>Announcements</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <div class="form-card" style="max-width:600px;">
        <h3><i class="fas fa-bullhorn"></i> Post Announcement</h3>
        <form method="POST">
            <div class="form-group"><label>Course</label>
                <select name="course_id">
                    <option value="0">-- All Courses --</option>
                    <?php if($ann_courses) while($c = $ann_courses->fetch_assoc()): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group"><label>Title</label><input type="text" name="title" placeholder="Announcement title" required></div>
            <div class="form-group"><label>Message</label><textarea name="message" placeholder="Write your announcement..." required></textarea></div>
            <button type="submit" name="add" class="btn btn-primary btn-full">Post Announcement</button>
        </form>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-bell"></i> Posted Announcements</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Course</th><th>Title</th><th>Message</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                <?php if($announcements && $announcements->num_rows > 0):
                    while($a = $announcements->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['course']) ?></td>
                        <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
                        <td style="max-width:250px;"><?= nl2br(htmlspecialchars($a['message'])) ?></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($a['created_at']) ?></td>
                        <td><a href="?delete_ann=<?= $a['id'] ?>" onclick="return confirm('Delete?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:30px;">No announcements yet</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====══
     ENROLLMENT SECTION
==== -->
<div id="enrollmentSection" style="display:none;">
    <div class="topbar">
        <h1>Enrollment Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <!-- BUG FIX: Fetch enrollments fresh for this section -->
    <?php
    $enroll_list = $conn->query(
        "SELECT e.id, e.status, e.enrolled_at, u.full_name, u.email, c.title AS course
         FROM enrollments e
         JOIN users u ON u.id=e.user_id
         JOIN courses c ON c.id=e.course_id
         WHERE u.role='student'
         ORDER BY e.id DESC"
    );
    ?>

    <form method="POST" class="bulk-actions">
        <button type="submit" name="approve_all" class="btn btn-success" onclick="return confirm('Approve ALL pending enrollments?')"><i class="fas fa-check-double"></i> Approve All</button>
        <button type="submit" name="reject_all"  class="btn btn-warning" onclick="return confirm('Reject ALL pending/ongoing enrollments?')"><i class="fas fa-times"></i> Reject All</button>
        <button type="submit" name="clear_all"   class="btn btn-danger"  onclick="return confirm('DELETE ALL enrollments permanently?')"><i class="fas fa-trash"></i> Delete All</button>
    </form>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-user-check"></i> All Enrollments</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Email</th><th>Course</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php while($row = $enroll_list->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($row['email']) ?></td>
                        <td><?= htmlspecialchars($row['course']) ?></td>
                        <td><span class="badge badge-<?= strtolower($row['status']) ?>"><?= strtoupper($row['status']) ?></span></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($row['enrolled_at']) ?></td>
                        <td>
                            <a href="?approve=<?= $row['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Approve</a>
                            <a href="?reject=<?= $row['id'] ?>"  class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Reject</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     TICKET SECTION
==== -->
<div id="ticketSection" style="display:none;">
    <div class="topbar">
        <h1>Support Tickets</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <?php
    if(isset($_POST['update_status'])){
        if(!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){ die("Invalid CSRF token."); }
        $tid    = (int)($_POST['ticket_id'] ?? 0);
        $tstat  = trim($_POST['status'] ?? '');
        $tallow = ['open','in_progress','resolved','closed'];
        if($tid > 0 && in_array($tstat, $tallow, true)){
            $stmt = $conn->prepare("UPDATE tickets SET status=? WHERE id=?");
            $stmt->bind_param("si", $tstat, $tid); $stmt->execute(); $stmt->close();
        }
    }
    ?>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-headset"></i> All Tickets</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student</th><th>Type</th><th>Subject</th><th>Message</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php
                $ts = $conn->prepare("SELECT t.*, u.full_name FROM tickets t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.id DESC");
                $ts->execute(); $tr = $ts->get_result();
                while($row = $tr->fetch_assoc()):
                    $tstat_cls = str_replace('_','-', strtolower($row['status'] ?? 'open'));
                ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['type'] ?? '') ?></td>
                        <td><strong><?= htmlspecialchars($row['subject'] ?? '') ?></strong></td>
                        <td style="max-width:200px;color:var(--muted);"><?= nl2br(htmlspecialchars($row['message'] ?? '')) ?></td>
                        <td><span class="badge badge-<?= $tstat_cls ?>"><?= strtoupper(htmlspecialchars($row['status'] ?? '')) ?></span></td>
                        <td>
                            <form method="POST" style="display:flex;gap:6px;align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="ticket_id"  value="<?= (int)$row['id'] ?>">
                                <select name="status" style="width:130px;padding:6px 10px;font-size:12px;">
                                    <option value="open"        <?= $row['status']==='open'        ?'selected':'' ?>>Open</option>
                                    <option value="in_progress" <?= $row['status']==='in_progress' ?'selected':'' ?>>In Progress</option>
                                    <option value="resolved"    <?= $row['status']==='resolved'    ?'selected':'' ?>>Resolved</option>
                                    <option value="closed"      <?= $row['status']==='closed'      ?'selected':'' ?>>Closed</option>
                                </select>
                                <button type="submit" name="update_status" class="btn btn-primary btn-sm" onclick="return confirm('Update ticket status?')">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; $ts->close(); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     PAYMENT SECTION
==== -->
<div id="paymentSection" style="display:none;">
    <div class="topbar">
        <h1>Payment Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
        <div class="card"><div class="card-icon c-green" style="margin-bottom:10px;"><i class="fas fa-check-circle"></i></div><h3><?= $successful ?></h3><p>Successful</p></div>
        <div class="card"><div class="card-icon c-red"   style="margin-bottom:10px;"><i class="fas fa-times-circle"></i></div><h3><?= $failed ?></h3><p>Failed</p></div>
        <div class="card"><div class="card-icon c-orange" style="margin-bottom:10px;"><i class="fas fa-clock"></i></div><h3><?= $pending ?></h3><p>Pending</p></div>
        <div class="card"><div class="card-icon c-blue"  style="margin-bottom:10px;"><i class="fas fa-calendar-day"></i></div><h3>KES <?= number_format($daily,0) ?></h3><p>Today</p></div>
        <div class="card"><div class="card-icon c-purple" style="margin-bottom:10px;"><i class="fas fa-calendar-week"></i></div><h3>KES <?= number_format($monthly,0) ?></h3><p>This Month</p></div>
        <div class="card"><div class="card-icon c-cyan"  style="margin-bottom:10px;"><i class="fas fa-calendar"></i></div><h3>KES <?= number_format($yearly,0) ?></h3><p>This Year</p></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px;">
        <div class="table-section">
            <div class="table-header"><h2><i class="fas fa-credit-card"></i> Payment Methods</h2></div>
            <table>
                <thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php while($row = mysqli_fetch_assoc($methods)): ?>
                    <tr><td><?= strtoupper($row['payment_method']) ?></td><td><?= $row['total'] ?></td><td>KES <?= number_format($row['revenue'],2) ?></td></tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div class="table-section">
            <div class="table-header"><h2><i class="fas fa-book"></i> Revenue by Course</h2></div>
            <table>
                <thead><tr><th>Course</th><th>Total Revenue</th></tr></thead>
                <tbody>
                <?php while($row = mysqli_fetch_assoc($courseRevenue)): ?>
                    <tr><td><?= htmlspecialchars($row['title']) ?></td><td>KES <?= number_format($row['total'],2) ?></td></tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-list"></i> Payment Monitoring</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Course</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php while($row = mysqli_fetch_assoc($payments)): ?>
                    <tr>
                        <td>#<?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['course_id']) ?></td>
                        <td>KES <?= number_format($row['amount'],2) ?></td>
                        <td><?= strtoupper($row['payment_method']) ?></td>
                        <td><span class="badge badge-<?= $row['status']=='success'?'success':($row['status']=='pending'?'warning':'danger') ?>"><?= strtoupper($row['status']) ?></span></td>
                        <td style="color:var(--muted);"><?= $row['created_at'] ?></td>
                        <td>
                            <a href="?action=success&id=<?= $row['id'] ?>" onclick="return confirm('Approve?')" class="btn btn-success btn-sm">Approve</a>
                            <a href="?action=failed&id=<?= $row['id'] ?>"  onclick="return confirm('Reject?')"  class="btn btn-danger btn-sm">Reject</a>
                            <a href="?action=pending&id=<?= $row['id'] ?>" onclick="return confirm('Pending?')" class="btn btn-warning btn-sm">Pending</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-users"></i> Student Payment Monitoring</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Student</th><th>Email</th><th>Course</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php while($row = mysqli_fetch_assoc($student_payments)): ?>
                    <tr>
                        <td>#<?= $row['id'] ?></td>
                        <!-- BUG FIX: was $row['fullname'], query returns student_name -->
                        <td><?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['course']) ?></td>
                        <td>KES <?= number_format($row['amount'],2) ?></td>
                        <td><?= strtoupper($row['payment_method']) ?></td>
                        <td><span class="badge badge-<?= $row['status']=='success'?'success':($row['status']=='pending'?'warning':'danger') ?>"><?= strtoupper($row['status']) ?></span></td>
                        <td style="color:var(--muted);"><?= $row['created_at'] ?></td>
                        <td>
                            <a href="?action=success&id=<?= $row['id'] ?>" onclick="return confirm('Approve?')" class="btn btn-success btn-sm">Approve</a>
                            <a href="?action=failed&id=<?= $row['id'] ?>"  onclick="return confirm('Reject?')"  class="btn btn-danger btn-sm">Reject</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ====
     STUDENTS SECTION
==== -->
<div id="studentsSection" style="display:none;">
    <div class="topbar">
        <h1>Student Management</h1>
        <div class="topbar-right"><div class="admin-profile"><img src="https://i.pravatar.cc/100" alt="Admin"><div class="info"><h4>System Admin</h4><p>Administrator</p></div></div></div>
    </div>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-user-graduate"></i> Student Records</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Full Name</th><th>Email</th><th>Phone</th><th>Registered</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php while($s = mysqli_fetch_assoc($students)): ?>
                    <tr>
                        <td>#<?= (int)$s['id'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">
                                    <?= strtoupper(substr($s['full_name'],0,1)) ?>
                                </div>
                                <?= htmlspecialchars($s['full_name']) ?>
                            </div>
                        </td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($s['email']) ?></td>
                        <td><?= htmlspecialchars($s['phone'] ?? '') ?></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($s['created_at']) ?></td>
                        <td><span class="badge badge-<?= strtolower($s['status'])=='active'?'success':'danger' ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                        <td>
                            <form method="POST" style="display:flex;gap:6px;align-items:center;">
                                <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                <select name="action" onchange="studentAction(this, <?= (int)$s['id'] ?>)" style="padding:6px 10px;font-size:12px;width:140px;">
                                    <option value="">Action</option>
                                    <option value="activate">Activate</option>
                                    <option value="suspend">Suspend</option>
                                    <option value="reset_password">Reset Password</option>
                                    <option value="delete">Delete</option>
                                </select>
                                <button type="submit" name="student_action" class="btn btn-primary btn-sm" onclick="return confirm('Proceed?')">Go</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-section">
        <div class="table-header"><h2><i class="fas fa-clipboard-list"></i> Enrollment Monitoring</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Student</th><th>Course</th><th>Status</th><th>Progress</th><th>Enrolled At</th><th>Actions</th></tr></thead>
                <tbody>
                <!-- BUG FIX: use $enrollments_data array instead of exhausted result resource -->
                <?php foreach($enrollments_data as $e): ?>
                    <tr>
                        <td>#<?= (int)$e['id'] ?></td>
                        <td><?= htmlspecialchars($e['full_name']) ?></td>
                        <td><?= htmlspecialchars($e['title']) ?></td>
                        <td><span class="badge badge-<?= strtolower($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></span></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                                    <div style="width:<?= (int)$e['progress'] ?>%;height:100%;background:var(--success);border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px;color:var(--muted);"><?= (int)$e['progress'] ?>%</span>
                            </div>
                        </td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($e['enrolled_at']) ?></td>
                        <td>
                            <!-- BUG FIX: use hidden input for action, not button name -->
                            <form method="POST" style="display:flex;gap:6px;">
                                <input type="hidden" name="enroll_id"     value="<?= (int)$e['id'] ?>">
                                <input type="hidden" name="enroll_action" value="1">
                                <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                <button type="submit" name="action" value="reject"  class="btn btn-warning btn-sm">Reject</button>
                                <button type="submit" name="action" value="delete"  class="btn btn-danger  btn-sm" onclick="return confirm('Delete enrollment?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /main -->

<!-- RESET PASSWORD MODAL -->
<div id="resetPasswordModal" class="modal">
    <div class="modal-content" style="max-width:380px;">
        <button class="close-modal" onclick="document.getElementById('resetPasswordModal').style.display='none'"><i class="fas fa-times"></i></button>
        <h3><i class="fas fa-key" style="color:var(--primary);margin-right:8px;"></i>Reset Password</h3>
        <form method="POST">
            <input type="hidden" name="student_id" id="modal_student_id">
            <div class="form-group"><label>New Password</label><input type="password" name="new_password" minlength="6" required></div>
            <button type="submit" name="reset_student_password" class="btn btn-success btn-full">Save Password</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* - SECTION SWITCHER - */
const allSections = [
    'dashboardSection','studentsSection','coursesSection','videoSection',
    'noteSection','enrollmentSection','paymentSection','announcementSection',
    'ticketSection','quizeSection','roleSection'
];

function showSection(id, link){
    allSections.forEach(s => {
        const el = document.getElementById(s);
        if(el) el.style.display = 'none';
    });
    const target = document.getElementById(id);
    if(target) target.style.display = 'block';

    // Update active link
    document.querySelectorAll('.menu ul li a').forEach(a => a.classList.remove('active'));
    if(link) link.classList.add('active');
    else {
        // Try to find by onclick attribute
        document.querySelectorAll('.menu ul li a').forEach(a => {
            if(a.getAttribute('onclick') && a.getAttribute('onclick').includes(id)){
                a.classList.add('active');
            }
        });
    }
    window.scrollTo({top:0, behavior:'smooth'});
}

/* - MODALS ─ */
function openModal(id){ document.getElementById(id).style.display = 'flex'; }
function closeModal(id){ document.getElementById(id).style.display = 'none'; }

window.onclick = function(e){
    document.querySelectorAll('.modal').forEach(m => {
        if(e.target === m) m.style.display = 'none';
    });
};

/* - STUDENT ACTION - */
function studentAction(select, studentId){
    if(select.value === 'reset_password'){
        document.getElementById('modal_student_id').value = studentId;
        document.getElementById('resetPasswordModal').style.display = 'flex';
        select.selectedIndex = 0;
    }
}

/* - CHARTS -─ */
const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 } } }
    }
};

new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($revenue_labels) ?>,
        datasets: [{
            label: 'Revenue (KES)',
            data: <?= json_encode($revenue_values) ?>,
            borderColor: '#4f46e5',
            backgroundColor: 'rgba(79,70,229,.08)',
            borderWidth: 2.5,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#4f46e5',
            pointRadius: 4
        }]
    },
    options: { ...chartDefaults, plugins: { legend: { display: true, position: 'top', labels:{ font:{size:12} } } } }
});

new Chart(document.getElementById('studentChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($student_labels) ?>,
        datasets: [{
            label: 'Students',
            data: <?= json_encode($student_values) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,.08)',
            borderWidth: 2.5,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#10b981',
            pointRadius: 4
        }]
    },
    options: { ...chartDefaults, plugins: { legend: { display: true, position: 'top', labels:{ font:{size:12} } } } }
});

new Chart(document.getElementById('enrollmentChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($enrollment_labels) ?>,
        datasets: [{
            label: 'Enrollments',
            data: <?= json_encode($enrollment_values) ?>,
            backgroundColor: 'rgba(79,70,229,.75)',
            borderRadius: 6,
            borderSkipped: false
        }]
    },
    options: { ...chartDefaults, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
        labels: ['Successful', 'Pending', 'Failed'],
        datasets: [{
            data: [
                <?= (int)$successful_payments ?>,
                <?= (int)$pending_payments ?>,
                <?= (int)$failed_payments ?>
            ],
            backgroundColor: ['#10b981','#f59e0b','#ef4444'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 16 } }
        },
        cutout: '65%'
    }
});
</script>
</body>
</html>