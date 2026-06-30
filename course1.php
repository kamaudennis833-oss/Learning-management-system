<?php
session_start();
include "db.php";
include "advance.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
/* === AUTH === */
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = (int) $_SESSION['user_id'];

/* === CSRF === */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function validateCsrf(): void {
    if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403); die("Invalid CSRF token.");
    }
}

/* === COURSE ID === */
if (empty($_GET['id'])) die("Course not found");
$course_id = intval($_GET['id']);

/* === SERVE NOTE PDF === */
if (isset($_GET['note'])) {
    $note_id = intval($_GET['note']);
    $stmt = $conn->prepare("SELECT file_path FROM notes WHERE id = ? AND course_id = ?");
    $stmt->bind_param("ii", $note_id, $course_id);
    $stmt->execute();
    $note_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$note_row) die("Note not found.");
    $file = $note_row['file_path'];
    if (!file_exists($file)) die("File not found on server.");
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    readfile($file);
    exit;
}

/* === QUIZ SUBMISSION === */
if (isset($_POST['submit_quiz'])) {
    validateCsrf();
    $roleCheck = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
    $roleCheck->bind_param("i", $user_id);
    $roleCheck->execute();
    $roleRow = $roleCheck->get_result()->fetch_assoc();
    $roleCheck->close();
    if (!$roleRow || $roleRow['role'] !== 'student') die("❌ Unauthorized.");

    $quiz_id    = (int)($_POST['quiz_id'] ?? 0);
    $student_id = $user_id;

    $stmt = $conn->prepare("SELECT * FROM quizzes WHERE id=? AND course_id=?");
    $stmt->bind_param("ii", $quiz_id, $course_id);
    $stmt->execute();
    $quiz_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($quiz_data) {
        $stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE quiz_id=?");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        $questions_result = $stmt->get_result();
        $stmt->close();
        $total = 0; $correct = 0;
        $valid_answers = ['A','B','C','D'];
        while ($question = $questions_result->fetch_assoc()) {
            $total++;
            $qid = (int)$question['id'];
            $student_answer = $_POST['answer'][$qid] ?? '';
            if (in_array($student_answer, $valid_answers, true) && $student_answer === $question['correct_answer']) $correct++;
        }
        $score = ($total > 0) ? (int)round(($correct / $total) * 100) : 0;
        $status  = ($score >= (int)$quiz_data['passing_score']) ? 'passed' : 'failed';
        $quiz_title = $quiz_data['title'];

        $stmt = $conn->prepare(
            "INSERT INTO quiz_results (student_id, user_id, quiz_id, quiz_title, score, status, completed_at)
             VALUES (?,?,?,?,?,?,NOW()) AS new_val
             ON DUPLICATE KEY UPDATE
                score=new_val.score,
                status=new_val.status,
                completed_at=NOW()"
        );
        $stmt->bind_param("iiisis", $student_id, $student_id, $quiz_id, $quiz_title, $score, $status);
        $stmt->execute(); $stmt->close();

        $prog_stmt = $conn->prepare(
            "INSERT INTO student_progress (user_id, course_id, item_type, item_id, completed, score, updated_at)
             VALUES (?,?,'quiz',?,1,?,NOW()) AS new_val
             ON DUPLICATE KEY UPDATE
                completed=1,
                score=new_val.score,
                updated_at=NOW()"
        );
        $prog_stmt->bind_param("iiid", $student_id, $course_id, $quiz_id, $score);
        $prog_stmt->execute(); $prog_stmt->close();

        $_SESSION['quiz_success'] = "Quiz Submitted! Score: {$score}% — " . ucfirst($status) . "!";
    }
    header("Location: course1.php?id=" . $course_id); exit;
}

/* === AJAX === */
if (isset($_POST['action'])) {

    /* Save video progress */
    if ($_POST['action'] === 'save_progress' && $user_id) {
        header('Content-Type: application/json');

        $video_id = intval($_POST['content_id'] ?? 0);
        $watched  = min(100, max(0, intval($_POST['watched'] ?? 0)));

        // Never save 0 
        if (!$video_id || $watched <= 0) {
            echo json_encode(['ok' => false, 'reason' => 'invalid_input', 'video_id' => $video_id, 'watched' => $watched]);
            exit;
        }

        $vcheck = $conn->prepare("SELECT id FROM course_videos WHERE id=? AND course_id=?");
        $vcheck->bind_param("ii", $video_id, $course_id);
        $vcheck->execute();
        $valid_video = $vcheck->get_result()->fetch_assoc();
        $vcheck->close();
        if (!$valid_video) {
            echo json_encode(['ok' => false, 'reason' => 'video_not_in_course', 'video_id' => $video_id, 'course_id' => $course_id]);
            exit;
        }
        $completed = ($watched >= 90) ? 1 : 0;
        try {
            $stmt = $conn->prepare(
                "INSERT INTO video_progress
                    (user_id, content_id, watched_percentage, completed)
                 VALUES (?,?,?,?) AS new_val
                 ON DUPLICATE KEY UPDATE
                    watched_percentage = GREATEST(video_progress.watched_percentage, new_val.watched_percentage),
                    completed= GREATEST(video_progress.completed, new_val.completed),
                    updated_at= CURRENT_TIMESTAMP"
            );
            $stmt->bind_param("iiii", $user_id, $video_id, $watched, $completed);
            $stmt->execute();
            $stmt->close();

            if ($completed) {
                $up = $conn->prepare(
                    "INSERT INTO student_progress
                        (user_id, course_id, item_type, item_id, completed, updated_at)
                     VALUES (?,?,'video',?,1,NOW())
                     ON DUPLICATE KEY UPDATE completed=1, updated_at=NOW()"
                );
                $up->bind_param("iii", $user_id, $course_id, $video_id);
                $up->execute();
                $up->close();
            }

            echo json_encode(['ok' => true, 'video_id' => $video_id, 'watched' => $watched, 'completed' => $completed]);
        } catch (mysqli_sql_exception $e) {
            error_log("save_progress failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'reason' => 'db_error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    /* Save note progress */
    if ($_POST['action'] === 'save_note_progress' && $user_id) {
        header('Content-Type: application/json');
        define('NOTE_COMPLETE_SECONDS', 30);
        $note_id = intval($_POST['note_id'] ?? 0);
        $seconds = max(0, intval($_POST['seconds'] ?? 0));
        $force_done = isset($_POST['force_done']) && $_POST['force_done'] === '1';
        if (!$note_id) {
            echo json_encode(['ok' => false, 'reason' => 'invalid_input']);
            exit;
        }
        $check = $conn->prepare("SELECT id FROM notes WHERE id=? AND course_id=?");
        $check->bind_param("ii", $note_id, $course_id);
        $check->execute();
        $valid_note = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$valid_note) {
            echo json_encode(['ok' => false, 'reason' => 'note_not_in_course', 'note_id' => $note_id, 'course_id' => $course_id]);
            exit;
        }
        $completed = ($seconds >= NOTE_COMPLETE_SECONDS || $force_done) ? 1 : 0;
        $seconds   = $force_done ? max($seconds, NOTE_COMPLETE_SECONDS) : $seconds;
        try {
            $stmt = $conn->prepare(
                "INSERT INTO note_progress
                    (user_id, note_id, opened, seconds_viewed, completed)
                 VALUES (?,?,1,?,?) AS new_val
                 ON DUPLICATE KEY UPDATE
                    opened         = 1,
                    seconds_viewed = GREATEST(note_progress.seconds_viewed, new_val.seconds_viewed),
                    completed      = GREATEST(note_progress.completed, new_val.completed),
                    updated_at     = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param("iiii", $user_id, $note_id, $seconds, $completed);
            $stmt->execute();
            $stmt->close();
            if ($completed) {
                $up = $conn->prepare(
                    "INSERT INTO student_progress
                        (user_id, course_id, item_type, item_id, completed, updated_at)
                     VALUES (?,?,'note',?,1,NOW())
                     ON DUPLICATE KEY UPDATE completed=1, updated_at=NOW()"
                );
                $up->bind_param("iii", $user_id, $course_id, $note_id);
                $up->execute();
                $up->close();
            }
            echo json_encode(['ok' => true, 'note_id' => $note_id, 'seconds' => $seconds, 'completed' => $completed]);
        } catch (mysqli_sql_exception $e) {
            error_log("save_note_progress failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'reason' => 'db_error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    /* Add review */
    if ($_POST['action'] === 'add_review' && $user_id) {
        header('Content-Type: application/json');
        $review_course_id = intval($_POST['course_id'] ?? $course_id);
        $rating  = min(5, max(1, intval($_POST['rating'])));
        $comment = trim($_POST['comment'] ?? '');
        try {
            $stmt = $conn->prepare(
                "INSERT INTO course_reviews (user_id, course_id, rating, review)
                 VALUES (?,?,?,?) AS new_val
                 ON DUPLICATE KEY UPDATE
                    rating=new_val.rating,
                    review=new_val.review"
            );
            $stmt->bind_param("iiis", $user_id, $review_course_id, $rating, $comment);
            $stmt->execute(); $stmt->close();
            $avg_stmt = $conn->prepare("SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM course_reviews WHERE course_id=?");
            $avg_stmt->bind_param("i", $review_course_id);
            $avg_stmt->execute();
            $avg_data = $avg_stmt->get_result()->fetch_assoc();
            $avg_stmt->close();
            echo json_encode(array_merge(['ok' => true], $avg_data));
        } catch (mysqli_sql_exception $e) {
            error_log("add_review failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'reason' => 'db_error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    http_response_code(200); exit;
}
/* ==========
   PAGE LOAD 
   ========== */
$course_query = $conn->prepare("SELECT c.*, u.full_name AS instructor FROM courses c LEFT JOIN teachers t ON t.id=c.teacher_id LEFT JOIN users u ON u.id=t.user_id WHERE c.id=?");
$course_query->bind_param("i", $course_id);
$course_query->execute();
$course = $course_query->get_result()->fetch_assoc();
$course_query->close();
if (!$course) die("Course not found");

$is_paid = (!empty($course['course_type']) && $course['course_type'] === 'Paid')
         || (!empty($course['price']) && (float)$course['price'] > 0);

/* NOTES */
$notes_stmt = $conn->prepare("SELECT * FROM notes WHERE course_id=? ORDER BY id ASC");
$notes_stmt->bind_param("i", $course_id); $notes_stmt->execute();
$notes_res  = $notes_stmt->get_result();
$note_count = $notes_res->num_rows;
$notes_stmt->close();
$notes_rows = $notes_res->fetch_all(MYSQLI_ASSOC);

/* VIDEOS */
$videos_stmt = $conn->prepare("SELECT * FROM course_videos WHERE course_id=? ORDER BY id ASC");
$videos_stmt->bind_param("i", $course_id); $videos_stmt->execute();
$videos_res  = $videos_stmt->get_result();
$video_count = $videos_res->num_rows;
$videos_stmt->close();
$videos_rows = $videos_res->fetch_all(MYSQLI_ASSOC);

/* QUIZZES */
$quizzes_stmt = $conn->prepare("SELECT * FROM quizzes WHERE course_id=? ORDER BY id DESC");
$quizzes_stmt->bind_param("i", $course_id); $quizzes_stmt->execute();
$quizzes_res = $quizzes_stmt->get_result();
$quiz_count  = $quizzes_res->num_rows;
$quizzes_stmt->close();
$quizzes_rows = $quizzes_res->fetch_all(MYSQLI_ASSOC);

/* 
   VIDEO PROGRESS MAP
 */
$video_progress_map = [];
foreach ($videos_rows as $v) {
    $video_progress_map[(int)$v['id']] = ['pct' => 0, 'completed' => 0];
}

if ($video_count > 0) {
    $vids = implode(',', array_map('intval', array_keys($video_progress_map)));
    $vpm = $conn->query(
        "SELECT content_id,
                MAX(watched_percentage)
                AS watched_percentage,
                MAX(completed) 
                AS completed
         FROM video_progress
         WHERE user_id = $user_id
           AND content_id IN ($vids)
         GROUP BY content_id"
    );
    if ($vpm) {
        while ($row = $vpm->fetch_assoc()) {
            $cid = (int)$row['content_id'];
            if (isset($video_progress_map[$cid])) {
                $video_progress_map[$cid] = [
                    'pct'       => (int)$row['watched_percentage'],
                    'completed' => (int)$row['completed'],
                ];
            }
        }
    }
}

/* 
   NOTE PROGRESS MAP
   Same pattern — MAX() collapses duplicates safely.
    */
$note_progress_map = [];
foreach ($notes_rows as $n) {
    $note_progress_map[(int)$n['id']] = ['seconds' => 0, 'completed' => 0];
}

if ($note_count > 0) {
    $note_ids = implode(',', array_map('intval', array_keys($note_progress_map)));

    $npres = $conn->query(
        "SELECT note_id,
                MAX(seconds_viewed) AS seconds_viewed,
                MAX(completed)      AS completed
         FROM note_progress
         WHERE user_id = $user_id
           AND note_id IN ($note_ids)
         GROUP BY note_id"
    );
    if ($npres) {
        while ($row = $npres->fetch_assoc()) {
            $nid = (int)$row['note_id'];
            if (isset($note_progress_map[$nid])) {
                $note_progress_map[$nid] = [
                    'seconds'   => (int)$row['seconds_viewed'],
                    'completed' => (int)$row['completed'],
                ];
            }
        }
    }
}

/*Compute aggregate percentages*/
$video_progress = 0;
if ($video_count > 0) {
    $sum = array_sum(array_column($video_progress_map, 'pct'));
    $video_progress = (int)round($sum / $video_count);
}

$note_progress = 0;
$completed_notes_count = 0;
if ($note_count > 0) {
    $completed_notes_count = array_sum(array_column($note_progress_map, 'completed'));
    $note_progress = (int)round(($completed_notes_count / $note_count) * 100);
}

$quiz_progress   = 0;
$quiz_status_map = [];
if ($quiz_count > 0) {
    $quiz_ids  = array_map(fn($q) => (int)$q['id'], $quizzes_rows);
    $in_clause = implode(',', $quiz_ids);
    $qsm = $conn->query(
        "SELECT quiz_id, status
         FROM quiz_results
         WHERE user_id=$user_id AND quiz_id IN ($in_clause)
         ORDER BY completed_at DESC"
    );
    while ($row = $qsm->fetch_assoc()) {
        if (!isset($quiz_status_map[(int)$row['quiz_id']])) {
            $quiz_status_map[(int)$row['quiz_id']] = $row['status'];
        }
    }
    $passed_count  = count(array_filter($quiz_status_map, fn($s) => $s === 'passed'));
    $quiz_progress = (int)round(($passed_count / $quiz_count) * 100);
}

/* Weighted overall progress */
$w_video = ($video_count > 0) ? 0.60 : 0;
$w_note  = ($note_count  > 0) ? 0.25 : 0;
$w_quiz  = ($quiz_count  > 0) ? 0.15 : 0;
$w_total = $w_video + $w_note + $w_quiz;
$progress = ($w_total > 0)
    ? (int)round(($video_progress * $w_video + $note_progress * $w_note + $quiz_progress * $w_quiz) / $w_total)
    : 0;

/* RATING */
$rating_stmt = $conn->prepare("SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM course_reviews WHERE course_id=?");
$rating_stmt->bind_param("i", $course_id); $rating_stmt->execute();
$rating_data   = $rating_stmt->get_result()->fetch_assoc(); $rating_stmt->close();
$avg_rating    = $rating_data['avg_rating'] ?? 0;
$total_ratings = $rating_data['total'] ?? 0;

/* BASE URL */
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';

/* QUIZ DATA */
$all_quizzes   = $quizzes_rows;
$all_questions = [];
if (!empty($all_quizzes)) {
    $quiz_ids  = array_map(fn($q) => (int)$q['id'], $all_quizzes);
    $in_clause = implode(',', $quiz_ids);
    $qres2 = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id IN ($in_clause) ORDER BY quiz_id, id");
    while ($row = $qres2->fetch_assoc()) $all_questions[] = $row;
}

/* QUIZ STATS */
$total_quizzes = 0; $passed = 0; $failed = 0; $avgScore = 0;
if (!empty($all_quizzes)) {
    $quiz_ids  = array_map(fn($q) => (int)$q['id'], $all_quizzes);
    $in_clause = implode(',', $quiz_ids);
    $statRes   = $conn->query("SELECT COUNT(*) AS total_quizzes, COALESCE(SUM(status='passed'),0) AS passed, COALESCE(SUM(status='failed'),0) AS failed, COALESCE(AVG(score),0) AS avg_score FROM quiz_results WHERE user_id=$user_id AND quiz_id IN ($in_clause)");
    $quizStats     = $statRes->fetch_assoc();
    $total_quizzes = (int)($quizStats['total_quizzes'] ?? 0);
    $passed        = (int)($quizStats['passed']        ?? 0);
    $failed        = (int)($quizStats['failed']        ?? 0);
    $avgScore      = round((float)($quizStats['avg_score'] ?? 0), 1);
}

$results_rows = [];
if (!empty($all_quizzes)) {
    $quiz_ids  = array_map(fn($q) => (int)$q['id'], $all_quizzes);
    $in_clause = implode(',', $quiz_ids);
    $rq = $conn->query("SELECT * FROM quiz_results WHERE user_id=$user_id AND quiz_id IN ($in_clause) ORDER BY completed_at DESC LIMIT 5");
    $results_rows = $rq->fetch_all(MYSQLI_ASSOC);
}

$stmt = $conn->prepare("SELECT * FROM quiz_history WHERE user_id=? ORDER BY completed_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$history_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM quiz_achievements WHERE user_id=?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$achievements_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare("SELECT * FROM certificates WHERE user_id=? ORDER BY issued_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$certs_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$flash        = $_SESSION['flash']        ?? ''; unset($_SESSION['flash']);
$quiz_success = $_SESSION['quiz_success'] ?? ''; unset($_SESSION['quiz_success']);

$csrf_js    = json_encode($_SESSION['csrf_token'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$is_paid_js = $is_paid ? 'true' : 'false';

/* JS INIT DATA — built directly from the DB-fetched maps */
$js_video_map = [];
foreach ($video_progress_map as $vid => $vdata) {
    $js_video_map[$vid] = $vdata['pct'];
}
$js_note_map = [];
foreach ($note_progress_map as $nid => $ndata) {
    $js_note_map[$nid] = $ndata['completed'] ? 1 : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($course['title']) ?> — LearnHub</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg:#f5f4f9; --surface:#fff; --surface2:#f0eef8;
    --border:rgba(108,99,255,0.12); --border-md:rgba(108,99,255,0.22);
    --text:#1a1830; --muted:#6b6888;
    --accent:#5b52e8; --accent2:#7c6ff5; --accent-lt:#eeecfd;
    --green:#16a34a; --green-lt:#dcfce7;
    --amber:#d97706; --amber-lt:#fef3c7; --red:#dc2626;
    --shadow-sm:0 1px 4px rgba(91,82,232,0.07);
    --shadow-md:0 4px 20px rgba(91,82,232,0.10);
    --radius-sm:8px; --radius-md:12px; --radius-lg:18px;
}
html { scroll-behavior:smooth; }
body { background:var(--bg); color:var(--text); font-family:-apple-system,'Segoe UI',sans-serif; font-size:15px; line-height:1.6; min-height:100vh; }
a { color:var(--accent); text-decoration:none; } a:hover { text-decoration:underline; }
img { display:block; max-width:100%; }

/* PAID */
body.protected { -webkit-user-select:none; user-select:none; -webkit-user-drag:none; }
body.protected video::-webkit-media-controls-download-button { display:none!important; }
body.protected img { pointer-events:none; }
.paid-watermark { position:fixed; inset:0; pointer-events:none; z-index:8000; display:none;
    background:repeating-linear-gradient(-45deg,transparent,transparent 120px,rgba(91,82,232,0.04) 120px,rgba(91,82,232,0.04) 122px); }
.paid-watermark::after { content:'LearnHub — Licensed Content'; position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%) rotate(-30deg); font-size:clamp(14px,2vw,22px); font-weight:700;
    color:rgba(91,82,232,0.07); white-space:nowrap; letter-spacing:2px; pointer-events:none; }
body.protected .paid-watermark { display:block; }
.badge-paid { background:rgba(217,119,6,0.2); border-color:rgba(217,119,6,0.4); color:#fef3c7; }

/* LAYOUT */
.page-wrap { max-width:1180px; margin:0 auto; padding:0 24px 80px; }
.hero { background:linear-gradient(130deg,#5b52e8 0%,#7c6ff5 50%,#a78bfa 100%); padding:20px 0; margin-bottom:10px; }
.hero-inner { max-width:1180px; margin:0 auto; padding:0 24px; }
.hero-meta { display:flex; align-items:center; gap:5px; margin-bottom:10px; flex-wrap:wrap; justify-content:center; }
.badge { display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,0.2); color:#fff;
    border:1px solid rgba(255,255,255,0.3); font-size:12px; font-weight:600; padding:4px 12px; border-radius:20px; }
.badge-green { background:rgba(22,163,74,0.2); border-color:rgba(22,163,74,0.4); }
.hero h1 { font-size:clamp(20px,4vw,40px); font-weight:700; color:#fff; margin-bottom:10px; text-align:center; }
.hero-desc { color:rgba(255,255,255,0.82); max-width:640px; margin:0 auto 15px; text-align:center; font-size:15px; }
.hero-stats { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; font-size:13px; color:rgba(255,255,255,0.75); }
.hero-stats span { display:flex; align-items:center; gap:6px; }
.hero-stats .dot { width:3px; height:3px; background:rgba(255,255,255,0.4); border-radius:50%; }
.hero-stats strong { color:#fff; }
.stars { color:#fcd34d; font-size:15px; }

/* GRID */
.main-grid { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; }

/* CARD */
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); overflow:hidden; margin-bottom:20px; }
.card-header { padding:18px 22px 14px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.card-header h2 { font-size:15px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:8px; }
.card-header h2 i { color:var(--accent); }
.card-header .count { font-size:12px; color:var(--muted); background:var(--surface2); padding:2px 10px; border-radius:20px; border:1px solid var(--border); font-weight:500; }

/* FLASH */
.flash { background:var(--green-lt); color:var(--green); border:1px solid rgba(22,163,74,0.25); border-radius:var(--radius-sm); padding:13px 18px; margin:0 22px 18px; font-weight:600; }

/* PROGRESS */
.progress-wrap { padding:20px 22px; }
.progress-numbers { display:flex; align-items:baseline; gap:6px; margin-bottom:12px; }
.progress-numbers .big { font-size:34px; font-weight:700; color:var(--green); }
.progress-numbers .small { font-size:14px; color:var(--muted); }
.progress-bar-track { width:100%; height:10px; background:var(--surface2); border-radius:10px; overflow:hidden; border:1px solid var(--border); }
.progress-bar-fill { height:100%; background:linear-gradient(90deg,#16a34a,#22c55e); border-radius:10px; transition:width 0.8s ease; }
.progress-label { margin-top:10px; font-size:13px; color:var(--muted); }
.progress-breakdown { display:flex; gap:12px; flex-wrap:wrap; margin-top:12px; font-size:12px; color:var(--muted); }
.progress-breakdown span strong { color:var(--text); }

/* VIDEO */
.video-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; padding:18px 22px; }
.video-card { background:var(--surface2); border-radius:var(--radius-md); overflow:hidden; border:1.5px solid var(--border); transition:transform 0.18s,box-shadow 0.18s,border-color 0.18s; cursor:pointer; }
.video-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--accent2); }
.video-card.watching { border-color:var(--accent); box-shadow:0 0 0 3px rgba(91,82,232,0.15); }
.video-card.done { border-color:var(--green); }
.video-thumb { position:relative; background:#1a1830; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center; }
.play-overlay { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(26,24,48,0.35); }
.play-btn { width:46px; height:46px; background:var(--accent); border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(91,82,232,0.45); transition:transform 0.15s; }
.video-card:hover .play-btn { transform:scale(1.1); }
.play-btn svg { width:18px; height:18px; fill:#fff; margin-left:3px; }
.video-info { padding:12px 14px; }
.video-num { font-size:11px; color:var(--accent); font-weight:700; text-transform:uppercase; letter-spacing:0.7px; margin-bottom:4px; }
.video-title { font-size:13px; font-weight:600; line-height:1.4; color:var(--text); }
.video-watched { margin-top:10px; height:4px; background:var(--border); border-radius:4px; overflow:hidden; }
.video-watched-fill { height:100%; background:var(--accent); border-radius:4px; transition:width 0.3s; }
.done-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; color:var(--green); background:var(--green-lt); padding:2px 8px; border-radius:10px; margin-top:4px; }
.done-badge i { font-size:9px; }

/* PLAYER */
#player-wrap { display:none; margin:0 22px 0; }
#player-wrap.active { display:block; }
#main-player { background:#0a0916; border-radius:var(--radius-md); overflow:hidden; aspect-ratio:16/9; border:1px solid var(--border-md); box-shadow:var(--shadow-md); }
#main-player video { width:100%; height:100%; display:block; }
#main-player video::-webkit-media-controls-download-button,
#main-player video::-webkit-media-controls-picture-in-picture-button { display:none!important; }
#player-title { padding:12px 4px 18px; font-size:14px; font-weight:600; color:var(--text); display:none; }
#player-title.active { display:block; }

/* NOTE VIEWERS */
#note-viewer-wrap,#text-note-wrap { display:none; margin:0 22px 18px; }
#note-viewer-wrap.active,#text-note-wrap.active { display:block; }
#note-viewer-toolbar,#text-note-toolbar { display:flex; align-items:center; justify-content:space-between; padding:10px 4px 12px; font-size:13px; color:var(--muted); flex-wrap:wrap; gap:6px; }
#note-viewer-title,#text-note-title { font-weight:700; color:var(--text); }
.read-timer { font-weight:600; color:var(--accent); }
.read-timer.done { color:var(--green); }
.read-done-pill { display:none; align-items:center; gap:4px; font-size:11px; font-weight:700; color:var(--green);
    background:var(--green-lt); border:1px solid rgba(22,163,74,0.3); border-radius:20px; padding:3px 10px; }
.read-done-pill.show { display:inline-flex; }
.read-done-pill i { font-size:10px; }
#note-viewer { width:100%; height:min(620px,70vh); border:1px solid var(--border-md); border-radius:var(--radius-md); box-shadow:var(--shadow-md); background:#fff; }
#text-note-body { max-height:min(620px,70vh); overflow-y:auto; background:var(--surface); border:1px solid var(--border-md); border-radius:var(--radius-md); box-shadow:var(--shadow-md); padding:26px 30px; font-size:14.5px; line-height:1.8; color:var(--text); white-space:pre-wrap; word-wrap:break-word; }
#text-note-body a { color:var(--accent); text-decoration:underline; word-break:break-all; }
.note-close-btn { background:var(--surface2); border:1px solid var(--border-md); color:var(--muted); border-radius:20px; padding:4px 12px; font-size:12px; cursor:pointer; font-weight:600; }
.note-close-btn:hover { background:var(--red); color:#fff; border-color:var(--red); }

/* NOTE LIST */
.note-list { padding:6px 22px 18px; }
.note-item { display:flex; align-items:flex-start; justify-content:space-between; padding:14px 0; border-bottom:1px solid var(--border); gap:12px; flex-wrap:wrap; }
.note-item:last-child { border-bottom:none; }
.note-item-left { display:flex; align-items:flex-start; gap:12px; flex:1; min-width:0; }
.note-icon { width:40px; height:40px; flex-shrink:0; background:var(--accent-lt); border-radius:var(--radius-sm); display:flex; align-items:center; justify-content:center; font-size:18px; border:1px solid var(--border); }
.note-name { font-size:14px; font-weight:600; color:var(--text); display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.note-done-badge { font-size:10px; font-weight:700; color:var(--green); background:var(--green-lt); padding:1px 8px; border-radius:10px; }
.note-sub { font-size:12px; color:var(--muted); margin-top:2px; }
.note-watched-wrap { margin-top:8px; width:100%; max-width:180px; }
.note-time-label { font-size:11px; color:var(--muted); margin-top:3px; }

/* BUTTONS */
.btn-outline { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; font-size:12px; font-weight:600; border:1.5px solid var(--border-md); background:var(--surface); color:var(--accent); border-radius:var(--radius-sm); cursor:pointer; transition:background 0.15s,border-color 0.15s; text-decoration:none; white-space:nowrap; flex-shrink:0; }
.btn-outline:hover { background:var(--accent-lt); border-color:var(--accent); text-decoration:none; }
.btn-outline.done-btn { border-color:var(--green); color:var(--green); }
.btn-primary { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; font-size:13px; font-weight:700; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); cursor:pointer; transition:background 0.15s,transform 0.1s,box-shadow 0.15s; text-decoration:none; white-space:nowrap; flex-shrink:0; box-shadow:0 2px 8px rgba(91,82,232,0.3); }
.btn-primary:hover { background:#4841d4; transform:translateY(-1px); box-shadow:0 4px 14px rgba(91,82,232,0.4); text-decoration:none; color:#fff; }
.btn-mark-done { background:var(--accent-lt)!important; color:var(--accent)!important; border-color:var(--accent)!important; }
.btn-mark-done:hover { background:var(--accent)!important; color:#fff!important; }

/* QUIZ */
.quiz-list { padding:6px 22px 18px; }
.quiz-item { display:flex; align-items:center; justify-content:space-between; padding:14px 0; border-bottom:1px solid var(--border); gap:12px; flex-wrap:wrap; }
.quiz-item:last-child { border-bottom:none; }
.quiz-item-left { display:flex; align-items:center; gap:12px; flex:1; min-width:0; }
.quiz-icon { width:40px; height:40px; flex-shrink:0; background:var(--amber-lt); border-radius:var(--radius-sm); display:flex; align-items:center; justify-content:center; font-size:18px; border:1px solid rgba(217,119,6,0.15); }
.quiz-name { font-size:14px; font-weight:600; color:var(--text); }
.quiz-meta { font-size:12px; color:var(--muted); margin-top:2px; }
.quiz-stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:18px 22px; }
.quiz-stat-box { background:var(--surface2); border-radius:var(--radius-md); padding:14px 12px; text-align:center; border:1px solid var(--accent); }
.quiz-stat-box.green { border-color:var(--green); }
.quiz-stat-box.red { border-color:var(--red); }
.quiz-stat-box i { font-size:1.1rem; color:var(--accent); margin-bottom:6px; display:block; }
.quiz-stat-box.green i { color:var(--green); }
.quiz-stat-box.red i { color:var(--red); }
.quiz-stat-box .val { font-size:20px; font-weight:700; color:var(--text); }
.quiz-stat-box .lbl { font-size:11px; color:var(--muted); margin-top:3px; font-weight:500; }

/* MISC */
.activity-item { padding:12px 14px; border-radius:10px; border:1px solid var(--accent); background:var(--surface2); margin:0 22px 10px; transition:transform 0.2s; }
.activity-item:last-child { margin-bottom:18px; }
.activity-item:hover { transform:translateX(4px); }
.activity-item p { font-weight:600; font-size:0.9rem; color:var(--text); margin-bottom:2px; }
.activity-item span { color:var(--muted); font-size:0.8rem; }
.stat-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:18px 22px; }
.stat-box { background:var(--surface2); border-radius:var(--radius-md); padding:14px 12px; text-align:center; border:1px solid var(--border); }
.stat-box .val { font-size:22px; font-weight:700; color:var(--accent); }
.stat-box .lbl { font-size:11px; color:var(--muted); margin-top:3px; font-weight:500; }
.rating-section { padding:14px 22px 22px; }
.rating-current { display:flex; align-items:center; gap:12px; background:var(--surface2); border-radius:var(--radius-md); padding:16px; margin-bottom:18px; border:1px solid var(--border); }
.rating-big { font-size:38px; font-weight:700; line-height:1; color:var(--text); }
.rating-sub { font-size:12px; color:var(--muted); margin-top:4px; font-weight:500; }
.star-select { display:flex; gap:4px; margin-bottom:12px; }
.star-select button { background:none; border:none; font-size:26px; cursor:pointer; opacity:0.25; transition:opacity 0.15s,transform 0.15s; padding:0; line-height:1; }
.star-select button.on { opacity:1; }
.star-select button:hover { transform:scale(1.2); opacity:1; }
.rating-textarea { width:100%; background:var(--surface2); border:1.5px solid var(--border-md); color:var(--text); border-radius:var(--radius-sm); padding:10px 12px; font-size:13px; resize:vertical; min-height:80px; font-family:inherit; }
.rating-textarea:focus { outline:none; border-color:var(--accent); }
.rating-submit { width:100%; margin-top:12px; padding:11px; font-size:14px; font-weight:700; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); cursor:pointer; }
.rating-submit:hover { background:#4841d4; }
.review-toast { display:none; margin-top:10px; padding:10px 14px; background:var(--green-lt); color:var(--green); border:1px solid rgba(22,163,74,0.25); border-radius:var(--radius-sm); font-size:13px; text-align:center; font-weight:600; }
.review-toast.show { display:block; }
.instructor-card { display:flex; align-items:center; gap:14px; padding:18px 22px; }
.avatar { width:48px; height:48px; flex-shrink:0; background:linear-gradient(135deg,var(--accent),var(--accent2)); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:19px; font-weight:700; color:#fff; }
.instructor-name { font-weight:700; font-size:15px; color:var(--text); }
.instructor-role { font-size:12px; color:var(--muted); font-weight:500; }
.empty { padding:36px 22px; text-align:center; color:var(--muted); font-size:14px; }
.empty-icon { font-size:34px; margin-bottom:8px; opacity:0.45; }
.video-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:var(--radius-sm); padding:10px 14px; font-size:13px; margin:0 22px 14px; display:none; }
.video-error.show { display:block; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(10,9,22,0.65); z-index:9000; align-items:center; justify-content:center; padding:16px; overflow-y:auto; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--surface); border-radius:var(--radius-lg); padding:30px; width:100%; max-width:860px; position:relative; animation:popIn 0.2s ease; box-shadow:var(--shadow-md); max-height:90vh; overflow-y:auto; }
@keyframes popIn { from{transform:scale(0.93);opacity:0} to{transform:scale(1);opacity:1} }
.modal-close { position:absolute; top:14px; right:16px; width:30px; height:30px; background:var(--surface2); border:none; border-radius:50%; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center; }
.modal-close:hover { background:var(--red); color:#fff; }
.quiz-option { display:block; background:var(--surface2); border:2px solid var(--border); border-radius:9px; padding:12px 14px; margin-bottom:10px; cursor:pointer; font-size:0.92rem; }
.quiz-option:hover { border-color:var(--accent); background:var(--accent-lt); }
.quiz-option input { margin-right:10px; accent-color:var(--accent); }
.quiz-timer { background:var(--red); color:#fff; padding:8px 16px; border-radius:9px; font-weight:700; font-size:1rem; min-width:80px; text-align:center; flex-shrink:0; }
.protection-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#1a1830; color:#fff; padding:12px 24px; border-radius:30px; font-size:13px; font-weight:600; box-shadow:0 4px 20px rgba(0,0,0,0.3); z-index:9999; opacity:0; transition:opacity 0.3s; pointer-events:none; white-space:nowrap; }
.protection-toast.show { opacity:1; }

@media(max-width:860px){.main-grid{grid-template-columns:1fr}.right-col{display:contents}}
@media(max-width:600px){.page-wrap{padding:0 14px 60px}.video-row{grid-template-columns:1fr 1fr;gap:10px;padding:14px}.card-header{padding:14px 16px 12px}}
@media(max-width:400px){.video-row{grid-template-columns:1fr}.note-item{flex-direction:column}.quiz-item{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body<?= $is_paid ? ' class="protected"' : '' ?>>

<div class="paid-watermark" aria-hidden="true"></div>
<div class="protection-toast" id="protection-toast"> This content is protected</div>

<section class="hero">
    <div class="hero-inner">
        <div class="hero-meta">
            <span class="badge">Course</span>
            <?php if ($is_paid): ?><span class="badge badge-paid"> Premium</span><?php endif; ?>
            <?php if ($progress >= 90): ?>
                <span class="badge badge-green"> Completed</span>
            <?php elseif ($progress > 0): ?>
                <span class="badge">▶ In Progress</span>
            <?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($course['title']) ?></h1>
        <p class="hero-desc"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
        <div class="hero-stats">
            <?php if ($avg_rating > 0): ?>
                <span>
                    <span class="stars"><?= str_repeat('★', round($avg_rating)) ?><?= str_repeat('☆', 5 - round($avg_rating)) ?></span>
                    <strong><?= $avg_rating ?></strong> <span>(<?= $total_ratings ?> ratings)</span>
                </span><span class="dot"></span>
            <?php endif; ?>
            <span><?= $video_count ?> videos</span>
            <span class="dot"></span>
            <span><?= $note_count ?> notes</span>
            <span class="dot"></span>
            <span><?= $quiz_count ?> quizzes</span>
        </div>
    </div>
</section>

<div class="page-wrap">
<div class="main-grid">
<div class="left-col">

    <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($quiz_success): ?><div class="flash"><?= htmlspecialchars($quiz_success) ?></div><?php endif; ?>

    <!--  VIDEOS  -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-film"></i> Course Videos</h2>
            <span class="count"><?= $video_count ?> lessons</span>
        </div>
        <div class="video-error" id="video-error"> Video could not be loaded. Check <code>uploads/videos/</code>.</div>
        <div id="player-wrap">
            <div id="main-player">
                <video id="main-video" controls
                    <?= $is_paid ? 'controlsList="nodownload nofullscreen" disablepictureinpicture' : 'controlsList="nodownload"' ?>
                    playsinline></video>
            </div>
            <p id="player-title"></p>
        </div>

        <?php if ($video_count > 0): ?>
        <div class="video-row">
            <?php foreach ($videos_rows as $vn => $video):
                $vid_id  = (int)$video['id'];
                if (empty($video['video_path'])) continue;
                $vpstate  = $video_progress_map[$vid_id];
                $filename = basename($video['video_path']);
                $video_url = $base_url . 'uploads/videos/' . rawurlencode($filename);
                $card_cls  = $vpstate['completed'] ? ' done' : '';
            ?>
            <div class="video-card<?= $card_cls ?>"
                 data-src="<?= htmlspecialchars($video_url) ?>"
                 data-title="<?= htmlspecialchars($video['title']) ?>"
                 data-id="<?= $vid_id ?>"
                 onclick="playVideo(this)">
                <div class="video-thumb">
                    <div class="play-overlay">
                        <div class="play-btn">
                            <svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg>
                        </div>
                    </div>
                </div>
                <div class="video-info">
                    <div class="video-num">Lesson <?= $vn + 1 ?></div>
                    <div class="video-title"><?= htmlspecialchars($video['title']) ?></div>
                    <?php if ($vpstate['completed']): ?>
                        <div class="done-badge"><i class="fas fa-check-circle"></i> Watched</div>
                    <?php endif; ?>
                    <div class="video-watched">
                        <div class="video-watched-fill" style="width:<?= $vpstate['pct'] ?>%" id="vw-<?= $vid_id ?>"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty"><div class="empty-icon">🎬</div><p>No videos added yet.</p></div>
        <?php endif; ?>
    </div>

    <!--  NOTES  -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-file-alt"></i> Course Notes</h2>
            <span class="count"><?= $note_count ?> files</span>
        </div>

        <!-- PDF viewer -->
        <div id="note-viewer-wrap">
            <div id="note-viewer-toolbar">
                <span id="note-viewer-title"></span>
                <span style="display:flex;align-items:center;gap:8px;">
                    <span class="read-timer" id="note-viewer-timer">00:00</span>
                    <span class="read-done-pill" id="note-pdf-done-pill"><i class="fas fa-check-circle"></i> Done reading</span>
                    <button class="note-close-btn btn-mark-done" id="note-pdf-mark-btn" onclick="markNoteDone('pdf')"> % Mark as Done</button>
                    <button class="note-close-btn" onclick="closeNote()">✕ Close</button>
                </span>
            </div>
            <iframe id="note-viewer" src="" allowfullscreen></iframe>
        </div>

        <!-- Text viewer -->
        <div id="text-note-wrap">
            <div id="text-note-toolbar">
                <span id="text-note-title"></span>
                <span style="display:flex;align-items:center;gap:8px;">
                    <span class="read-timer" id="text-note-timer">00:00</span>
                    <span class="read-done-pill" id="note-text-done-pill"><i class="fas fa-check-circle"></i> Done reading</span>
                    <button class="note-close-btn btn-mark-done" id="note-text-mark-btn" onclick="markNoteDone('text')">% Mark as Done</button>
                    <button class="note-close-btn" onclick="closeTextNote()">✕ Close</button>
                </span>
            </div>
            <div id="text-note-body"></div>
        </div>

        <?php if ($note_count > 0): ?>
        <div class="note-list">
            <?php foreach ($notes_rows as $note):
                $nid     = (int)$note['id'];
                $npstate = $note_progress_map[$nid];
                $npct    = min(100, (int)round(($npstate['seconds'] / 30) * 100));
                $has_pdf  = !empty($note['file_path']);
                $has_text = !$has_pdf && trim((string)($note['content'] ?? '')) !== '';
            ?>
            <div class="note-item" id="note-item-<?= $nid ?>" data-note-id="<?= $nid ?>">
                <div class="note-item-left">
                    <div class="note-icon"><?= $has_pdf ? '' : '' ?></div>
                    <div style="min-width:0;flex:1;">
                        <div class="note-name" id="note-name-<?= $nid ?>">
                            <?= htmlspecialchars($note['title']) ?>
                            <?php if ($npstate['completed']): ?>
                                <span class="note-done-badge" id="note-badge-<?= $nid ?>"><i class="fas fa-check-circle"></i> Read</span>
                            <?php else: ?>
                                <span class="note-done-badge" id="note-badge-<?= $nid ?>" style="display:none;"><i class="fas fa-check-circle"></i> Read</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($note['content'])): ?>
                            <div class="note-sub"><?= htmlspecialchars(mb_substr($note['content'], 0, 70)) ?>…</div>
                        <?php endif; ?>
                        <?php if ($has_pdf || $has_text): ?>
                        <div class="note-watched-wrap">
                            <div class="video-watched">
                                <div class="video-watched-fill" style="width:<?= $npct ?>%" id="nw-<?= $nid ?>"></div>
                            </div>
                            <div class="note-time-label" id="ntime-<?= $nid ?>">
                                <?= $npstate['seconds'] ?>s read · <?= $npct ?>% complete
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                    <?php if ($has_pdf): ?>
                        <button type="button" class="btn-outline<?= $npstate['completed'] ? ' done-btn' : '' ?>" id="note-btn-<?= $nid ?>"
                                onclick="openNote(<?= $nid ?>, '?id=<?= $course_id ?>&note=<?= $nid ?>', '<?= htmlspecialchars($note['title'], ENT_QUOTES) ?>')">
                           <i class="fas fa-file-pdf"></i> <?= $npstate['completed'] ? 'Re-read PDF' : 'Open PDF' ?>
                        </button>
                    <?php elseif ($has_text): ?>
                        <button type="button" class="btn-outline<?= $npstate['completed'] ? ' done-btn' : '' ?>" id="note-btn-<?= $nid ?>"
                                data-note-text="<?= htmlspecialchars($note['content'], ENT_QUOTES) ?>"
                                data-note-title="<?= htmlspecialchars($note['title'], ENT_QUOTES) ?>"
                                onclick="openTextNote(<?= $nid ?>, this.dataset.noteText, this.dataset.noteTitle)">
                           <i class="fas fa-book-open"></i> <?= $npstate['completed'] ? 'Re-read' : 'Open Text' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty"><div class="empty-icon"> </div><p>No notes available yet.</p></div>
        <?php endif; ?>
    </div>

    <!--  QUIZZES  -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-question-circle"></i> Quizzes</h2>
            <span class="count"><?= $quiz_count ?> quizzes</span>
        </div>
        <?php if ($quiz_count > 0): ?>
        <div class="quiz-list">
            <?php foreach ($quizzes_rows as $quiz):
                $qpassed = $quiz_status_map[(int)$quiz['id']] ?? '';
            ?>
            <div class="quiz-item">
                <div class="quiz-item-left">
                    <div class="quiz-icon"> </div>
                    <div>
                        <div class="quiz-name">
                            <?= htmlspecialchars($quiz['title']) ?>
                            <?php if ($qpassed === 'passed'): ?>
                                <span class="note-done-badge" style="margin-left:6px;"><i class="fas fa-check-circle"></i> Passed</span>
                            <?php elseif ($qpassed === 'failed'): ?>
                                <span class="note-done-badge" style="background:#fee2e2;color:#dc2626;margin-left:6px;">✕ Failed</span>
                            <?php endif; ?>
                        </div>
                        <div class="quiz-meta">⏱ <?= (int)$quiz['duration'] ?> min &nbsp;·&nbsp; Pass at <?= (int)$quiz['passing_score'] ?>%</div>
                    </div>
                </div>
                <button class="btn-primary" onclick="openQuiz(<?= (int)$quiz['id'] ?>)">
                    <?= $qpassed ? 'Retry →' : 'Start →' ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty"><div class="empty-icon"></div><p>No quizzes for this course yet.</p></div>
        <?php endif; ?>
    </div>

    <!-- QUIZ HISTORY -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-history"></i> Quiz History</h2></div>
        <?php if ($history_rows): foreach ($history_rows as $h): ?>
            <div class="activity-item"><p><?= htmlspecialchars($h['title'] ?? '') ?></p><span>Completed <?= htmlspecialchars($h['completed_at'] ?? '') ?></span></div>
        <?php endforeach; else: ?><div class="activity-item"><p>No history yet</p></div><?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2><i class="fas fa-trophy"></i> Top Performance</h2></div>
        <?php if ($achievements_rows): foreach ($achievements_rows as $a): ?>
            <div class="activity-item"><p><?= htmlspecialchars($a['title'] ?? '') ?></p><span><?= htmlspecialchars($a['value'] ?? '') ?></span></div>
        <?php endforeach; else: ?><div class="activity-item"><p>No achievements yet</p></div><?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2><i class="fas fa-award"></i> Certification Exams</h2></div>
        <?php if ($certs_rows): foreach ($certs_rows as $c): ?>
            <div class="activity-item"><p><?= htmlspecialchars($c['title'] ?? '') ?></p><span><?= htmlspecialchars($c['status'] ?? '') ?></span></div>
        <?php endforeach; else: ?><div class="activity-item"><p>No certifications yet</p></div><?php endif; ?>
    </div>

</div>

<!--  SIDEBAR  -->
<div class="right-col">

    <div class="card">
        <div class="card-header"><h2>Your progress</h2></div>
        <div class="progress-wrap">
            <div class="progress-numbers">
                <span class="big" id="progress-pct"><?= $progress ?>%</span>
                <span class="small">completed</span>
            </div>
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="progress-bar" style="width:<?= $progress ?>%"></div>
            </div>
            <p class="progress-label" id="progress-label">
                <?php if ($progress===0): ?>Start watching to track your progress.
                <?php elseif ($progress<50): ?>Just getting started — keep going!
                <?php elseif ($progress<90): ?>More than halfway there. Great work!
                <?php else: ?> You've completed this course!<?php endif; ?>
            </p>
            <div class="progress-breakdown">
                <?php if ($video_count > 0): ?>
                    <span> Videos: <strong id="vp-pct"><?= $video_progress ?>%</strong></span>
                <?php endif; ?>
                <?php if ($note_count > 0): ?>
                    <span> Notes: <strong id="np-pct"><?= $note_progress ?>%</strong>
                        <span style="color:var(--muted);font-weight:400;" id="np-fraction">(<?= $completed_notes_count ?>/<?= $note_count ?>)</span>
                    </span>
                <?php endif; ?>
                <?php if ($quiz_count > 0): ?>
                    <span> Quizzes: <strong><?= $quiz_progress ?>%</strong></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Course overview</h2></div>
        <div class="stat-grid">
            <div class="stat-box"><div class="val"><?= $video_count ?></div><div class="lbl">Videos</div></div>
            <div class="stat-box"><div class="val"><?= $note_count ?></div><div class="lbl">Notes</div></div>
            <div class="stat-box"><div class="val"><?= $quiz_count ?></div><div class="lbl">Quizzes</div></div>
            <div class="stat-box"><div class="val"><?= $avg_rating > 0 ? $avg_rating : '—' ?></div><div class="lbl">Rating</div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><i class="fas fa-chart-line"></i> Your quiz stats</h2></div>
        <div class="quiz-stats-grid">
            <div class="quiz-stat-box"><i class="fas fa-file-alt"></i><div class="val"><?= $total_quizzes ?></div><div class="lbl">Total</div></div>
            <div class="quiz-stat-box green"><i class="fas fa-check-circle"></i><div class="val"><?= $passed ?></div><div class="lbl">Passed</div></div>
            <div class="quiz-stat-box red"><i class="fas fa-times-circle"></i><div class="val"><?= $failed ?></div><div class="lbl">Failed</div></div>
            <div class="quiz-stat-box"><i class="fas fa-chart-line"></i><div class="val"><?= $avgScore ?>%</div><div class="lbl">Avg Score</div></div>
        </div>
        <div style="padding:0 22px 18px;">
            <?php if ($results_rows): foreach ($results_rows as $r): ?>
                <div class="activity-item" style="margin:0 0 10px;">
                    <p><?= htmlspecialchars($r['quiz_title'] ?? 'Quiz') ?></p>
                    <span>Score: <?= (int)$r['score'] ?>% • <?= ucfirst($r['status']) ?></span>
                </div>
            <?php endforeach; else: ?>
                <div class="activity-item" style="margin:0;"><p>No results yet</p></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($course['instructor'])): ?>
    <div class="card">
        <div class="card-header"><h2>Instructor</h2></div>
        <div class="instructor-card">
            <div class="avatar"><?= strtoupper(mb_substr($course['instructor'], 0, 1)) ?></div>
            <div>
                <div class="instructor-name"><?= htmlspecialchars($course['instructor']) ?></div>
                <div class="instructor-role">Course Instructor</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Rate this course</h2></div>
        <div class="rating-section">
            <?php if ($avg_rating > 0): ?>
            <div class="rating-current">
                <div style="flex:1;text-align:center;">
                    <div class="rating-big"><?= $avg_rating ?></div>
                    <div class="stars" style="font-size:18px;margin-top:6px;">
                        <?= str_repeat('★', round($avg_rating)) ?><?= str_repeat('☆', 5-round($avg_rating)) ?>
                    </div>
                    <div class="rating-sub"><?= $total_ratings ?> rating<?= $total_ratings!==1?'s':'' ?></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="star-select" id="star-select">
                <?php for ($i=1;$i<=5;$i++): ?>
                    <button type="button" data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)">⭐</button>
                <?php endfor; ?>
            </div>
            <input type="hidden" id="rating-val" value="5">
            <textarea class="rating-textarea" id="comment" placeholder="Share your thoughts…"></textarea>
            <button class="rating-submit" onclick="sendReview()">Submit review</button>
            <div class="review-toast" id="review-toast"> Review submitted — thank you!</div>
        </div>
    </div>

</div>
</div>
</div>

<div id="quizModal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <button class="modal-close" onclick="closeQuiz()">✕</button>
        <div id="quizContainer"></div>
    </div>
</div>

<script>
/* ====
   DB on every page load
   ==== */
const IS_PAID       = <?= $is_paid_js ?>;
const ALL_QUIZZES   = <?= json_encode($all_quizzes,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const ALL_QUESTIONS = <?= json_encode($all_questions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const CSRF_TOKEN    = <?= $csrf_js ?>;

const VIDEO_COUNT = <?= (int)$video_count ?>;
const NOTE_COUNT  = <?= (int)$note_count ?>;
const QUIZ_COUNT  = <?= (int)$quiz_count ?>;
const NOTE_DONE_S = 30;
const VID_DONE_PC = 90;

const W_VIDEO = VIDEO_COUNT > 0 ? 0.60 : 0;
const W_NOTE  = NOTE_COUNT  > 0 ? 0.25 : 0;
const W_QUIZ  = QUIZ_COUNT  > 0 ? 0.15 : 0;
const W_TOTAL = W_VIDEO + W_NOTE + W_QUIZ;
const videoMap = <?= json_encode($js_video_map, JSON_HEX_TAG) ?>;
const noteMap  = <?= json_encode($js_note_map,  JSON_HEX_TAG) ?>;

// Quiz progress comes from DB too — used in the weighted calc below
let liveQuizProgress = <?= (int)$quiz_progress ?>;

/* ====
   PROGRESS CALCULATOR 
   ==== */
function calcProgress() {
    let videoSum = 0;
    Object.keys(videoMap).forEach(id => { videoSum += (videoMap[id] || 0); });
    const videoPct = VIDEO_COUNT > 0 ? Math.round(videoSum / VIDEO_COUNT) : 0;

    const doneNotes = Object.values(noteMap).filter(v => v === 1).length;
    const notePct   = NOTE_COUNT > 0 ? Math.round((doneNotes / NOTE_COUNT) * 100) : 0;

    const overall = W_TOTAL > 0
        ? Math.round((videoPct * W_VIDEO + notePct * W_NOTE + liveQuizProgress * W_QUIZ) / W_TOTAL)
        : 0;

    return { videoPct, notePct, doneNotes, overall };
}

function updateProgressUI() {
    const { videoPct, notePct, doneNotes, overall } = calcProgress();

    const elPct = document.getElementById('progress-pct');
    const elBar = document.getElementById('progress-bar');
    const elLbl = document.getElementById('progress-label');
    const elVP  = document.getElementById('vp-pct');
    const elNP  = document.getElementById('np-pct');
    const elNF  = document.getElementById('np-fraction');

    if (elPct) elPct.textContent = overall + '%';
    if (elBar) elBar.style.width = overall + '%';
    if (elVP)  elVP.textContent  = videoPct + '%';
    if (elNP)  elNP.textContent  = notePct  + '%';
    if (elNF)  elNF.textContent  = '(' + doneNotes + '/' + NOTE_COUNT + ')';
    if (elLbl) {
        if (overall === 0)      elLbl.textContent = 'Start watching to track your progress.';
        else if (overall < 50)  elLbl.textContent = 'Just getting started — keep going!';
        else if (overall < 90)  elLbl.textContent = 'More than halfway there. Great work!';
        else                    elLbl.textContent  = '🎉 You\'ve completed this course!';
    }
}

/* ====
   PAID PROTECTION
   ==== */
if (IS_PAID) {
    document.addEventListener('contextmenu', e => { e.preventDefault(); showToast(); });
    document.addEventListener('keydown', e => {
        const b = e.key==='PrintScreen'||e.key==='F12'
            ||(e.ctrlKey&&['s','p','c','a','u','S','P','C','A','U'].includes(e.key))
            ||(e.metaKey&&['s','p','c','a','S','P','C','A'].includes(e.key));
        if (b) { e.preventDefault(); e.stopImmediatePropagation(); showToast(); return false; }
    }, true);
    if (navigator.mediaDevices?.getDisplayMedia) {
        navigator.mediaDevices.getDisplayMedia = () => {
            showToast();
            return Promise.reject(new DOMException('Disabled','NotAllowedError'));
        };
    }
    document.addEventListener('dragstart', e => {
        if (['VIDEO','IMG','IFRAME'].includes(e.target.tagName)) { e.preventDefault(); showToast(); }
    });
}
function showToast() {
    const t = document.getElementById('protection-toast');
    if (!t) return;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}

/* ====
   VIDEO PLAYER
   ==== */
const mainVideo   = document.getElementById('main-video');
const playerWrap  = document.getElementById('player-wrap');
const playerTitle = document.getElementById('player-title');
const videoError  = document.getElementById('video-error');
let   currentCard = null;
function playVideo(card) {
    document.querySelectorAll('.video-card').forEach(c => c.classList.remove('watching'));
    card.classList.add('watching');
    currentCard = card;
    const src   = card.dataset.src;
    const title = card.dataset.title;
    const cid   = parseInt(card.dataset.id, 10);
    videoError.classList.remove('show');
    playerWrap.classList.add('active');
    playerTitle.textContent = '▶  ' + title;
    playerTitle.classList.add('active');
    mainVideo.src = src;
    mainVideo.load();
    mainVideo.play().catch(() => {});
    mainVideo.onerror = () => videoError.classList.add('show');
    playerWrap.scrollIntoView({ behavior:'smooth', block:'start' });
    const savedPct = videoMap[cid] || 0;
    if (savedPct > 0 && savedPct < VID_DONE_PC) {
        mainVideo.addEventListener('loadedmetadata', function restorePos() {
            mainVideo.removeEventListener('loadedmetadata', restorePos);
            mainVideo.currentTime = (savedPct / 100) * mainVideo.duration;
        });
    }

    mainVideo.ontimeupdate = null;
    mainVideo.ontimeupdate = function () {
        if (!mainVideo.duration) return;
        const pct = Math.floor((mainVideo.currentTime / mainVideo.duration) * 100);

        // Update the progress bar on the card
        const fill = document.getElementById('vw-' + cid);
        if (fill) fill.style.width = pct + '%';
        if (pct > (videoMap[cid] || 0)) {
            videoMap[cid] = pct;
            updateProgressUI();
            saveVideoProgress(cid, pct);
        }
        if (pct >= VID_DONE_PC) {
            card.classList.remove('watching');
            card.classList.add('done');
            if (!card.querySelector('.done-badge')) {
                const info = card.querySelector('.video-info');
                if (info) {
                    const b = document.createElement('div');
                    b.className = 'done-badge';
                    b.innerHTML = '<i class="fas fa-check-circle"></i> Watched';
                    info.insertBefore(b, info.querySelector('.video-watched'));
                }
            }
        }
    };
}
const lastSentVideo = {};
function saveVideoProgress(cid, pct) {
    if (pct === 0) return;
    if (lastSentVideo[cid] === pct) return;
    lastSentVideo[cid] = pct;
    fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_progress&content_id='+cid+'&watched='+pct+'&csrf_token='+encodeURIComponent(CSRF_TOKEN),
        keepalive:true
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            console.warn('Video progress NOT saved:', data);
            delete lastSentVideo[cid]; 
        }
    })
    .catch(err => {
        console.error('Video progress save request failed:', err);
        delete lastSentVideo[cid];
    });
}

/* ====
   NOTE VIEWER  PDF
   ==== */
const noteWrap    = document.getElementById('note-viewer-wrap');
const noteFrame   = document.getElementById('note-viewer');
const noteTitleEl = document.getElementById('note-viewer-title');
const noteTimer   = document.getElementById('note-viewer-timer');
const notePdfDone = document.getElementById('note-pdf-done-pill');
let noteHandle=null, noteSec=0, curNoteId=null;

function openNote(noteId, url, title) {
    stopNoteTimer();
    if (curTextNoteId !== null) closeTextNote();
    curNoteId = noteId;
    noteFrame.src = url;
    noteTitleEl.textContent = ' ' + title;
    noteTimer.textContent = '00:00';
    noteTimer.classList.remove('done');
    notePdfDone.classList.remove('show');
    const alreadyDone = noteMap[noteId] === 1;
    toggleMarkBtn('pdf', alreadyDone);
    if (alreadyDone) {
        noteTimer.textContent = '00:30';
        noteTimer.classList.add('done');
        notePdfDone.classList.add('show');
    }
    noteWrap.classList.add('active');
    noteWrap.scrollIntoView({behavior:'smooth', block:'start'});
    saveNoteProgress(noteId, 0);

    if (!alreadyDone) {
        noteHandle = setInterval(() => {
            noteSec++;
            noteTimer.textContent = fmtTime(noteSec);
            updateNoteBar(noteId, noteSec);
            if (noteSec === NOTE_DONE_S) {
                noteTimer.classList.add('done');
                notePdfDone.classList.add('show');
                onNoteCompleted(noteId);
            }
            if (noteSec % 5 === 0) saveNoteProgress(noteId, noteSec);
        }, 1000);
    }
}

function closeNote() {
    if (curNoteId !== null) saveNoteProgress(curNoteId, noteSec);
    stopNoteTimer();
    noteWrap.classList.remove('active');
    noteFrame.src = '';
    curNoteId = null;
}
function stopNoteTimer() { clearInterval(noteHandle); noteHandle = null; }

/* ====
   NOTE VIEWER 
   ==== */
const textWrap    = document.getElementById('text-note-wrap');
const textBody    = document.getElementById('text-note-body');
const textTitleEl = document.getElementById('text-note-title');
const textTimer   = document.getElementById('text-note-timer');
const textDone    = document.getElementById('note-text-done-pill');
let textHandle=null, textSec=0, curTextNoteId=null;

function openTextNote(noteId, content, title) {
    closeNote(); stopTextTimer();
    curTextNoteId = noteId;
    textSec = 0;
    textBody.innerHTML = linkify(content);
    textTitleEl.textContent = '📝 ' + title;
    textTimer.textContent = '00:00';
    textTimer.classList.remove('done');
    textDone.classList.remove('show');
    const alreadyDone = noteMap[noteId] === 1;
    toggleMarkBtn('text', alreadyDone);
    if (alreadyDone) {
        textTimer.textContent = '00:30';
        textTimer.classList.add('done');
        textDone.classList.add('show');
    }
    textWrap.classList.add('active');
    textWrap.scrollIntoView({behavior:'smooth', block:'start'});
    saveNoteProgress(noteId, 0);

    if (!alreadyDone) {
        textHandle = setInterval(() => {
            textSec++;
            textTimer.textContent = fmtTime(textSec);
            updateNoteBar(noteId, textSec);
            if (textSec === NOTE_DONE_S) {
                textTimer.classList.add('done');
                textDone.classList.add('show');
                onNoteCompleted(noteId);
            }
            if (textSec % 5 === 0) saveNoteProgress(noteId, textSec);
        }, 1000);
    }
}

function closeTextNote() {
    if (curTextNoteId !== null) saveNoteProgress(curTextNoteId, textSec);
    stopTextTimer();
    textWrap.classList.remove('active');
    textBody.innerHTML = '';
    curTextNoteId = null;
}
function stopTextTimer() { clearInterval(textHandle); textHandle = null; }

function linkify(text) {
    const escaped = escHtml(text);
    const urlPattern = /(\bhttps?:\/\/[^\s<]+|\bwww\.[^\s<]+)/gi;
    return escaped.replace(urlPattern, (match) => {
        const href = match.startsWith('http') ? match : 'https://' + match;
        return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + match + '</a>';
    }).replace(/\n/g, '<br>');
}

function updateNoteBar(noteId, sec) {
    const fill = document.getElementById('nw-' + noteId);
    const lbl  = document.getElementById('ntime-' + noteId);
    const pct  = Math.min(100, Math.round((sec / NOTE_DONE_S) * 100));
    if (fill) fill.style.width = pct + '%';
    if (lbl)  lbl.textContent  = sec + 's read · ' + pct + '% complete';
}

function toggleMarkBtn(type, isDone) {
    const btn = document.getElementById(type === 'pdf' ? 'note-pdf-mark-btn' : 'note-text-mark-btn');
    if (!btn) return;
    btn.style.display = isDone ? 'none' : '';
}

function markNoteDone(type) {
    let noteId, sec;
    if (type === 'pdf') {
        if (curNoteId === null) return;
        noteId = curNoteId;
        noteSec = Math.max(noteSec, NOTE_DONE_S);
        sec = noteSec;
        stopNoteTimer();
        noteTimer.textContent = fmtTime(sec);
        noteTimer.classList.add('done');
        notePdfDone.classList.add('show');
    } else {
        if (curTextNoteId === null) return;
        noteId = curTextNoteId;
        textSec = Math.max(textSec, NOTE_DONE_S);
        sec = textSec;
        stopTextTimer();
        textTimer.textContent = fmtTime(sec);
        textTimer.classList.add('done');
        textDone.classList.add('show');
    }
    updateNoteBar(noteId, sec);
    saveNoteProgress(noteId, sec, true);
    onNoteCompleted(noteId);
    toggleMarkBtn(type, true);
}

// Track already-completed notes to avoid double-firing
const completedNotesSet = new Set(
    Object.entries(noteMap).filter(([,v]) => v === 1).map(([k]) => parseInt(k))
);

function onNoteCompleted(noteId) {
    if (completedNotesSet.has(noteId)) return;
    completedNotesSet.add(noteId);
    noteMap[noteId] = 1;

    const badge = document.getElementById('note-badge-' + noteId);
    if (badge) badge.style.display = '';

    const btn = document.getElementById('note-btn-' + noteId);
    if (btn) {
        btn.classList.add('done-btn');
        btn.innerHTML = btn.innerHTML
            .replace('Open PDF',  'Re-read PDF')
            .replace('Open Text', 'Re-read');
    }

    updateProgressUI();
}

function saveNoteProgress(noteId, seconds, forceDone) {
    let body = 'action=save_note_progress&note_id=' + noteId + '&seconds=' + seconds + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN);
    if (forceDone) body += '&force_done=1';
    fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body,
        keepalive:true
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) console.warn('Note progress NOT saved:', data);
    })
    .catch(err => console.error('Note progress save request failed:', err));
}

function fmtTime(s) {
    return String(Math.floor(s / 60)).padStart(2,'0') + ':' + String(s % 60).padStart(2,'0');
}

window.addEventListener('beforeunload', () => {
    if (curNoteId     !== null) saveNoteProgress(curNoteId,     noteSec);
    if (curTextNoteId !== null) saveNoteProgress(curTextNoteId, textSec);
});

/* ====
   STAR RATING
   ==== */
let selectedRating = 5;
function setRating(val) {
    selectedRating = val;
    document.getElementById('rating-val').value = val;
    document.querySelectorAll('#star-select button').forEach(b =>
        b.classList.toggle('on', parseInt(b.dataset.val) <= val));
}
setRating(5);

function sendReview() {
    const comment = document.getElementById('comment').value.trim();
    fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=add_review&course_id=<?= $course_id ?>&rating='+encodeURIComponent(selectedRating)+'&comment='+encodeURIComponent(comment)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)
    }).finally(() => {
        const t = document.getElementById('review-toast');
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 4000);
    });
}

/* ====
   QUIZ MODAL
   ==== */
let countdown;
document.addEventListener('keydown', e => { if (e.key==='Escape') closeQuiz(); });

function openQuiz(id) {
    const quiz = ALL_QUIZZES.find(q => parseInt(q.id) === id);
    if (!quiz) { alert('Quiz not found.'); return; }
    const qs = ALL_QUESTIONS.filter(q => parseInt(q.quiz_id) === id);

    let html = `<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
        <div>
            <h2 style="font-size:1.2rem;color:var(--text);">${escHtml(quiz.title)}</h2>
            <p style="color:var(--muted);font-size:0.87rem;margin-top:4px;">
                ${parseInt(quiz.questions)} questions &bull; ${parseInt(quiz.duration)} min &bull; Pass: ${parseInt(quiz.passing_score)}%
            </p>
        </div>
        <div class="quiz-timer" id="quizTimer">${String(quiz.duration).padStart(2,'0')}:00</div>
    </div>
    <form method="POST" action="course1.php?id=<?= $course_id ?>">
        <input type="hidden" name="csrf_token" value="${escHtml(CSRF_TOKEN)}">
        <input type="hidden" name="quiz_id" value="${parseInt(quiz.id)}">`;

    if (qs.length === 0) {
        html += '<p style="color:var(--muted);">No questions available.</p>';
    } else {
        qs.forEach((q, i) => {
            const qid = parseInt(q.id);
            html += `<div style="padding:16px;border:1px solid var(--border);border-radius:10px;margin-bottom:14px;">
                <p style="font-weight:700;margin-bottom:12px;font-size:0.95rem;">Q${i+1}: ${escHtml(q.question)}</p>
                ${['A','B','C','D'].map(opt => `
                    <label class="quiz-option">
                        <input type="radio" name="answer[${qid}]" value="${opt}" required>
                        <strong>${opt}.</strong> ${escHtml(q['option_'+opt.toLowerCase()] ?? '')}
                    </label>`).join('')}
            </div>`;
        });
    }
    html += `<button type="submit" name="submit_quiz" class="btn-primary" style="width:100%;justify-content:center;margin-top:8px;padding:12px;">
        <i class="fas fa-paper-plane"></i> Submit Quiz
    </button></form>`;

    document.getElementById('quizContainer').innerHTML = html;
    document.getElementById('quizModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    startTimer(parseInt(quiz.duration));
}

function startTimer(minutes) {
    clearInterval(countdown);
    let m = minutes, s = 0;
    countdown = setInterval(() => {
        const timer = document.getElementById('quizTimer');
        if (!timer) { clearInterval(countdown); return; }
        if (s === 0) {
            if (m === 0) { clearInterval(countdown); document.querySelector('#quizContainer form')?.submit(); return; }
            m--; s = 59;
        } else { s--; }
        timer.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        if (m < 2) timer.style.background = m < 1 ? '#dc2626' : '#f97316';
    }, 1000);
}

function closeQuiz() {
    clearInterval(countdown);
    document.getElementById('quizModal')?.classList.remove('active');
    document.body.style.overflow = '';
}

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str ?? '')));
    return d.innerHTML;
}
</script>
</body>
</html>