<?php
include "db.php";

$raw = file_get_contents("php://input");
error_log("MPESA CALLBACK RAW: " . $raw);

$data = json_decode($raw, true);

$callback = $data['Body']['stkCallback'] ?? null;

if (!$callback) {
    http_response_code(400);
    echo json_encode(["ResultCode" => 1, "ResultDesc" => "Invalid payload"]);
    exit;
}

$checkoutRequestId = $callback['CheckoutRequestID'] ?? null;
$resultCode        = $callback['ResultCode'] ?? null;
$resultDesc         = $callback['ResultDesc'] ?? '';

if (!$checkoutRequestId) {
    http_response_code(400);
    echo json_encode(["ResultCode" => 1, "ResultDesc" => "Missing CheckoutRequestID"]);
    exit;
}

/* FIND THE PENDING PAYMENT */
$stmt = $conn->prepare("
    SELECT id, user_id, course_id, status
    FROM payments
    WHERE checkout_request_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $checkoutRequestId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

if (!$payment) {
    error_log("MPESA CALLBACK: no matching payment for " . $checkoutRequestId);
    http_response_code(200); // acknowledge so Safaricom stops retrying
    echo json_encode(["ResultCode" => 0, "ResultDesc" => "Accepted"]);
    exit;
}

/* ALREADY PROCESSED — AVOID DOUBLE ENROLLMENT */
if ($payment['status'] !== 'pending') {
    http_response_code(200);
    echo json_encode(["ResultCode" => 0, "ResultDesc" => "Already processed"]);
    exit;
}

/* PAYMENT FAILED OR CANCELLED */
if ((int)$resultCode !== 0) {

    $stmt = $conn->prepare("
        UPDATE payments
        SET status = 'failed', result_desc = ?
        WHERE id = ?
    ");
    $stmt->bind_param("si", $resultDesc, $payment['id']);
    $stmt->execute();

    http_response_code(200);
    echo json_encode(["ResultCode" => 0, "ResultDesc" => "Accepted"]);
    exit;
}

/* PAYMENT SUCCEEDED — PULL RECEIPT DETAILS */
$mpesaReceipt = null;
$items = $callback['CallbackMetadata']['Item'] ?? [];
foreach ($items as $item) {
    if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
        $mpesaReceipt = $item['Value'] ?? null;
    }
}

$user_id   = (int)$payment['user_id'];
$course_id = (int)$payment['course_id'];

$conn->begin_transaction();

try {

    /* MARK PAYMENT COMPLETE */
    $stmt = $conn->prepare("
        UPDATE payments
        SET status = 'completed',
            mpesa_receipt = ?,
            result_desc = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $mpesaReceipt, $resultDesc, $payment['id']);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    /* DOUBLE CHECK NOT ALREADY ENROLLED (e.g. duplicate callback) */
    $check = $conn->prepare("
        SELECT id FROM enrollments
        WHERE user_id = ? AND course_id = ?
        LIMIT 1
    ");
    $check->bind_param("ii", $user_id, $course_id);
    $check->execute();

    if ($check->get_result()->num_rows === 0) {

        /* CREATE ENROLLMENT */
        $stmt = $conn->prepare("
            INSERT INTO enrollments
            (user_id, course_id, progress, status, enrolled_at)
            VALUES (?, ?, 0, 'ongoing', NOW())
        ");
        $stmt->bind_param("ii", $user_id, $course_id);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        /* UPDATE COURSE COUNT */
        $stmt = $conn->prepare("
            UPDATE courses
            SET enrolled_students = COALESCE(enrolled_students, 0) + 1
            WHERE id = ?
        ");
        $stmt->bind_param("i", $course_id);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    error_log("MPESA CALLBACK enrollment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ResultCode" => 1, "ResultDesc" => "Server error"]);
    exit;
}

/* ACKNOWLEDGE TO SAFARICOM */
http_response_code(200);
echo json_encode(["ResultCode" => 0, "ResultDesc" => "Accepted"]);