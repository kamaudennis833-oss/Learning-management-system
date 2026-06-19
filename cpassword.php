<?php
session_start();
include "db.php";

/* -
   SECURITY — Admin only
- */
if (!isset($_SESSION['user_id'])); 
/* Confirm the logged-in user is an admin */
$me = $conn->prepare("SELECT role FROM users WHERE id = ?");
$me->bind_param("i", $_SESSION['user_id']);
$me->execute();
$me_row = $me->get_result()->fetch_assoc();
$me->close();

if (!$me_row || $me_row['role'] !== 'admin') {
    http_response_code(403);
    die("Access denied. Admins only.");
}

/* -
   CSRF TOKEN
- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* -
   HANDLE FORM SUBMISSION
- */
$message      = '';
$message_type = 'success'; // or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_teacher'])) {

    /* CSRF check */
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $message      = 'Invalid request token. Please try again.';
        $message_type = 'error';
        goto render;
    }

    $course_id  = (int)($_POST['course_id']  ?? 0);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);

    if (!$course_id || !$teacher_id) {
        $message      = 'Please select both a course and a teacher.';
        $message_type = 'error';
        goto render;
    }

    /* Validate course */
    $cs = $conn->prepare("SELECT id, title FROM courses WHERE id = ?");
    $cs->bind_param("i", $course_id);
    $cs->execute();
    $course = $cs->get_result()->fetch_assoc();
    $cs->close();

    if (!$course) {
        $message      = 'Course not found.';
        $message_type = 'error';
        goto render;
    }

    /* Validate teacher — must be role=teacher in users table */
    $ts = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'teacher'");
    $ts->bind_param("i", $teacher_id);
    $ts->execute();
    $teacher = $ts->get_result()->fetch_assoc();
    $ts->close();

    if (!$teacher) {
        $message      = 'Invalid teacher selected.';
        $message_type = 'error';
        goto render;
    }

    /* Check existing assignment */
    $ck = $conn->prepare("SELECT id FROM course_teachers WHERE course_id = ?");
    $ck->bind_param("i", $course_id);
    $ck->execute();
    $existing = $ck->get_result()->fetch_assoc();
    $ck->close();

    if ($existing) {
        $upd = $conn->prepare("UPDATE course_teachers SET teacher_id = ?, assigned_at = NOW() WHERE course_id = ?");
        $upd->bind_param("ii", $teacher_id, $course_id);
        $upd->execute();
        $upd->close();
        $action_word = 'reassigned to';
    } else {
        $ins = $conn->prepare("INSERT INTO course_teachers (course_id, teacher_id) VALUES (?, ?)");
        $ins->bind_param("ii", $course_id, $teacher_id);
        $ins->execute();
        $ins->close();
        $action_word = 'assigned to';
    }

    $cname = htmlspecialchars($course['title']);
    $tname = htmlspecialchars($teacher['full_name']);
    $message = "$tname successfully $action_word <strong>$cname</strong>.";

    /* Regenerate CSRF after successful action */
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf_token'];
}

render:
/* -
   LOAD DROPDOWNS
- */
$courses  = mysqli_query($conn, "SELECT id, title FROM courses ORDER BY title ASC");
$teachers = mysqli_query($conn, "SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name ASC");

/* Current assignments (for the table) */
$assignments = mysqli_query($conn, "
    SELECT c.title AS course_title, u.full_name AS teacher_name, ct.assigned_at
    FROM course_teachers ct
    JOIN courses c ON c.id = ct.course_id
    JOIN users u   ON u.id = ct.teacher_id
    ORDER BY ct.assigned_at DESC
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assign Teacher — Admin</title>
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg:      #0f0f13;
    --surface: #18181f;
    --surf2:   #22222c;
    --border:  rgba(255,255,255,0.08);
    --text:    #f0eff5;
    --muted:   #8b8a9a;
    --accent:  #6c63ff;
    --acc2:    #a78bfa;
    --green:   #22c55e;
    --red:     #ef4444;
    --r-sm:    8px;
    --r-md:    14px;
    --r-lg:    20px;
}
body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, 'Segoe UI', sans-serif;
    font-size: 15px;
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 48px 20px 80px;
}
.wrap { width: 100%; max-width: 560px; }

/* - HEADER - */
.page-header { margin-bottom: 32px; }
.eyebrow {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.8px;
    text-transform: uppercase; color: var(--acc2);
    margin-bottom: 10px;
}
.eyebrow::before {
    content: '';
    display: block; width: 18px; height: 2px;
    background: var(--acc2); border-radius: 2px;
}
.page-header h1 { font-size: 26px; font-weight: 700; line-height: 1.2; }
.page-header p  { color: var(--muted); font-size: 14px; margin-top: 6px; }

/* - CARD - */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    overflow: hidden;
    margin-bottom: 20px;
}
.card-body { padding: 28px; }

/* - ALERT - */
.alert {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px; border-radius: var(--r-sm);
    font-size: 13px; line-height: 1.5;
    margin-bottom: 24px;
}
.alert-success {
    background: rgba(34,197,94,0.1);
    border: 1px solid rgba(34,197,94,0.25);
    color: #4ade80;
}
.alert-error {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.3);
    color: #f87171;
}
.alert-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

/* - FORM - */
.field { margin-bottom: 20px; }
.field label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 8px;
}
.field select {
    width: 100%;
    background: var(--surf2);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    padding: 11px 14px;
    font-size: 14px;
    font-family: inherit;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238b8a9a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
    cursor: pointer;
    transition: border-color 0.15s;
}
.field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(108,99,255,0.15);
}
.field select option { background: #22222c; }

.divider {
    height: 1px; background: var(--border);
    margin: 24px 0;
}

.btn-submit {
    width: 100%;
    padding: 13px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: var(--r-sm);
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-submit:hover  { background: #7c73ff; }
.btn-submit:active { transform: scale(0.98); }

/* - RECENT TABLE - */
.section-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.6px; color: var(--muted);
    padding: 18px 24px 12px;
    border-bottom: 1px solid var(--border);
}
.assign-table { width: 100%; border-collapse: collapse; }
.assign-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.5px; color: var(--muted);
    padding: 10px 20px; text-align: left;
    border-bottom: 1px solid var(--border);
}
.assign-table td {
    padding: 12px 20px;
    font-size: 13px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    vertical-align: middle;
}
.assign-table tr:last-child td { border-bottom: none; }
.assign-table tr:hover td { background: rgba(255,255,255,0.02); }
.pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 11px; font-weight: 500;
    background: rgba(108,99,255,0.15);
    color: var(--acc2);
    border: 1px solid rgba(108,99,255,0.25);
}
.assign-date { color: var(--muted); font-size: 12px; }
.no-data { padding: 24px; text-align: center; color: var(--muted); font-size: 13px; }

/* - BACK LINK - */
.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--muted); font-size: 13px;
    margin-top: 20px; text-decoration: none;
    transition: color 0.15s;
}
.back-link:hover { color: var(--text); }
</style>
</head>
<body>
<div class="wrap">

    <!-- Header -->
    <div class="page-header">
        <div class="eyebrow">Admin Panel</div>
        <h1>Assign Teacher</h1>
        <p>Link a teacher to a course, or update an existing assignment.</p>
    </div>

    <!-- Form card -->
    <div class="card">
        <div class="card-body">

            <!-- Alert message -->
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?>">
                <span class="alert-icon"><?= $message_type === 'success' ? '✓' : '⚠' ?></span>
                <span><?= $message /* already escaped or marked safe above */ ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <!-- CSRF -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <!-- Course -->
                <div class="field">
                    <label for="course_id">Course</label>
                    <select name="course_id" id="course_id" required>
                        <option value="">Select a course…</option>
                        <?php while ($c = mysqli_fetch_assoc($courses)): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= ((int)($_POST['course_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Teacher -->
                <div class="field">
                    <label for="teacher_id">Teacher</label>
                    <select name="teacher_id" id="teacher_id" required>
                        <option value="">Select a teacher…</option>
                        <?php while ($t = mysqli_fetch_assoc($teachers)): ?>
                            <option value="<?= (int)$t['id'] ?>"
                                <?= ((int)($_POST['teacher_id'] ?? 0) === (int)$t['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['full_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="divider"></div>

                <button type="submit" name="assign_teacher" class="btn-submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Assign / Reassign Teacher
                </button>
            </form>

        </div>
    </div>

    <!-- Recent assignments table -->
    <div class="card">
        <div class="section-label">Recent assignments</div>
        <?php if (mysqli_num_rows($assignments) > 0): ?>
        <table class="assign-table">
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Teacher</th>
                    <th>Assigned</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($assignments)): ?>
                <tr>
                    <td><?= htmlspecialchars($row['course_title']) ?></td>
                    <td><span class="pill"><?= htmlspecialchars($row['teacher_name']) ?></span></td>
                    <td class="assign-date">
                        <?= date('M j, Y', strtotime($row['assigned_at'])) ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="no-data">No assignments yet.</div>
        <?php endif; ?>
    </div>

    <a href="admin.php" class="back-link">
        ← Back to dashboard
    </a>

</div>
</body>
</html>