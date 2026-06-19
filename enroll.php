<?php
session_start();
include "db.php";

/* ===
   LOGIN CHECK
=== */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/* ===
   CSRF TOKEN
=== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===
   COURSE ID
=== */
$course_id = isset($_GET['course_id'])
    ? (int)$_GET['course_id']
    : 0;

if ($course_id <= 0) {
    die("Invalid course.");
}

/* ===
   GET COURSE
=== */
$stmt = $conn->prepare("
    SELECT *
    FROM courses
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $course_id);
$stmt->execute();

$course = $stmt->get_result()->fetch_assoc();

if (!$course) {
    die("Course not found.");
}

/* ===
   CHECK USER EXISTS
=== */
$stmt = $conn->prepare("
    SELECT id
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

if ($stmt->get_result()->num_rows === 0) {
    die("User account not found.");
}

/* ===
   CHECK ALREADY ENROLLED
=== */
$stmt = $conn->prepare("
    SELECT id, status
    FROM enrollments
    WHERE user_id = ?
    AND course_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $user_id,
    $course_id
);

$stmt->execute();

$existingEnrollment = $stmt->get_result()->fetch_assoc();

if ($existingEnrollment) {

    header("Location: course1.php?id=" . $course_id);
    exit;
}

/* ===
   FREE COURSE
=== */
if ((float)$course['price'] <= 0) {

    $conn->begin_transaction();

    try {

        /* DOUBLE CHECK */
        $check = $conn->prepare("
            SELECT id
            FROM enrollments
            WHERE user_id = ?
            AND course_id = ?
            LIMIT 1
        ");

        $check->bind_param(
            "ii",
            $user_id,
            $course_id
        );

        $check->execute();

        if ($check->get_result()->num_rows > 0) {

            $conn->rollback();

            header("Location: course1.php?id=" . $course_id);
            exit;
        }

        /* CREATE ACTIVE ENROLLMENT */
        $stmt = $conn->prepare("
            INSERT INTO enrollments
            (
                user_id,
                course_id,
                progress,
                status,
                enrolled_at
            )
            VALUES
            (
                ?, ?, 0, 'ongoing', NOW()
            )
        ");

        $stmt->bind_param(
            "ii",
            $user_id,
            $course_id
        );

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        /* UPDATE COURSE COUNT */
        $stmt = $conn->prepare("
            UPDATE courses
            SET enrolled_students =
                COALESCE(enrolled_students,0) + 1
            WHERE id = ?
        ");

        $stmt->bind_param(
            "i",
            $course_id
        );

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $conn->commit();

        $_SESSION['success'] =
            "Successfully enrolled in course.";

        header("Location: course1.php?id=" . $course_id);
        exit;

    } catch (Exception $e) {

        $conn->rollback();

        die(
            "Enrollment Error: " .
            htmlspecialchars($e->getMessage())
        );
    }
}

/* ===
   PAID COURSE — START M-PESA STK PUSH
=== */
$errorMessage = null;

if (isset($_POST['pay'])) {

    /* CSRF CHECK */
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errorMessage = "Your session expired. Please refresh the page and try again.";
    }

    $phone = trim($_POST['phone'] ?? '');

    /* NORMALIZE + VALIDATE PHONE (expects 07XXXXXXXX, 7XXXXXXXX, +254..., or 254...) */
    if (!$errorMessage) {
        $digits = preg_replace('/\D/', '', $phone);

        if (preg_match('/^0(7|1)\d{8}$/', $digits)) {
            $digits = '254' . substr($digits, 1);
        } elseif (preg_match('/^(7|1)\d{8}$/', $digits)) {
            $digits = '254' . $digits;
        }

        if (!preg_match('/^254(7|1)\d{8}$/', $digits)) {
            $errorMessage = "Enter a valid M-Pesa phone number, e.g. 0712345678.";
        } else {
            $phone = $digits;
        }
    }

    if (!$errorMessage) {

        $amount = (float)$course['price'];

        /* === MPESA CONFIG === */
        $consumerKey       = $_ENV['MPESA_CONSUMER_KEY'];
        $consumerSecret    = $_ENV['MPESA_CONSUMER_SECRET'];
        $BusinessShortCode = $_ENV['MPESA_BUSINESS_SHORTCODE'];
        $Passkey           = $_ENV['MPESA_PASSKEY'];
        $callbackURL       = $_ENV['MPESA_CALLBACK_URL'];

        /* ACCESS TOKEN */
        $credentials = base64_encode($consumerKey . ":" . $consumerSecret);

        $ch = curl_init(
            "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials"
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic " . $credentials]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $tokenResponse = json_decode(curl_exec($ch));
        $tokenCurlErr  = curl_error($ch);
        curl_close($ch);

        if (!isset($tokenResponse->access_token)) {
            error_log("MPESA token error: " . $tokenCurlErr);
            $errorMessage = "Could not reach M-Pesa right now. Please try again shortly.";
        } else {

            $access_token = $tokenResponse->access_token;

            $timestamp = date("YmdHis");
            $password  = base64_encode($BusinessShortCode . $Passkey . $timestamp);

            $stkData = [
                "BusinessShortCode" => $BusinessShortCode,
                "Password"          => $password,
                "Timestamp"         => $timestamp,
                "TransactionType"   => "CustomerPayBillOnline",
                "Amount"            => $amount,
                "PartyA"            => $phone,
                "PartyB"            => $BusinessShortCode,
                "PhoneNumber"       => $phone,
                "CallBackURL"       => $callbackURL,
                "AccountReference"  => "COURSE" . $course_id,
                "TransactionDesc"   => "Course Payment",
            ];

            $ch = curl_init(
                "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest"
            );
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer " . $access_token,
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stkData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $result   = curl_exec($ch);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $resultData = json_decode($result, true);

            if ($curlErr || empty($resultData['CheckoutRequestID'])) {
                error_log("MPESA STK push error: " . $curlErr . " | " . $result);
                $errorMessage = "We couldn't start the M-Pesa payment. Please try again.";
            } else {

                /* SAVE PENDING PAYMENT SO THE CALLBACK KNOWS WHAT TO ENROLL */
                $stmt = $conn->prepare("
                    INSERT INTO payments
                    (
                        user_id,
                        course_id,
                        phone,
                        amount,
                        checkout_request_id,
                        merchant_request_id,
                        status,
                        created_at
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, 'pending', NOW()
                    )
                ");

                $stmt->bind_param(
                    "iisdss",
                    $user_id,
                    $course_id,
                    $phone,
                    $amount,
                    $resultData['CheckoutRequestID'],
                    $resultData['MerchantRequestID']
                );

                if (!$stmt->execute()) {
                    error_log("Failed to save pending payment: " . $stmt->error);
                    $errorMessage = "Payment was started but we couldn't save the record. Contact support.";
                } else {
                    $_SESSION['pending_checkout_id'] = $resultData['CheckoutRequestID'];
                    header("Location: payment_pending.php?course_id=" . $course_id);
                    exit;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Enroll - <?= htmlspecialchars($course['title']) ?></title>
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
            padding: 30px;
            background: #f1f5f9;
        }

        .card {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }

        .card h2 {
            color: #1e293b;
            margin-bottom: 12px;
            font-size: 24px;
        }

        .card p {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .price {
            display: inline-block;
            margin-top: 8px;
            color: #2563eb;
            font-size: 26px;
            font-weight: 700;
        }

        hr {
            border: none;
            height: 1px;
            background: #e2e8f0;
            margin: 22px 0;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-weight: 600;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .btn {
            display: block;
            width: 100%;
            padding: 13px;
            margin-top: 14px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>

<div class="card">

    <h2><?= htmlspecialchars($course['title']) ?></h2>

    <p><?= htmlspecialchars($course['description']) ?></p>

    <p>
        Price:
        <span class="price">KES <?= number_format((float)$course['price']) ?></span>
    </p>

    <p>Instructor: <?= htmlspecialchars($course['instructor'] ?? 'N/A') ?></p>

    <hr>

    <?php if ($errorMessage) { ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php } ?>

    <?php if ((float)$course['price'] > 0) { ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label>Phone Number (e.g. 0712345678)</label>
            <input type="text" name="phone" placeholder="0712345678" required>

            <button type="submit" name="pay" class="btn">
                Pay with M-Pesa &amp; Enroll
            </button>
        </form>

    <?php } else { ?>

        <a href="?course_id=<?= $course_id ?>" class="btn">
            Enroll for Free
        </a>

    <?php } ?>

</div>

</body>
</html>