<?php
include "db.php";

date_default_timezone_set("Africa/Nairobi");

/* =
   SESSION SECURITY
= */
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_strict_mode', 1);

session_start();

/* =
   CSRF SETUP
= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateCsrf()
{
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Invalid CSRF token.");
    }
}

/* =
   INPUT EMAIL
= */
$email = trim($_GET['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid request.");
}

$msg = "";
$success = false;

/* =
   OTP ATTEMPT LIMIT
= */
if (!isset($_SESSION['otp_attempts'])) {
    $_SESSION['otp_attempts'] = 0;
}

/* =
   VERIFY OTP
= */
if (isset($_POST['verify'])) {

    validateCsrf();

    if ($_SESSION['otp_attempts'] >= 5) {
        die("Too many failed attempts. Try again later.");
    }

    $otp = trim($_POST['otp'] ?? '');

    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        $msg = "Invalid OTP format.";

    } else {

        try {

            /* =
               CHECK OTP
            = */
            $stmt = $conn->prepare("
                SELECT *
                FROM pending_teachers
                WHERE email=?
                AND otp=?
                AND otp_verified=0
                AND otp_expires > NOW()
                LIMIT 1
            ");

            $stmt->bind_param("ss", $email, $otp);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {

                $conn->begin_transaction();

                /* =
                   CHECK USER EXISTS
                = */
                $check = $conn->prepare("
                    SELECT id FROM users WHERE email=? LIMIT 1
                ");

                $check->bind_param("s", $row['email']);
                $check->execute();
                $check->store_result();

                if ($check->num_rows > 0) {
                    throw new Exception("Account already exists.");
                }

                /* =
                   CREATE USER (FIXED ROLE)
                = */
                $role = "teacher";

                $user = $conn->prepare("
                    INSERT INTO users
                    (full_name, email, password, role, is_verified, status)
                    VALUES (?, ?, ?, ?, 1, 'active')
                ");

                $user->bind_param(
                    "ssss",
                    $row['full_name'],
                    $row['email'],
                    $row['password'],
                    $role
                );

                $user->execute();
                $user_id = $conn->insert_id;

                /* =
                   CREATE TEACHER PROFILE
                = */
                $teacher = $conn->prepare("
                    INSERT INTO teachers
                    (user_id, employee_no, specialization, qualification, experience_years)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $teacher->bind_param(
                    "isssi",
                    $user_id,
                    $row['employee_no'],
                    $row['specialization'],
                    $row['qualification'],
                    $row['experience_years']
                );

                $teacher->execute();

                /* =
                   UPDATE OTP STATUS (FIXED)
                = */
                $update = $conn->prepare("
                    UPDATE pending_teachers
                    SET otp_verified=1,
                        status='active'
                    WHERE id=?
                ");

                $update->bind_param("i", $row['id']);
                $update->execute();

                $conn->commit();

                session_regenerate_id(true);
                $_SESSION['otp_attempts'] = 0;

                $success = true;

                header("refresh:3;url=login.php");
                exit;

            } else {
                $_SESSION['otp_attempts']++;
                $msg = "Invalid or expired OTP";
            }

        } catch (Exception $e) {

            $conn->rollback();
            error_log($e->getMessage());

            $msg = "An unexpected error occurred. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Verify Email</title>

<style>
body{
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    font-family:Arial;
    background:#f4f4f4;
}

.box{
    background:#fff;
    padding:30px;
    width:350px;
    border-radius:10px;
    text-align:center;
}

input,button{
    width:100%;
    padding:12px;
    margin-top:10px;
}

button{
    background:green;
    color:#fff;
    border:none;
}

.error{ color:red; }
.success{ color:green; }
</style>
</head>

<body>

<div class="box">

<h2>Verify OTP</h2>

<?php if ($success): ?>

    <p class="success"> Account activated successfully</p>
    <p>Redirecting to login...</p>

<?php else: ?>

    <?php if ($msg): ?>
        <p class="error"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>

    <form method="POST">

        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <input type="text" name="otp"
               placeholder="Enter OTP"
               maxlength="6"
               required>

        <button type="submit" name="verify">Verify</button>
    </form>

<?php endif; ?>

</div>

</body>
</html>