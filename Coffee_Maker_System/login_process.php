<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit();
}

$username = trim($_POST["username"] ?? "");
$password = trim($_POST["password"] ?? "");

if (empty($username) || empty($password)) {
    header("Location: index.php?error=1");
    exit();
}

// 1. Fetch user from database using prepared statements
$stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    
    // 2. Validate password (plain-text check)
    if ($password === $user["password"]) {
        session_regenerate_id(true);
        $_SESSION["username"] = $user["username"];
        $_SESSION["role"]     = $user["role"];

        if ($user["role"] === "admin") {
            $_SESSION["admin"] = $user["username"];
        }

        // 3. Dynamic redirection based on database role
        if ($user["role"] === "cashier") {
            header("Location: pages/pos.php");
        } else {
            header("Location: pages/dashboard.php");
        }
        exit();
    }
}

// Invalid username or password
header("Location: index.php?error=1");
exit();
?>