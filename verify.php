<?php
date_default_timezone_set("Africa/Nairobi");

/* ====
   SECURE SESSION SETTINGS
==== */
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']),
    'samesite' => 'Strict'
]);

session_start();
include "db.php";

/* ====
   SECURITY HEADERS
==== */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=()");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline';");

/* ====
   CSRF TOKEN
==== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ====
   VALIDATE EMAIL
==== */
$email = filter_input(INPUT_GET, 'email', FILTER_VALIDATE_EMAIL);

if (!$email) {
    die("Invalid request.");
}

$msg = "";
$success = false;

/* ====
   VERIFY OTP
==== */
if (isset($_POST['verify'])) {

    /* CSRF CHECK */
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Invalid request.");
    }

    $otp = trim($_POST['otp']);
    $otp = preg_replace('/\s+/', '', $otp);

    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        $msg = "OTP must be 6 digits only.";
    } else {

        /* ====
           GET PENDING ACCOUNT
        ==== */
        $stmt = $conn->prepare("
            SELECT *
            FROM pending_students
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$row = $result->fetch_assoc()) {
            $msg = "Verification failed.";
        } else {

            /* ====
               CHECK LOCK STATUS
            ==== */
            if (
                !empty($row['otp_locked_until']) &&
                strtotime($row['otp_locked_until']) > time()
            ) {
                $msg = "Too many attempts. Try again later.";
            }

            elseif ($row['otp_verified'] == 1) {
                $msg = "Account already verified.";
            }

            elseif (strtotime($row['otp_expires']) < time()) {
                $msg = "OTP expired.";
            }

            else {

                /* ====
                   VERIFY OTP
                ==== */
                if ($row['otp'] !== $otp) {

                    /* INCREMENT ATTEMPTS */
                    $attempts = (int)($row['otp_attempts'] ?? 0) + 1;

                    $lockUntil = null;
                    if ($attempts >= 5) {
                        $lockUntil = date("Y-m-d H:i:s", strtotime("+15 minutes"));
                    }

                    try {
                        $update = $conn->prepare("
                            UPDATE pending_students
                            SET otp_attempts = ?,
                                otp_locked_until = ?
                            WHERE email = ?
                        ");
                        $update->bind_param("iss", $attempts, $lockUntil, $email);
                        $update->execute();
                    } catch (Exception $e) {
                        error_log("OTP attempt-tracking update failed: " . $e->getMessage());
                        /* Don't crash the page over tracking failure —
                           the OTP itself is still correctly rejected below. */
                    }

                    $msg = "Invalid OTP.";
                }

                else {

                    /* ====
                       TRANSACTION START
                    ==== */
                    $conn->begin_transaction();

                    try {

                        /* CHECK IF USER EXISTS */
                        $check = $conn->prepare("
                            SELECT id FROM users WHERE email = ? LIMIT 1
                        ");
                        $check->bind_param("s", $email);
                        $check->execute();
                        $check->store_result();

                        if ($check->num_rows > 0) {
                            throw new Exception("Account already exists.");
                        }

                        /* INSERT USER */
                        $insert = $conn->prepare("
                            INSERT INTO users
                            (full_name, email, phone, password, role, is_verified, status)
                            VALUES (?, ?, ?, ?, ?, 1, 'active')
                        ");

                        $insert->bind_param(
                            "sssss",
                            $row['full_name'],
                            $row['email'],
                            $row['phone'],
                            $row['password'],
                            $row['role']
                        );

                        if (!$insert->execute()) {
                            throw new Exception("Insert failed");
                        }

                        $newUserId = $conn->insert_id;

                        /* CLEAN PENDING RECORD */
                        $clean = $conn->prepare("
                            UPDATE pending_students
                            SET otp_verified = 1,
                                otp = NULL,
                                otp_expires = NULL,
                                otp_attempts = 0,
                                otp_locked_until = NULL
                            WHERE id = ?
                        ");
                        $clean->bind_param("i", $row['id']);
                        $clean->execute();

                        $conn->commit();

                        /* ====
                           SECURE SESSION
                        ==== */
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $newUserId;
                        $_SESSION['user_email'] = $row['email'];
                        $_SESSION['user_name'] = $row['full_name'];
                        $_SESSION['user_role'] = $row['role'];

                        $success = true;

                        header("refresh:3;url=login.php");

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Student verification failed: " . $e->getMessage());
                        $msg = "Verification failed.";
                    }
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
<title>OTP Verification</title>

<style>
body{
    margin:0;
    font-family:Arial;
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    background:#0f172a;
}

.box{
    width:350px;
    background:#fff;
    padding:25px;
    border-radius:10px;
    text-align:center;
}

input{
    width:100%;
    padding:12px;
    margin-top:10px;
}

button{
    width:100%;
    padding:12px;
    margin-top:10px;
    background:green;
    color:#fff;
    border:none;
    cursor:pointer;
}

.error{color:red;}
.success{color:green;}
</style>
</head>

<body>

<div class="box">

<h2>OTP Verification</h2>

<?php if($success): ?>

<p class="success">
✔ Verification successful. Redirecting...
</p>

<?php else: ?>

<?php if(!empty($msg)): ?>
<p class="error"><?= htmlspecialchars($msg) ?></p>
<?php endif; ?>

<form method="POST">

<input type="hidden" name="csrf_token"
value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

<input type="text" name="otp"
maxlength="6"
placeholder="Enter 6-digit OTP"
required>

<button type="submit" name="verify">
Verify
</button>

</form>

<?php endif; ?>

</div>

</body>
</html>