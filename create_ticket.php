<?php
session_start();
include "db.php";

if (isset($_POST['submit_ticket'])) {
    /* ====
       GET LOGGED IN USER
    ==== */
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        header("Location: login.php");
        exit();
    }
    $user_id = (int) $user_id;
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die("Invalid CSRF token. Request blocked.");
    }
    $type  = trim(strip_tags($_POST['type']    ?? ''));
    $subject = trim(strip_tags($_POST['subject'] ?? ''));
    $message = trim(strip_tags($_POST['message'] ?? ''));
    $valid_types = ['academic', 'technical', 'attendance', 'general'];
    /* ====
       VALIDATION
    ==== */
    if ($type === '' || $subject === '' || $message === '' || !in_array($type, $valid_types, true)) {
        $_SESSION['flash'] = "Please fill in all fields with a valid ticket type.";
        $_SESSION['active_section'] = 'ticket';
        header("Location: student.php");
        exit();
    }

    /* ====
       CHECK IF USER EXISTS
    ==== */
    $check = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $check->bind_param("i", $user_id);
    $check->execute();
    $userExists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$userExists) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }

    /* ====
       INSERT TICKET 
    ==== */
    $stmt = $conn->prepare("
        INSERT INTO tickets (user_id, type, subject, message, status)
        VALUES (?, ?, ?, ?, 'open')
    ");
    $stmt->bind_param("isss", $user_id, $type, $subject, $message);

    if ($stmt->execute()) {
        $stmt->close();
        $_SESSION['flash'] = "Ticket submitted successfully. Our team will respond shortly.";
        $_SESSION['active_section'] = 'ticket';
        header("Location: student.php");
        exit();
    } else {
        $stmt->close();
        $_SESSION['flash'] = "Something went wrong while submitting your ticket. Please try again.";
        $_SESSION['active_section'] = 'ticket';
        header("Location: student.php");
        exit();
    }
}
header("Location: student.php");
exit();