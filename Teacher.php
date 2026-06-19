<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/* ===
   GET TEACHER INFO
=== */
$stmt = $conn->prepare("
    SELECT t.*, u.full_name, u.email
    FROM teachers t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacherInfo = $stmt->get_result()->fetch_assoc();

if (!$teacherInfo) {
    die("Access denied: Not a teacher account");
}

$teacher_id = $user_id;

/* ===
   GENERATE CSRF TOKEN FOR NOTES
=== */
if (empty($_SESSION['note_token'])) {
    $_SESSION['note_token'] = bin2hex(random_bytes(32));
}

/* ===
   UPDATE COURSE
=== */
if (isset($_POST['update_course'])) {
    $course_id   = (int)$_POST['id'];
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price       = (float)$_POST['price'];

    $stmt = $conn->prepare("
        UPDATE courses c
        INNER JOIN course_teachers ct ON ct.course_id = c.id
        SET c.title = ?, c.description = ?, c.price = ?
        WHERE c.id = ?
          AND ct.teacher_id = ?
          AND ct.status = 'active'
    ");
    $stmt->bind_param("ssdii", $title, $description, $price, $course_id, $teacher_id);
    $stmt->execute();
    $_SESSION['success_message'] = "Course updated successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE COURSE
=== */
if (isset($_GET['delete_course'])) {
    $course_id = (int)$_GET['delete_course'];
    $stmt = $conn->prepare("
        DELETE FROM course_teachers
        WHERE course_id = ? AND teacher_id = ?
    ");
    $stmt->bind_param("ii", $course_id, $teacher_id);
    $stmt->execute();
    $_SESSION['success_message'] = "Course removed successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   UPLOAD DIRECTORIES
=== */
$video_dir = "uploads/videos/";
$note_dir  = "uploads/notes/";
if (!file_exists($video_dir)) mkdir($video_dir, 0777, true);
if (!file_exists($note_dir))  mkdir($note_dir,  0777, true);

/* ===
   UPLOAD VIDEO
=== */
if (isset($_POST['upload_video'])) {
    $course_id = (int)$_POST['course_id'];
    $title     = trim($_POST['title']);
    $access    = $_POST['access_type'];
    $file_path = "";

    // Verify teacher owns the course
    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("Unauthorized course access");
    }

    if (!empty($_FILES['video']['name'])) {
        $file_name = time() . "_" . basename($_FILES['video']['name']);
        $target    = $video_dir . $file_name;
        move_uploaded_file($_FILES['video']['tmp_name'], $target);
        $file_path = $target;
    }

    $stmt = $conn->prepare("
        INSERT INTO course_videos (course_id, title, video_path, access_type, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssi", $course_id, $title, $file_path, $access, $teacher_id);
    $stmt->execute();
    $_SESSION['success_message'] = "Video uploaded successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE VIDEO
=== */
if (isset($_GET['delete_video'])) {
    $id = (int)$_GET['delete_video'];

    // Verify ownership before delete
    $stmt = $conn->prepare("
        SELECT v.video_path FROM course_videos v
        JOIN course_teachers ct ON ct.course_id = v.course_id
        WHERE v.id = ? AND ct.teacher_id = ?
    ");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();

    if ($old) {
        if (!empty($old['video_path']) && file_exists($old['video_path'])) {
            unlink($old['video_path']);
        }
        $stmt = $conn->prepare("DELETE FROM course_videos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['success_message'] = "Video deleted.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   VIEW VIDEO
=== */
if (isset($_GET['view_video'])) {
    $id = (int)$_GET['view_video'];
    $stmt = $conn->prepare("
        SELECT v.*, c.title AS course_title
        FROM course_videos v
        INNER JOIN courses c ON c.id = v.course_id
        INNER JOIN course_teachers ct ON ct.course_id = c.id
        WHERE v.id = ? AND ct.teacher_id = ?
    ");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $video = $stmt->get_result()->fetch_assoc();

    if (!$video) {
        die("Video not found or access denied");
    }

    // FIX: == not = for comparison
    $stmt = $conn->prepare("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? LIMIT 1");
    $stmt->bind_param("ii", $user_id, $video['course_id']);
    $stmt->execute();
    $isEnrolled = $stmt->get_result()->num_rows > 0;

    if ($video['access_type'] == 'paid' && !$isEnrolled) {
        die("<h2>Access Denied ❌</h2><p>You must enroll to watch this video.</p>");
    }
    ?>
    <!DOCTYPE html><html><head><title><?= htmlspecialchars($video['title']) ?></title></head>
    <body style="background:#000;color:#fff;padding:20px;">
        <h2><?= htmlspecialchars($video['title']) ?></h2>
        <video width="100%" controls controlsList="nodownload" oncontextmenu="return false">
            <source src="<?= htmlspecialchars($video['video_path']) ?>" type="video/mp4">
        </video>
        <script>document.addEventListener("contextmenu", e => e.preventDefault());</script>
    </body></html>
    <?php exit;
}

/* ===
   ADD NOTE
=== */
if (isset($_POST['add_note'])) {
    if (!isset($_POST['note_token']) || !hash_equals($_SESSION['note_token'], $_POST['note_token'])) {
        die("Invalid or duplicate submission.");
    }

    /* TEACHER ID MUST COME FROM SESSION — NEVER FROM CLIENT INPUT */
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
        die("Unauthorized.");
    }
    $teacher_id = (int)$_SESSION['user_id'];

    $course_id = (int)$_POST['course_id'];

    /* VERIFY OWNERSHIP */
    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("❌ Unauthorized course access");
    }

    $title       = trim($_POST['title'] ?? '');
    $content     = trim($_POST['content'] ?? '');
    $access_type = $_POST['access_type'] ?? 'free';
    $file_path   = "";

    /* === FILE UPLOAD: VALIDATE BEFORE MOVING === */
    if (isset($_FILES['file']) && !empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];
        $allowedMime = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'image/jpeg',
            'image/png',
        ];
        $maxFileSize = 10 * 1024 * 1024; // 10 MB

        $originalName = basename($_FILES['file']['name']);
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            die("❌ File type not allowed. Allowed types: " . implode(', ', $allowedExt));
        }

        if ($_FILES['file']['size'] > $maxFileSize) {
            die("❌ File is too large. Max size is 10MB.");
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['file']['tmp_name']);

        if (!in_array($mimeType, $allowedMime, true)) {
            die("❌ File content does not match an allowed type.");
        }

        /* SAFE, RANDOM FILENAME — NEVER TRUST THE ORIGINAL NAME */
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $target   = rtrim($note_dir, '/') . '/' . $safeName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            $file_path = $target;
        } else {
            die("❌ Failed to save uploaded file.");
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO notes (course_id, title, content, access_type, created_by, file_path)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssis", $course_id, $title, $content, $access_type, $teacher_id, $file_path);

    if ($stmt->execute()) {
        $_SESSION['note_token']      = bin2hex(random_bytes(32));
        $_SESSION['success_message'] = "Note added successfully.";
    } else {
        $_SESSION['error_message'] = "Failed to save note.";
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}
/* ===
   DELETE NOTE
=== */
if (isset($_POST['delete_note'])) {
    $id = (int)$_POST['delete_note'];
    $stmt = $conn->prepare("
        DELETE n FROM notes n
        JOIN course_teachers ct ON ct.course_id = n.course_id
        WHERE n.id = ? AND ct.teacher_id = ?
    ");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $_SESSION['success_message'] = "Note deleted successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   VIEW NOTE
=== */
if (isset($_GET['view_note'])) {
    $note_id = (int)$_GET['view_note'];
    $stmt = $conn->prepare("
        SELECT n.*, c.title AS course_title
        FROM notes n
        JOIN courses c ON c.id = n.course_id
        JOIN course_teachers ct ON ct.course_id = c.id
        WHERE n.id = ? AND ct.teacher_id = ?
    ");
    $stmt->bind_param("ii", $note_id, $teacher_id);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();

    if (!$note) die("Note not found");

    $stmt = $conn->prepare("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? LIMIT 1");
    $stmt->bind_param("ii", $user_id, $note['course_id']);
    $stmt->execute();
    $isEnrolled = $stmt->get_result()->num_rows > 0;

    // FIX: == not =
    if ($note['access_type'] == 'paid' && !$isEnrolled) {
        die("Access Denied ❌");
    }
}

/* ===
   CREATE QUIZ
=== */
if (isset($_POST['create_quiz'])) {
    $course_id = (int)$_POST['course_id'];

    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $teacher_id);
    $check->execute();
    // FIX: == not =
    if ($check->get_result()->num_rows == 0) {
        die("❌ Unauthorized course access");
    }

    // FIX: column is passing_score not passing_marks
    $stmt = $conn->prepare("
        INSERT INTO quizzes (course_id, title, description, passing_score, duration)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issii", $course_id, $_POST['title'], $_POST['description'], $_POST['passing_marks'], $_POST['duration']);
    $stmt->execute();
    $_SESSION['success_message'] = "Quiz created.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   ADD QUESTION
=== */
if (isset($_POST['add_question'])) {
    $quiz_id = (int)$_POST['quiz_id'];

    $check = $conn->prepare("
        SELECT 1 FROM quizzes q
        JOIN course_teachers ct ON ct.course_id = q.course_id
        WHERE q.id = ? AND ct.teacher_id = ?
        LIMIT 1
    ");
    $check->bind_param("ii", $quiz_id, $teacher_id);
    $check->execute();
    // FIX: == not =
    if ($check->get_result()->num_rows == 0) {
        die("❌ Unauthorized quiz access");
    }

    $stmt = $conn->prepare("
        INSERT INTO quiz_questions
        (quiz_id, question, question_type, option_a, option_b, option_c, option_d, correct_answer, marks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "isssssssi",
        $quiz_id,
        $_POST['question'],
        $_POST['question_type'],
        $_POST['option_a'],
        $_POST['option_b'],
        $_POST['option_c'],
        $_POST['option_d'],
        $_POST['correct_answer'],
        $_POST['marks']
    );
    $stmt->execute();
    $_SESSION['success_message'] = "Question added.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   STUDENT ACTIONS
=== */
if (isset($_POST['student_action'])) {
    $student_id = (int)$_POST['student_id'];
    $action     = $_POST['action'];    // FIX: just read, don't assign in if()

    $stmt = $conn->prepare("SELECT user_id FROM students WHERE id=?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();

    if (!$student) die("Student not found");
    $userId = $student['user_id'];

    // FIX: == comparisons, not =
    if ($action == "activate") {
        $s = $conn->prepare("UPDATE students SET status='active' WHERE id=?");
        $s->bind_param("i", $student_id); $s->execute();
        $s = $conn->prepare("UPDATE users SET status='active' WHERE id=?");
        $s->bind_param("i", $userId); $s->execute();
    } elseif ($action == "suspend") {
        $s = $conn->prepare("UPDATE students SET status='suspended' WHERE id=?");
        $s->bind_param("i", $student_id); $s->execute();
        $s = $conn->prepare("UPDATE users SET status='suspended' WHERE id=?");
        $s->bind_param("i", $userId); $s->execute();
    } elseif ($action == "reset_password") {
        $newPass = password_hash("123456", PASSWORD_DEFAULT);
        $s = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $s->bind_param("si", $newPass, $userId); $s->execute();
    } elseif ($action == "delete") {
        $s = $conn->prepare("DELETE FROM enrollments WHERE user_id=?");
        $s->bind_param("i", $userId); $s->execute();
        $s = $conn->prepare("DELETE FROM students WHERE id=?");
        $s->bind_param("i", $student_id); $s->execute();
        $s = $conn->prepare("DELETE FROM users WHERE id=?");
        $s->bind_param("i", $userId); $s->execute();
    }

    $_SESSION['success_message'] = "Student action applied.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   ENROLLMENT ACTIONS
=== */
if (isset($_POST['enroll_action'])) {
    $enroll_id = (int)$_POST['enroll_id'];
    $action    = $_POST['action'];

    $check = $conn->prepare("
        SELECT e.id FROM enrollments e
        JOIN course_teachers ct ON ct.course_id = e.course_id
        WHERE e.id=? AND ct.teacher_id=?
    ");
    $check->bind_param("ii", $enroll_id, $teacher_id);
    $check->execute();
    // FIX: == not =
    if ($check->get_result()->num_rows == 0) {
        die("Unauthorized enrollment action");
    }

    // FIX: == comparisons
    if ($action == "approve") {
        $s = $conn->prepare("UPDATE enrollments SET status='approved' WHERE id=?");
        $s->bind_param("i", $enroll_id); $s->execute();
    } elseif ($action == "reject") {
        $s = $conn->prepare("UPDATE enrollments SET status='rejected' WHERE id=?");
        $s->bind_param("i", $enroll_id); $s->execute();
    } elseif ($action == "delete") {
        $s = $conn->prepare("DELETE FROM enrollments WHERE id=?");
        $s->bind_param("i", $enroll_id); $s->execute();
    }

    $_SESSION['success_message'] = "Enrollment updated.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   ADD ANNOUNCEMENT
=== */
if (isset($_POST['add_announcement'])) {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
        die("Unauthorized.");
    }

    $session_user_id = (int)$_SESSION['user_id'];

    $course_id = (int)$_POST['course_id'];
    $title     = trim($_POST['title']);
    $message   = trim($_POST['message']);

    if ($course_id <= 0 || empty($title) || empty($message)) die("Invalid input");

    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $session_user_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) die("Unauthorized course access");

    $teacherLookup = $conn->prepare("SELECT id FROM teachers WHERE user_id=? LIMIT 1");
    $teacherLookup->bind_param("i", $session_user_id);
    $teacherLookup->execute();
    $teacherRow = $teacherLookup->get_result()->fetch_assoc();
    $teacherLookup->close();

    if (!$teacherRow) {
        die("Teacher profile not found.");
    }

    $teacher_profile_id = (int)$teacherRow['id'];

    $stmt = $conn->prepare("INSERT INTO announcements (course_id, teacher_id, title, message) VALUES (?,?,?,?)");
    $stmt->bind_param("iiss", $course_id, $teacher_profile_id, $title, $message);
    $stmt->execute();
    $_SESSION['success_message'] = "Announcement posted.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE ANNOUNCEMENT
=== */
if (isset($_GET['delete_announcement'])) {
    $id = (int)$_GET['delete_announcement'];
    $stmt = $conn->prepare("
        DELETE a FROM announcements a
        INNER JOIN course_teachers ct ON ct.course_id = a.course_id
        WHERE a.id=? AND ct.teacher_id=?
    ");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $_SESSION['success_message'] = "Announcement deleted.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   UPDATE PROFILE
=== */
if (isset($_POST['update_profile'])) {
    $specialization  = trim($_POST['specialization'] ?? '');
    $qualification   = trim($_POST['qualification'] ?? '');
    $experience_years = (int)($_POST['experience_years'] ?? 0);

    if ($experience_years < 0 || $experience_years > 60) die("Invalid experience value");

    $stmt = $conn->prepare("
        UPDATE teachers SET specialization=?, qualification=?, experience_years=?
        WHERE user_id=?
    ");
    $stmt->bind_param("ssii", $specialization, $qualification, $experience_years, $user_id);
    $stmt->execute();
    $_SESSION['success_message'] = "Profile updated.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   HELPER: SAFE COUNT FUNCTION
=== */
function getCount($conn, $sql, $teacher_id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

/* ===
   STATS
=== */
$courses_count  = getCount($conn, "SELECT COUNT(DISTINCT course_id) AS total FROM course_teachers WHERE teacher_id=? AND status='active'", $teacher_id);
$students_count = getCount($conn, "SELECT COUNT(DISTINCT e.user_id) AS total FROM enrollments e INNER JOIN course_teachers ct ON ct.course_id=e.course_id WHERE ct.teacher_id=? AND ct.status='active'", $teacher_id);
$notes_count    = getCount($conn, "SELECT COUNT(*) AS total FROM notes n INNER JOIN course_teachers ct ON ct.course_id=n.course_id WHERE ct.teacher_id=? AND ct.status='active'", $teacher_id);
$videos_count   = getCount($conn, "SELECT COUNT(*) AS total FROM course_contents cc INNER JOIN course_teachers ct ON ct.course_id=cc.course_id WHERE ct.teacher_id=? AND ct.status='active' AND cc.content_type='Video'", $teacher_id);
$quiz_count     = getCount($conn, "SELECT COUNT(*) AS total FROM quizzes q INNER JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=? AND ct.status='active'", $teacher_id);

$total_courses  = getCount($conn, "SELECT COUNT(*) AS total FROM course_teachers WHERE teacher_id=?", $teacher_id);
$total_students = getCount($conn, "SELECT COUNT(DISTINCT e.user_id) AS total FROM enrollments e JOIN course_teachers ct ON ct.course_id=e.course_id WHERE ct.teacher_id=?", $teacher_id);
$total_content  = getCount($conn, "SELECT COUNT(*) AS total FROM course_contents cc JOIN course_teachers ct ON ct.course_id=cc.course_id WHERE ct.teacher_id=?", $teacher_id);
$total_videos   = getCount($conn, "SELECT COUNT(*) AS total FROM course_videos v JOIN course_teachers ct ON ct.course_id=v.course_id WHERE ct.teacher_id=?", $teacher_id);
$total_notes    = getCount($conn, "SELECT COUNT(*) AS total FROM notes n JOIN course_teachers ct ON ct.course_id=n.course_id WHERE ct.teacher_id=?", $teacher_id);
$total_quizzes  = getCount($conn, "SELECT COUNT(*) AS total FROM quizzes q JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=?", $teacher_id);
$total_questions= getCount($conn, "SELECT COUNT(*) AS total FROM quiz_questions qq JOIN quizzes q ON q.id=qq.quiz_id JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=?", $teacher_id);
$total_attempts = getCount($conn, "SELECT COUNT(*) AS total FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=?", $teacher_id);
$passed         = getCount($conn, "SELECT COUNT(*) AS total FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=? AND qa.result='Pass'", $teacher_id);
$failed         = getCount($conn, "SELECT COUNT(*) AS total FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=? AND qa.result='Fail'", $teacher_id);

/* Average score */
$stmt = $conn->prepare("SELECT AVG(qa.score) AS avg_score FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$average_score = $stmt->get_result()->fetch_assoc()['avg_score'] ?? 0;

/* Revenue */
$stmt = $conn->prepare("SELECT COALESCE(SUM(p.amount),0) AS total_revenue FROM payments p JOIN enrollments e ON e.user_id=p.user_id JOIN course_teachers ct ON ct.course_id=e.course_id WHERE ct.teacher_id=? AND p.status='success'");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$revenue = $stmt->get_result()->fetch_assoc()['total_revenue'] ?? 0;

/* ===
   FETCH DATA SETS (all prepared)
=== */

/* All courses for the My Courses grid */
$stmtAllCourses = $conn->prepare("
    SELECT c.id, c.title, c.description, COALESCE(c.price,0) AS price, c.created_at,
        (SELECT COUNT(DISTINCT e.user_id) FROM enrollments e WHERE e.course_id=c.id) AS students,
        (SELECT COUNT(*) FROM course_contents cc WHERE cc.course_id=c.id) AS contents
    FROM courses c
    INNER JOIN course_teachers ct ON ct.course_id=c.id
    WHERE ct.teacher_id=? AND ct.status='active'
    ORDER BY c.created_at DESC
");
$stmtAllCourses->bind_param("i", $teacher_id);
$stmtAllCourses->execute();
$allCourses = $stmtAllCourses->get_result();

/* Courses list for dashboard table */
$stmtTeacherCourses = $conn->prepare("
    SELECT c.id, c.title, c.price, c.status
    FROM courses c
    JOIN course_teachers ct ON ct.course_id=c.id
    WHERE ct.teacher_id=?
    ORDER BY c.created_at DESC
");
$stmtTeacherCourses->bind_param("i", $teacher_id);
$stmtTeacherCourses->execute();
$teacherCourses = $stmtTeacherCourses->get_result();

/* Courses dropdown (reusable) */
$stmtCoursesDrop = $conn->prepare("SELECT c.id, c.title FROM courses c JOIN course_teachers ct ON ct.course_id=c.id WHERE ct.teacher_id=? ORDER BY c.title");
$stmtCoursesDrop->bind_param("i", $teacher_id);
$stmtCoursesDrop->execute();
$coursesDrop = $stmtCoursesDrop->get_result();
$coursesDropData = $coursesDrop->fetch_all(MYSQLI_ASSOC); // store as array so we can reuse

/* Activities */
$stmtActivities = $conn->prepare("SELECT message, created_at FROM activities ORDER BY created_at DESC LIMIT 5");
$stmtActivities->execute();
$activities = $stmtActivities->get_result();

/* Videos */
$stmtVideos = $conn->prepare("
    SELECT v.*, c.title AS course_title FROM course_videos v
    JOIN courses c ON c.id=v.course_id
    JOIN course_teachers ct ON ct.course_id=c.id
    WHERE ct.teacher_id=?
    ORDER BY v.id DESC
");
$stmtVideos->bind_param("i", $teacher_id);
$stmtVideos->execute();
$videos = $stmtVideos->get_result();

/* Notes */
$stmtNotes = $conn->prepare("
    SELECT n.*, c.title AS course_title FROM notes n
    JOIN courses c ON c.id=n.course_id
    JOIN course_teachers ct ON ct.course_id=c.id
    WHERE ct.teacher_id=?
    ORDER BY n.id DESC
");
$stmtNotes->bind_param("i", $teacher_id);
$stmtNotes->execute();
$notes = $stmtNotes->get_result();

/* Students */
$stmtStudents = $conn->prepare("
    SELECT DISTINCT s.* FROM students s
    JOIN enrollments e ON e.user_id=s.user_id
    JOIN course_teachers ct ON ct.course_id=e.course_id
    WHERE ct.teacher_id=?
    ORDER BY s.id DESC
");
$stmtStudents->bind_param("i", $teacher_id);
$stmtStudents->execute();
$students = $stmtStudents->get_result();

/* Enrollments */
$stmtEnrollments = $conn->prepare("
    SELECT e.*, u.full_name, c.title FROM enrollments e
    JOIN users u ON u.id=e.user_id
    JOIN courses c ON c.id=e.course_id
    JOIN course_teachers ct ON ct.course_id=c.id
    WHERE ct.teacher_id=?
    ORDER BY e.id DESC
");
$stmtEnrollments->bind_param("i", $teacher_id);
$stmtEnrollments->execute();
$enrollments = $stmtEnrollments->get_result();

/* Top courses */
$stmtTop = $conn->prepare("
    SELECT c.title, COUNT(e.id) AS students FROM courses c
    JOIN course_teachers ct ON ct.course_id=c.id
    LEFT JOIN enrollments e ON e.course_id=c.id
    WHERE ct.teacher_id=?
    GROUP BY c.id, c.title
    ORDER BY students DESC LIMIT 5
");
$stmtTop->bind_param("i", $teacher_id);
$stmtTop->execute();
$top_courses = $stmtTop->get_result();

/* Quizzes list */
$stmtQuizList = $conn->prepare("
    SELECT q.*, c.title AS course_name FROM quizzes q
    LEFT JOIN courses c ON c.id=q.course_id
    JOIN course_teachers ct ON ct.course_id=q.course_id
    WHERE ct.teacher_id=?
    ORDER BY q.id DESC
");
$stmtQuizList->bind_param("i", $teacher_id);
$stmtQuizList->execute();
$quizzes = $stmtQuizList->get_result();

/* Quiz questions dropdown */
$stmtQuizDrop = $conn->prepare("
    SELECT q.id, q.title FROM quizzes q
    JOIN course_teachers ct ON ct.course_id=q.course_id
    WHERE ct.teacher_id=?
    ORDER BY q.id DESC
");
$stmtQuizDrop->bind_param("i", $teacher_id);
$stmtQuizDrop->execute();
$quizDrop = $stmtQuizDrop->get_result();

/* Quiz attempts */
$stmtAttempts = $conn->prepare("
    SELECT qa.*, u.full_name, q.title AS quiz_title,
        (SELECT COALESCE(SUM(qq.marks),0) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS total_marks
    FROM quiz_attempts qa
    LEFT JOIN users u ON u.id=qa.user_id
    LEFT JOIN quizzes q ON q.id=qa.quiz_id
    JOIN course_teachers ct ON ct.course_id=q.course_id
    WHERE ct.teacher_id=?
    ORDER BY qa.id DESC
");
$stmtAttempts->bind_param("i", $teacher_id);
$stmtAttempts->execute();
$attempts = $stmtAttempts->get_result();

/* Announcements */
$stmtAnnouncements = $conn->prepare("
    SELECT a.id, a.title, a.message, a.created_at, c.title AS course
    FROM announcements a
    INNER JOIN course_teachers ct ON ct.course_id=a.course_id
    INNER JOIN courses c ON c.id=a.course_id
    WHERE ct.teacher_id=?
    ORDER BY a.created_at DESC
");
$stmtAnnouncements->bind_param("i", $teacher_id);
$stmtAnnouncements->execute();
$announcements = $stmtAnnouncements->get_result();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* == RESET & BASE == */
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f8;display:flex;min-height:100vh;}

/* == SIDEBAR == */
.sidebar{
    width:250px;height:100vh;background:linear-gradient(180deg,#1e3a8a 0%,#1e40af 100%);
    color:#fff;position:fixed;top:0;left:0;overflow-y:auto;z-index:100;
    box-shadow:4px 0 15px rgba(0,0,0,.2);
}
.logo{
    padding:24px 20px;font-size:18px;font-weight:700;
    border-bottom:1px solid rgba(255,255,255,.15);
    display:flex;align-items:center;gap:10px;
    background:rgba(0,0,0,.15);
}
.logo i{font-size:22px;color:#93c5fd;}
.sidebar ul{list-style:none;padding:12px 0;}
.sidebar ul li a{
    display:flex;align-items:center;gap:12px;
    color:rgba(255,255,255,.8);text-decoration:none;
    padding:13px 22px;font-size:14px;font-weight:500;
    transition:all .2s;border-left:3px solid transparent;
}
.sidebar ul li a:hover,
.sidebar ul li a.active{
    background:rgba(255,255,255,.12);color:#fff;
    border-left-color:#93c5fd;
}
.sidebar ul li a i{width:18px;text-align:center;font-size:15px;}

/* == MAIN == */
.main{margin-left:250px;flex:1;padding:28px;min-height:100vh;}

/* == HEADER == */
.header{
    background:#fff;padding:20px 28px;border-radius:14px;
    display:flex;justify-content:space-between;align-items:center;
    box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:24px;
}
.header h2{font-size:22px;color:#1e293b;margin-bottom:4px;}
.header p{color:#64748b;font-size:14px;}
.header-right{
    background:#eff6ff;border-radius:10px;padding:12px 18px;
    text-align:right;font-size:13px;color:#1e40af;
}
.header-right strong{display:block;font-size:15px;color:#1e293b;}

/* == ALERT == */
.alert{
    padding:12px 18px;border-radius:10px;margin-bottom:18px;
    font-size:14px;font-weight:500;
}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.alert-danger {background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

/* == STAT CARDS == */
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;}
.card{
    background:#fff;padding:20px 18px;border-radius:14px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:6px;
}
.card .icon{
    width:44px;height:44px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;font-size:20px;
}
.card .icon.blue{background:#dbeafe;color:#2563eb;}
.card .icon.green{background:#dcfce7;color:#16a34a;}
.card .icon.yellow{background:#fef9c3;color:#ca8a04;}
.card .icon.red{background:#fee2e2;color:#dc2626;}
.card .icon.purple{background:#ede9fe;color:#7c3aed;}
.card .icon.orange{background:#ffedd5;color:#ea580c;}
.card h2{font-size:26px;font-weight:700;color:#1e293b;}
.card p{font-size:13px;color:#64748b;}

/* == SECTION BOX == */
.section{
    background:#fff;padding:24px;border-radius:14px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:24px;
}
.section h3{font-size:16px;color:#1e293b;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;}

/* == TABLE == */
table{width:100%;border-collapse:collapse;}
th,td{padding:12px 14px;text-align:left;font-size:13px;}
th{background:#1e40af;color:#fff;font-weight:600;}
tr:nth-child(even){background:#f8fafc;}
tr:hover{background:#eff6ff;}
td{border-bottom:1px solid #e2e8f0;color:#374151;}

/* == FORM ELEMENTS == */
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;}
.form-group input,
.form-group select,
.form-group textarea{
    width:100%;padding:10px 13px;border:1px solid #cbd5e1;
    border-radius:8px;font-size:14px;color:#1e293b;
    outline:none;transition:.2s;background:#fff;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}

/* == BUTTONS == */
.btn{
    padding:9px 16px;border:none;border-radius:8px;
    font-size:13px;font-weight:600;cursor:pointer;
    display:inline-flex;align-items:center;gap:6px;
    text-decoration:none;transition:.2s;
}
.btn:hover{opacity:.88;transform:translateY(-1px);}
.btn-primary{background:#2563eb;color:#fff;}
.btn-success{background:#16a34a;color:#fff;}
.btn-warning{background:#f59e0b;color:#fff;}
.btn-danger {background:#dc2626;color:#fff;}
.btn-info   {background:#0891b2;color:#fff;}
.btn-sm{padding:6px 11px;font-size:12px;}

/* == BADGE == */
.badge{
    display:inline-block;padding:4px 10px;border-radius:20px;
    font-size:11px;font-weight:700;letter-spacing:.3px;
}
.badge-active   {background:#dcfce7;color:#166534;}
.badge-suspended{background:#fee2e2;color:#991b1b;}
.badge-inactive {background:#e5e7eb;color:#374151;}
.badge-pending  {background:#fef3c7;color:#92400e;}
.badge-approved {background:#dcfce7;color:#166534;}
.badge-rejected {background:#fee2e2;color:#991b1b;}
.badge-free     {background:#dbeafe;color:#1e40af;}
.badge-paid     {background:#fce7f3;color:#9d174d;}
.badge-pass     {background:#dcfce7;color:#166534;}
.badge-fail     {background:#fee2e2;color:#991b1b;}

/* == GRID == */
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;}

/* == COURSE CARD == */
.course-card{
    background:#fff;border-radius:14px;padding:20px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);border-top:4px solid #2563eb;
}
.course-card h3{font-size:16px;color:#1e293b;margin-bottom:10px;}
.course-card p{font-size:13px;color:#64748b;margin-bottom:6px;}
.course-card .meta{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0;}
.course-card .meta span{font-size:12px;color:#64748b;display:flex;align-items:center;gap:4px;}
.course-card .actions{display:flex;gap:8px;flex-wrap:wrap;padding-top:12px;border-top:1px solid #e2e8f0;}

/* == ACTIVITY == */
.activity-item{
    display:flex;justify-content:space-between;align-items:center;
    padding:12px 0;border-bottom:1px solid #f1f5f9;font-size:13px;
}
.activity-item:last-child{border-bottom:none;}
.activity-item .msg{color:#374151;}
.activity-item .time{color:#94a3b8;font-size:12px;white-space:nowrap;margin-left:10px;}

/* == MODAL == */
.modal{
    display:none;position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,.55);z-index:9999;justify-content:center;align-items:center;
}
.modal.open{display:flex;}
.modal-box{
    background:#fff;width:480px;max-width:95%;border-radius:16px;
    padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;
}
.modal-box h3{font-size:18px;color:#1e293b;margin-bottom:18px;}
.modal-close{
    float:right;background:none;border:none;font-size:20px;
    cursor:pointer;color:#94a3b8;margin-top:-5px;
}

/* == STAT HIGHLIGHT == */
.stat-highlight{
    background:linear-gradient(135deg,#1e40af,#3b82f6);
    color:#fff;border-radius:14px;padding:20px;text-align:center;
}
.stat-highlight h2{font-size:28px;font-weight:700;}
.stat-highlight p{font-size:13px;opacity:.85;margin-top:4px;}

/* == PAGE TITLE == */
.page-title{font-size:20px;font-weight:700;color:#1e293b;margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.page-title i{color:#2563eb;}

/* == RESPONSIVE == */
@media(max-width:768px){
    .sidebar{width:200px;}
    .main{margin-left:200px;padding:16px;}
}
</style>
</head>
<body>

<!-- == SIDEBAR == -->
<div class="sidebar">
    <div class="logo"><i class="fas fa-graduation-cap"></i> LMS Teacher</div>
    <ul>
        <li><a href="#" onclick="showSection('dashboard',this)"><i class="fas fa-home"></i> Dashboard</a></li>
        <li><a href="#" onclick="showSection('courses',this)"><i class="fas fa-book"></i> My Courses</a></li>
        <li><a href="#" onclick="showSection('notes',this)"><i class="fas fa-file-pdf"></i> Notes</a></li>
        <li><a href="#" onclick="showSection('videos',this)"><i class="fas fa-video"></i> Videos</a></li>
        <li><a href="#" onclick="showSection('quiz',this)"><i class="fas fa-question-circle"></i> Quizzes</a></li>
        <li><a href="#" onclick="showSection('students',this)"><i class="fas fa-users"></i> Students</a></li>
        <li><a href="#" onclick="showSection('reports',this)"><i class="fas fa-chart-line"></i> Reports</a></li>
        <li><a href="#" onclick="showSection('announcements',this)"><i class="fas fa-bullhorn"></i> Announcements</a></li>
        <li><a href="#" onclick="showSection('profile',this)"><i class="fas fa-user"></i> Profile</a></li>
        <li><a href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>

<!-- == MAIN == -->
<div class="main">

<?php
/* Flash messages */
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($_SESSION['error_message']) . '</div>';
    unset($_SESSION['error_message']);
}
?>

<!-- ====
     DASHBOARD
==== -->
<div id="sec-dashboard" class="section-page" style="display:none;">

    <div class="header">
        <div>
            <h2>Welcome back, <?= htmlspecialchars($teacherInfo['full_name'] ?? 'Teacher') ?> 👋</h2>
            <p>Here's what's happening with your courses today.</p>
        </div>
        <div class="header-right">
            <strong><?= htmlspecialchars($teacherInfo['email'] ?? '') ?></strong>
            Teacher Account
        </div>
    </div>

    <div class="cards">
        <div class="card"><div class="icon blue"><i class="fas fa-book"></i></div><h2><?= $courses_count ?></h2><p>My Courses</p></div>
        <div class="card"><div class="icon green"><i class="fas fa-users"></i></div><h2><?= $students_count ?></h2><p>Students</p></div>
        <div class="card"><div class="icon yellow"><i class="fas fa-file-pdf"></i></div><h2><?= $notes_count ?></h2><p>Notes</p></div>
        <div class="card"><div class="icon red"><i class="fas fa-video"></i></div><h2><?= $videos_count ?></h2><p>Videos</p></div>
        <div class="card"><div class="icon purple"><i class="fas fa-question-circle"></i></div><h2><?= $quiz_count ?></h2><p>Quizzes</p></div>
    </div>

    <div class="section">
        <h3><i class="fas fa-book"></i> My Courses Overview</h3>
        <table>
            <tr><th>Course</th><th>Price (KES)</th><th>Students</th><th>Status</th></tr>
            <?php while($row = $teacherCourses->fetch_assoc()):
                $cid = $row['id'];
                $stmtE = $conn->prepare("SELECT COUNT(*) AS total FROM enrollments WHERE course_id=?");
                $stmtE->bind_param("i", $cid); $stmtE->execute();
                $enrolled = $stmtE->get_result()->fetch_assoc()['total'];
            ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= number_format($row['price'] ?? 0) ?></td>
                <td><strong><?= $enrolled ?></strong></td>
                <td><span class="badge badge-<?= strtolower($row['status'] ?? 'inactive') ?>"><?= htmlspecialchars($row['status'] ?? 'Inactive') ?></span></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

    <div class="section">
        <h3><i class="fas fa-clock"></i> Recent Activities</h3>
        <?php while($a = $activities->fetch_assoc()): ?>
        <div class="activity-item">
            <span class="msg"><i class="fas fa-circle" style="font-size:8px;color:#2563eb;margin-right:8px;"></i><?= htmlspecialchars($a['message']) ?></span>
            <span class="time"><?= $a['created_at'] ?></span>
        </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- ====
     MY COURSES
==== -->
<div id="sec-courses" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-book"></i> My Courses</p>
    <div class="grid-2">
    <?php if ($allCourses->num_rows > 0): ?>
        <?php while($row = $allCourses->fetch_assoc()):
            $id = (int)$row['id'];
            $title = htmlspecialchars($row['title'] ?? '');
            $desc  = htmlspecialchars($row['description'] ?? '');
            $price = (float)($row['price'] ?? 0);
        ?>
        <div class="course-card">
            <h3><?= $title ?></h3>
            <?php if (!empty($desc)): ?><p><?= $desc ?></p><?php endif; ?>
            <div class="meta">
                <span><i class="fas fa-users"></i> <?= (int)($row['students'] ?? 0) ?> students</span>
                <span><i class="fas fa-folder"></i> <?= (int)($row['contents'] ?? 0) ?> items</span>
                <span><i class="fas fa-tag"></i> KES <?= number_format($price,2) ?></span>
            </div>
            <div class="actions">
                <button class="btn btn-warning btn-sm" onclick="editCourse(<?= $id ?>,`<?= addslashes($row['title'] ?? '') ?>`,`<?= addslashes($row['description'] ?? '') ?>`,<?= $price ?>)">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <a class="btn btn-danger btn-sm" href="?delete_course=<?= $id ?>" onclick="return confirm('Delete this course?')">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="section"><p style="color:#64748b;">No courses assigned to you yet.</p></div>
    <?php endif; ?>
    </div>
</div>

<!-- ====
     VIDEOS
==== -->
<div id="sec-videos" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-video"></i> Video Management</p>

    <div class="section">
        <h3>Upload New Video</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Course</label>
                <select name="course_id" required>
                    <option value="">-- Select Course --</option>
                    <?php foreach($coursesDropData as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Video Title</label><input name="title" placeholder="Enter title" required></div>
            <div class="form-group"><label>Video File</label><input type="file" name="video" accept="video/*" required></div>
            <div class="form-group">
                <label>Access Type</label>
                <select name="access_type">
                    <option value="free">Free</option>
                    <option value="paid">Paid</option>
                </select>
            </div>
            <button type="submit" name="upload_video" class="btn btn-primary"><i class="fas fa-upload"></i> Upload Video</button>
        </form>
    </div>

    <div class="section">
        <h3>Uploaded Videos</h3>
        <table>
            <tr><th>Course</th><th>Title</th><th>Access</th><th>Actions</th></tr>
            <?php while($v = $videos->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($v['course_title']) ?></td>
                <td><?= htmlspecialchars($v['title']) ?></td>
                <td><span class="badge badge-<?= $v['access_type'] ?>"><?= ucfirst($v['access_type']) ?></span></td>
                <td>
                    <a class="btn btn-success btn-sm" href="?view_video=<?= $v['id'] ?>" target="_blank"><i class="fas fa-play"></i> Watch</a>
                    <a class="btn btn-danger btn-sm" href="?delete_video=<?= $v['id'] ?>" onclick="return confirm('Delete this video?')"><i class="fas fa-trash"></i> Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ====
     NOTES
==== -->
<div id="sec-notes" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-file-pdf"></i> Notes Management</p>

    <div class="section">
        <h3>Create Note</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="note_token" value="<?= $_SESSION['note_token'] ?>">
            <div class="form-group">
                <label>Course</label>
                <select name="course_id" required>
                    <option value="">-- Select Course --</option>
                    <?php foreach($coursesDropData as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Title</label><input name="title" placeholder="Note title" required></div>
            <div class="form-group"><label>Content</label><textarea name="content" rows="4" placeholder="Write note content..."></textarea></div>
            <div class="form-group"><label>Attachment (optional)</label><input type="file" name="file"></div>
            <div class="form-group">
                <label>Access Type</label>
                <select name="access_type">
                    <option value="free">Free</option>
                    <option value="paid">Paid</option>
                </select>
            </div>
            <button type="submit" name="add_note" class="btn btn-primary"><i class="fas fa-plus"></i> Add Note</button>
        </form>
    </div>

    <div class="section">
        <h3>All Notes</h3>
        <table>
            <tr><th>Course</th><th>Title</th><th>Access</th><th>File</th><th>Action</th></tr>
            <?php while($n = $notes->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($n['course_title']) ?></td>
                <td><?= htmlspecialchars($n['title']) ?></td>
                <td><span class="badge badge-<?= $n['access_type'] ?>"><?= ucfirst($n['access_type']) ?></span></td>
                <td><?= !empty($n['file_path']) ? '<span style="color:#16a34a;"><i class="fas fa-paperclip"></i> Attached</span>' : 'Text only' ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this note?');" style="display:inline;">
                        <input type="hidden" name="delete_note" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ====
     QUIZZES
==== -->
<div id="sec-quiz" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-question-circle"></i> Quiz Management</p>

    <div class="cards">
        <div class="card"><div class="icon blue"><i class="fas fa-list"></i></div><h2><?= $total_quizzes ?></h2><p>Total Quizzes</p></div>
        <div class="card"><div class="icon purple"><i class="fas fa-question"></i></div><h2><?= $total_questions ?></h2><p>Questions</p></div>
        <div class="card"><div class="icon yellow"><i class="fas fa-pen"></i></div><h2><?= $total_attempts ?></h2><p>Attempts</p></div>
        <div class="card"><div class="icon green"><i class="fas fa-check"></i></div><h2><?= $passed ?></h2><p>Passed</p></div>
        <div class="card"><div class="icon red"><i class="fas fa-times"></i></div><h2><?= $failed ?></h2><p>Failed</p></div>
        <div class="card"><div class="icon orange"><i class="fas fa-chart-bar"></i></div><h2><?= round($average_score,1) ?>%</h2><p>Avg Score</p></div>
    </div>

    <div class="grid-2">
        <div class="section">
            <h3>Create Quiz</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Course</label>
                    <select name="course_id" required>
                        <option value="">-- Select Course --</option>
                        <?php foreach($coursesDropData as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Quiz Title</label><input type="text" name="title" placeholder="Quiz title" required></div>
                <div class="form-group"><label>Description</label><textarea name="description" rows="3" placeholder="Description..."></textarea></div>
                <div class="form-group"><label>Passing Marks (%)</label><input type="number" name="passing_marks" placeholder="e.g. 60" required></div>
                <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration" placeholder="e.g. 30" required></div>
                <button type="submit" name="create_quiz" class="btn btn-primary"><i class="fas fa-plus"></i> Create Quiz</button>
            </form>
        </div>

        <div class="section">
            <h3>Add Question</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Quiz</label>
                    <select name="quiz_id" required>
                        <option value="">-- Select Quiz --</option>
                        <?php while($q = $quizDrop->fetch_assoc()): ?>
                            <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['title']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group"><label>Question</label><textarea name="question" rows="3" placeholder="Enter question" required></textarea></div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="question_type">
                        <option value="mcq">Multiple Choice</option>
                        <option value="short_answer">Short Answer</option>
                    </select>
                </div>
                <div class="form-group"><label>Option A</label><input type="text" name="option_a" placeholder="Option A"></div>
                <div class="form-group"><label>Option B</label><input type="text" name="option_b" placeholder="Option B"></div>
                <div class="form-group"><label>Option C</label><input type="text" name="option_c" placeholder="Option C"></div>
                <div class="form-group"><label>Option D</label><input type="text" name="option_d" placeholder="Option D"></div>
                <div class="form-group"><label>Correct Answer</label><input type="text" name="correct_answer" placeholder="e.g. A or full answer" required></div>
                <div class="form-group"><label>Marks</label><input type="number" name="marks" value="1"></div>
                <button type="submit" name="add_question" class="btn btn-primary"><i class="fas fa-plus"></i> Add Question</button>
            </form>
        </div>
    </div>

    <div class="section">
        <h3>Quiz List</h3>
        <table>
            <tr><th>ID</th><th>Course</th><th>Quiz Title</th><th>Passing %</th><th>Duration</th><th>Status</th></tr>
            <?php while($row = $quizzes->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['course_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= $row['passing_score'] ?? 0 ?>%</td>
                <td><?= $row['duration'] ?> mins</td>
                <td><span class="badge badge-<?= strtolower($row['status'] ?? 'inactive') ?>"><?= ucfirst($row['status'] ?? 'Active') ?></span></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

    <div class="section">
        <h3>Student Quiz Attempts</h3>
        <table>
            <tr><th>Student</th><th>Quiz</th><th>Score</th><th>Percentage</th><th>Result</th><th>Date</th></tr>
            <?php while($attempt = $attempts->fetch_assoc()):
                $pct = ($attempt['total_marks'] > 0) ? round(($attempt['score'] / $attempt['total_marks']) * 100, 1) : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($attempt['full_name'] ?? 'Unknown') ?></td>
                <td><?= htmlspecialchars($attempt['quiz_title'] ?? 'Unknown') ?></td>
                <td><?= (int)$attempt['score'] ?></td>
                <td><?= $pct ?>%</td>
                <td><span class="badge badge-<?= strtolower($attempt['result'] ?? 'fail') ?>"><?= htmlspecialchars($attempt['result'] ?? 'Fail') ?></span></td>
                <td><?= !empty($attempt['finished_at']) ? $attempt['finished_at'] : $attempt['started_at'] ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ====
     STUDENTS
==== -->
<div id="sec-students" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-users"></i> Student Management</p>

    <div class="section">
        <h3>My Students</h3>
        <table>
            <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Joined</th><th>Action</th></tr>
            <?php while($s = $students->fetch_assoc()): ?>
            <tr>
                <td><?= $s['id'] ?></td>
                <td><?= htmlspecialchars($s['full_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($s['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($s['phone'] ?? '') ?></td>
                <td><span class="badge badge-<?= strtolower($s['status'] ?? 'inactive') ?>"><?= ucfirst($s['status'] ?? '') ?></span></td>
                <td><?= $s['created_at'] ?></td>
                <td>
                    <form method="POST" style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                        <select name="action" style="padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;">
                            <option value="activate">Activate</option>
                            <option value="suspend">Suspend</option>
                            <option value="reset_password">Reset Password</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" name="student_action" class="btn btn-primary btn-sm">Apply</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

    <div class="section">
        <h3>Enrollments</h3>
        <table>
            <tr><th>ID</th><th>Student</th><th>Course</th><th>Status</th><th>Progress</th><th>Enrolled</th><th>Actions</th></tr>
            <?php while($e = $enrollments->fetch_assoc()): ?>
            <tr>
                <td><?= $e['id'] ?></td>
                <td><?= htmlspecialchars($e['full_name']) ?></td>
                <td><?= htmlspecialchars($e['title']) ?></td>
                <td><span class="badge badge-<?= strtolower($e['status'] ?? '') ?>"><?= ucfirst($e['status'] ?? '') ?></span></td>
                <td><?= $e['progress'] ?? 0 ?>%</td>
                <td><?= $e['enrolled_at'] ?></td>
                <td>
                    <form method="POST" style="display:flex;gap:5px;flex-wrap:wrap;">
                        <input type="hidden" name="enroll_id" value="<?= $e['id'] ?>">
                        <input type="hidden" name="enroll_action" value="1">
                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                        <button type="submit" name="action" value="reject"  class="btn btn-warning btn-sm">Reject</button>
                        <button type="submit" name="action" value="delete"  class="btn btn-danger btn-sm" onclick="return confirm('Delete enrollment?')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ====
     REPORTS
==== -->
<div id="sec-reports" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-chart-line"></i> Reports Dashboard</p>

    <div class="cards">
        <div class="card"><div class="icon blue"><i class="fas fa-book"></i></div><h2><?= $total_courses ?></h2><p>My Courses</p></div>
        <div class="card"><div class="icon green"><i class="fas fa-users"></i></div><h2><?= $total_students ?></h2><p>Total Students</p></div>
        <div class="card"><div class="icon yellow"><i class="fas fa-folder"></i></div><h2><?= $total_content ?></h2><p>Content Items</p></div>
        <div class="card"><div class="icon red"><i class="fas fa-video"></i></div><h2><?= $total_videos ?></h2><p>Videos</p></div>
        <div class="card"><div class="icon purple"><i class="fas fa-file-pdf"></i></div><h2><?= $total_notes ?></h2><p>Notes</p></div>
        <div class="card"><div class="icon orange"><i class="fas fa-coins"></i></div><h2>KES <?= number_format($revenue) ?></h2><p>Revenue</p></div>
    </div>

    <div class="section">
        <h3><i class="fas fa-trophy"></i> Top Performing Courses</h3>
        <table>
            <tr><th>Course</th><th>Enrolled Students</th></tr>
            <?php while($row = $top_courses->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><strong><?= $row['students'] ?></strong></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ====
     ANNOUNCEMENTS
==== -->
<div id="sec-announcements" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-bullhorn"></i> Announcements</p>

    <div class="section">
        <h3>Post Announcement</h3>
        <form method="POST">
            <div class="form-group">
                <label>Course</label>
                <select name="course_id" required>
                    <option value="">-- Select Course --</option>
                    <?php foreach($coursesDropData as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Title</label><input type="text" name="title" placeholder="Announcement title" required></div>
            <div class="form-group"><label>Message</label><textarea name="message" rows="4" placeholder="Write your message..." required></textarea></div>
            <button type="submit" name="add_announcement" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post</button>
        </form>
    </div>

    <div class="section">
        <h3>All Announcements</h3>
        <table>
            <tr><th>Course</th><th>Title</th><th>Message</th><th>Date</th><th>Action</th></tr>
            <?php if($announcements->num_rows > 0): while($a = $announcements->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($a['course']) ?></td>
                <td><?= htmlspecialchars($a['title']) ?></td>
                <td><?= htmlspecialchars($a['message']) ?></td>
                <td><?= $a['created_at'] ?></td>
                <td>
                    <a class="btn btn-danger btn-sm" href="?delete_announcement=<?= $a['id'] ?>" onclick="return confirm('Delete?')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="5" style="text-align:center;color:#64748b;">No announcements yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ====
     PROFILE
==== -->
<div id="sec-profile" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-user"></i> Teacher Profile</p>

    <div class="grid-2">
        <div class="section">
            <h3>Profile Info</h3>
            <p style="margin-bottom:10px;"><strong>Name:</strong> <?= htmlspecialchars($teacherInfo['full_name']) ?></p>
            <p style="margin-bottom:10px;"><strong>Email:</strong> <?= htmlspecialchars($teacherInfo['email']) ?></p>
            <p style="margin-bottom:10px;"><strong>Employee No:</strong> <?= htmlspecialchars($teacherInfo['employee_no'] ?? 'N/A') ?></p>
            <p style="margin-bottom:10px;"><strong>Specialization:</strong> <?= htmlspecialchars($teacherInfo['specialization'] ?? 'N/A') ?></p>
            <p style="margin-bottom:10px;"><strong>Qualification:</strong> <?= htmlspecialchars($teacherInfo['qualification'] ?? 'N/A') ?></p>
            <p><strong>Experience:</strong> <?= $teacherInfo['experience_years'] ?? 0 ?> years</p>
        </div>

        <div class="section">
            <h3>Update Profile</h3>
            <form method="POST">
                <div class="form-group"><label>Specialization</label><input type="text" name="specialization" value="<?= htmlspecialchars($teacherInfo['specialization'] ?? '') ?>" placeholder="e.g. Mathematics"></div>
                <div class="form-group"><label>Qualification</label><input type="text" name="qualification" value="<?= htmlspecialchars($teacherInfo['qualification'] ?? '') ?>" placeholder="e.g. M.Sc"></div>
                <div class="form-group"><label>Experience (years)</label><input type="number" name="experience_years" value="<?= $teacherInfo['experience_years'] ?? 0 ?>" min="0" max="60"></div>
                <button type="submit" name="update_profile" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </form>
        </div>
    </div>

    <div class="cards" style="margin-top:0;">
        <div class="card"><div class="icon blue"><i class="fas fa-book"></i></div><h2><?= $total_courses ?></h2><p>Courses</p></div>
        <div class="card"><div class="icon green"><i class="fas fa-users"></i></div><h2><?= $total_students ?></h2><p>Students</p></div>
        <div class="card"><div class="icon red"><i class="fas fa-video"></i></div><h2><?= $total_videos ?></h2><p>Videos</p></div>
        <div class="card"><div class="icon yellow"><i class="fas fa-file-pdf"></i></div><h2><?= $total_notes ?></h2><p>Notes</p></div>
        <div class="card"><div class="icon purple"><i class="fas fa-question-circle"></i></div><h2><?= $total_quizzes ?></h2><p>Quizzes</p></div>
        <div class="card"><div class="icon orange"><i class="fas fa-coins"></i></div><h2>KES <?= number_format($revenue) ?></h2><p>Revenue</p></div>
    </div>
</div>

</div><!-- /main -->

<!-- ====
     EDIT COURSE MODAL
==== -->
<div class="modal" id="editModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">&#x2715;</button>
        <h3>Edit Course</h3>
        <form method="POST">
            <input type="hidden" name="id" id="modal-id">
            <div class="form-group"><label>Title</label><input type="text" name="title" id="modal-title" required></div>
            <div class="form-group"><label>Description</label><textarea name="description" id="modal-desc" rows="4"></textarea></div>
            <div class="form-group"><label>Price (KES)</label><input type="number" step="0.01" name="price" id="modal-price" required></div>
            <button type="submit" name="update_course" class="btn btn-primary"><i class="fas fa-save"></i> Update Course</button>
        </form>
    </div>
</div>

<script>
/* == SECTION SWITCHER == */
function showSection(id, link) {
    document.querySelectorAll('.section-page').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.sidebar ul li a').forEach(el => el.classList.remove('active'));
    document.getElementById('sec-' + id).style.display = 'block';
    if (link) link.classList.add('active');
}

/* == EDIT COURSE MODAL == */
function editCourse(id, title, desc, price) {
    document.getElementById('modal-id').value    = id;
    document.getElementById('modal-title').value = title;
    document.getElementById('modal-desc').value  = desc;
    document.getElementById('modal-price').value = price;
    document.getElementById('editModal').classList.add('open');
}

function closeModal() {
    document.getElementById('editModal').classList.remove('open');
}

// FIX: == comparison for modal backdrop click
window.addEventListener('click', function(e) {
    const modal = document.getElementById('editModal');
    if (e.target == modal) closeModal();
});

/* == DEFAULT PAGE == */
window.onload = function() {
    showSection('dashboard');
    // Activate first sidebar link
    const firstLink = document.querySelector('.sidebar ul li a');
    if (firstLink) firstLink.classList.add('active');
};
</script>

</body>
</html>