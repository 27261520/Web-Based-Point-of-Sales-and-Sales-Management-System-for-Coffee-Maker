<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["admin"])) {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";

$admin_username = $_SESSION["admin"];
$user_role = $_SESSION["role"] ?? "admin";
$user_name = $_SESSION["username"] ?? $_SESSION["admin"] ?? "Admin";

// Restriction: Redirect Cashier away from User Management page
if (strtolower($user_role) === "cashier") {
    header("Location: pos.php");
    exit();
}

// Read and clear flash messages from session
$message = $_SESSION["message"] ?? "";
$error = $_SESSION["error"] ?? "";
unset($_SESSION["message"], $_SESSION["error"]);

// Fetch user contact/mobile number
$user_phone = "N/A";
$logged_username = $_SESSION["username"] ?? $_SESSION["admin"] ?? null;
if ($logged_username) {
    $u_stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    if ($u_stmt) {
        $u_stmt->bind_param("s", $logged_username);
        $u_stmt->execute();
        $u_res = $u_stmt->get_result();
        if ($u_row = $u_res->fetch_assoc()) {
            $user_phone = $u_row["phone"] ?? $u_row["mobile"] ?? $u_row["contact_number"] ?? $u_row["phone_number"] ?? "N/A";
        }
        $u_stmt->close();
    }
}

$editing_user = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $user_id = (int) ($_POST["user_id"] ?? 0);
    $full_name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $submitted_role = $_POST["role"] ?? "Cashier";
    // Only allow Admin or Cashier roles
    $role = in_array($submitted_role, ["Admin", "Cashier"], true) ? $submitted_role : "Cashier";
    $status = ($_POST["status"] ?? "Active") === "Inactive" ? "Inactive" : "Active";
    $password = (string) ($_POST["password"] ?? "");

    if ($action === "save_user") {
        if ($full_name === "" || $username === "") {
            $_SESSION["error"] = "Full name and username are required.";
        } elseif ($user_id > 0) {
            if ($password !== "") {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $statement = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, status = ?, password = ? WHERE id = ?");
                $statement->bind_param("sssssi", $full_name, $username, $role, $status, $hashed_password, $user_id);
            } else {
                $statement = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, status = ? WHERE id = ?");
                $statement->bind_param("ssssi", $full_name, $username, $role, $status, $user_id);
            }
            if ($statement->execute()) {
                $_SESSION["message"] = "User information updated successfully.";
                header("Location: users.php");
                exit();
            } else {
                $_SESSION["error"] = "Unable to update user information.";
            }
            $statement->close();
        } elseif ($password === "") {
            $_SESSION["error"] = "A password is required for a new user.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $statement = $conn->prepare("INSERT INTO users (full_name, username, role, status, password) VALUES (?, ?, ?, ?, ?)");
            $statement->bind_param("sssss", $full_name, $username, $role, $status, $hashed_password);
            if ($statement->execute()) {
                $_SESSION["message"] = "New user added successfully!";
                header("Location: users.php");
                exit();
            } else {
                $_SESSION["error"] = "Unable to add user. The username may already exist.";
            }
            $statement->close();
        }
    } elseif ($action === "delete_user" && $user_id > 0) {
        // Fetch details of user to be deleted
        $check_stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $target_user = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if (!$target_user) {
            $_SESSION["error"] = "User not found.";
        } elseif ($target_user["username"] === $admin_username) {
            $_SESSION["error"] = "You cannot delete the currently logged-in Admin account.";
        } else {
            // Check if user is Admin and if deleting them leaves 0 Admins
            if (strtolower($target_user["role"]) === "admin") {
                $admin_count_stmt = $conn->prepare("SELECT COUNT(*) as admin_count FROM users WHERE LOWER(role) = 'admin'");
                $admin_count_stmt->execute();
                $admin_res = $admin_count_stmt->get_result()->fetch_assoc();
                $admin_count_stmt->close();

                if (($admin_res["admin_count"] ?? 0) <= 1) {
                    $_SESSION["error"] = "Cannot delete this Admin account because at least one Admin must remain in the system.";
                }
            }

            if (empty($_SESSION["error"])) {
                $statement = $conn->prepare("DELETE FROM users WHERE id = ?");
                $statement->bind_param("i", $user_id);
                if ($statement->execute() && $statement->affected_rows > 0) {
                    $_SESSION["message"] = "User deleted successfully.";
                } else {
                    $_SESSION["error"] = "Unable to delete user.";
                }
                $statement->close();
            }
        }
        header("Location: users.php");
        exit();
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
$admins = count(array_filter($users, fn($user) => strtolower($user["role"]) === "admin"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management | Coffee Maker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #f7f7f7; color: #2b211e; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.app-shell { min-height: 100vh; display: flex; }

/* SIDEBAR STYLES */
.sidebar { width: 245px; min-height: 100vh; padding: 30px 18px 20px; background: #2b1610; color: #fff; position: fixed; left: 0; top: 0; bottom: 0; display: flex; flex-direction: column; justify-content: space-between; font-family: Arial, Helvetica, sans-serif; }
.brand { padding: 0 16px 35px; font-size: 20px; font-weight: 700; letter-spacing: 1px; }
.nav { display: flex; flex-direction: column; gap: 8px; }
.nav-item { padding: 14px 16px; color: #aeb3bd; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; letter-spacing: .5px; transition: .2s; }
.nav-item:hover, .nav-item.active { background: #74473b; color: #fff; }
.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; }

/* PROFILE & POPOVER WIDGET STYLES */
.user-wrapper { position: relative; flex: 1; }
.user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 12px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; transition: background 0.2s; user-select: none; }
.user:hover { background: #3c2018; }
.avatar { width: 34px; height: 34px; border-radius: 50%; background: #60463e; display: grid; place-items: center; font-size: 14px; color: #fff; flex-shrink: 0; }
.user-details-text { display: flex; flex-direction: column; line-height: 1.25; }
.sidebar .user-name { color: #fff; font-size: 13px; font-weight: 600; }
.sidebar .user-role { color: #aeb3bd; font-size: 10px; }
.toggle-icon { margin-left: auto; font-size: 10px; color: #aeb3bd; transition: transform 0.2s; }
.user.active .toggle-icon { transform: rotate(180deg); }

.user-popover { position: absolute; bottom: calc(100% + 12px); left: 0; width: 210px; background: #ffffff; color: #20242a; border-radius: 8px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25); padding: 14px; display: none; z-index: 100; animation: popoverFadeIn 0.2s ease; }
.user-popover.show { display: block; }
@keyframes popoverFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
.popover-header { display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
.popover-avatar { font-size: 28px; color: #74473b; }
.badge-role { display: inline-block; font-size: 9px; background: #f0ecea; color: #74473b; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-top: 3px; }
.popover-body { padding: 10px 0; }
.info-row { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #555; }
.info-row i { color: #74473b; width: 14px; }
.popover-footer { padding-top: 10px; border-top: 1px solid #eee; }
.popover-logout { display: flex; align-items: center; gap: 8px; color: #e74c3c; text-decoration: none; font-size: 12px; font-weight: 600; padding: 6px 8px; border-radius: 5px; transition: background 0.15s; }
.popover-logout:hover { background: #fdf2f2; }

.settings { border: 0; background: transparent; color: #fff; font-size: 18px; text-decoration: none; cursor: pointer; display: flex; align-items: center; }

/* MAIN CONTENT AREA STYLES */
.content { width: calc(100% - 245px); margin-left: 245px; padding: 35px 40px; }
.topbar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
.topbar h1 { font-size: 28px; font-weight: 700; color: #20242b; }
.button { height: 38px; border: 0; border-radius: 6px; padding: 0 16px; background: #2b211e; color: #fff; font-size: 10px; font-weight: 700; cursor: pointer; text-decoration: none; font-family: inherit; display: inline-flex; align-items: center; justify-content: center; }
.button:hover { background: #54392f; }
.button.light { background: #fff; color: #54392f; border: 1px solid #ded7d4; }
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 22px; }
.stat, .panel { border: 1px solid #eeeae8; border-radius: 8px; background: #fff; }
.stat { padding: 18px; }
.stat small { color: #8f8986; font-size: 9px; }
.stat strong { display: block; margin-top: 9px; font-size: 22px; }
.panel { overflow: hidden; }
.panel-toolbar { display: flex; justify-content: flex-start; align-items: center; gap: 8px; padding: 16px; border-bottom: 1px solid #f0edeb; }
.search { height: 38px; width: min(100%, 300px); padding: 0 12px; border: 1px solid #e5e1df; border-radius: 6px; font-size: 11px; font-family: inherit; }
.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 650px; border-collapse: collapse; }
th { padding: 12px 16px; background: #fcfbfb; color: #756b67; font-size: 9px; text-align: left; }
td { padding: 14px 16px; border-top: 1px solid #f0edeb; font-size: 11px; color: #4c4441; }
.user-cell .user-name { font-weight: 700; color: #2b211e; }
.user-cell .user-email { margin-top: 4px; color: #999; font-size: 10px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #111; color: #fff; display: grid; place-items: center; font-weight: 700; }
.user-cell { display: flex; align-items: center; gap: 10px; }
.badge { display: inline-block; padding: 5px 10px; border-radius: 12px; font-size: 9px; font-weight: 700; }
.badge.active { background: #e2f2e5; color: #28763b; }
.badge.inactive { background: #eeeae8; color: #756b67; }
.badge.role { background: #fff4d8; color: #9a5d19; }

/* ACTION BUTTONS */
.actions { display: flex; align-items: center; gap: 8px; }
.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
}
.btn-action.edit {
    background-color: #f2ece9;
    color: #54392f;
}
.btn-action.edit:hover {
    background-color: #54392f;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(84, 57, 47, 0.15);
}
.btn-action.delete {
    background-color: #fdf2f2;
    color: #a34a3f;
}
.btn-action.delete:hover {
    background-color: #a34a3f;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(163, 74, 63, 0.15);
}

.notice { margin-bottom: 15px; padding: 11px 13px; border-radius: 6px; background: #e2f2e5; color: #28763b; font-size: 11px; }
.notice.error { background: #f9e3e0; color: #a53e35; }
.form-panel { max-width: 760px; padding: 20px; }
.form-panel h2 { font-size: 17px; margin-bottom: 18px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.field label { display: block; margin-bottom: 7px; color: #756b67; font-size: 9px; font-weight: 700; }
.field input, .field select { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #ded7d4; border-radius: 6px; background: #fff; font-size: 11px; font-family: inherit; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #eee; }
.empty { padding: 35px; text-align: center; color: #8f8986; font-size: 12px; }

/* SYSTEM SYSTEM-MATCHED DELETE MODAL STYLES */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    backdrop-filter: blur(2px);
    animation: fadeInModal 0.2s ease-out;
}
.modal-overlay.show { display: flex; }
@keyframes fadeInModal {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
.modal-card {
    background: #ffffff;
    border-radius: 16px;
    width: min(92%, 400px);
    padding: 28px 24px 24px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
    text-align: center;
}
.warning-icon-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: #fdf2f2;
    border-radius: 50%;
    margin-bottom: 14px;
}
.warning-icon {
    font-size: 20px;
    color: #dc2626;
}
.modal-card h3 {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 6px;
}
.modal-subtitle {
    font-size: 12px;
    color: #4b5563;
    margin-bottom: 16px;
    line-height: 1.4;
}
.impact-notice {
    background: #fef2f2;
    border: 1px solid #fee2e2;
    border-radius: 8px;
    padding: 12px 14px;
    text-align: left;
    margin-bottom: 20px;
}
.impact-title {
    font-size: 11px;
    font-weight: 700;
    color: #991b1b;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
}
.impact-text {
    font-size: 11px;
    color: #991b1b;
    line-height: 1.4;
    opacity: 0.9;
}
.modal-footer {
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn-modal-cancel {
    flex: 1;
    height: 40px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    color: #374151;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
    font-family: inherit;
}
.btn-modal-cancel:hover {
    background: #f9fafb;
}
.btn-modal-delete {
    width: 100%;
    height: 40px;
    background: #dc2626;
    border: none;
    border-radius: 8px;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.15s;
    font-family: inherit;
}
.btn-modal-delete:hover {
    background: #b91c1c;
}

@media (max-width: 700px) {
    .sidebar { width: 100%; min-height: auto; padding: 22px 0; position: relative; left: auto; top: auto; bottom: auto; }
    .brand { padding-bottom: 20px; }
    .nav { flex-direction: row; flex-wrap: wrap; }
    .nav-item { padding: 10px 14px; }
    .sidebar-footer { margin-top: 18px; }
    .content { width: 100%; margin-left: 0; padding: 28px 16px; }
    .topbar { align-items: flex-start; flex-direction: column; gap: 12px; }
    .stats { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .panel-toolbar { align-items: stretch; flex-direction: column; gap: 10px; }
    .search { width: 100%; }
}
</style>
<link rel="stylesheet" href="../assets/sidebar.css">
<script>
document.addEventListener('DOMContentLoaded', function () {
    var backButton = document.querySelector('.form-actions .button.light');
    if (backButton) backButton.textContent = 'BACK';
});
</script>
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div>
			<div class="brand">COFFEE MAKER</div>
			<nav class="nav">
				<?php if (strtolower($user_role) === 'admin'): ?>
					<a class="nav-item" href="dashboard.php">DASHBOARD</a>
					<a class="nav-item" href="pos.php">POS</a>
					<a class="nav-item" href="orders.php">ORDERS</a>
					<a class="nav-item" href="products.php">PRODUCTS</a>
					<a class="nav-item active" href="users.php">USERS</a>
				<?php else: ?>
					<a class="nav-item active" href="pos.php">POS</a>
					<a class="nav-item" href="orders.php">ORDERS</a>
				<?php endif; ?>
			</nav>
		</div>

		<div class="sidebar-footer">
			<div class="user-wrapper">
				<div class="user" id="userProfileBtn" role="button" tabindex="0">
					<div class="avatar"><i class="fas fa-user"></i></div>
					<div class="user-details-text">
						<span class="user-name"><?= htmlspecialchars($user_name) ?></span>
						<small class="user-role"><?= htmlspecialchars(ucfirst($user_role)) ?></small>
					</div>
					<i class="fas fa-chevron-up toggle-icon"></i>
				</div>

				<div class="user-popover" id="userPopover">
					<div class="popover-header">
						<div class="popover-avatar"><i class="fas fa-user-circle"></i></div>
						<div>
							<strong><?= htmlspecialchars($user_name) ?></strong>
							<span class="badge-role"><?= htmlspecialchars(strtoupper($user_role)) ?></span>
						</div>
					</div>
					<div class="popover-footer">
						<a href="../logout.php" class="popover-logout">
							<i class="fas fa-sign-out-alt"></i> Log Out
						</a>
					</div>
				</div>
			</div>

			<a class="settings" href="settings.php" aria-label="Settings" title="Settings"><i class="fas fa-cog"></i></a>
		</div>
	</aside>

	<section class="content">
		<header class="topbar">
			<div>
				<h1>User Management</h1>
			</div>
			<a class="button" href="users.php?add=1">＋ ADD NEW USER</a>
		</header>

		<?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
		<?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

		<?php if (isset($_GET["add"]) || $editing_user): ?>
			<section class="panel form-panel">
				<h2><?= $editing_user ? "Edit User" : "Add New User" ?></h2>
				<form method="post">
					<input type="hidden" name="action" value="save_user">
					<input type="hidden" name="user_id" value="<?= (int) ($editing_user["id"] ?? 0) ?>">
					<div class="form-grid">
						<div class="field">
							<label>FULL NAME</label>
							<input name="full_name" value="<?= htmlspecialchars($editing_user["full_name"] ?? "") ?>" placeholder="e.g. Jane Doe" required>
						</div>
						<div class="field">
							<label>USERNAME / EMAIL</label>
							<input name="username" value="<?= htmlspecialchars($editing_user["username"] ?? "") ?>" required>
						</div>
						<div class="field">
							<label>ROLE</label>
							<select name="role">
								<option value="Admin" <?= (($editing_user["role"] ?? "") === "Admin") ? "selected" : "" ?>>Admin</option>
								<option value="Cashier" <?= (($editing_user["role"] ?? "Cashier") === "Cashier") ? "selected" : "" ?>>Cashier</option>
							</select>
						</div>
						<div class="field">
							<label>STATUS</label>
							<select name="status">
								<option <?= (($editing_user["status"] ?? "Active") === "Active") ? "selected" : "" ?>>Active</option>
								<option <?= (($editing_user["status"] ?? "") === "Inactive") ? "selected" : "" ?>>Inactive</option>
							</select>
						</div>
						<div class="field">
							<label>PASSWORD <?= $editing_user ? "(LEAVE BLANK TO KEEP CURRENT)" : "" ?></label>
							<input type="password" name="password" <?= $editing_user ? "" : "required" ?>>
						</div>
					</div>
					<div class="form-actions">
						<a class="button light" href="users.php">CANCEL</a>
						<button class="button" type="submit">SAVE USER</button>
					</div>
				</form>
			</section>
		<?php else: ?>
			<section class="stats">
				<article class="stat">
					<small>TOTAL USERS</small>
					<strong><?= $total_users ?></strong>
				</article>
				<article class="stat">
					<small>ACTIVE NOW</small>
					<strong><?= $active_users ?></strong>
				</article>
				<article class="stat">
					<small>ADMINS</small>
					<strong><?= $admins ?></strong>
				</article>
			</section>

			<section class="panel">
				<form class="panel-toolbar" method="get">
					<input class="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or username..." aria-label="Search users">
					<button class="button" type="submit">SEARCH</button>
				</form>
				<div class="table-wrap">
					<table>
						<thead>
							<tr>
								<th>USER</th>
								<th>ROLE</th>
								<th>STATUS</th>
								<th>ACTIONS</th>
							</tr>
						</thead>
						<tbody>
						<?php if ($users): foreach ($users as $user): ?>
							<tr>
								<td>
									<div class="user-cell">
										<span class="user-avatar"><?= htmlspecialchars(strtoupper(substr($user["full_name"], 0, 1))) ?></span>
										<div>
											<div class="user-name"><?= htmlspecialchars($user["full_name"]) ?></div>
											<div class="user-email"><?= htmlspecialchars($user["username"]) ?></div>
										</div>
									</div>
								</td>
								<td><span class="badge role"><?= htmlspecialchars($user["role"]) ?></span></td>
								<td><span class="badge <?= strtolower($user["status"]) === "active" ? "active" : "inactive" ?>"><?= htmlspecialchars($user["status"]) ?></span></td>
								<td>
									<div class="actions">
										<a class="btn-action edit" href="users.php?edit=<?= (int) $user["id"] ?>" title="Edit User">
											<i class="fas fa-pen"></i>
											<span>Edit</span>
										</a>
										<button class="btn-action delete" type="button" onclick="openDeleteModal(<?= (int) $user['id'] ?>, '<?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?>')" title="Delete User">
											<i class="fas fa-trash-alt"></i>
											<span>Delete</span>
										</button>
									</div>
								</td>
							</tr>
						<?php endforeach; else: ?>
							<tr><td class="empty" colspan="4">No users found.</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php endif; ?>
	</section>
</main>

<!-- SYSTEM-MATCHED DELETE CONFIRMATION MODAL -->
<div id="deleteModal" class="modal-overlay">
	<div class="modal-card">
		<div class="warning-icon-wrapper">
			<i class="fas fa-exclamation-triangle warning-icon"></i>
		</div>
		<h3>Delete User?</h3>
		<p class="modal-subtitle">Are you sure you want to delete "<span id="deleteUserName"></span>"?</p>

		<div class="impact-notice">
			<div class="impact-title">
				<i class="fas fa-exclamation-circle"></i> Impact Notice
			</div>
			<div class="impact-text">
				This user account will be permanently removed from the system and will no longer have access to the POS.
			</div>
		</div>

		<div class="modal-footer">
			<button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button>
			<form id="confirmDeleteForm" method="post" style="margin: 0; flex: 1;">
				<input type="hidden" name="action" value="delete_user">
				<input type="hidden" name="user_id" id="deleteUserId" value="">
				<button type="submit" class="btn-modal-delete">
					<i class="fas fa-trash-alt"></i> Delete
				</button>
			</form>
		</div>
	</div>
</div>

<script>
const userProfileBtn = document.getElementById('userProfileBtn');
const userPopover = document.getElementById('userPopover');

if (userProfileBtn && userPopover) {
	userProfileBtn.addEventListener('click', (event) => {
		event.stopPropagation();
		userProfileBtn.classList.toggle('active');
		userPopover.classList.toggle('show');
	});

	document.addEventListener('click', (event) => {
		if (!userPopover.contains(event.target) && !userProfileBtn.contains(event.target)) {
			userProfileBtn.classList.remove('active');
			userPopover.classList.remove('show');
		}
	});
}

// DELETE MODAL FUNCTIONS
function openDeleteModal(userId, userName) {
	document.getElementById('deleteUserId').value = userId;
	document.getElementById('deleteUserName').textContent = userName;
	document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
	document.getElementById('deleteModal').classList.remove('show');
}

// Close modal when clicking outside the modal card
document.getElementById('deleteModal').addEventListener('click', function (e) {
	if (e.target === this) {
		closeDeleteModal();
	}
});
</script>
<!-- ===== Log Out Warning Modal ===== -->
<style>
.lgo-overlay { position: fixed; inset: 0; background: rgba(43, 22, 16, 0.55); display: none; align-items: center; justify-content: center; z-index: 99999; padding: 16px; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.lgo-overlay.lgo-open { display: flex; }
.lgo-box { width: min(100%, 360px); background: #fff; border-radius: 12px; padding: 26px 24px 20px; text-align: center; box-shadow: 0 18px 45px rgba(0,0,0,.3); animation: lgoPop .18s ease-out; }
@keyframes lgoPop { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.lgo-icon { width: 54px; height: 54px; margin: 0 auto 14px; border-radius: 50%; background: #fdecea; color: #e74c3c; display: grid; place-items: center; font-size: 22px; }
.lgo-box h3 { font-size: 17px; font-weight: 800; color: #2b1610; margin: 0 0 6px; }
.lgo-box p { font-size: 12px; color: #5c4f4a; line-height: 1.5; margin: 0 0 20px; }
.lgo-actions { display: flex; gap: 10px; }
.lgo-actions button, .lgo-actions a { flex: 1; height: 40px; border-radius: 8px; font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
.lgo-cancel { border: 1px solid #ded5d1; background: #fff; color: #2b1610; }
.lgo-cancel:hover { background: #f7f3f1; }
.lgo-confirm { border: 0; background: #e74c3c; color: #fff; }
.lgo-confirm:hover { background: #cf3f2f; }
</style>

<div class="lgo-overlay" id="lgoOverlay" role="dialog" aria-modal="true" aria-labelledby="lgoTitle">
    <div class="lgo-box">
        <div class="lgo-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3 id="lgoTitle">Log out?</h3>
        <p>Are you sure you want to log out? You will need to sign in again to continue.</p>
        <div class="lgo-actions">
            <button type="button" class="lgo-cancel" id="lgoCancel">Cancel</button>
            <a href="../logout.php" class="lgo-confirm" id="lgoConfirm">Yes, Log Out</a>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('lgoOverlay');
    var cancelBtn = document.getElementById('lgoCancel');
    var confirmBtn = document.getElementById('lgoConfirm');
    if (!overlay) return;

    function openLogoutWarning() { overlay.classList.add('lgo-open'); cancelBtn.focus(); }
    function closeLogoutWarning() { overlay.classList.remove('lgo-open'); }

    document.querySelectorAll('.popover-logout').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            confirmBtn.setAttribute('href', link.getAttribute('href'));
            openLogoutWarning();
        });
    });

    cancelBtn.addEventListener('click', closeLogoutWarning);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeLogoutWarning(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLogoutWarning(); });
})();
</script>
<!-- ===== End Log Out Warning Modal ===== -->
</body>
</html>