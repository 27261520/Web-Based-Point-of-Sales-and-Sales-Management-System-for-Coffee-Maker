<?php

session_start();
$username = $_POST["username"] ?? "";
$password = $_POST["password"] ?? "";

// Login credentials
$correct_username = "admin";
$correct_password = "admin123";

// Check login
if ($username === $correct_username && $password === $correct_password) {

    $_SESSION["admin"] = $username;

    header("Location: pages/dashboard.php");
    exit();

} else {

    header("Location: index.php?error=1");
    exit();

}

?>