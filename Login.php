<?php
session_start();
include "db.php";

$error = "";
$reset_msg = "";

/* =
   LOGIN HANDLER
= */
if (isset($_POST['login'])) {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("
        SELECT * 
        FROM users 
        WHERE email=? 
        LIMIT 1
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        // CHECK EMAIL VERIFIED
        if ($row['is_verified'] != 1) {
            $error = "Please verify your email first.";
        }

        // CHECK ACCOUNT STATUS
        elseif ($row['status'] != "active") {
            $error = "Your account is not active.";
        }

        // CHECK PASSWORD
        elseif (password_verify($password, $row['password'])) {

            // =
            // SESSION DATA
            // =
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['user_email'] = $row['email'];
            $_SESSION['user_role'] = $row['role'];

            // =
            // ROLE REDIRECT (FIXED)
            // =
            if ($row['role'] == 'admin') {
                header("Location: Admin.php");
                exit;
            }
            elseif ($row['role'] == 'teacher') {
                header("Location: Teacher.php");
                exit;
            }
            else {
                header("Location: student.php");
                exit;
            }

        } else {
            $error = "Invalid password!";
        }

    } else {
        $error = "User not found!";
    }
}


/* =
   FORGOT PASSWORD
= */
if (isset($_POST['email']) && isset($_POST['new_password'])) {

    $email = trim($_POST['email']);
    $new_password = $_POST['new_password'];

    $check = $conn->prepare("
        SELECT id, is_verified, status 
        FROM users 
        WHERE email=? 
        LIMIT 1
    ");

    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($user = $result->fetch_assoc()) {

        if ($user['is_verified'] != 1) {
            $reset_msg = "Account not verified.";
        }
        elseif ($user['status'] != "active") {
            $reset_msg = "Account not active.";
        }
        else {

            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            $update = $conn->prepare("
                UPDATE users 
                SET password=? 
                WHERE email=?
            ");

            $update->bind_param("ss", $hashed, $email);

            if ($update->execute() && $update->affected_rows > 0) {
                $reset_msg = "Password reset successful. You can now login.";
            } else {
                $reset_msg = "Password reset failed.";
            }
        }

    } else {
        $reset_msg = "User not found!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login System</title>

<style>
body{
    margin:0;
    font-family: Arial;
    background: linear-gradient(to right, cornsilk, black);
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
}

.login-1{
    display:flex;
    background:white;
    width:1100px;
    height:90vh;
    border-radius:15px;
    overflow:hidden;
    box-shadow:0 10px 25px rgba(0,0,0,0.3);
}

form{
    flex:0.4;
    padding:30px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:20px;
}

input{
    padding:12px;
    border:1px solid #ccc;
    border-radius:8px;
}

button{
    padding:12px;
    border:none;
    border-radius:8px;
    cursor:pointer;
}

button[type="submit"]{
    background:green;
    color:white;
}

button[type="button"]{
    background:orange;
    color:white;
}

.error{
    color:red;
    font-weight:bold;
}

.image-box{
    flex:0.6;
}

.image-box img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.register-link{
    text-align:center;
    margin-top:10px;
}

.register-link a{
    color:blue;
    text-decoration:none;
    font-weight:bold;
}

.modal{
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    justify-content:center;
    align-items:center;
}

.modal-content{
    background:white;
    padding:25px;
    width:320px;
    border-radius:12px;
    position:relative;
    text-align:center;
}

.close{
    position:absolute;
    right:10px;
    top:5px;
    font-size:22px;
    cursor:pointer;
}
</style>

</head>

<body>

<div class="login-1">

<form method="POST">

<h2>LMS Login Portal</h2>

<?php if($error) echo "<p class='error'>$error</p>"; ?>

<label>Email</label>
<input type="email" name="email" required>

<label>Password</label>
<input type="password" name="password" required>

<button type="submit" name="login">Login</button>

<button type="button" onclick="openModal()">Forgot Password</button>

<div class="register-link">
    Don't have an account?
    <a href="register.php">Create Account</a>
</div>

</form>

<div class="image-box">
    <img src="webpage.jpg">
</div>

</div>

<!-- RESET MODAL -->
<div id="forgotModal" class="modal">
  <div class="modal-content">

    <span class="close" onclick="closeModal()">&times;</span>

    <h3>Reset Password</h3>

    <?php if($reset_msg) echo "<p>$reset_msg</p>"; ?>

    <form method="POST">

        <input type="email" name="email" placeholder="Enter Email" required>
        <input type="password" name="new_password" placeholder="New Password" required>

        <button type="submit">Reset</button>

    </form>

  </div>
</div>

<script>
function openModal(){
    document.getElementById("forgotModal").style.display = "flex";
}

function closeModal(){
    document.getElementById("forgotModal").style.display = "none";
}

window.onclick = function(e){
    let modal = document.getElementById("forgotModal");
    if(e.target == modal){
        modal.style.display = "none";
    }
}
</script>

</body>
</html>