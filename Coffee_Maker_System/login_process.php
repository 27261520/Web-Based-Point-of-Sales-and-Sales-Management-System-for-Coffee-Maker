<?php

session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit();
}

$username = trim($_POST["username"] ?? "");
$password = (string) ($_POST["password"] ?? "");

// Login credentials
$correct_username = "admin";
$correct_password = "admin123";

// Check login
if ($username === $correct_username && $password === $correct_password) {

    session_regenerate_id(true);
    $_SESSION["admin"] = $username;

    header("Location: pages/dashboard.php");
    exit();

} else {

    header("Location: index.php?error=1");
    exit();

}

?>