<?php
session_start();

if (!isset($_SESSION["admin"])) {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";

$admin_username = $_SESSION["admin"];
$message = "";
$error = "";
$editing_user = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $user_id = (int) ($_POST["user_id"] ?? 0);
    $full_name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $submitted_role = $_POST["role"] ?? "Staff";
    $role = in_array($submitted_role, ["Manager", "Staff"], true) ? $submitted_role : "Staff";
    $status = ($_POST["status"] ?? "Active") === "Inactive" ? "Inactive" : "Active";
    $password = (string) ($_POST["password"] ?? "");

    if ($action === "save_user") {
        if ($full_name === "" || $username === "") {
            $error = "Full name and username are required.";
        } elseif ($user_id > 0) {
            if ($password !== "") {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $statement = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, status = ?, password = ? WHERE id = ?");
                $statement->bind_param("sssssi", $full_name, $username, $role, $status, $hashed_password, $user_id);
            } else {
                $statement = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, status = ? WHERE id = ?");
                $statement->bind_param("ssssi", $full_name, $username, $role, $status, $user_id);
            }
            if ($statement->execute()) $message = "User information updated.";
            else $error = "Unable to update user information.";
            $statement->close();
        } elseif ($password === "") {
            $error = "A password is required for a new user.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $statement = $conn->prepare("INSERT INTO users (full_name, username, role, status, password) VALUES (?, ?, ?, ?, ?)");
            $statement->bind_param("sssss", $full_name, $username, $role, $status, $hashed_password);
            if ($statement->execute()) $message = "New user added.";
            else $error = "Unable to add user. The username may already exist.";
            $statement->close();
        }
    } elseif ($action === "delete_user" && $user_id > 0) {
        $statement = $conn->prepare("DELETE FROM users WHERE id = ? AND username != ?");
        $statement->bind_param("is", $user_id, $admin_username);
        if ($statement->execute() && $statement->affected_rows > 0) $message = "User deleted.";
        else $error = "You cannot delete the currently logged-in admin or the user was not found.";
        $statement->close();
    }
}

if (isset($_GET["edit"])) {
    $edit_id = (int) $_GET["edit"];
    $statement = $conn->prepare("SELECT id, full_name, username, role, status FROM users WHERE id = ? LIMIT 1");
    $statement->bind_param("i", $edit_id);
    $statement->execute();
    $editing_user = $statement->get_result()->fetch_assoc();
    $statement->close();
}

$search = trim($_GET["search"] ?? "");
$users = [];
if ($search !== "") {
    $term = "%" . $search . "%";
    $statement = $conn->prepare("SELECT id, full_name, username, role, status FROM users WHERE full_name LIKE ? OR username LIKE ? ORDER BY full_name");
    $statement->bind_param("ss", $term, $term);
} else {
    $statement = $conn->prepare("SELECT id, full_name, username, role, status FROM users ORDER BY full_name");
}
$statement->execute();
$result = $statement->get_result();
while ($row = $result->fetch_assoc()) $users[] = $row;
$statement->close();
$total_users = count($users);
$active_users = count(array_filter($users, fn($user) => $user["status"] === "Active"));
$admins = count(array_filter($users, fn($user) => strtolower($user["role"]) === "manager"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>User Management | Coffee Maker</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{background:#f7f7f7;color:#2b211e;font-family:Arial,Helvetica,sans-serif}.app-shell{min-height:100vh;display:flex}.sidebar{width:242px;min-height:100vh;padding:30px 0 20px;background:#2b1610;color:#fff;position:fixed;display:flex;flex-direction:column;justify-content:space-between}.brand{padding:0 30px 35px;font-size:21px;font-weight:700}.nav{display:grid;gap:3px}.nav-item{padding:14px 30px;color:#d8c8c3;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:.5px}.nav-item:hover,.nav-item.active{background:#54392f;color:#fff}.sidebar-footer{display:flex;align-items:center;gap:10px;border-top:1px solid #43251e;padding:16px 30px 0;font-size:10px;font-weight:700}.avatar{width:30px;height:30px;display:grid;place-items:center;border-radius:50%;background:#60463e}.content{width:calc(100% - 242px);margin-left:242px;padding:42px 38px}.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}.topbar h1{font-size:25px}.topbar p{margin-top:6px;color:#8f8986;font-size:12px}.button{border:0;border-radius:7px;padding:11px 16px;background:#54392f;color:#fff;font-size:10px;font-weight:700;cursor:pointer}.button:hover{background:#2b1610}.button.light{background:#fff;color:#54392f;border:1px solid #ded7d4}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}.stat,.panel{border:1px solid #eeeae8;border-radius:8px;background:#fff}.stat{padding:18px}.stat small{color:#8f8986;font-size:9px}.stat strong{display:block;margin-top:9px;font-size:22px}.panel{overflow:hidden}.panel-toolbar{display:flex;justify-content:space-between;align-items:center;padding:16px;border-bottom:1px solid #f0edeb}.search{height:37px;width:min(100%,300px);padding:0 12px;border:1px solid #e5e1df;border-radius:6px;font-size:11px}.table-wrap{overflow-x:auto}table{width:100%;min-width:650px;border-collapse:collapse}th{padding:12px 16px;background:#fcfbfb;color:#756b67;font-size:9px;text-align:left}td{padding:14px 16px;border-top:1px solid #f0edeb;font-size:11px;color:#4c4441}.user-name{font-weight:700;color:#2b211e}.user-email{margin-top:4px;color:#999;font-size:10px}.user-avatar{width:32px;height:32px;border-radius:50%;background:#111;color:#fff;display:grid;place-items:center;font-weight:700}.user-cell{display:flex;align-items:center;gap:10px}.badge{display:inline-block;padding:5px 10px;border-radius:12px;font-size:9px;font-weight:700}.active{background:#e2f2e5;color:#28763b}.inactive{background:#eeeae8;color:#756b67}.role{background:#fff4d8;color:#9a5d19}.actions{display:flex;gap:8px}.icon-button{border:0;background:transparent;color:#6c5e58;cursor:pointer;font-size:11px}.icon-button.delete{color:#a34a3f}.notice{margin-bottom:15px;padding:11px 13px;border-radius:6px;background:#e2f2e5;color:#28763b;font-size:11px}.notice.error{background:#f9e3e0;color:#a53e35}.form-panel{max-width:760px;padding:20px}.form-panel h2{font-size:17px;margin-bottom:18px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field label{display:block;margin-bottom:7px;color:#756b67;font-size:9px;font-weight:700}.field input,.field select{width:100%;height:38px;padding:0 10px;border:1px solid #ded7d4;border-radius:6px;background:#fff;font-size:11px}.form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:1px solid #eee}.delete-form{display:inline}.empty{padding:35px;text-align:center;color:#8f8986;font-size:12px}@media(max-width:700px){.sidebar{position:relative;width:100%;min-height:auto;padding:22px 0}.app-shell{display:block}.brand{padding-bottom:20px}.nav{display:flex;flex-wrap:wrap}.nav-item{padding:10px 14px}.sidebar-footer{margin-top:18px}.content{width:100%;margin:0;padding:28px 16px}.topbar{align-items:flex-start;flex-direction:column;gap:12px}.stats{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.panel-toolbar{align-items:stretch;flex-direction:column;gap:10px}.search{width:100%}}
</style>
<script>document.addEventListener('DOMContentLoaded', function () { var backButton = document.querySelector('.form-actions .button.light'); if (backButton) backButton.textContent = 'BACK'; });</script>
</head>
<body><main class="app-shell"><aside class="sidebar"><div><div class="brand">COFFEE MAKER</div><nav class="nav"><a class="nav-item" href="dashboard.php">DASHBOARD</a><a class="nav-item" href="pos.php">POS</a><a class="nav-item" href="orders.php">ORDERS</a><a class="nav-item" href="products.php">PRODUCTS</a><a class="nav-item active" href="users.php">USERS</a></nav></div><div class="sidebar-footer"><span class="avatar">♙</span><span><?= htmlspecialchars($admin_username) ?></span></div></aside><section class="content"><header class="topbar"><div><h1>User Management</h1><p>Manage active cashiers and staff accounts</p></div><a class="button" href="users.php?add=1">＋ ADD NEW USER</a></header><?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (isset($_GET["add"]) || $editing_user): ?><section class="panel form-panel"><h2><?= $editing_user ? "Edit User" : "Add New User" ?></h2><form method="post"><input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="<?= (int) ($editing_user["id"] ?? 0) ?>"><div class="form-grid"><div class="field"><label>FULL NAME</label><input name="full_name" value="<?= htmlspecialchars($editing_user["full_name"] ?? "") ?>" placeholder="e.g. Jane Doe" required></div><div class="field"><label>USERNAME / EMAIL</label><input name="username" value="<?= htmlspecialchars($editing_user["username"] ?? "") ?>" required></div><div class="field"><label>ROLE</label><select name="role"><option <?= (($editing_user["role"] ?? "Staff") === "Staff") ? "selected" : "" ?>>Staff</option><option <?= (($editing_user["role"] ?? "") === "Manager") ? "selected" : "" ?>>Manager</option></select></div><div class="field"><label>STATUS</label><select name="status"><option <?= (($editing_user["status"] ?? "Active") === "Active") ? "selected" : "" ?>>Active</option><option <?= (($editing_user["status"] ?? "") === "Inactive") ? "selected" : "" ?>>Inactive</option></select></div><div class="field"><label>PASSWORD <?= $editing_user ? "(LEAVE BLANK TO KEEP CURRENT)" : "" ?></label><input type="password" name="password" <?= $editing_user ? "" : "required" ?>></div></div><div class="form-actions"><a class="button light" href="users.php">CANCEL</a><button class="button" type="submit">SAVE USER</button></div></form></section><?php else: ?><section class="stats"><article class="stat"><small>TOTAL USERS</small><strong><?= $total_users ?></strong></article><article class="stat"><small>ACTIVE NOW</small><strong><?= $active_users ?></strong></article><article class="stat"><small>MANAGERS</small><strong><?= $admins ?></strong></article></section><section class="panel"><form class="panel-toolbar" method="get"><input class="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or username..." aria-label="Search users"><button class="button light" type="submit">SEARCH</button></form><div class="table-wrap"><table><thead><tr><th>USER</th><th>ROLE</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody><?php if ($users): foreach ($users as $user): ?><tr><td><div class="user-cell"><span class="user-avatar"><?= htmlspecialchars(strtoupper(substr($user["full_name"], 0, 1))) ?></span><div><div class="user-name"><?= htmlspecialchars($user["full_name"]) ?></div><div class="user-email"><?= htmlspecialchars($user["username"]) ?></div></div></div></td><td><span class="badge role"><?= htmlspecialchars($user["role"]) ?></span></td><td><span class="badge <?= strtolower($user["status"]) === "active" ? "active" : "inactive" ?>"><?= htmlspecialchars($user["status"]) ?></span></td><td><div class="actions"><a class="icon-button" href="users.php?edit=<?= (int) $user["id"] ?>" title="Edit user">✎ Edit</a><form class="delete-form" method="post" onsubmit="return confirm('Delete this user?');"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= (int) $user["id"] ?>"><button class="icon-button delete" type="submit" title="Delete user">⌫ Delete</button></form></div></td></tr><?php endforeach; else: ?><tr><td class="empty" colspan="4">No users found.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?></section></main></body></html>
