<?php
declare(strict_types=1);

/* =
   STRICT SESSION SECURITY
= */
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

/* =
   ENABLE SECURE COOKIE ONLY IF HTTPS
= */
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

ini_set('session.cookie_secure', $isHttps ? '1' : '0');

session_start();

date_default_timezone_set("Africa/Nairobi");

include "db.php";
include "sendmail.php";

/* =
   SECURITY HEADERS
= */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");

$error = "";

/* =
   CSRF TOKEN
= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =
   RATE LIMIT
= */
if (!isset($_SESSION['last_register'])) {
    $_SESSION['last_register'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() - $_SESSION['last_register'] < 5) {
        $error = "Too many requests. Please wait a few seconds.";
    }
}
/* =
   PROCESS FORM
= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register']) && empty($error)) {

    /* =
       CSRF CHECK
    = */
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die("Invalid CSRF token.");
    }

    $_SESSION['last_register'] = time();

    /* =
       SAFE INPUT HANDLING
    = */
    function clean_string(string $data): string {
        return trim(htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8'));
    }

    $name = clean_string($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
    $passwordRaw = $_POST['password'] ?? '';

    /* =
       EMAIL INJECTION PROTECTION
    = */
    if (preg_match("/[\r\n]/", $email)) {
        die("Invalid input detected.");
    }

    /* =
       VALIDATION
    = */
    if (!$name || !$email || !$phone || !$passwordRaw) {
        $error = "All fields are required.";
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    }
    elseif (strlen($name) < 3) {
        $error = "Name too short.";
    }
    elseif (strlen($phone) < 8) {
        $error = "Invalid phone number.";
    }
    elseif (
        strlen($passwordRaw) < 8 ||
        !preg_match('/[A-Z]/', $passwordRaw) ||
        !preg_match('/[0-9]/', $passwordRaw) ||
        !preg_match('/[\W]/', $passwordRaw)
    ) {
        $error = "Password must be 8+ chars, include uppercase, number, and symbol.";
    }
    else {

        /* =
           CHECK USER EXISTS
        = */
        $stmt = $conn->prepare("
            SELECT email, phone FROM users WHERE email=? OR phone=? LIMIT 1
        ");
        $stmt->bind_param("ss", $email, $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->fetch_assoc()) {
            $error = "User already exists (email or phone).";
            $stmt->close();
        } else {
            $stmt->close();

            /* =
               DELETE OLD OTP
            = */
            $del = $conn->prepare("DELETE FROM pending_students WHERE email=?");
            $del->bind_param("s", $email);
            $del->execute();
            $del->close();

            /* =
               PASSWORD HASH (SECURE)
            = */
            $passwordHash = password_hash(
                $passwordRaw,
                defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT
            );
            $otp = random_int(100000, 999999);
            $created = date("Y-m-d H:i:s");
            $expires = date("Y-m-d H:i:s", strtotime("+10 minutes"));
            $insert = $conn->prepare("
                INSERT INTO pending_students
                (full_name, email, phone, password, otp, otp_created, otp_expires)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->bind_param(
                "sssssss",
                $name,
                $email,
                $phone,
                $passwordHash,
                $otp,
                $created,
                $expires
            );
            if ($insert->execute()) {
                if (sendOTP($email, $name, $otp)) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: verify.php?email=" . urlencode($email));
                    exit;
                } else {
                    $error = "Unable to send OTP email.";
                }

            } else {
                $error = "Database error occurred.";
            }
            $insert->close();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Secure Register</title>
<style>
body{
    margin:0;
    font-family:Arial, sans-serif;
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    background: linear-gradient(-45deg, #0f0c29, #302b63, #24243e, #00d4ff);
    background-size: 400% 400%;
    animation: bg 10s ease infinite;
}
/* background animation */
@keyframes bg{
    0%{background-position:0% 50%;}
    50%{background-position:100% 50%;}
    100%{background-position:0% 50%;}
}

.box{
    width:420px;
    background: rgba(255,255,255,0.95);
    padding:35px;
    border-radius:16px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.4);
    backdrop-filter: blur(10px);
    transform: translateY(0);
    transition: 0.3s ease;
}
.box:hover{
    transform: translateY(-3px);
}

h2{
    text-align:center;
    margin-bottom:20px;
    color:#111;
    font-weight:700;
    letter-spacing:0.5px;
}

/* inputs */
input{
    width:100%;
    padding:13px;
    margin:10px 0;
    border:1px solid #ddd;
    border-radius:10px;
    outline:none;
    transition:0.2s;
    font-size:14px;
    background:#f9fafb;
}

input:focus{
    border-color:#0a8f0a;
    box-shadow:0 0 0 3px rgba(10,143,10,0.2);
    background:#fff;
}

/* button */
button{
    width:100%;
    padding:13px;
    margin-top:10px;
    background: linear-gradient(135deg, #0a8f0a, #16a34a);
    color:#fff;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:bold;
    font-size:15px;
    transition:0.3s;
    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
}

button:hover{
    transform: scale(1.02);
    background: linear-gradient(135deg, #0c9a0c, #22c55e);
}

button:active{
    transform: scale(0.98);
}

/* error */
.error{
    color:#dc2626;
    text-align:center;
    margin-bottom:10px;
    font-weight:bold;
    background:#fee2e2;
    padding:10px;
    border-radius:8px;
    border:1px solid #fecaca;
}

/* small mobile responsiveness */
@media (max-width: 500px){
    .box{
        width:90%;
        padding:25px;
    }
}
</style>
</head>

<body>

<div class="box">
<h2>Register Securely</h2>

<?php if($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST">

    <input type="hidden" name="csrf_token"
        value="<?= $_SESSION['csrf_token'] ?>">

    <input type="text" name="full_name" placeholder="Full Name" required>

    <input type="email" name="email" placeholder="Email" required>

    <input type="text" name="phone" placeholder="Phone" required>

    <input type="password" name="password" placeholder="Password" required>

    <button type="submit" name="register">Create Account</button>

</form>

</div>

</body>
</html>