<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if ($course_id <= 0) {
    die("Invalid course.");
}

/* AJAX STATUS CHECK */
if (isset($_GET['check'])) {
    header("Content-Type: application/json");

    $stmt = $conn->prepare("
        SELECT status FROM payments
        WHERE user_id = ? AND course_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    echo json_encode(["status" => $row['status'] ?? 'unknown']);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Processing Payment</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f1f5f9;
            padding: 30px;
        }

        .card {
            background: #fff;
            border-radius: 16px;
            padding: 36px;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }

        .spinner {
            width: 44px;
            height: 44px;
            border: 4px solid #dbeafe;
            border-top-color: #2563eb;
            border-radius: 50%;
            margin: 0 auto 20px;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        h2 {
            color: #1e293b;
            margin-bottom: 10px;
            font-size: 20px;
        }

        p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }

        .btn {
            display: inline-block;
            margin-top: 18px;
            padding: 10px 18px;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="spinner" id="spinner"></div>
    <h2 id="title">Check your phone</h2>
    <p id="message">Enter your M-Pesa PIN to complete the payment. This page will update automatically.</p>
</div>

<script>
const courseId = <?= $course_id ?>;
let attempts = 0;

function poll() {
    attempts++;
    fetch(`payment_pending.php?course_id=${courseId}&check=1`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'completed') {
                document.getElementById('title').textContent = 'Payment received!';
                document.getElementById('message').textContent = 'Redirecting you to the course...';
                document.getElementById('spinner').style.display = 'none';
                setTimeout(() => {
                    window.location.href = `course1.php?id=${courseId}`;
                }, 1200);
            } else if (data.status === 'failed') {
                document.getElementById('title').textContent = 'Payment not completed';
                document.getElementById('message').innerHTML =
                    'The payment was cancelled or timed out.<br><a class="btn" href="enroll.php?course_id=' + courseId + '">Try again</a>';
                document.getElementById('spinner').style.display = 'none';
            } else if (attempts < 30) {
                setTimeout(poll, 3000);
            } else {
                document.getElementById('title').textContent = 'Still waiting...';
                document.getElementById('message').innerHTML =
                    'This is taking longer than usual.<br><a class="btn" href="enroll.php?course_id=' + courseId + '">Back to course</a>';
                document.getElementById('spinner').style.display = 'none';
            }
        })
        .catch(() => setTimeout(poll, 3000));
}

poll();
</script>

</body>
</html>