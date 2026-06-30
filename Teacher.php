<?php
/* ===
   SESSION SETUP 
==*/
$session_lifetime = 1800;

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
include "db.php";

/* ===
   AUTH + SESSION EXPIRY CHECK
=== */
function force_login_redirect($conn = null) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php?expired=1");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    force_login_redirect($conn ?? null);
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_lifetime) {
    force_login_redirect($conn ?? null);
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 900) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
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
    force_login_redirect($conn);
}
$teacher_id = $user_id;

/* ===
   GENERATE CSRF TOKENS
=== */
function ensure_token($key) {
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}
ensure_token('note_token');
ensure_token('quiz_token');
ensure_token('course_token');
ensure_token('video_token');
ensure_token('student_token');
ensure_token('enroll_token');
ensure_token('announce_token');
ensure_token('profile_token');

/* ===
   CSRF VALIDATION 
=== */
function require_csrf($postKey, $sessionKey) {
    if (!isset($_POST[$postKey]) || !isset($_SESSION[$sessionKey]) || !hash_equals($_SESSION[$sessionKey], $_POST[$postKey])) {
        force_login_redirect();
    }
}

/* ===
   ROLE CHECK HELPER
=== */
function require_teacher_role() {
    if (($_SESSION['user_role'] ?? '') !== 'teacher') {
        force_login_redirect();
    }
}

/* ===
   UPDATE COURSE
=== */
if (isset($_POST['update_course'])) {
    require_csrf('course_token', 'course_token');

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
    $_SESSION['course_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Course updated successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE COURSE
=== */
if (isset($_GET['delete_course'])) {
    $token = $_GET['course_token'] ?? '';
    if (!hash_equals($_SESSION['course_token'], $token)) {
        force_login_redirect();
    }
    $course_id = (int)$_GET['delete_course'];
    $stmt = $conn->prepare("
        DELETE FROM course_teachers
        WHERE course_id = ? AND teacher_id = ?
    ");
    $stmt->bind_param("ii", $course_id, $teacher_id);
    $stmt->execute();
    $_SESSION['course_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Course removed successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   UPLOAD DIRECTORIES
=== */
$video_dir = "uploads/videos/";
$note_dir  = "uploads/notes/";
if (!file_exists($video_dir)) mkdir($video_dir, 0755, true);
if (!file_exists($note_dir))  mkdir($note_dir,  0755, true);

/* ===
   UPLOAD VIDEO
=== */
if (isset($_POST['upload_video'])) {
    require_csrf('video_token', 'video_token');

    $course_id = (int)$_POST['course_id'];
    $title     = trim($_POST['title']);
    $access    = $_POST['access_type'];
    $file_path = "";

    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("Unauthorized course access");
    }

    if (!empty($_FILES['video']['name']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
        $allowedVideoExt  = ['mp4', 'mov', 'webm', 'mkv'];
        $allowedVideoMime = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];
        $maxVideoSize     = 500 * 1024 * 1024;

        $originalName = basename($_FILES['video']['name']);
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedVideoExt, true)) {
            die("X Video file type not allowed.");
        }
        if ($_FILES['video']['size'] > $maxVideoSize) {
            die("X Video is too large. Max size is 500MB.");
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['video']['tmp_name']);
        if (!in_array($mimeType, $allowedVideoMime, true)) {
            die("X Video content does not match an allowed type.");
        }

        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $target   = $video_dir . $safeName;

        if (move_uploaded_file($_FILES['video']['tmp_name'], $target)) {
            $file_path = $target;
        } else {
            die("X Failed to save uploaded video.");
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO course_videos (course_id, title, video_path, access_type, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssi", $course_id, $title, $file_path, $access, $teacher_id);
    $stmt->execute();
    $_SESSION['video_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Video uploaded successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE VIDEO
=== */
if (isset($_GET['delete_video'])) {
    $token = $_GET['video_token'] ?? '';
    if (!hash_equals($_SESSION['video_token'], $token)) {
        force_login_redirect();
    }
    $id = (int)$_GET['delete_video'];

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
        $stmt = $conn->prepare("
            DELETE v FROM course_videos v
            JOIN course_teachers ct ON ct.course_id = v.course_id
            WHERE v.id = ? AND ct.teacher_id = ?
        ");
        $stmt->bind_param("ii", $id, $teacher_id);
        $stmt->execute();
        $_SESSION['success_message'] = "Video deleted.";
    }
    $_SESSION['video_token'] = bin2hex(random_bytes(32));
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

    $stmt = $conn->prepare("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? LIMIT 1");
    $stmt->bind_param("ii", $user_id, $video['course_id']);
    $stmt->execute();
    $isEnrolled = $stmt->get_result()->num_rows > 0;

    if ($video['access_type'] == 'paid' && !$isEnrolled) {
        die("<h2>Access Denied X</h2><p>You must enroll to watch this video.</p>");
    }
    ?>
    <!DOCTYPE html><html><head><title><?= htmlspecialchars($video['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
    <body style="background:#000;color:#fff;padding:20px;margin:0;">
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
    require_csrf('note_token', 'note_token');
    require_teacher_role();

    $teacher_id = (int)$_SESSION['user_id'];
    $course_id = (int)$_POST['course_id'];

    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("X Unauthorized course access");
    }

    $title   = trim($_POST['title'] ?? '');
    $content  = trim($_POST['content'] ?? '');
    $access_type = $_POST['access_type'] ?? 'free';
    $file_path   = "";
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
        $maxFileSize = 10 * 1024 * 1024;

        $originalName = basename($_FILES['file']['name']);
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            die("X File type not allowed. Allowed types: " . implode(', ', $allowedExt));
        }
        if ($_FILES['file']['size'] > $maxFileSize) {
            die("X File is too large. Max size is 10MB.");
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['file']['tmp_name']);

        if (!in_array($mimeType, $allowedMime, true)) {
            die("X File content does not match an allowed type.");
        }

        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $target   = rtrim($note_dir, '/') . '/' . $safeName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            $file_path = $target;
        } else {
            die("X Failed to save uploaded file.");
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
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE NOTE
=== */
if (isset($_POST['delete_note'])) {
    require_csrf('note_token', 'note_token');

    $id = (int)$_POST['delete_note'];
    $stmt = $conn->prepare("
        DELETE n FROM notes n
        JOIN course_teachers ct ON ct.course_id = n.course_id
        WHERE n.id = ? AND ct.teacher_id = ?
    ");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $_SESSION['note_token'] = bin2hex(random_bytes(32));
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

    if ($note['access_type'] == 'paid' && !$isEnrolled) {
        die("Access Denied X");
    }
}

/* ===
   CREATE QUIZ
=== */
if (isset($_POST['create_quiz'])) {
    require_csrf('quiz_token', 'quiz_token');
    $course_id = (int)$_POST['course_id'];
    $check = $conn->prepare("SELECT 1 FROM course_teachers WHERE course_id=? AND teacher_id=? LIMIT 1");
    $check->bind_param("ii", $course_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("X Unauthorized course access");
    }
    $passing_marks = (int)$_POST['passing_marks'];
    $duration      = (int)$_POST['duration'];
    $title         = trim($_POST['title']);
    $description   = trim($_POST['description']);

    $stmt = $conn->prepare("
        INSERT INTO quizzes (course_id, title, description, passing_score, duration)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issii", $course_id, $title, $description, $passing_marks, $duration);
    $stmt->execute();
    $_SESSION['quiz_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Quiz created.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE QUIZ
=== */
if (isset($_GET['delete_quiz'])) {
    $token = $_GET['quiz_token'] ?? '';
    if (!hash_equals($_SESSION['quiz_token'], $token)) {
        force_login_redirect();
    }
    $quiz_id = (int)$_GET['delete_quiz'];
    // Verify teacher owns this quiz via course_teachers
    $check = $conn->prepare("
        SELECT 1 FROM quizzes q
        JOIN course_teachers ct ON ct.course_id = q.course_id
        WHERE q.id = ? AND ct.teacher_id = ?
        LIMIT 1
    ");
    $check->bind_param("ii", $quiz_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("X Unauthorized quiz access");
    }
    // attempts → questions → quiz
    $stmt = $conn->prepare("DELETE FROM quiz_attempts WHERE quiz_id = ?");
    $stmt->bind_param("i", $quiz_id); $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
    $stmt->bind_param("i", $quiz_id); $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
    $stmt->bind_param("i", $quiz_id); $stmt->execute();

    $_SESSION['quiz_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Quiz deleted successfully.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   ADD QUESTION
=== */
if (isset($_POST['add_question'])) {
    require_csrf('quiz_token', 'quiz_token');

    $quiz_id = (int)$_POST['quiz_id'];
    $check = $conn->prepare("
        SELECT 1 FROM quizzes q
        JOIN course_teachers ct ON ct.course_id = q.course_id
        WHERE q.id = ? AND ct.teacher_id = ?
        LIMIT 1
    ");
    $check->bind_param("ii", $quiz_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("X Unauthorized quiz access");
    }
    $marks = (int)$_POST['marks'];
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
        $marks
    );
    $stmt->execute();
    $_SESSION['quiz_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Question added.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   BULK IMPORT QUESTIONS
=== */

function parseBulkQuestionText(string $content): array {
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $content = str_replace("\xC2\xA0", " ", $content);
    $lines = explode("\n", $content);
    $lines = array_filter($lines, function ($line) {
        return !preg_match('/^\s*HINT\s*[:\-]/i', $line);
    });
    $content = implode("\n", $lines);
    $blocks = preg_split('/\n\s*\n/', trim($content));
    $rows   = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;
        if (strpos($block, '|') !== false) {
            $pipeLines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== ''));
            $allPipeLines = true;
            foreach ($pipeLines as $pl) {
                if (substr_count($pl, '|') < 5) { $allPipeLines = false; break; }
            }
            if ($allPipeLines && count($pipeLines) > 0) {
                foreach ($pipeLines as $pl) {
                    $parts = array_map('trim', explode('|', $pl));
                    if (count($parts) >= 6) {
                        $rows[] = [
                            'question' => $parts[0],
                            'option_a' => $parts[1],
                            'option_b' => $parts[2],
                            'option_c' => $parts[3],
                            'option_d' => $parts[4],
                            'answer'   => strtoupper(substr(trim($parts[5]), 0, 1)),
                        ];
                    }
                }
                continue;
            }
        }
        $rawLines = array_values(array_filter(
            array_map('trim', explode("\n", $block)),
            fn ($l) => $l !== ''
        ));
        if (count($rawLines) < 5) continue;

        $optionPattern = '/^[A-Da-d][\.\)]\s*/';
        $optionIndices = [];
        foreach ($rawLines as $i => $line) {
            if (preg_match($optionPattern, $line)) {
                $optionIndices[] = $i;
            }
        }
        if (count($optionIndices) < 4) continue;
        $numQuestions = intdiv(count($optionIndices), 4);
        $answerLinePattern = '/(?:Answer|Ans|Correct)\s*[:\-]?\s*([A-Da-d])\b/i';
        $loneLetterPattern  = '/^\s*([A-Da-d])\s*$/';

        for ($q = 0; $q < $numQuestions; $q++) {
            $a = $optionIndices[$q * 4 + 0];
            $b = $optionIndices[$q * 4 + 1];
            $c = $optionIndices[$q * 4 + 2];
            $d = $optionIndices[$q * 4 + 3];
            $prevD   = ($q > 0) ? $optionIndices[$q * 4 - 1] : -1;
            $between = array_slice($rawLines, $prevD + 1, $a - $prevD - 1);
            $questionParts = array_values(array_filter($between, function ($l) use ($answerLinePattern, $loneLetterPattern) {
                return !preg_match($answerLinePattern, $l) && !preg_match($loneLetterPattern, $l);
            }));
            $question = implode(' ', $questionParts);
            $question = preg_replace('/^\d+[\.\)]\s*/', '', trim($question));
            $optA = preg_replace($optionPattern, '', $rawLines[$a]);
            $optB = preg_replace($optionPattern, '', $rawLines[$b]);
            $optC = preg_replace($optionPattern, '', $rawLines[$c]);
            $optD = preg_replace($optionPattern, '', $rawLines[$d]);
            $nextA = ($q + 1 < $numQuestions) ? $optionIndices[($q + 1) * 4 + 0] : count($rawLines);
            $afterOptions = array_slice($rawLines, $d + 1, $nextA - $d - 1);

            $answer = '';
            foreach ($afterOptions as $l) {
                if (preg_match($answerLinePattern, $l, $m)) {
                    $answer = strtoupper($m[1]);
                    break;
                }
                if (preg_match($loneLetterPattern, $l, $m)) {
                    $answer = strtoupper($m[1]);
                    break;
                }
            }

            if ($question === '' || $optA === '' || $optB === '' || $optC === '' || $optD === '') {
                continue;
            }

            $rows[] = [
                'question' => $question,
                'option_a' => $optA,
                'option_b' => $optB,
                'option_c' => $optC,
                'option_d' => $optD,
                'answer'   => $answer,
            ];
        }
    }

    return $rows;
}

function extractTextFromDocx(string $path): ?string {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return null;
    $xml = preg_replace('/<\/w:p>/', "\n\n", $xml);
    $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
    $xml = preg_replace('/<w:tab\/>/', ' ', $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return $text;
}

function extractTextFromPdf(string $path): ?string {
    if (!function_exists('shell_exec')) return null;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) return null;

    $which = @shell_exec('command -v pdftotext 2>/dev/null');
    if (empty(trim((string) $which))) return null;

    $out = $path . '_extracted.txt';
    @shell_exec(
        'pdftotext -layout -nopgbrk '
        . escapeshellarg($path) . ' '
        . escapeshellarg($out)
        . ' 2>/dev/null'
    );

    if (!file_exists($out)) return null;

    $text = file_get_contents($out);
    @unlink($out);
    if ($text === false) return null;

    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return $text;
}

function parseCsvQuestionFile(string $path): array {
    $rows = [];
    if (($handle = fopen($path, 'r')) === false) return $rows;

    $first = true;
    while (($line = fgetcsv($handle)) !== false) {
        if ($first) {
            $first = false;
            $joined = strtolower(implode(',', $line));
            if (strpos($joined, 'question') !== false && strpos($joined, 'answer') !== false) {
                continue;
            }
        }
        if (count($line) < 6) continue;
        $rows[] = [
            'question' => trim($line[0]),
            'option_a' => trim($line[1]),
            'option_b' => trim($line[2]),
            'option_c' => trim($line[3]),
            'option_d' => trim($line[4]),
            'answer'   => strtoupper(substr(trim($line[5]), 0, 1)),
        ];
    }
    fclose($handle);
    return $rows;
}

if (isset($_POST['bulk_import_questions'])) {
    require_csrf('quiz_token', 'quiz_token');

    $quiz_id = (int)($_POST['quiz_id'] ?? 0);

    $check = $conn->prepare("
        SELECT 1 FROM quizzes q
        JOIN course_teachers ct ON ct.course_id = q.course_id
        WHERE q.id = ? AND ct.teacher_id = ?
        LIMIT 1
    ");
    $check->bind_param("ii", $quiz_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("X Unauthorized quiz access");
    }

    $rows       = [];
    $sourceInfo = '';
    $rawText    = trim($_POST['bulk_text'] ?? '');
    $tempFile   = null;

    if (isset($_FILES['bulk_file']) && !empty($_FILES['bulk_file']['name']) && $_FILES['bulk_file']['error'] === UPLOAD_ERR_OK) {

        $allowedExt = ['txt', 'csv', 'docx', 'xlsx', 'pdf'];
        $maxSize    = 8 * 1024 * 1024;

        $originalName = basename($_FILES['bulk_file']['name']);
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            die("X Unsupported file type. Allowed: " . implode(', ', $allowedExt));
        }
        if ($_FILES['bulk_file']['size'] > $maxSize) {
            die("X File too large. Max size is 8MB.");
        }

        $allowedMimeMap = [
            'txt'  => ['text/plain'],
            'csv'  => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'pdf'  => ['application/pdf'],
        ];
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['bulk_file']['tmp_name']);
        if (!in_array($mimeType, $allowedMimeMap[$ext], true)) {
            die("X File content does not match the expected type for ." . $ext);
        }

        $tmp_dir = "uploads/tmp_imports/";
        if (!file_exists($tmp_dir)) mkdir($tmp_dir, 0700, true);
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $tempFile = rtrim($tmp_dir, '/') . '/' . $safeName;

        if (!move_uploaded_file($_FILES['bulk_file']['tmp_name'], $tempFile)) {
            die("X Failed to process the uploaded file.");
        }

        switch ($ext) {
            case 'txt':
                $rawText = file_get_contents($tempFile);
                $sourceInfo = "from text file";
                break;

            case 'csv':
                $rows = parseCsvQuestionFile($tempFile);
                $sourceInfo = "from CSV file";
                break;

            case 'docx':
                $extracted = extractTextFromDocx($tempFile);
                if ($extracted === null) {
                    @unlink($tempFile);
                    die("X Could not read the Word document. Try saving it as .txt and re-uploading.");
                }
                $rawText = $extracted;
                $sourceInfo = "from Word document";
                break;

            case 'xlsx':
                if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                    try {
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempFile);
                        $sheet = $spreadsheet->getActiveSheet();
                        foreach ($sheet->getRowIterator() as $i => $row) {
                            $cells = [];
                            foreach ($row->getCellIterator() as $cell) {
                                $cells[] = trim((string)$cell->getValue());
                            }
                            if ($i === 1 && stripos(implode(',', $cells), 'question') !== false) continue;
                            if (count($cells) >= 6 && trim($cells[0]) !== '') {
                                $rows[] = [
                                    'question' => $cells[0],
                                    'option_a' => $cells[1],
                                    'option_b' => $cells[2],
                                    'option_c' => $cells[3],
                                    'option_d' => $cells[4],
                                    'answer'   => strtoupper(substr(trim($cells[5]), 0, 1)),
                                ];
                            }
                        }
                        $sourceInfo = "from Excel file";
                    } catch (\Throwable $e) {
                        @unlink($tempFile);
                        die("X Could not read the Excel file. Make sure it's a valid .xlsx.");
                    }
                } else {
                    @unlink($tempFile);
                    die("X Excel (.xlsx) import requires the PhpSpreadsheet library on the server (composer require phpoffice/phpspreadsheet). Please use CSV, TXT, or paste instead.");
                }
                break;

            case 'pdf':
                $extracted = extractTextFromPdf($tempFile);
                if ($extracted === null) {
                    @unlink($tempFile);
                    die("X PDF import requires the 'pdftotext' tool on the server. Please use TXT, CSV, or paste instead.");
                }
                $rawText = $extracted;
                $sourceInfo = "from PDF file";
                break;
        }

        @unlink($tempFile);
    }

    if (empty($rows) && $rawText !== '') {
        $rows = parseBulkQuestionText($rawText);
        if ($sourceInfo === '') $sourceInfo = "from pasted text";
    }

    if (empty($rows)) {
        die("X No valid questions were found. Please check the format and try again.");
    }

    $maxQuestions = 500;
    if (count($rows) > $maxQuestions) {
        $rows = array_slice($rows, 0, $maxQuestions);
    }

    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $insert = $conn->prepare("
        INSERT INTO quiz_questions
        (quiz_id, question, question_type, option_a, option_b, option_c, option_d, correct_answer, marks)
        VALUES (?, ?, 'mcq', ?, ?, ?, ?, ?, 1)
    ");

    foreach ($rows as $idx => $r) {
        $question = mb_substr(trim($r['question'] ?? ''), 0, 2000);
        $a        = mb_substr(trim($r['option_a'] ?? ''), 0, 255);
        $b        = mb_substr(trim($r['option_b'] ?? ''), 0, 255);
        $c        = mb_substr(trim($r['option_c'] ?? ''), 0, 255);
        $d        = mb_substr(trim($r['option_d'] ?? ''), 0, 255);
        $answer   = strtoupper(trim($r['answer'] ?? ''));

        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($answer, ['A','B','C','D'], true)) {
            $skipped++;
            $errors[] = "Row " . ($idx + 1) . ": " . ($question !== '' ? $question : '(empty question)');
            continue;
        }

        $insert->bind_param("issssss", $quiz_id, $question, $a, $b, $c, $d, $answer);
        if ($insert->execute()) {
            $imported++;
        } else {
            $skipped++;
            $errors[] = "Row " . ($idx + 1) . ": database error";
        }
    }

    $_SESSION['quiz_token'] = bin2hex(random_bytes(32));

    $msg = "Imported {$imported} question(s) {$sourceInfo}.";
    if ($skipped > 0) {
        $msg .= " {$skipped} row(s) were skipped due to missing/invalid data.";
    }
    $_SESSION['success_message'] = $msg;
    if (!empty($errors)) {
        $_SESSION['error_message'] = "Skipped details: " . htmlspecialchars(implode(' | ', array_slice($errors, 0, 10)));
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   STUDENT ACTIONS
=== */
if (isset($_POST['student_action'])) {
    require_csrf('student_token', 'student_token');
    $student_id = (int)$_POST['student_id'];
    $action     = $_POST['action'];
    $ownCheck = $conn->prepare("
        SELECT 1 FROM students s
        JOIN enrollments e ON e.user_id = s.user_id
        JOIN course_teachers ct ON ct.course_id = e.course_id
        WHERE s.id = ? AND ct.teacher_id = ?
        LIMIT 1
    ");
    $ownCheck->bind_param("ii", $student_id, $teacher_id);
    $ownCheck->execute();
    if ($ownCheck->get_result()->num_rows == 0) {
        die("X Unauthorized student access");
    }

    $stmt = $conn->prepare("SELECT user_id FROM students WHERE id=?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();

    if (!$student) die("Student not found");
    $userId = $student['user_id'];

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
        $newPass = password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
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

    $_SESSION['student_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Student action applied.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   ENROLLMENT ACTIONS
=== */
if (isset($_POST['enroll_action'])) {
    require_csrf('enroll_token', 'enroll_token');

    $enroll_id = (int)$_POST['enroll_id'];
    $action    = $_POST['action'];

    $check = $conn->prepare("
        SELECT e.id FROM enrollments e
        JOIN course_teachers ct ON ct.course_id = e.course_id
        WHERE e.id=? AND ct.teacher_id=?
    ");
    $check->bind_param("ii", $enroll_id, $teacher_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        die("Unauthorized enrollment action");
    }

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

    $_SESSION['enroll_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Enrollment updated.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   ADD ANNOUNCEMENT
=== */
if (isset($_POST['add_announcement'])) {
    require_csrf('announce_token', 'announce_token');
    require_teacher_role();

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
    $_SESSION['announce_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Announcement posted.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   DELETE ANNOUNCEMENT
=== */
if (isset($_GET['delete_announcement'])) {
    $token = $_GET['announce_token'] ?? '';
    if (!hash_equals($_SESSION['announce_token'], $token)) {
        force_login_redirect();
    }
    $id = (int)$_GET['delete_announcement'];
    $stmt = $conn->prepare("
        DELETE a FROM announcements a
        INNER JOIN course_teachers ct ON ct.course_id = a.course_id
        WHERE a.id=? AND ct.teacher_id=?
    ");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $_SESSION['announce_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Announcement deleted.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   UPDATE PROFILE
=== */
if (isset($_POST['update_profile'])) {
    require_csrf('profile_token', 'profile_token');

    $specialization   = trim($_POST['specialization'] ?? '');
    $qualification    = trim($_POST['qualification'] ?? '');
    $experience_years = (int)($_POST['experience_years'] ?? 0);

    if ($experience_years < 0 || $experience_years > 60) die("Invalid experience value");

    $stmt = $conn->prepare("
        UPDATE teachers SET specialization=?, qualification=?, experience_years=?
        WHERE user_id=?
    ");
    $stmt->bind_param("ssii", $specialization, $qualification, $experience_years, $user_id);
    $stmt->execute();
    $_SESSION['profile_token'] = bin2hex(random_bytes(32));
    $_SESSION['success_message'] = "Profile updated.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ===
   SAFE COUNT FUNCTION
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
$videos_count   = getCount($conn, "SELECT COUNT(*) AS total FROM course_videos cv INNER JOIN course_teachers ct ON ct.course_id = cv.course_id WHERE ct.teacher_id = ? AND ct.status = 'active'", $teacher_id);
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

$stmt = $conn->prepare("SELECT AVG(qa.score) AS avg_score FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN course_teachers ct ON ct.course_id=q.course_id WHERE ct.teacher_id=?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$average_score = $stmt->get_result()->fetch_assoc()['avg_score'] ?? 0;

$stmt = $conn->prepare("SELECT COALESCE(SUM(p.amount),0) AS total_revenue FROM payments p JOIN enrollments e ON e.user_id=p.user_id JOIN course_teachers ct ON ct.course_id=e.course_id WHERE ct.teacher_id=? AND p.status='success'");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$revenue = $stmt->get_result()->fetch_assoc()['total_revenue'] ?? 0;

/* ===
   FETCH DATA SETS
=== */
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

$stmtCoursesDrop = $conn->prepare("SELECT c.id, c.title FROM courses c JOIN course_teachers ct ON ct.course_id=c.id WHERE ct.teacher_id=? ORDER BY c.title");
$stmtCoursesDrop->bind_param("i", $teacher_id);
$stmtCoursesDrop->execute();
$coursesDrop = $stmtCoursesDrop->get_result();
$coursesDropData = $coursesDrop->fetch_all(MYSQLI_ASSOC);

$stmtActivities = $conn->prepare("SELECT message, created_at FROM activities ORDER BY created_at DESC LIMIT 5");
$stmtActivities->execute();
$activities = $stmtActivities->get_result();

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

$stmtQuizDrop = $conn->prepare("
    SELECT q.id, q.title FROM quizzes q
    JOIN course_teachers ct ON ct.course_id=q.course_id
    WHERE ct.teacher_id=?
    ORDER BY q.id DESC
");
$stmtQuizDrop->bind_param("i", $teacher_id);
$stmtQuizDrop->execute();
$quizDrop = $stmtQuizDrop->get_result();
$quizDropData = $quizDrop->fetch_all(MYSQLI_ASSOC);

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

/* ===
   INSTRUCTORS 
=== */
$stmtInstructors = $conn->prepare("
    SELECT
        u2.id              AS teacher_user_id,
        u2.full_name,
        u2.email,
        t2.employee_no,
        t2.specialization,
        t2.qualification,
        t2.experience_years,
        t2.status          AS teacher_status,
        GROUP_CONCAT(DISTINCT c.title ORDER BY c.title SEPARATOR ', ') AS shared_courses,
        COUNT(DISTINCT c.id) AS shared_course_count
    FROM course_teachers ct_mine
    INNER JOIN course_teachers ct2
        ON ct2.course_id = ct_mine.course_id
        AND ct2.teacher_id != ct_mine.teacher_id
    INNER JOIN courses c ON c.id = ct_mine.course_id
    INNER JOIN users u2 ON u2.id = ct2.teacher_id
    LEFT JOIN teachers t2 ON t2.user_id = u2.id
    WHERE ct_mine.teacher_id = ?
      AND ct_mine.status = 'active'
      AND ct2.status = 'active'
    GROUP BY u2.id, u2.full_name, u2.email, t2.employee_no, t2.specialization, t2.qualification, t2.experience_years, t2.status
    ORDER BY u2.full_name
");
$stmtInstructors->bind_param("i", $teacher_id);
$stmtInstructors->execute();
$instructors = $stmtInstructors->get_result();
$instructors_count = $instructors->num_rows;

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

/* == TOPBAR (mobile only) == */
.topbar{
    display:none;
    position:fixed;top:0;left:0;width:100%;height:56px;
    background:#1e3a8a;color:#fff;align-items:center;
    padding:0 16px;z-index:1100;box-shadow:0 2px 8px rgba(0,0,0,.2);
}
.topbar .menu-btn{
    background:none;border:none;color:#fff;font-size:22px;cursor:pointer;
    padding:8px;margin-right:10px;
}
.topbar .topbar-title{font-size:16px;font-weight:700;}

/* == SIDEBAR == */
.sidebar{
    width:250px;height:100vh;background:linear-gradient(180deg,#1e3a8a 0%,#1e40af 100%);
    color:#fff;position:fixed;top:0;left:0;overflow-y:auto;z-index:1050;
    box-shadow:4px 0 15px rgba(0,0,0,.2);
    transition:transform .3s ease;
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

/* == SIDEBAR OVERLAY  == */
.sidebar-overlay{
    display:none;position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,.5);z-index:1040;
}
.sidebar-overlay.open{display:block;}

/* == MAIN == */
.main{margin-left:250px;flex:1;padding:28px;min-height:100vh;max-width:100%;overflow-x:hidden;}

/* == HEADER == */
.header{
    background:#fff;padding:20px 28px;border-radius:14px;
    display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;
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
    overflow-x:auto;
}
.section h3{font-size:16px;color:#1e293b;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;}

/* == TABLE == */
table{width:100%;border-collapse:collapse;min-width:560px;}
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
    padding:12px 0;border-bottom:1px solid #f1f5f9;font-size:13px;flex-wrap:wrap;gap:6px;
}
.activity-item:last-child{border-bottom:none;}
.activity-item .msg{color:#374151;}
.activity-item .time{color:#94a3b8;font-size:12px;white-space:nowrap;margin-left:10px;}

/* == MODAL == */
.modal{
    display:none;position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,.55);z-index:9999;justify-content:center;align-items:center;
    padding:16px;
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

/* == IMPORT TABS == */
.import-tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;border-bottom:1px solid #e2e8f0;padding-bottom:10px;}
.tab-btn{
    background:#f1f5f9;border:none;padding:9px 16px;border-radius:8px 8px 0 0;
    font-size:13px;font-weight:600;color:#64748b;cursor:pointer;
    display:inline-flex;align-items:center;gap:6px;transition:.2s;
}
.tab-btn:hover{background:#e2e8f0;}
.tab-btn.active{background:#2563eb;color:#fff;}
.qtab-panel textarea[name="bulk_text"]{font-family:monospace;font-size:12.5px;}

/* == FORMAT HINT BOX == */
.format-hint{
    background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;
    padding:12px 14px;margin-bottom:14px;font-size:12px;color:#0369a1;
    line-height:1.7;
}
.format-hint strong{display:block;margin-bottom:4px;font-size:13px;color:#0c4a6e;}
.format-hint code{
    background:#e0f2fe;border-radius:4px;padding:1px 4px;
    font-family:monospace;font-size:12px;color:#075985;
}

/* == PAGE TITLE == */
.page-title{font-size:20px;font-weight:700;color:#1e293b;margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.page-title i{color:#2563eb;}

/* ========
   RESPONSIVE BREAKPOINTS
======== */
@media (max-width:1024px){
    .sidebar{width:210px;}
    .main{margin-left:210px;padding:20px;}
    .cards{grid-template-columns:repeat(auto-fit,minmax(140px,1fr));}
}
@media (max-width:900px){
    .topbar{display:flex;}
    .sidebar{transform:translateX(-100%);top:0;z-index:1150;}
    .sidebar.open{transform:translateX(0);}
    .main{margin-left:0;padding:16px;padding-top:72px;}
    .header{padding:16px 18px;}
    .header-right{text-align:left;}
}
@media (max-width:600px){
    .cards{grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;}
    .card{padding:16px 14px;}
    .card h2{font-size:21px;}
    .section{padding:16px;}
    .grid-2{grid-template-columns:1fr;}
    .header h2{font-size:18px;}
    .page-title{font-size:17px;}
    table{min-width:480px;}
    .modal-box{padding:20px;}
}
@media (max-width:380px){
    .main{padding:12px;padding-top:68px;}
    .cards{grid-template-columns:1fr 1fr;}
    .card{padding:12px;}
    .topbar .topbar-title{font-size:14px;}
}
</style>
</head>
<body>

<!-- == TOPBAR  == -->
<div class="topbar">
    <button class="menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div class="topbar-title"><i class="fas fa-graduation-cap"></i> LMS Teacher</div>
</div>

<!-- == SIDEBAR OVERLAY == -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- == SIDEBAR == -->
<div class="sidebar" id="sidebar">
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
        <li><a href="#" onclick="showSection('instructors',this)"><i class="fas fa-chalkboard-teacher"></i> Instructors</a></li>
        <li><a href="#" onclick="showSection('profile',this)"><i class="fas fa-user"></i> Profile</a></li>
        <li><a href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>

<!-- == MAIN == -->
<div class="main">

<?php
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($_SESSION['error_message']) . '</div>';
    unset($_SESSION['error_message']);
}
?>

<!-- ==== DASHBOARD ==== -->
<div id="sec-dashboard" class="section-page" style="display:none;">
    <div class="header">
        <div>
            <h2>Welcome back, <?= htmlspecialchars($teacherInfo['full_name'] ?? 'Teacher') ?> </h2>
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
        <div class="card"><div class="icon orange"><i class="fas fa-chalkboard-teacher"></i></div><h2><?= $instructors_count ?></h2><p>Co-Instructors</p></div>
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
            <span class="time"><?= htmlspecialchars($a['created_at']) ?></span>
        </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- ==== MY COURSES ==== -->
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
                <button class="btn btn-warning btn-sm" onclick="editCourse(<?= $id ?>,<?= json_encode($row['title'] ?? '') ?>,<?= json_encode($row['description'] ?? '') ?>,<?= json_encode($price) ?>)">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <a class="btn btn-danger btn-sm" href="?delete_course=<?= $id ?>&course_token=<?= urlencode($_SESSION['course_token']) ?>" onclick="return confirm('Delete this course?')">
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

<!-- ==== VIDEOS ==== -->
<div id="sec-videos" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-video"></i> Video Management</p>

    <div class="section">
        <h3>Upload New Video</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="video_token" value="<?= htmlspecialchars($_SESSION['video_token']) ?>">
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
                <td><span class="badge badge-<?= htmlspecialchars($v['access_type']) ?>"><?= ucfirst(htmlspecialchars($v['access_type'])) ?></span></td>
                <td>
                    <a class="btn btn-success btn-sm" href="?view_video=<?= $v['id'] ?>" target="_blank"><i class="fas fa-play"></i> Watch</a>
                    <a class="btn btn-danger btn-sm" href="?delete_video=<?= $v['id'] ?>&video_token=<?= urlencode($_SESSION['video_token']) ?>" onclick="return confirm('Delete this video?')"><i class="fas fa-trash"></i> Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ==== NOTES ==== -->
<div id="sec-notes" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-file-pdf"></i> Notes Management</p>

    <div class="section">
        <h3>Create Note</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="note_token" value="<?= htmlspecialchars($_SESSION['note_token']) ?>">
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
                <td><span class="badge badge-<?= htmlspecialchars($n['access_type']) ?>"><?= ucfirst(htmlspecialchars($n['access_type'])) ?></span></td>
                <td><?= !empty($n['file_path']) ? '<span style="color:#16a34a;"><i class="fas fa-paperclip"></i> Attached</span>' : 'Text only' ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this note?');" style="display:inline;">
                        <input type="hidden" name="note_token" value="<?= htmlspecialchars($_SESSION['note_token']) ?>">
                        <input type="hidden" name="delete_note" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ==== QUIZZES ==== -->
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
                <input type="hidden" name="quiz_token" value="<?= htmlspecialchars($_SESSION['quiz_token']) ?>">
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
            <h3>Add Questions</h3>

            <div class="import-tabs">
                <button type="button" class="tab-btn active" onclick="showQTab('single', this)"><i class="fas fa-keyboard"></i> Single Question</button>
                <button type="button" class="tab-btn" onclick="showQTab('bulk', this)"><i class="fas fa-file-import"></i> Bulk Import</button>
            </div>

            <!-- SINGLE QUESTION FORM -->
            <div id="qtab-single" class="qtab-panel">
                <form method="POST">
                    <input type="hidden" name="quiz_token" value="<?= htmlspecialchars($_SESSION['quiz_token']) ?>">
                    <div class="form-group">
                        <label>Quiz</label>
                        <select name="quiz_id" required>
                            <option value="">-- Select Quiz --</option>
                            <?php foreach ($quizDropData as $q): ?>
                                <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['title']) ?></option>
                            <?php endforeach; ?>
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

            <!-- BULK IMPORT FORM -->
            <div id="qtab-bulk" class="qtab-panel" style="display:none;">

                <div class="format-hint">
                    <strong> Required Format (paste, .txt, Word, or PDF)</strong>
                    Separate each question with a <strong>blank line</strong>. Each question needs 4 options (A–D) and an <code>Answer: X</code> line. <code>HINT:</code> lines are ignored automatically.<br><br>
                    <strong>Multi-line format:</strong><br>
                    <code>1. What is the capital of France?</code><br>
                    <code>A. Berlin</code><br>
                    <code>B. Madrid</code><br>
                    <code>C. Paris</code><br>
                    <code>D. Rome</code><br>
                    <code>Answer: C</code><br><br>
                    <strong>Pipe format (one line per question):</strong><br>
                    <code>Question text|Opt A|Opt B|Opt C|Opt D|B</code>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="quiz_token" value="<?= htmlspecialchars($_SESSION['quiz_token']) ?>">
                    <div class="form-group">
                        <label>Quiz</label>
                        <select name="quiz_id" required>
                            <option value="">-- Select Quiz --</option>
                            <?php foreach ($quizDropData as $q): ?>
                                <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Paste Questions <span style="font-weight:400;color:#64748b;">(optional if uploading a file)</span></label>
                        <textarea name="bulk_text" rows="10" placeholder="1. What is the capital of France?
                            A. Berlin
                            B. Madrid
                            C. Paris
                            D. Rome
                            Answer: C

                            2. HTML stands for?
                            A. Hyper Text Markup Language
                            B. High Text Machine Language
                            C. Home Tool Markup Language
                            D. Hyperlinks and Text Markup Language
                            Answer: A">
                        </textarea>
                    </div>

                    <div class="form-group">
                        <label>Or Upload File <span style="font-weight:400;color:#64748b;">(.txt, .csv, .docx, .xlsx, .pdf — max 8MB)</span></label>
                        <input type="file" name="bulk_file" accept=".txt,.csv,.docx,.xlsx,.pdf">
                    </div>

                    <button type="submit" name="bulk_import_questions" class="btn btn-primary"><i class="fas fa-file-import"></i> Import Questions</button>
                </form>
            </div>
        </div>
    </div>

    <div class="section">
        <h3>Quiz List</h3>
        <table>
            <tr><th>ID</th><th>Course</th><th>Quiz Title</th><th>Passing %</th><th>Duration</th><th>Status</th><th>Action</th></tr>
            <?php while($row = $quizzes->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['course_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= $row['passing_score'] ?? 0 ?>%</td>
                <td><?= $row['duration'] ?> mins</td>
                <td><span class="badge badge-<?= strtolower(htmlspecialchars($row['status'] ?? 'inactive')) ?>"><?= ucfirst(htmlspecialchars($row['status'] ?? 'Active')) ?></span></td>
                <td>
                    <a class="btn btn-danger btn-sm"
                       href="?delete_quiz=<?= $row['id'] ?>&quiz_token=<?= urlencode($_SESSION['quiz_token']) ?>"
                       onclick="return confirm('Delete this quiz and all its questions and attempts? This cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                </td>
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
                <td><span class="badge badge-<?= strtolower(htmlspecialchars($attempt['result'] ?? 'fail')) ?>"><?= htmlspecialchars($attempt['result'] ?? 'Fail') ?></span></td>
                <td><?= htmlspecialchars(!empty($attempt['finished_at']) ? $attempt['finished_at'] : $attempt['started_at']) ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<!-- ==== STUDENTS ==== -->
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
                <td><span class="badge badge-<?= strtolower(htmlspecialchars($s['status'] ?? 'inactive')) ?>"><?= ucfirst(htmlspecialchars($s['status'] ?? '')) ?></span></td>
                <td><?= htmlspecialchars($s['created_at']) ?></td>
                <td>
                    <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="student_token" value="<?= htmlspecialchars($_SESSION['student_token']) ?>">
                        <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                        <select name="action" style="padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;">
                            <option value="activate">Activate</option>
                            <option value="suspend">Suspend</option>
                            <option value="reset_password">Reset Password</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" name="student_action" class="btn btn-primary btn-sm" onclick="return confirm('Apply this action?')">Apply</button>
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
                <td><span class="badge badge-<?= strtolower(htmlspecialchars($e['status'] ?? '')) ?>"><?= ucfirst(htmlspecialchars($e['status'] ?? '')) ?></span></td>
                <td><?= $e['progress'] ?? 0 ?>%</td>
                <td><?= htmlspecialchars($e['enrolled_at']) ?></td>
                <td>
                    <form method="POST" style="display:flex;gap:5px;flex-wrap:wrap;">
                        <input type="hidden" name="enroll_token" value="<?= htmlspecialchars($_SESSION['enroll_token']) ?>">
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

<!-- ==== REPORTS ==== -->
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

<!-- ==== ANNOUNCEMENTS ==== -->
<div id="sec-announcements" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-bullhorn"></i> Announcements</p>

    <div class="section">
        <h3>Post Announcement</h3>
        <form method="POST">
            <input type="hidden" name="announce_token" value="<?= htmlspecialchars($_SESSION['announce_token']) ?>">
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
                <td><?= htmlspecialchars($a['created_at']) ?></td>
                <td>
                    <a class="btn btn-danger btn-sm" href="?delete_announcement=<?= $a['id'] ?>&announce_token=<?= urlencode($_SESSION['announce_token']) ?>" onclick="return confirm('Delete?')">
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

<!-- ==== INSTRUCTORS ==== -->
<div id="sec-instructors" class="section-page" style="display:none;">
    <p class="page-title"><i class="fas fa-chalkboard-teacher"></i> Instructors</p>
    <div class="section" style="margin-bottom:18px;">
        <p style="color:#64748b;font-size:13px;">Other teachers assigned alongside you on the courses you teach.</p>
    </div>

    <div class="grid-2">
    <?php if ($instructors_count > 0): ?>
        <?php while($ins = $instructors->fetch_assoc()): ?>
        <div class="course-card" style="border-top-color:#7c3aed;">
            <h3><i class="fas fa-chalkboard-teacher" style="color:#7c3aed;margin-right:6px;"></i><?= htmlspecialchars($ins['full_name'] ?? 'Unknown') ?></h3>
            <p><i class="fas fa-envelope" style="width:16px;color:#94a3b8;"></i> <?= htmlspecialchars($ins['email'] ?? '') ?></p>
            <?php if (!empty($ins['employee_no'])): ?>
                <p><i class="fas fa-id-badge" style="width:16px;color:#94a3b8;"></i> <?= htmlspecialchars($ins['employee_no']) ?></p>
            <?php endif; ?>
            <div class="meta">
                <?php if (!empty($ins['specialization'])): ?>
                    <span><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($ins['specialization']) ?></span>
                <?php endif; ?>
                <?php if (!empty($ins['qualification'])): ?>
                    <span><i class="fas fa-award"></i> <?= htmlspecialchars($ins['qualification']) ?></span>
                <?php endif; ?>
                <span><i class="fas fa-briefcase"></i> <?= (int)($ins['experience_years'] ?? 0) ?> yrs experience</span>
            </div>
            <div class="actions" style="border-top:1px solid #e2e8f0;">
                <span class="badge badge-<?= strtolower(htmlspecialchars($ins['teacher_status'] ?? 'active')) ?>">
                    <?= ucfirst(htmlspecialchars($ins['teacher_status'] ?? 'Active')) ?>
                </span>
                <span style="font-size:12px;color:#64748b;display:flex;align-items:center;gap:5px;">
                    <i class="fas fa-book" style="color:#2563eb;"></i>
                    Shared: <?= htmlspecialchars($ins['shared_courses'] ?? '') ?>
                    (<?= (int)($ins['shared_course_count'] ?? 0) ?>)
                </span>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="section"><p style="color:#64748b;">You're not currently sharing any courses with another instructor.</p></div>
    <?php endif; ?>
    </div>
</div>

<!-- ==== PROFILE ==== -->
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
                <input type="hidden" name="profile_token" value="<?= htmlspecialchars($_SESSION['profile_token']) ?>">
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

</div>

<!-- ==== EDIT COURSE MODAL ==== -->
<div class="modal" id="editModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">&#x2715;</button>
        <h3>Edit Course</h3>
        <form method="POST">
            <input type="hidden" name="course_token" value="<?= htmlspecialchars($_SESSION['course_token']) ?>">
            <input type="hidden" name="id" id="modal-id">
            <div class="form-group"><label>Title</label><input type="text" name="title" id="modal-title" required></div>
            <div class="form-group"><label>Description</label><textarea name="description" id="modal-desc" rows="4"></textarea></div>
            <div class="form-group"><label>Price (KES)</label><input type="number" step="0.01" name="price" id="modal-price" required></div>
            <button type="submit" name="update_course" class="btn btn-primary"><i class="fas fa-save"></i> Update Course</button>
        </form>
    </div>
</div>

<script>
function showSection(id, link) {
    document.querySelectorAll('.section-page').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.sidebar ul li a').forEach(el => el.classList.remove('active'));
    document.getElementById('sec-' + id).style.display = 'block';
    if (link) link.classList.add('active');
    closeSidebar();
    window.scrollTo(0,0);
}

function showQTab(tab, btn) {
    document.getElementById('qtab-single').style.display = (tab === 'single') ? 'block' : 'none';
    document.getElementById('qtab-bulk').style.display   = (tab === 'bulk')   ? 'block' : 'none';
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}

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

window.addEventListener('click', function(e) {
    const modal = document.getElementById('editModal');
    if (e.target == modal) closeModal();
});

(function () {
    const SESSION_LIFETIME_MS = <?= $session_lifetime * 1000 ?>;
    setTimeout(function () {
        window.location.href = 'login.php?expired=1';
    }, SESSION_LIFETIME_MS);
})();

window.onload = function() {
    showSection('dashboard');
    const firstLink = document.querySelector('.sidebar ul li a');
    if (firstLink) firstLink.classList.add('active');
};
</script>

</body>
</html>