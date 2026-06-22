<?php
session_start();
include "db.php";
include "advance.php";

/* === AUTH === */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = (int) $_SESSION['user_id'];

/* === CSRF TOKEN === */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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

/* === COURSE ID === */
if (empty($_GET['id'])) {
    die("Course not found");
}
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
    readfile($file);
    exit;
}

/* === QUIZ SUBMISSION (full page POST, redirects back to course) === */
if (isset($_POST['submit_quiz'])) {
    validateCsrf();

    $roleCheck = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
    $roleCheck->bind_param("i", $user_id);
    $roleCheck->execute();
    $roleRow = $roleCheck->get_result()->fetch_assoc();
    $roleCheck->close();

    if (!$roleRow || $roleRow['role'] !== 'student') {
        die("❌ Unauthorized: only students can submit quizzes.");
    }

    $quiz_id    = (int) ($_POST['quiz_id'] ?? 0);
    $student_id = $user_id;

    /* Ensure the quiz actually belongs to this course */
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

        $total   = 0;
        $correct = 0;
        $valid_answers = ['A','B','C','D'];

        while ($question = $questions_result->fetch_assoc()) {
            $total++;
            $qid            = (int)$question['id'];
            $student_answer = $_POST['answer'][$qid] ?? '';
            if (in_array($student_answer, $valid_answers, true) &&
                $student_answer === $question['correct_answer']) {
                $correct++;
            }
        }

        $score      = ($total > 0) ? (int)round(($correct / $total) * 100) : 0;
        $status     = ($score >= (int)$quiz_data['passing_score']) ? 'passed' : 'failed';
        $quiz_title = $quiz_data['title'];

        $stmt = $conn->prepare("
            INSERT INTO quiz_results (student_id, user_id, quiz_id, quiz_title, score, status, completed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE score=VALUES(score), status=VALUES(status), completed_at=NOW()
        ");
        $stmt->bind_param("iiisis", $student_id, $student_id, $quiz_id, $quiz_title, $score, $status);
        $stmt->execute();
        $stmt->close();

        $_SESSION['quiz_success'] = "Quiz Submitted! Score: {$score}%";
    }

    header("Location: course.php?id=" . $course_id);
    exit;
}

/* === AJAX HANDLER === */
if (isset($_POST['action'])) {

    if ($_POST['action'] === 'save_progress' && $user_id) {
        $content_id = intval($_POST['content_id']);
        $watched    = min(100, max(0, intval($_POST['watched'])));
        if ($content_id) {
            $completed = ($watched >= 90) ? 1 : 0;
            $stmt = $conn->prepare("
                INSERT INTO video_progress (user_id, content_id, watched_percentage, completed)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    watched_percentage = VALUES(watched_percentage),
                    completed          = VALUES(completed),
                    updated_at         = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param("iiii", $user_id, $content_id, $watched, $completed);
            $stmt->execute();
            $stmt->close();
        }
        exit;
    }

    if ($_POST['action'] === 'add_review' && $user_id) {
        $review_course_id = intval($_POST['course_id'] ?? $course_id);
        $rating           = min(5, max(1, intval($_POST['rating'])));
        $comment          = trim($_POST['comment'] ?? '');
        $stmt = $conn->prepare("
            INSERT INTO course_reviews (user_id, course_id, rating, review)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), review = VALUES(review)
        ");
        $stmt->bind_param("iiis", $user_id, $review_course_id, $rating, $comment);
        $stmt->execute();
        $stmt->close();

        $avg_stmt = $conn->prepare("SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM course_reviews WHERE course_id = ?");
        $avg_stmt->bind_param("i", $review_course_id);
        $avg_stmt->execute();
        $avg_data = $avg_stmt->get_result()->fetch_assoc();
        $avg_stmt->close();
        header('Content-Type: application/json');
        echo json_encode($avg_data);
        exit;
    }

    exit;
}

/* === COURSE === */
$course_query = mysqli_query($conn, "
    SELECT c.*, u.full_name AS instructor
    FROM courses c
    LEFT JOIN teachers t ON t.id = c.teacher_id
    LEFT JOIN users u ON u.id = t.user_id
    WHERE c.id = $course_id
");
if (!$course_query || mysqli_num_rows($course_query) === 0) die("Course not found");
$course = mysqli_fetch_assoc($course_query);

/* === PROGRESS === */
$progress_query = mysqli_query($conn, "
    SELECT IFNULL(AVG(vp.watched_percentage), 0) AS progress
    FROM video_progress vp
    JOIN course_contents cc ON cc.id = vp.content_id
    WHERE vp.user_id = $user_id AND cc.course_id = $course_id
");
$progress = 0;
if ($progress_query) $progress = (int)round(mysqli_fetch_assoc($progress_query)['progress'] ?? 0);

/* === RATING === */
$rating_query = mysqli_query($conn, "SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM course_reviews WHERE course_id = $course_id");
$rating_data  = mysqli_fetch_assoc($rating_query);
$avg_rating   = $rating_data['avg_rating'] ?? 0;
$total_ratings = $rating_data['total'] ?? 0;

/* === NOTES === */
$notes = mysqli_query($conn, "SELECT * FROM notes WHERE course_id = $course_id ORDER BY id DESC");

/* === VIDEOS === */
$videos = mysqli_query($conn, "SELECT * FROM course_videos WHERE course_id = $course_id ORDER BY id ASC");

/* === QUIZZES — scoped to THIS course only === */
$quizzes = mysqli_query($conn, "SELECT * FROM quizzes WHERE course_id = $course_id ORDER BY id DESC");

/* === CONTENTS === */
$contents = mysqli_query($conn, "SELECT * FROM course_contents WHERE course_id = $course_id ORDER BY id ASC");

/* === Build content_map === */
$content_map = [];
if ($contents) {
    while ($row = mysqli_fetch_assoc($contents)) {
        if (!empty($row['video_id'])) $content_map[$row['video_id']] = $row['id'];
    }
    mysqli_data_seek($contents, 0);
}

$video_count = mysqli_num_rows($videos);
$note_count  = mysqli_num_rows($notes);
$quiz_count  = mysqli_num_rows($quizzes);

/* === BASE URL for videos — fix for XAMPP === */
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';

/* === QUIZ DATA (for modal) — quizzes + questions for THIS course only === */
$all_quizzes = [];
$qres = $conn->prepare("SELECT * FROM quizzes WHERE course_id = ? ORDER BY id DESC");
$qres->bind_param("i", $course_id);
$qres->execute();
$qres_result = $qres->get_result();
while ($row = $qres_result->fetch_assoc()) { $all_quizzes[] = $row; }
$qres->close();

$all_questions = [];
if (!empty($all_quizzes)) {
    $quiz_ids = array_map(fn($q) => (int)$q['id'], $all_quizzes);
    $in_clause = implode(',', $quiz_ids);
    $qres2 = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id IN ($in_clause)");
    while ($row = $qres2->fetch_assoc()) { $all_questions[] = $row; }
}

/* === QUIZ STATS for this user, scoped to this course's quizzes === */
$total_quizzes = 0; $passed = 0; $failed = 0; $avgScore = 0;
if (!empty($all_quizzes)) {
    $quiz_ids = array_map(fn($q) => (int)$q['id'], $all_quizzes);
    $in_clause = implode(',', $quiz_ids);
    $statRes = $conn->query("
        SELECT COUNT(*) AS total_quizzes,
               COALESCE(SUM(status='passed'),0) AS passed,
               COALESCE(SUM(status='failed'),0) AS failed,
               COALESCE(AVG(score),0)           AS avg_score
        FROM quiz_results
        WHERE user_id = $user_id AND quiz_id IN ($in_clause)
    ");
    $quizStats = $statRes->fetch_assoc();
    $total_quizzes = (int)($quizStats['total_quizzes'] ?? 0);
    $passed        = (int)($quizStats['passed']        ?? 0);
    $failed        = (int)($quizStats['failed']        ?? 0);
    $avgScore      = round((float)($quizStats['avg_score'] ?? 0), 1);
}

/* Recent quiz results (this user, this course's quizzes) */
$results = null;
if (!empty($all_quizzes)) {
    $quiz_ids = array_map(fn($q) => (int)$q['id'], $all_quizzes);
    $in_clause = implode(',', $quiz_ids);
    $results = $conn->query("
        SELECT * FROM quiz_results
        WHERE user_id = $user_id AND quiz_id IN ($in_clause)
        ORDER BY completed_at DESC LIMIT 5
    ");
}

/* Quiz history */
$stmt = $conn->prepare("SELECT * FROM quiz_history WHERE user_id=? ORDER BY completed_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$history = $stmt->get_result(); $stmt->close();

/* Quiz achievements */
$stmt = $conn->prepare("SELECT * FROM quiz_achievements WHERE user_id=?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$achievements = $stmt->get_result(); $stmt->close();

/* Certificates */
$stmt = $conn->prepare("SELECT * FROM certificates WHERE user_id=? ORDER BY issued_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$certs = $stmt->get_result(); $stmt->close();

/* Flash messages */
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$quiz_success = $_SESSION['quiz_success'] ?? '';
unset($_SESSION['quiz_success']);

$csrf_js = json_encode($_SESSION['csrf_token'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($course['title']) ?> — LearnHub</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg:        #f5f4f9;
    --surface:   #ffffff;
    --surface2:  #f0eef8;
    --border:    rgba(108,99,255,0.12);
    --border-md: rgba(108,99,255,0.22);
    --text:      #1a1830;
    --muted:     #6b6888;
    --accent:    #5b52e8;
    --accent2:   #7c6ff5;
    --accent-lt: #eeecfd;
    --green:     #16a34a;
    --green-lt:  #dcfce7;
    --amber:     #d97706;
    --amber-lt:  #fef3c7;
    --red:       #dc2626;
    --shadow-sm: 0 1px 4px rgba(91,82,232,0.07);
    --shadow-md: 0 4px 20px rgba(91,82,232,0.10);
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 18px;
    --transition: 0.25s ease;
}
html { scroll-behavior: smooth; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, 'Segoe UI', sans-serif;
    font-size: 15px;
    line-height: 1.6;
    min-height: 100vh;
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
img { display: block; max-width: 100%; }

/* ── LAYOUT ── */
.page-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px 80px;
}
.hero {
    background: linear-gradient(130deg, #5b52e8 0%, #7c6ff5 50%, #a78bfa 100%);
    padding: 6px 0 4px;
    margin-bottom: 10px;
    height: 12rem;
    justify-items:center;
}
.hero-inner {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px;
}
.hero-meta { display: flex; 
    align-items: center; 
    gap: 5px;
    margin-bottom: 10px;
    flex-wrap: wrap;
    margin-left:5rem;
 }
.badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,0.2);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
    font-size: 12px; font-weight: 600;
    padding: 4px 12px; border-radius: 20px;
    letter-spacing: 0.2px;
}
.badge-green {
    background: rgba(22,163,74,0.2);
    border-color: rgba(22,163,74,0.4);
}
.hero h1 {
    font-size: clamp(22px, 4vw, 40px);
    font-weight: 700; line-height: 1.2;
    color: #fff;
    margin-bottom: 10px;
    text-align:center;
}
.hero-desc { color: rgba(255,255,255,0.82); max-width: 640px; margin-bottom: 15px; text-align:center;font-size: 15px; }
.hero-stats {
    display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
    font-size: 13px; color: rgba(255,255,255,0.75);
}
.hero-stats span { display: flex; align-items: center; gap: 6px; }
.hero-stats .dot { width: 3px; height: 3px; background: rgba(255,255,255,0.4); border-radius: 50%; }
.hero-stats strong { color: #fff; }
.stars { color: #fcd34d; letter-spacing: 0px; font-size: 15px; }

/* ── GRID ── */
.main-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 860px) { .main-grid { grid-template-columns: 1fr; } }

/* ── CARD ── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 20px;
}
.card-header {
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.card-header h2 { font-size: 15px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.card-header h2 i { color: var(--accent); }
.card-header .count {
    font-size: 12px; color: var(--muted);
    background: var(--surface2);
    padding: 2px 10px; border-radius: 20px;
    border: 1px solid var(--border);
    font-weight: 500;
}

/* ── FLASH ── */
.flash {
    background: var(--green-lt); color: var(--green);
    border: 1px solid rgba(22,163,74,0.25);
    border-radius: var(--radius-sm);
    padding: 13px 18px;
    margin: 0 22px 18px;
    font-weight: 600;
}

/* ── PROGRESS ── */
.progress-wrap { padding: 20px 22px; }
.progress-numbers { display: flex; align-items: baseline; gap: 6px; margin-bottom: 12px; }
.progress-numbers .big { font-size: 34px; font-weight: 700; color: var(--green); }
.progress-numbers .small { font-size: 14px; color: var(--muted); }
.progress-bar-track {
    width: 100%; height: 10px;
    background: var(--surface2);
    border-radius: 10px; overflow: hidden;
    border: 1px solid var(--border);
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #16a34a, #22c55e);
    border-radius: 10px;
    transition: width 1s ease;
}
.progress-label { margin-top: 10px; font-size: 13px; color: var(--muted); }

/* ── VIDEO GRID ── */
.video-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 14px;
    padding: 18px 22px;
}
.video-card {
    background: var(--surface2);
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1.5px solid var(--border);
    transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s;
    cursor: pointer;
}
.video-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
    border-color: var(--accent2);
}
.video-card.active {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(91,82,232,0.15);
}
.video-thumb {
    position: relative;
    background: #1a1830;
    aspect-ratio: 16/9;
    display: flex; align-items: center; justify-content: center;
}
.video-thumb .play-overlay {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(26,24,48,0.35);
    transition: opacity 0.2s;
}
.video-thumb .play-btn {
    width: 46px; height: 46px;
    background: var(--accent);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 16px rgba(91,82,232,0.45);
    transition: transform 0.15s;
}
.video-card:hover .play-btn { transform: scale(1.1); }
.video-thumb .play-btn svg { width: 18px; height: 18px; fill: #fff; margin-left: 3px; }
.video-info { padding: 12px 14px; }
.video-num {
    font-size: 11px; color: var(--accent); font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.7px; margin-bottom: 4px;
}
.video-title { font-size: 13px; font-weight: 600; line-height: 1.4; color: var(--text); }
.video-watched {
    margin-top: 10px; height: 4px;
    background: var(--border);
    border-radius: 4px; overflow: hidden;
}
.video-watched-fill { height: 100%; background: var(--accent); border-radius: 4px; transition: width 0.3s; }

/* ── MAIN PLAYER ── */
#player-wrap {
    display: none;
    margin: 0 22px 0;
}
#player-wrap.active { display: block; }
#main-player {
    background: #0a0916;
    border-radius: var(--radius-md);
    overflow: hidden;
    aspect-ratio: 16/9;
    border: 1px solid var(--border-md);
    box-shadow: var(--shadow-md);
}
#main-player video { width: 100%; height: 100%; display: block; }
#player-title {
    padding: 12px 4px 18px;
    font-size: 14px; font-weight: 600; color: var(--text);
    display: none;
}
#player-title.active { display: block; }

/* ── NOTES ── */
.note-list { padding: 6px 22px 18px; }
.note-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 0; border-bottom: 1px solid var(--border); gap: 12px;
}
.note-item:last-child { border-bottom: none; }
.note-item-left { display: flex; align-items: center; gap: 12px; }
.note-icon {
    width: 40px; height: 40px; flex-shrink: 0;
    background: var(--accent-lt);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    border: 1px solid var(--border);
}
.note-name { font-size: 14px; font-weight: 600; color: var(--text); }
.note-sub  { font-size: 12px; color: var(--muted); margin-top: 2px; }

.btn-outline {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; font-size: 12px; font-weight: 600;
    border: 1.5px solid var(--border-md);
    background: var(--surface); color: var(--accent);
    border-radius: var(--radius-sm); cursor: pointer;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
    text-decoration: none; white-space: nowrap;
}
.btn-outline:hover { background: var(--accent-lt); border-color: var(--accent); text-decoration: none; }

/* ── QUIZZES ── */
.quiz-list { padding: 6px 22px 18px; }
.quiz-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 0; border-bottom: 1px solid var(--border); gap: 12px;
}
.quiz-item:last-child { border-bottom: none; }
.quiz-item-left { display: flex; align-items: center; gap: 12px; }
.quiz-icon {
    width: 40px; height: 40px; flex-shrink: 0;
    background: var(--amber-lt);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center; font-size: 18px;
    border: 1px solid rgba(217,119,6,0.15);
}
.quiz-name { font-size: 14px; font-weight: 600; color: var(--text); }
.quiz-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
.btn-primary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; font-size: 13px; font-weight: 700;
    background: var(--accent); color: #fff;
    border: none; border-radius: var(--radius-sm); cursor: pointer;
    transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
    text-decoration: none; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(91,82,232,0.3);
}
.btn-primary:hover {
    background: #4841d4;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(91,82,232,0.4);
    text-decoration: none; color: #fff;
}

/* ── QUIZ STATS GRID (sidebar widget) ── */
.quiz-stats-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 10px; padding: 18px 22px;
}
.quiz-stat-box {
    background: var(--surface2);
    border-radius: var(--radius-md);
    padding: 14px 12px; text-align: center;
    border: 1px solid var(--border);
    border: 1px solid var(--accent);
}
.quiz-stat-box.green { border: var(--green); }
.quiz-stat-box.red   { border: var(--red); }
.quiz-stat-box i { font-size: 1.1rem; color: var(--accent); margin-bottom: 6px; display: block; }
.quiz-stat-box.green i { color: var(--green); }
.quiz-stat-box.red i { color: var(--red); }
.quiz-stat-box .val { font-size: 20px; font-weight: 700; color: var(--text); }
.quiz-stat-box .lbl { font-size: 11px; color: var(--muted); margin-top: 3px; font-weight: 500; }

/* ── ACTIVITY ITEMS (history / achievements / certs) ── */
.activity-item {
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--accent);
    background: var(--surface2);
    box-shadow:0px 0px 2px ;
    margin: 0 22px 10px;
    transition: transform 0.2s;
}
.activity-item:last-child { margin-bottom: 18px; }
.activity-item:hover { transform: translateX(4px); }
.activity-item p    { font-weight: 600; font-size: 0.9rem; color: var(--text); margin-bottom: 2px; }
.activity-item span { color: var(--muted); font-size: 0.8rem; }

/* ── SIDEBAR STATS ── */
.stat-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 10px; padding: 18px 22px;
}
.stat-box {
    background: var(--surface2);
    border-radius: var(--radius-md);
    padding: 14px 12px; text-align: center;
    border: 1px solid var(--border);
}
.stat-box .val { font-size: 22px; font-weight: 700; color: var(--accent); }
.stat-box .lbl { font-size: 11px; color: var(--muted); margin-top: 3px; font-weight: 500; }

/* ── RATING ── */
.rating-section { padding: 14px 22px 22px; }
.rating-current {
    display: flex; align-items: center; gap: 12px;
    background: var(--surface2);
    border-radius: var(--radius-md);
    padding: 16px; margin-bottom: 18px;
    border: 1px solid var(--border);
}
.rating-big { font-size: 38px; font-weight: 700; line-height: 1; color: var(--text); }
.rating-sub { font-size: 12px; color: var(--muted); margin-top: 4px; font-weight: 500; }
.star-select { display: flex; gap: 4px; margin-bottom: 12px; }
.star-select button {
    background: none; border: none; font-size: 26px; cursor: pointer;
    opacity: 0.25; transition: opacity 0.15s, transform 0.15s;
    padding: 0; line-height: 1;
}
.star-select button.on { opacity: 1; }
.star-select button:hover { transform: scale(1.2); opacity: 1; }
.rating-textarea {
    width: 100%; background: var(--surface2);
    border: 1.5px solid var(--border-md);
    color: var(--text); border-radius: var(--radius-sm);
    padding: 10px 12px; font-size: 13px; resize: vertical;
    min-height: 80px; font-family: inherit;
    transition: border-color 0.15s;
}
.rating-textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(91,82,232,0.1); }
.rating-textarea::placeholder { color: var(--muted); }
.rating-submit {
    width: 100%; margin-top: 12px;
    padding: 11px; font-size: 14px; font-weight: 700;
    background: var(--accent); color: #fff;
    border: none; border-radius: var(--radius-sm); cursor: pointer;
    transition: background 0.15s, box-shadow 0.15s;
    box-shadow: 0 2px 8px rgba(91,82,232,0.25);
}
.rating-submit:hover { background: #4841d4; box-shadow: 0 4px 14px rgba(91,82,232,0.35); }
.review-toast {
    display: none; margin-top: 10px; padding: 10px 14px;
    background: var(--green-lt); color: var(--green);
    border: 1px solid rgba(22,163,74,0.25);
    border-radius: var(--radius-sm); font-size: 13px; text-align: center; font-weight: 600;
}
.review-toast.show { display: block; }

/* ── INSTRUCTOR ── */
.instructor-card {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 22px;
}
.avatar {
    width: 48px; height: 48px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 19px; font-weight: 700; color: #fff;
    box-shadow: 0 2px 10px rgba(91,82,232,0.3);
}
.instructor-name { font-weight: 700; font-size: 15px; color: var(--text); }
.instructor-role { font-size: 12px; color: var(--muted); font-weight: 500; }

/* ── EMPTY ── */
.empty { padding: 36px 22px; text-align: center; color: var(--muted); font-size: 14px; }
.empty-icon { font-size: 34px; margin-bottom: 8px; opacity: 0.45; }
.empty p { margin-top: 6px; }

/* ── VIDEO ERROR NOTICE ── */
.video-error {
    background: #fef2f2; color: #991b1b;
    border: 1px solid #fecaca;
    border-radius: var(--radius-sm);
    padding: 10px 14px; font-size: 13px; margin: 0 22px 14px;
    display: none;
}
.video-error.show { display: block; }

/* ── QUIZ MODAL ── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(10,9,22,0.65);
    z-index: 9000;
    align-items: center;
    justify-content: center;
    padding: 16px;
    overflow-y: auto;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: 30px;
    width: 100%;
    max-width: 860px;
    position: relative;
    animation: popIn 0.2s ease;
    box-shadow: var(--shadow-md);
}
@keyframes popIn { from { transform: scale(0.93); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-close {
    position: absolute; top: 14px; right: 16px;
    width: 30px; height: 30px;
    background: var(--surface2); border: none;
    border-radius: 50%; cursor: pointer;
    font-size: 1rem; display: flex; align-items: center; justify-content: center;
    transition: background 0.2s, color 0.2s;
}
.modal-close:hover { background: var(--red); color: #fff; }

.quiz-option {
    display: block;
    background: var(--surface2);
    border: 2px solid var(--border);
    border-radius: 9px;
    padding: 12px 14px;
    margin-bottom: 10px;
    cursor: pointer;
    font-size: 0.92rem;
    transition: border-color 0.2s, background 0.2s;
}
.quiz-option:hover { border-color: var(--accent); background: var(--accent-lt); }
.quiz-option input { margin-right: 10px; accent-color: var(--accent); }

.quiz-timer {
    background: var(--red); color: #fff;
    padding: 8px 16px; border-radius: 9px;
    font-weight: 700; font-size: 1rem;
    min-width: 80px; text-align: center;
}
</style>
</head>
<body>

<!-- HERO -->
<section class="hero">
    <div class="hero-inner">
        <div class="hero-meta">
            <span class="badge"> Course</span>
            <?php if ($progress >= 90): ?>
                <span class="badge badge-green">✓ Completed</span>
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
                    <strong><?= $avg_rating ?></strong>
                    <span>(<?= $total_ratings ?> ratings)</span>
                </span>
                <span class="dot"></span>
            <?php endif; ?>
            <span><?= $video_count ?> videos</span>
            <span class="dot"></span>
            <span> <?= $note_count ?> notes</span>
            <span class="dot"></span>
            <span><?= $quiz_count ?> quizzes</span>
        </div>
    </div>
</section>

<div class="page-wrap">
<div class="main-grid">

<!-- LEFT COLUMN -->
<div class="left-col">

    <?php if ($flash): ?>
        <div class="flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($quiz_success): ?>
        <div class="flash"><?= htmlspecialchars($quiz_success) ?></div>
    <?php endif; ?>

    <!-- VIDEOS -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-film"></i> Course Videos</h2>
            <span class="count"><?= $video_count ?> lessons</span>
        </div>

        <!-- Video error notice -->
        <div class="video-error" id="video-error">
            ⚠️ Video could not be loaded. Make sure the file exists in
            <code>uploads/videos/</code> on your server.
        </div>

        <!-- Main player -->
        <div id="player-wrap">
            <div id="main-player">
                <video id="main-video" controls></video>
            </div>
            <p id="player-title"></p>
        </div>

        <?php if (mysqli_num_rows($videos) > 0):
            mysqli_data_seek($videos, 0);
        ?>
        <div class="video-row">
            <?php $vn = 0; while ($video = mysqli_fetch_assoc($videos)): $vn++;
                $content_id = $content_map[$video['id']] ?? $video['id'];
                if (empty($video['video_path'])) continue;

                /*
                 * Build the correct absolute URL to the video.
                 * Files are served from uploads/videos/ regardless of
                 * how the path was stored (filename only or relative path).
                 */
                $filename    = basename($video['video_path']);
                $video_url   = $base_url . 'uploads/videos/' . rawurlencode($filename);
            ?>
            <div class="video-card"
                 data-src="<?= htmlspecialchars($video_url) ?>"
                 data-title="<?= htmlspecialchars($video['title']) ?>"
                 data-id="<?= (int)$content_id ?>"
                 onclick="playVideo(this)">
                <div class="video-thumb">
                    <div class="play-overlay">
                        <div class="play-btn">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <polygon points="5,3 19,12 5,21"/>
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="video-info">
                    <div class="video-num">Lesson <?= $vn ?></div>
                    <div class="video-title"><?= htmlspecialchars($video['title']) ?></div>
                    <div class="video-watched">
                        <div class="video-watched-fill" style="width:0%" id="vw-<?= (int)$content_id ?>"></div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon">🎬</div>
                <p>No videos added yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- NOTES -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-file-alt"></i> Course Notes</h2>
            <span class="count"><?= $note_count ?> files</span>
        </div>

        <?php if (mysqli_num_rows($notes) > 0):
            mysqli_data_seek($notes, 0);
        ?>
        <div class="note-list">
            <?php while ($note = mysqli_fetch_assoc($notes)): ?>
            <div class="note-item">
                <div class="note-item-left">
                    <div class="note-icon">📄</div>
                    <div>
                        <div class="note-name"><?= htmlspecialchars($note['title']) ?></div>
                        <?php if (!empty($note['content'])): ?>
                            <div class="note-sub"><?= htmlspecialchars(mb_substr($note['content'], 0, 60)) ?>…</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($note['file_path'])): ?>
                    <a class="btn-outline"
                       href="?id=<?= $course_id ?>&note=<?= (int)$note['id'] ?>"
                       target="_blank">
                       📥 Open PDF
                    </a>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon">📄</div>
                <p>No notes available yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- QUIZZES — only quizzes belonging to this course -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-question-circle"></i> Quizzes</h2>
            <span class="count"><?= $quiz_count ?> quizzes</span>
        </div>

        <?php if (mysqli_num_rows($quizzes) > 0):
            mysqli_data_seek($quizzes, 0);
        ?>
        <div class="quiz-list">
            <?php while ($quiz = mysqli_fetch_assoc($quizzes)): ?>
            <div class="quiz-item">
                <div class="quiz-item-left">
                    <div class="quiz-icon">📝</div>
                    <div>
                        <div class="quiz-name"><?= htmlspecialchars($quiz['title']) ?></div>
                        <div class="quiz-meta">
                            ⏱ <?= (int)$quiz['duration'] ?> min &nbsp;·&nbsp;
                            🎯 Pass at <?= (int)$quiz['passing_score'] ?>%
                        </div>
                    </div>
                </div>
                <button class="btn-primary" onclick="openQuiz(<?= (int)$quiz['id'] ?>)">Start →</button>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon">📝</div>
                <p>No quizzes for this course yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- QUIZ HISTORY -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-history"></i> Quiz History</h2></div>
        <?php $hasHistory = false; while ($h = $history->fetch_assoc()): $hasHistory = true; ?>
            <div class="activity-item">
                <p><?= htmlspecialchars($h['title'] ?? '') ?></p>
                <span>Completed <?= htmlspecialchars($h['completed_at'] ?? '') ?></span>
            </div>
        <?php endwhile; ?>
        <?php if (!$hasHistory): ?><div class="activity-item"><p>No history yet</p></div><?php endif; ?>
    </div>

    <!-- ACHIEVEMENTS -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-trophy"></i> Top Performance</h2></div>
        <?php $hasA = false; while ($a = $achievements->fetch_assoc()): $hasA = true; ?>
            <div class="activity-item">
                <p><?= htmlspecialchars($a['title'] ?? '') ?></p>
                <span><?= htmlspecialchars($a['value'] ?? '') ?></span>
            </div>
        <?php endwhile; ?>
        <?php if (!$hasA): ?><div class="activity-item"><p>No achievements yet</p></div><?php endif; ?>
    </div>

    <!-- CERTIFICATES -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-award"></i> Certification Exams</h2></div>
        <?php $hasCerts = false; while ($c = $certs->fetch_assoc()): $hasCerts = true; ?>
            <div class="activity-item">
                <p><?= htmlspecialchars($c['title'] ?? '') ?></p>
                <span><?= htmlspecialchars($c['status'] ?? '') ?></span>
            </div>
        <?php endwhile; ?>
        <?php if (!$hasCerts): ?><div class="activity-item"><p>No certifications yet</p></div><?php endif; ?>
    </div>

</div>

<!-- RIGHT SIDEBAR -->
<div class="right-col">

    <!-- PROGRESS -->
    <div class="card">
        <div class="card-header"><h2>Your progress</h2></div>
        <div class="progress-wrap">
            <div class="progress-numbers">
                <span class="big"><?= $progress ?>%</span>
                <span class="small">completed</span>
            </div>
            <div class="progress-bar-track">
                <div class="progress-bar-fill" style="width:<?= $progress ?>%"></div>
            </div>
            <p class="progress-label">
                <?php if ($progress === 0): ?>
                    Start watching to track your progress.
                <?php elseif ($progress < 50): ?>
                    Just getting started — keep going!
                <?php elseif ($progress < 90): ?>
                    More than halfway there. Great work!
                <?php else: ?>
                    🎉 You've completed this course!
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- COURSE STATS -->
    <div class="card">
        <div class="card-header"><h2>Course overview</h2></div>
        <div class="stat-grid">
            <div class="stat-box"><div class="val"><?= $video_count ?></div><div class="lbl">Videos</div></div>
            <div class="stat-box"><div class="val"><?= $note_count ?></div><div class="lbl">Notes</div></div>
            <div class="stat-box"><div class="val"><?= $quiz_count ?></div><div class="lbl">Quizzes</div></div>
            <div class="stat-box">
                <div class="val"><?= $avg_rating > 0 ? $avg_rating : '—' ?></div>
                <div class="lbl">Avg rating</div>
            </div>
        </div>
    </div>

    <!-- QUIZ PERFORMANCE -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-chart-line"></i> Your quiz stats</h2></div>
        <div class="quiz-stats-grid">
            <div class="quiz-stat-box"><i class="fas fa-file-alt"></i><div class="val"><?= $total_quizzes ?></div><div class="lbl">Total</div></div>
            <div class="quiz-stat-box green"><i class="fas fa-check-circle"></i><div class="val"><?= $passed ?></div><div class="lbl">Passed</div></div>
            <div class="quiz-stat-box red"><i class="fas fa-times-circle"></i><div class="val"><?= $failed ?></div><div class="lbl">Failed</div></div>
            <div class="quiz-stat-box"><i class="fas fa-chart-line"></i><div class="val"><?= $avgScore ?>%</div><div class="lbl">Avg Score</div></div>
        </div>
        <div style="padding:0 22px 18px;">
            <?php
            $hasResults = false;
            if ($results) { while ($r = $results->fetch_assoc()): $hasResults = true; ?>
                <div class="activity-item" style="margin:0 0 10px;">
                    <p><?= htmlspecialchars($r['quiz_title'] ?? 'Quiz') ?></p>
                    <span>Score: <?= (int)$r['score'] ?>% • <?= ucfirst($r['status']) ?></span>
                </div>
            <?php endwhile; } ?>
            <?php if (!$hasResults): ?><div class="activity-item" style="margin:0;"><p>No results yet</p></div><?php endif; ?>
        </div>
    </div>

    <!-- INSTRUCTOR -->
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

    <!-- RATING -->
    <div class="card">
        <div class="card-header"><h2>Rate this course</h2></div>
        <div class="rating-section">
            <?php if ($avg_rating > 0): ?>
            <div class="rating-current">
                <div style="flex:1; text-align:center;">
                    <div class="rating-big"><?= $avg_rating ?></div>
                    <div class="stars" style="font-size:18px; margin-top:6px;">
                        <?= str_repeat('★', round($avg_rating)) ?><?= str_repeat('☆', 5 - round($avg_rating)) ?>
                    </div>
                    <div class="rating-sub"><?= $total_ratings ?> rating<?= $total_ratings !== 1 ? 's' : '' ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($user_id): ?>
            <div class="star-select" id="star-select">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)">⭐</button>
                <?php endfor; ?>
            </div>
            <input type="hidden" id="rating-val" value="5">

            <textarea class="rating-textarea" id="comment"
                      placeholder="Share your thoughts about this course…"></textarea>
            <button class="rating-submit" onclick="sendReview()">Submit review</button>
            <div class="review-toast" id="review-toast">✓ Review submitted — thank you!</div>
            <?php else: ?>
                <p style="font-size:13px;color:var(--muted);">
                    <a href="login.php">Sign in</a> to leave a review.
                </p>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>
</div>

<!-- QUIZ MODAL -->
<div id="quizModal" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Quiz">
    <div class="modal-box">
        <button class="modal-close" onclick="closeQuiz()" aria-label="Close quiz">✕</button>
        <div id="quizContainer"></div>
    </div>
</div>

<script>
/* ─── DATA FOR QUIZ MODAL (scoped to this course) ─── */
const ALL_QUIZZES   = <?= json_encode($all_quizzes,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const ALL_QUESTIONS = <?= json_encode($all_questions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const CSRF_TOKEN    = <?= $csrf_js ?>;

/* ─── VIDEO PLAYER ─── */
const mainVideo   = document.getElementById('main-video');
const playerWrap  = document.getElementById('player-wrap');
const playerTitle = document.getElementById('player-title');
const videoError  = document.getElementById('video-error');

function playVideo(card) {
    document.querySelectorAll('.video-card').forEach(c => c.classList.remove('active'));
    card.classList.add('active');

    const src   = card.dataset.src;
    const title = card.dataset.title;
    const cid   = parseInt(card.dataset.id, 10);

    videoError.classList.remove('show');

    playerWrap.classList.add('active');
    playerTitle.textContent = '▶  ' + title;
    playerTitle.classList.add('active');

    mainVideo.src = src;
    mainVideo.load();
    mainVideo.play().catch(function() {
        /* Autoplay blocked — user will click the play button manually, that's fine */
    });

    mainVideo.onerror = function() {
        videoError.classList.add('show');
        console.error('Video failed to load:', src);
    };

    playerWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });

    mainVideo.ontimeupdate = null;
    mainVideo.ontimeupdate = function() {
        if (!mainVideo.duration) return;
        const pct  = Math.floor((mainVideo.currentTime / mainVideo.duration) * 100);
        const fill = document.getElementById('vw-' + cid);
        if (fill) fill.style.width = pct + '%';
        saveProgress(cid, pct);
    };
}

let lastSent = {};
function saveProgress(cid, pct) {
    if (lastSent[cid] === pct) return;
    lastSent[cid] = pct;
    fetch(location.href, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : 'action=save_progress&content_id=' + cid + '&watched=' + pct
    });
}

/* ─── STAR RATING ─── */
let selectedRating = 5;
function setRating(val) {
    selectedRating = val;
    document.getElementById('rating-val').value = val;
    document.querySelectorAll('#star-select button').forEach(function(btn) {
        btn.classList.toggle('on', parseInt(btn.dataset.val) <= val);
    });
}
setRating(5);

/* ─── SUBMIT REVIEW ─── */
function sendReview() {
    const comment = document.getElementById('comment').value.trim();
    fetch(location.href, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : 'action=add_review'
               + '&course_id=<?= $course_id ?>'
               + '&rating='  + encodeURIComponent(selectedRating)
               + '&comment=' + encodeURIComponent(comment)
    }).then(function(res) { return res.json(); })
      .then(function() {
        const toast = document.getElementById('review-toast');
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 4000);
    }).catch(function() {
        const toast = document.getElementById('review-toast');
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 4000);
    });
}

/* ─── QUIZ MODAL (take quiz inline, no separate page) ─── */
let countdown;

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeQuiz();
});

function openQuiz(id) {
    const quiz = ALL_QUIZZES.find(q => parseInt(q.id) === id);
    if (!quiz) { alert('Quiz not found.'); return; }

    const qs = ALL_QUESTIONS.filter(q => parseInt(q.quiz_id) === id);

    let html = `
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
            <div>
                <h2 style="font-size:1.2rem;color:var(--text);">${escHtml(quiz.title)}</h2>
                <p style="color:var(--muted);font-size:0.87rem;margin-top:4px;">
                    ${parseInt(quiz.questions)} questions &bull; ${parseInt(quiz.duration)} min &bull; Passing: ${parseInt(quiz.passing_score)}%
                </p>
            </div>
            <div class="quiz-timer" id="quizTimer">${String(quiz.duration).padStart(2,'0')}:00</div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="${escHtml(CSRF_TOKEN)}">
            <input type="hidden" name="quiz_id" value="${parseInt(quiz.id)}">
    `;

    if (qs.length === 0) {
        html += '<p style="color:var(--muted);">No questions available for this quiz.</p>';
    } else {
        qs.forEach((q, i) => {
            const qid = parseInt(q.id);
            html += `
            <div style="padding:16px;border:1px solid var(--border);border-radius:10px;margin-bottom:14px;">
                <p style="font-weight:700;margin-bottom:12px;font-size:0.95rem;">Q${i+1}: ${escHtml(q.question)}</p>
                ${['A','B','C','D'].map(opt => `
                    <label class="quiz-option">
                        <input type="radio" name="answer[${qid}]" value="${opt}" required>
                        <strong>${opt}.</strong> ${escHtml(q['option_'+opt.toLowerCase()] ?? '')}
                    </label>
                `).join('')}
            </div>`;
        });
    }

    html += `<button type="submit" name="submit_quiz" class="btn-primary" style="width:100%;justify-content:center;margin-top:8px;padding:12px;"><i class="fas fa-paper-plane"></i> Submit Quiz</button></form>`;

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
            if (m === 0) {
                clearInterval(countdown);
                const form = document.querySelector('#quizContainer form');
                if (form) form.submit();
                return;
            }
            m--; s = 59;
        } else { s--; }
        timer.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        if (m < 2) timer.style.background = m < 1 ? '#dc2626' : '#f97316';
    }, 1000);
}

function closeQuiz() {
    clearInterval(countdown);
    const modal = document.getElementById('quizModal');
    if (modal) modal.classList.remove('active');
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