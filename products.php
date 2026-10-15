<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["admin"])) {
	header("Location: ../index.php");
	exit();
}

require_once "../config/database.php";

$user_role = $_SESSION["role"] ?? "admin";
$user_name = $_SESSION["username"] ?? $_SESSION["admin"] ?? "Admin";

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

// Ensure required table columns exist
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'Active'");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS addons TEXT NULL AFTER price");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER category");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER addons");
$conn->query("CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'Active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$upload_directory = "../assets/images/products/";
if (!is_dir($upload_directory)) {
	mkdir($upload_directory, 0755, true);
}

$message = "";
$edit_product = null;
$active_tab = ($_GET["tab"] ?? "products") === "categories" ? "categories" : "products";
$edit_category = null;
$delete_category = null;
$delete_product = null;
$show_add_form = isset($_GET["add_category"]) && $_SERVER["REQUEST_METHOD"] !== "POST";
$add_form_data = [
    "name" => "",
    "description" => ""
];

function decode_addons($value) {
	$decoded = json_decode((string) $value, true);
	if (is_array($decoded)) {
		$values = is_array($decoded) ? $decoded : explode(",", (string) $value);
		$addons = [];
		foreach ($values as $addon) {
			$name = is_array($addon) ? trim((string) ($addon["name"] ?? "")) : trim((string) $addon);
			if ($name === "") {
				continue;
			}
			$addons[] = [
				"name" => $name,
				"price" => is_array($addon) ? max(0, (float) ($addon["price"] ?? 0)) : 0,
			];
		}
		return $addons;
	}
	$addons = [];
	foreach (explode(",", (string) $value) as $addon) {
		$name = trim($addon);
		if ($name !== "") {
			$addons[] = ["name" => $name, "price" => 0];
		}
	}
	return $addons;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$action = $_POST["action"] ?? "";

	if ($action === "save_category") {
		$category_id = (int) ($_POST["category_id"] ?? 0);
		$category_name = trim($_POST["category_name"] ?? "");
		$category_description = trim($_POST["category_description"] ?? "");
		$category_status = ($_POST["category_status"] ?? "Active") === "Inactive" ? "Inactive" : "Active";

		$has_dup_name = false;

		if ($category_name === "") {
			$message = "Enter a category name.";
		} else {
			$check_name = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id != ? AND status != 'Inactive' LIMIT 1");
			$check_name->bind_param("si", $category_name, $category_id);
			$check_name->execute();
			$has_dup_name = (bool) $check_name->get_result()->fetch_assoc();
			$check_name->close();

			if ($has_dup_name) {
				$message = "Category name already exists.";
			} else {
				if ($category_id > 0) {
					$old_statement = $conn->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
					$old_statement->bind_param("i", $category_id);
					$old_statement->execute();
					$old_category = $old_statement->get_result()->fetch_assoc();
					$old_statement->close();

					$statement = $conn->prepare("UPDATE categories SET name = ?, description = ?, status = ? WHERE id = ?");
					$statement->bind_param("sssi", $category_name, $category_description, $category_status, $category_id);
					if ($statement->execute()) {
						if ($old_category && $old_category["name"] !== $category_name) {
							$rename_statement = $conn->prepare("UPDATE products SET category = ? WHERE category = ?");
							$rename_statement->bind_param("ss", $category_name, $old_category["name"]);
							$rename_statement->execute();
							$rename_statement->close();
						}
						$message = "Category updated successfully.";
					} else {
						$message = "Unable to update category.";
					}
					$statement->close();
				} else {
					$statement = $conn->prepare("INSERT INTO categories (name, description, status) VALUES (?, ?, 'Active') ON DUPLICATE KEY UPDATE description = VALUES(description), status = 'Active'");
					$statement->bind_param("ss", $category_name, $category_description);
					if ($statement->execute()) {
						$message = "Category added successfully.";
					} else {
						$message = "Unable to add category.";
					}
					$statement->close();
				}
			}
		}

		if ($category_name === "" || $has_dup_name) {
			if ($category_id > 0) {
				$edit_category = [
					"id" => $category_id,
					"name" => $category_name,
					"description" => $category_description,
					"status" => $category_status,
				];
			} else {
				$show_add_form = true;
				$add_form_data = [
					"name" => $category_name,
					"description" => $category_description,
				];
			}
		}

		$active_tab = "categories";
	}

	if ($action === "delete_category") {
		$category_id = (int) ($_POST["category_id"] ?? 0);
		$statement = $conn->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
		$statement->bind_param("i", $category_id);
		$statement->execute();
		$category = $statement->get_result()->fetch_assoc();
		$statement->close();
		if ($category) {
			$cat_name = trim($category["name"]);

			// Set all products under this category to Inactive so they don't linger
			$clear_statement = $conn->prepare("UPDATE products SET status = 'Inactive' WHERE LOWER(TRIM(category)) = LOWER(TRIM(?))");
			$clear_statement->bind_param("s", $cat_name);
			$clear_statement->execute();
			$clear_statement->close();

			$delete_statement = $conn->prepare("DELETE FROM categories WHERE id = ?");
			$delete_statement->bind_param("i", $category_id);
			$delete_statement->execute();
			$delete_statement->close();

			$message = "Category and its products deleted successfully.";
		}
		$active_tab = "categories";
	}

	if ($action === "save") {
		$product_id = (int) ($_POST["product_id"] ?? 0);
		$product_name = trim($_POST["product_name"] ?? "");
		$category = trim($_POST["category"] ?? "");
		$price = (float) ($_POST["price"] ?? 0);
		$addon_values = is_array($_POST["addons"] ?? null) ? $_POST["addons"] : [];
		$addon_prices = is_array($_POST["addon_prices"] ?? null) ? $_POST["addon_prices"] : [];
		$addon_records = [];
		foreach ($addon_values as $index => $addon_value) {
			$addon_name = trim((string) $addon_value);
			if ($addon_name !== "") {
				$addon_records[] = ["name" => $addon_name, "price" => max(0, (float) ($addon_prices[$index] ?? 0))];
			}
		}
		$addons = json_encode($addon_records, JSON_UNESCAPED_UNICODE);
		$image_path = trim($_POST["current_image"] ?? "");

		if (isset($_FILES["product_image"]) && $_FILES["product_image"]["error"] !== UPLOAD_ERR_NO_FILE) {
			$uploaded_file = $_FILES["product_image"];
			$allowed_types = ["image/jpeg", "image/png", "image/webp"];
			$file_type = mime_content_type($uploaded_file["tmp_name"]);

			if ($uploaded_file["error"] !== UPLOAD_ERR_OK || !in_array($file_type, $allowed_types, true)) {
				$message = "Upload a valid JPG, PNG, or WEBP product image.";
			} elseif ($uploaded_file["size"] > 5 * 1024 * 1024) {
				$message = "Product images must be 5 MB or smaller.";
			} else {
				$extension = strtolower(pathinfo($uploaded_file["name"], PATHINFO_EXTENSION));
				$file_name = "product_" . bin2hex(random_bytes(8)) . "." . $extension;
				if (move_uploaded_file($uploaded_file["tmp_name"], $upload_directory . $file_name)) {
					$image_path = "assets/images/products/" . $file_name;
				} else {
					$message = "The product image could not be uploaded.";
				}
			}
		}

		if ($message === "" && $product_name !== "" && $category !== "" && $price >= 0) {
			if ($product_id > 0) {
				$statement = $conn->prepare("UPDATE products SET product_name = ?, category = ?, price = ?, addons = ?, image_path = ? WHERE id = ?");
				$statement->bind_param("ssdssi", $product_name, $category, $price, $addons, $image_path, $product_id);
				$message = "Product updated successfully.";
			} else {
				$status = "Active";
				$statement = $conn->prepare("INSERT INTO products (product_name, category, price, addons, image_path, status) VALUES (?, ?, ?, ?, ?, ?)");
				$statement->bind_param("ssdsss", $product_name, $category, $price, $addons, $image_path, $status);
				$message = "Product added successfully.";
			}

			if ($statement && !$statement->execute()) {
				$message = "Unable to save the product.";
			}
			if ($statement) {
				$statement->close();
			}
		} else {
			$message = "Enter a product name, category, and valid price.";
		}
	}

	if ($action === "delete") {
		$product_id = (int) ($_POST["product_id"] ?? 0);
		$statement = $conn->prepare("UPDATE products SET status = 'Inactive' WHERE id = ?");
		$statement->bind_param("i", $product_id);
		$statement->execute();
		$statement->close();
		$message = "Product removed from the menu.";
	}
}

if (isset($_GET["edit"])) {
	$product_id = (int) $_GET["edit"];
	$statement = $conn->prepare("SELECT id, product_name, category, price, addons, image_path FROM products WHERE id = ? AND status != 'Inactive' LIMIT 1");
	$statement->bind_param("i", $product_id);
	$statement->execute();
	$edit_result = $statement->get_result();
	$edit_product = $edit_result->fetch_assoc() ?: null;
	$statement->close();
}

if (isset($_GET["edit_category"]) && $_SERVER["REQUEST_METHOD"] !== "POST") {
	$category_id = (int) $_GET["edit_category"];
	$statement = $conn->prepare("SELECT id, name, description, status FROM categories WHERE id = ? AND status != 'Inactive' LIMIT 1");
	$statement->bind_param("i", $category_id);
	$statement->execute();
	$edit_category = $statement->get_result()->fetch_assoc() ?: null;
	$statement->close();
}

if (isset($_GET["delete_category"])) {
	$category_id = (int) $_GET["delete_category"];
	$statement = $conn->prepare("SELECT id, name FROM categories WHERE id = ? AND status != 'Inactive' LIMIT 1");
	$statement->bind_param("i", $category_id);
	$statement->execute();
	$delete_category = $statement->get_result()->fetch_assoc() ?: null;
	$statement->close();
}

if (isset($_GET["delete"])) {
	$product_id = (int) $_GET["delete"];
	$statement = $conn->prepare("SELECT id, product_name FROM products WHERE id = ? AND status != 'Inactive' LIMIT 1");
	$statement->bind_param("i", $product_id);
	$statement->execute();
	$delete_product = $statement->get_result()->fetch_assoc() ?: null;
	$statement->close();
}

$products = [];
$result = $conn->query("SELECT id, product_name, category, price, addons, image_path FROM products WHERE status != 'Inactive' ORDER BY product_name ASC");
if ($result) {
	while ($row = $result->fetch_assoc()) {
		$products[] = $row;
	}
}

$stored_categories = [];
$category_result = $conn->query("SELECT id, name, description, status FROM categories WHERE status != 'Inactive' ORDER BY name ASC");
if ($category_result) {
	while ($row = $category_result->fetch_assoc()) {
		$stored_categories[$row["name"]] = $row;
	}
}

$categories = array_keys($stored_categories);

$category_rows = [];
foreach ($stored_categories as $cat_name => $cat_data) {
	$product_count = 0;
	foreach ($products as $product) {
		if (strcasecmp($product["category"], $cat_name) === 0) {
			$product_count++;
		}
	}
	$category_rows[] = [
		"id" => (int) $cat_data["id"],
		"name" => $cat_name,
		"description" => $cat_data["description"] !== "" ? $cat_data["description"] : ($product_count . " product" . ($product_count === 1 ? "" : "s") . " in this category"),
		"product_count" => $product_count,
	];
}

usort($category_rows, function ($a, $b) {
	return strcasecmp($a["name"], $b["name"]);
});

$form_addons = $edit_product ? decode_addons($edit_product["addons"] ?? "") : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Coffee Maker Products</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		[hidden] { display: none !important; }
		body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background: #f7f7f7; color: #000000; }
		.app-shell { min-height: 100vh; display: flex; }
		
		/* Sidebar Styles */
		.sidebar { width: 245px; min-height: 100vh; padding: 30px 18px 20px; background: #2B1610; color: #fff; position: fixed; left: 0; top: 0; bottom: 0; display: flex; flex-direction: column; justify-content: space-between; font-family: Arial, Helvetica, sans-serif; }
		.brand { padding: 0 16px 35px; font-size: 20px; font-weight: 700; letter-spacing: 1px; }
		.nav { display: flex; flex-direction: column; gap: 8px; }
		.nav-item { padding: 14px 16px; color: #aeb3bd; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; letter-spacing: .5px; transition: .2s; }
		.nav-item:hover, .nav-item.active { background: #74473b; color: #fff; }
		.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; }

		/* Profile & Popover Styles */
		.user-wrapper { position: relative; flex: 1; }
		.user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 12px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; transition: background 0.2s; user-select: none; }
		.user:hover { background: #3c2018; }
		.avatar { width: 34px; height: 34px; border-radius: 50%; background: #60463e; display: grid; place-items: center; font-size: 14px; color: #fff; flex-shrink: 0; }
		.user-details-text { display: flex; flex-direction: column; line-height: 1.25; }
		.user-name { color: #fff; font-size: 13px; font-weight: 600; }
		.user-role { color: #aeb3bd; font-size: 10px; }
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

		.settings { border: 0; background: transparent; color: #fff; font-size: 18px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }

		/* Content Area Styles */
		.content { width: calc(100% - 245px); margin-left: 245px; padding: 35px 40px; color: #000000; }
		.topbar { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 30px; }
		.topbar h1 { font-size: 28px; margin-bottom: 6px; }
		.subtitle { color: #000000; font-size: 13px; }

		.add-button, .save-button {
			border: none;
			border-radius: 8px;
			background: #74473b;
			color: #ffffff;
			padding: 10px 18px;
			font-size: 11px;
			font-family: inherit;
			font-weight: 700;
			cursor: pointer;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			transition: all 0.2s ease;
			box-shadow: 0 2px 6px rgba(116, 71, 59, 0.2);
		}
		.add-button:hover, .save-button:hover {
			background: #5c382f;
			transform: translateY(-1px);
			box-shadow: 0 4px 10px rgba(116, 71, 59, 0.3);
		}

		.menu-tabs { display: flex; gap: 8px; margin-top: 10px; } 
		.menu-tab { border: 0; border-radius: 16px; padding: 8px 14px; color: #000000; background: #ececee; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; text-decoration: none; } 
		.menu-tab.active, .menu-tab:hover { background: #74473b; color: #ffffff; }

		.product-toolbar { margin-bottom: 25px; } 
		.product-search { width: min(100%, 480px); height: 40px; border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; padding: 0 14px; font-size: 12px; font-family: inherit; outline: none; color: #000000; } 
		.product-search:focus { border-color: #74473b; }

		.category-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; } 
		.category-filter { border: 0; border-radius: 16px; padding: 8px 13px; color: #000000; background: #ececee; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; } 
		.category-filter.active, .category-filter:hover { background: #74473b; color: #ffffff; }

		.category-header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }

		.category-table-wrap { overflow-x: auto; border: 1px solid #ededed; border-radius: 8px; background: #fff; } 
		.category-table { width: 100%; min-width: 650px; border-collapse: collapse; color: #000000; } 
		.category-table th { padding: 14px 16px; background: #faf9f8; color: #000000; font-size: 10px; text-align: left; font-weight: 700; border-bottom: 1px solid #eee; } 
		.category-table td { padding: 14px 16px; border-top: 1px solid #f0edeb; color: #000000; font-size: 11px; } 
		.category-table td:first-child { width: 45px; text-align: center; } 
		.category-name { color: #000000; font-weight: 700; } 
		.category-status { display: inline-block; padding: 4px 8px; border-radius: 4px; background: #e2f2e5; color: #28763b; font-size: 9px; font-weight: 700; }

		/* Action Buttons */
		.category-actions, .card-actions { display: flex; align-items: center; gap: 8px; }
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

		.category-edit-page { background: #fff; border: 1px solid #ededed; border-radius: 8px; padding: 24px; color: #000000; } 
		.category-edit-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; } 
		.category-edit-heading a { color: #000000; text-decoration: none; font-size: 22px; font-weight: 700; } 
		.category-edit-heading h2 { font-size: 20px; color: #000000; font-weight: 700; } 
		.category-edit-heading p { margin-top: 5px; color: #000000; font-size: 11px; }
		.category-status-field { margin-top: 16px; color: #000000; font-size: 11px; } 
		.category-status-field input { width: auto; height: auto; margin-right: 7px; accent-color: #000000; } 
		.category-form-note { margin-top: 8px; color: #000000; font-size: 10px; }

		/* Pop-up Modal Styles */
		.category-modal { 
			position: fixed; 
			inset: 0; 
			z-index: 1000; 
			display: grid; 
			place-items: center; 
			padding: 20px; 
			background: rgba(15, 10, 8, 0.65); 
			backdrop-filter: blur(4px); 
		} 
		.category-confirm { 
			width: min(100%, 420px); 
			padding: 28px 24px; 
			border-radius: 12px; 
			background: #ffffff; 
			box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25); 
			text-align: center; 
			color: #20242a; 
			border: 1px solid #f0edeb; 
		} 
		.warning-icon { 
			width: 56px; 
			height: 56px; 
			display: grid; 
			place-items: center; 
			margin: 0 auto 16px; 
			border-radius: 50%; 
			background: #fdf2f2; 
			color: #dc2626; 
			font-size: 24px; 
		} 
		.category-confirm h2 { 
			font-size: 20px; 
			color: #1a202c; 
			font-weight: 700; 
			margin-bottom: 8px; 
		} 
		.category-confirm p { 
			margin: 0 auto 16px; 
			max-width: 340px; 
			color: #4a5568; 
			font-size: 12px; 
			line-height: 1.5; 
		} 
		.impact-box { 
			padding: 12px 14px; 
			border: 1px solid #fee2e2; 
			border-radius: 8px; 
			background: #fef2f2; 
			color: #991b1b; 
			text-align: left; 
			font-size: 11px; 
			line-height: 1.5; 
			margin-bottom: 20px; 
		} 
		.impact-title { 
			font-weight: 700; 
			margin-bottom: 4px; 
			display: flex; 
			align-items: center; 
			gap: 6px; 
		}
		.confirm-actions { 
			display: grid; 
			grid-template-columns: 1fr 1fr; 
			gap: 12px; 
		} 
		.confirm-actions form { width: 100%; } 
		.btn-cancel { 
			display: flex; 
			align-items: center; 
			justify-content: center; 
			width: 100%; 
			height: 40px; 
			border: 1px solid #cbd5e0; 
			border-radius: 8px; 
			background: #ffffff; 
			color: #4a5568; 
			text-decoration: none; 
			font-size: 12px; 
			font-weight: 700; 
			transition: all 0.2s; 
		}
		.btn-cancel:hover { background: #f7fafc; color: #1a202c; }
		.btn-delete-confirm { 
			display: flex; 
			align-items: center; 
			justify-content: center; 
			gap: 8px; 
			width: 100%; 
			height: 40px; 
			border: none; 
			border-radius: 8px; 
			background: #dc2626; 
			color: #ffffff; 
			font-size: 12px; 
			font-family: inherit; 
			font-weight: 700; 
			cursor: pointer; 
			transition: all 0.2s; 
		}
		.btn-delete-confirm:hover { background: #b91c1c; }

		.management-grid { display: grid; grid-template-columns: 1fr; gap: 25px; align-items: start; }
		.panel { background: #fff; border: 1px solid #ededed; border-radius: 8px; padding: 22px; color: #000000; }
		.panel h2 { font-size: 17px; margin-bottom: 18px; font-weight: 700; }

		.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 18px; }
		.product-card { border: 1px solid #ededed; background: #fff; border-radius: 8px; overflow: hidden; transition: transform .15s, box-shadow .15s; display: flex; flex-direction: column; justify-content: space-between; }
		.product-card:hover { transform: translateY(-2px); box-shadow: 0 5px 14px rgba(43,22,16,.1); }
		.product-image { height: 128px; background: #74473b; display: grid; place-items: center; color: #ffffff; font-size: 34px; overflow: hidden; } 
		.product-image img { width: 100%; height: 100%; object-fit: cover; }

		/* Improved Add Product Card Design */
		.add-product-card { 
			color: #74473b; 
			text-decoration: none; 
			border: 2px dashed #d6c8c3; 
			background: #faf8f7; 
			border-radius: 8px;
			transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
		} 
		.add-product-card:hover { 
			border-color: #74473b; 
			background: #f4edea; 
			transform: translateY(-3px); 
			box-shadow: 0 8px 20px rgba(116, 71, 59, 0.12); 
		}
		.add-product-image { 
			height: 128px; 
			display: grid; 
			place-items: center; 
			background: transparent; 
		} 
		.add-icon-wrapper { 
			width: 48px; 
			height: 48px; 
			display: grid; 
			place-items: center; 
			border-radius: 50%; 
			background: #ffffff; 
			color: #74473b; 
			font-size: 18px; 
			box-shadow: 0 4px 12px rgba(116, 71, 59, 0.15); 
			transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease; 
		}
		.add-product-card:hover .add-icon-wrapper { 
			transform: scale(1.12) rotate(90deg); 
			background: #74473b; 
			color: #ffffff; 
		}
		.add-product-info { 
			min-height: 102px; 
			padding: 16px 14px; 
			background: #ffffff; 
			border-top: 1px solid #f0edeb; 
			display: flex; 
			flex-direction: column; 
			justify-content: center; 
		} 
		.add-product-info h3 { 
			color: #2b1610; 
			font-size: 13px; 
			line-height: 1.35; 
			font-weight: 700; 
			margin-bottom: 2px;
		}
		.add-product-sub { 
			font-size: 10px; 
			color: #8c7b75; 
			font-weight: 500; 
		}

		.product-info { min-height: 102px; padding: 14px 13px; display: flex; flex-direction: column; justify-content: space-between; flex-grow: 1; color: #000000; } 
		.product-info h3 { font-size: 13px; line-height: 1.35; margin-bottom: 4px; font-weight: 700; color: #000000; }
		.category-label { color: #000000; font-size: 10px; margin-bottom: 6px; font-weight: 600; text-transform: uppercase; } 
		.price { color: #000000; font-size: 13px; font-weight: 700; }

		.card-actions { margin-top: 10px; } 

		label { display: block; color: #000000; font-size: 10px; font-weight: 700; margin: 15px 0 7px; text-transform: uppercase; letter-spacing: 0.5px; } 
		input, textarea, select { width: 100%; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px; font-family: inherit; font-size: 12px; outline: none; color: #000000; background: #fff; } 
		input { height: 40px; } 
		textarea { min-height: 75px; resize: vertical; } 
		input:focus, textarea:focus, select:focus { border-color: #74473b; }

		.form-actions { display: flex; align-items: center; gap: 10px; margin-top: 20px; } 
		.cancel-link { border: 1px solid #ded5d1; border-radius: 7px; background: #fff; color: #000000; padding: 10px 16px; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
		.cancel-link:hover { background: #f7f7f7; }

		.message { margin-bottom: 18px; border-radius: 6px; padding: 12px 14px; background: #f0ecea; color: #000000; font-size: 12px; font-weight: 600; border: 1px solid #ded5d1; }
		.category-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; } 
		.category-chip { background: #ececee; border-radius: 16px; padding: 7px 12px; color: #000000; font-size: 10px; font-weight: 600; }

		.addons-heading { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; } 
		.addons-heading label { margin: 0; }
		.custom-addon { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; margin-top: 8px; } 
		.custom-addon input { height: 36px; } 
		.custom-addon button { border: 1px solid #ded5d1; border-radius: 6px; background: #f7f7f7; color: #000000; padding: 0 12px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit; }

		.addon-row { display: grid; grid-template-columns: minmax(0, 1fr) 88px 34px; gap: 8px; margin-top: 8px; } 
		.remove-addon { border: 1px solid #ded5d1; border-radius: 6px; background: #fff; color: #a34a3f; cursor: pointer; font-size: 16px; font-family: inherit; display: grid; place-items: center; } 
		.remove-addon:hover { background: #fdf2f2; }
		.add-addon { border: 0; background: transparent; color: #000000; cursor: pointer; font-size: 0; font-weight: 700; font-family: inherit; } 
		.add-addon::after { content: "+ ADD"; font-size: 11px; color: #74473b; font-weight: 700; }

		.edit-modal { position: fixed; inset: 0; z-index: 20; display: grid; place-items: center; padding: 22px; background: rgba(32, 22, 19, .68); } 
		.edit-modal .panel { width: min(100%, 460px); max-height: calc(100vh - 44px); overflow-y: auto; border-radius: 10px; box-shadow: 0 18px 45px rgba(0,0,0,.25); }

		@media (max-width: 700px) { 
			.sidebar { position: relative; width: 100%; min-height: auto; padding: 22px 0; } 
			.app-shell { display: block; } 
			.brand { padding-bottom: 20px; } 
			.nav { display: flex; flex-wrap: wrap; } 
			.nav-item { padding: 10px 14px; } 
			.sidebar-footer { margin-top: 18px; } 
			.content { width: 100%; margin-left: 0; padding: 30px 16px; } 
			.topbar { align-items: start; flex-direction: column; gap: 15px; } 
			.product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } 
		}
	</style>
	<link rel="stylesheet" href="../assets/sidebar.css">
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div>
			<div class="brand">COFFEE MAKER</div>
			<nav class="nav">
				<?php if ($user_role !== "cashier"): ?>
					<a class="nav-item" href="dashboard.php">DASHBOARD</a>
				<?php endif; ?>
				<a class="nav-item" href="pos.php">POS</a>
				<a class="nav-item" href="orders.php">ORDERS</a>
				<?php if ($user_role !== "cashier"): ?>
					<a class="nav-item active" href="products.php">PRODUCTS</a>
					<a class="nav-item" href="users.php">USERS</a>
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

			<?php if ($user_role !== "cashier"): ?>
				<a class="settings" href="settings.php" aria-label="Settings" title="Settings"><i class="fas fa-cog"></i></a>
			<?php endif; ?>
		</div>
	</aside>

	<section class="content">
		<header class="topbar">
			<div>
				<h1>Menu Management</h1>
				<div class="menu-tabs">
					<a class="menu-tab <?= $active_tab === "categories" ? "active" : "" ?>" href="products.php?tab=categories">CATEGORIES</a>
					<a class="menu-tab <?= $active_tab === "products" ? "active" : "" ?>" href="products.php?tab=products">PRODUCTS</a>
				</div>
			</div>
			<?php if ($active_tab === "categories" && !$edit_category && !$show_add_form): ?>
				<a class="add-button" href="products.php?tab=categories&amp;add_category=1">
					<i class="fas fa-plus"></i> ADD CATEGORY
				</a>
			<?php elseif ($active_tab === "products" && !$edit_product): ?>
				<a class="add-button" href="add_product.php">
					<i class="fas fa-plus"></i> ADD PRODUCT
				</a>
			<?php endif; ?>
		</header>
		
		<?php if ($message !== ""): ?>
			<div class="message"><?= htmlspecialchars($message) ?></div>
		<?php endif; ?>

		<?php if ($active_tab === "categories"): ?>
			<?php if ($show_add_form): ?>
				<section class="panel" style="margin-bottom: 20px;">
					<h2>Add Category</h2>
					<form action="products.php?tab=categories" method="post">
						<input type="hidden" name="action" value="save_category">
						<label for="category-name">CATEGORY NAME</label>
						<input id="category-name" name="category_name" value="<?= htmlspecialchars($add_form_data['name']) ?>" placeholder="e.g. Coffee" required>
						<label for="category-description">DESCRIPTION</label>
						<input id="category-description" name="category_description" value="<?= htmlspecialchars($add_form_data['description']) ?>" placeholder="Premium coffee beverages">
						<div class="form-actions">
							<a class="cancel-link" href="products.php?tab=categories">CANCEL</a>
							<button class="save-button" type="submit">ADD CATEGORY</button>
						</div>
					</form>
				</section>
			<?php endif; ?>

			<?php if ($edit_category): ?>
				<section class="category-edit-page">
					<div class="category-edit-heading">
						<a href="products.php?tab=categories" aria-label="Back to categories">←</a>
						<div>
							<h2>Edit Category</h2>
							<p>Update details for “<?= htmlspecialchars($edit_category["name"]) ?>”</p>
						</div>
					</div>
					<form action="products.php?tab=categories" method="post">
						<input type="hidden" name="action" value="save_category">
						<input type="hidden" name="category_id" value="<?= (int) $edit_category["id"] ?>">
						<div>
							<label for="edit-category-name">CATEGORY NAME</label>
							<input id="edit-category-name" name="category_name" value="<?= htmlspecialchars($edit_category["name"]) ?>" required>
						</div>
						<label for="edit-category-description">DESCRIPTION</label>
						<textarea id="edit-category-description" name="category_description"><?= htmlspecialchars($edit_category["description"]) ?></textarea>
						<div class="category-status-field">
							<label for="category-status">STATUS</label>
							<label><input id="category-status" type="checkbox" name="category_status" value="Active" <?= $edit_category["status"] === "Active" ? "checked" : "" ?>> Active</label>
							<p class="category-form-note">Inactive categories will not appear on the POS system.</p>
						</div>
						<div class="form-actions">
							<a class="cancel-link" href="products.php?tab=categories">CANCEL</a>
							<button class="save-button" type="submit">SAVE CHANGES</button>
						</div>
					</form>
				</section>
			<?php elseif (!$show_add_form): ?>
				<section>
					<div class="category-header-bar">
						<h2 style="font-size: 17px; margin: 0; font-weight: 700;">Categories</h2>
					</div>

					<div class="category-table-wrap">
						<table class="category-table">
							<thead>
								<tr>
									<th>#</th>
									<th>NAME</th>
									<th>DESCRIPTION</th>
									<th>STATUS</th>
									<th>ACTIONS</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($category_rows as $index => $category): ?>
									<tr>
										<td><?= $index + 1 ?></td>
										<td class="category-name"><?= htmlspecialchars($category["name"]) ?></td>
										<td><?= htmlspecialchars($category["description"]) ?></td>
										<td><span class="category-status">Active</span></td>
										<td>
											<div class="category-actions">
												<a class="btn-action edit" href="products.php?tab=categories&amp;edit_category=<?= (int) $category["id"] ?>" title="Edit Category">
													<i class="fas fa-pen"></i>
													<span>Edit</span>
												</a>
												<a class="btn-action delete" href="products.php?tab=categories&amp;delete_category=<?= (int) $category["id"] ?>" title="Delete Category">
													<i class="fas fa-trash-alt"></i>
													<span>Delete</span>
												</a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
								<?php if (!$category_rows): ?>
									<tr><td colspan="5">No categories yet.</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endif; ?>

			<?php if ($delete_category): ?>
				<div class="category-modal">
					<section class="category-confirm">
						<div class="warning-icon">
							<i class="fas fa-triangle-exclamation"></i>
						</div>
						<h2>Delete Category?</h2>
						<p>Are you sure you want to delete <strong>“<?= htmlspecialchars($delete_category["name"]) ?>”</strong>? This will also remove all products currently assigned to it.</p>
						<div class="impact-box">
							<div class="impact-title"><i class="fas fa-circle-info"></i> Impact Notice</div>
							All products in this category will be removed from the menu and will no longer appear on the POS system.
						</div>
						<div class="confirm-actions">
							<a href="products.php?tab=categories" class="btn-cancel">Cancel</a>
							<form action="products.php?tab=categories" method="post">
								<input type="hidden" name="action" value="delete_category">
								<input type="hidden" name="category_id" value="<?= (int) $delete_category["id"] ?>">
								<button type="submit" class="btn-delete-confirm"><i class="fas fa-trash-alt"></i> Delete</button>
							</form>
						</div>
					</section>
				</div>
			<?php endif; ?>

		<?php else: ?>
			<div class="management-grid">
				<section class="panel">
					<h2>Products</h2>
					<div class="product-toolbar">
						<input class="product-search" id="product-search" type="search" placeholder="⌕ Search menu items..." aria-label="Search menu items">
						<div class="category-filters">
							<button class="category-filter active" type="button" data-category="all">All</button>
							<?php foreach ($categories as $category): ?>
								<button class="category-filter" type="button" data-category="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="product-grid">
						<?php foreach ($products as $product): ?>
							<article class="product-card" data-name="<?= htmlspecialchars(strtolower($product["product_name"])) ?>" data-category="<?= htmlspecialchars($product["category"]) ?>">
								<div class="product-image">
									<?php if (!empty($product["image_path"])): ?>
										<img src="../<?= htmlspecialchars($product["image_path"]) ?>" alt="<?= htmlspecialchars($product["product_name"]) ?>">
									<?php endif; ?>
								</div>
								<div class="product-info">
									<div>
										<div class="category-label"><?= htmlspecialchars($product["category"]) ?></div>
										<h3><?= htmlspecialchars($product["product_name"]) ?></h3>
									</div>
									<div>
										<div class="price">₱<?= number_format((float) $product["price"], 2) ?></div>
										<div class="card-actions">
											<a class="btn-action edit" href="products.php?edit=<?= (int) $product["id"] ?>#product-form" title="Edit product">
												<i class="fas fa-pen"></i>
												<span>Edit</span>
											</a>
											<a class="btn-action delete" href="products.php?tab=products&amp;delete=<?= (int) $product["id"] ?>" title="Delete product">
												<i class="fas fa-trash-alt"></i>
												<span>Delete</span>
											</a>
										</div>
									</div>
								</div>
							</article>
						<?php endforeach; ?>
						<a class="product-card add-product-card" href="add_product.php" aria-label="Add new product">
							<div class="add-product-image" aria-hidden="true">
								<div class="add-icon-wrapper">
									<i class="fas fa-plus"></i>
								</div>
							</div>
							<div class="add-product-info">
								<h3>Add New Product</h3>
								<span class="add-product-sub">Click to add items</span>
							</div>
						</a>
						<?php if (count($products) === 0): ?>
							<p class="subtitle">No active products yet.</p>
						<?php endif; ?>
					</div>
				</section>

				<?php if ($delete_product): ?>
					<div class="category-modal">
						<section class="category-confirm">
							<div class="warning-icon">
								<i class="fas fa-triangle-exclamation"></i>
							</div>
							<h2>Delete Product?</h2>
							<p>Are you sure you want to delete <strong>“<?= htmlspecialchars($delete_product["product_name"]) ?>”</strong>?</p>
							<div class="impact-box">
								<div class="impact-title"><i class="fas fa-circle-info"></i> Impact Notice</div>
								This product will be removed from the menu and will no longer appear on the POS system.
							</div>
							<div class="confirm-actions">
								<a href="products.php?tab=products" class="btn-cancel">Cancel</a>
								<form action="products.php?tab=products" method="post">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="product_id" value="<?= (int) $delete_product["id"] ?>">
									<button type="submit" class="btn-delete-confirm"><i class="fas fa-trash-alt"></i> Delete</button>
								</form>
							</div>
						</section>
					</div>
				<?php endif; ?>

				<?php if ($edit_product): ?>
					<div class="edit-modal" id="edit-modal">
						<aside class="panel" id="product-form">
							<h2>Edit Product</h2>
							<form action="products.php?tab=products" method="post" enctype="multipart/form-data">
								<input type="hidden" name="action" value="save">
								<input type="hidden" name="product_id" value="<?= (int) $edit_product["id"] ?>">
								<input type="hidden" name="current_image" value="<?= htmlspecialchars($edit_product["image_path"] ?? "") ?>">
								<label for="product-name">PRODUCT NAME</label>
								<input id="product-name" name="product_name" value="<?= htmlspecialchars($edit_product["product_name"] ?? "") ?>" placeholder="e.g. Classic Beef Tapa" required>
								<label for="category">CATEGORY</label>
								<input id="category" name="category" list="category-options" value="<?= htmlspecialchars($edit_product["category"] ?? "") ?>" placeholder="e.g. Snacks" required>
								<datalist id="category-options">
									<?php foreach ($categories as $category): ?>
										<option value="<?= htmlspecialchars($category) ?>">
									<?php endforeach; ?>
								</datalist>
								<label for="price">PRICE</label>
								<input id="price" name="price" type="number" min="0" step="0.01" value="<?= htmlspecialchars($edit_product["price"] ?? "") ?>" placeholder="0.00" required>
								<div class="addons-heading">
									<label>ADD ONS &amp; TOPPINGS</label>
									<button class="add-addon" id="add-addon" type="button">+ ADD ADD-ON</button>
								</div>
								<div id="addons-list">
									<?php foreach ($form_addons as $addon): ?>
										<div class="addon-row">
											<input type="text" name="addons[]" value="<?= htmlspecialchars($addon["name"]) ?>" placeholder="e.g. Extra shot">
											<input type="number" name="addon_prices[]" min="0" step="0.01" value="<?= htmlspecialchars($addon["price"]) ?>" placeholder="Price">
											<button class="remove-addon" type="button" aria-label="Remove add-on">×</button>
										</div>
									<?php endforeach; ?>
								</div>
								<label for="product-image">PRODUCT PICTURE</label>
								<input id="product-image" name="product_image" type="file" accept="image/jpeg,image/png,image/webp" data-crop>
								<div class="form-actions">
									<button class="save-button" type="submit">SAVE CHANGES</button>
									<a class="cancel-link" href="products.php">CANCEL</a>
								</div>
							</form>
						</aside>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
</main>

<template id="addon-template">
	<div class="addon-row">
		<input type="text" name="addons[]" placeholder="e.g. Extra shot">
		<button class="remove-addon" type="button" aria-label="Remove add-on">×</button>
	</div>
</template>

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

const addonsList = document.getElementById("addons-list");
const addonTemplate = document.getElementById("addon-template");
const savedAddons = <?= json_encode($form_addons, JSON_UNESCAPED_UNICODE) ?>;

if (addonsList) {
	document.querySelector(".addons-heading label").textContent = "ADD ONS & TOPPINGS";
	document.getElementById("add-addon").textContent = "";

	function addAddonPrice(row, price = "") {
		if (row.querySelector('input[name="addon_prices[]"]')) return;
		const input = document.createElement("input");
		input.type = "number";
		input.name = "addon_prices[]";
		input.min = "0";
		input.step = "0.01";
		input.placeholder = "Price";
		input.value = price;
		row.insertBefore(input, row.querySelector(".remove-addon"));
	}

	function convertAddonSelect(select) {
		const input = document.createElement("input");
		input.type = "text";
		input.name = "addons[]";
		input.placeholder = "e.g. Extra shot";
		input.value = select.value;
		select.replaceWith(input);
	}

	document.querySelectorAll("#addons-list select").forEach((select, index) => {
		if (savedAddons[index]) select.value = savedAddons[index].name;
		convertAddonSelect(select);
		addAddonPrice(select.closest(".addon-row"), savedAddons[index]?.price || "");
	});

	document.getElementById("add-addon").addEventListener("click", () => {
		const row = addonTemplate.content.cloneNode(true);
		addonsList.appendChild(row);
		addAddonPrice(addonsList.lastElementChild);
		addonsList.lastElementChild.querySelector("input").focus();
	});

	addonsList.addEventListener("click", event => {
		if (event.target.classList.contains("remove-addon")) event.target.closest(".addon-row").remove();
	});
}

const productSearch = document.getElementById("product-search");
const productCards = [...document.querySelectorAll(".product-card[data-name]")];
let selectedCategory = "all";

function filterProducts() {
	const query = (productSearch?.value || "").toLowerCase().trim();
	productCards.forEach(card => {
		const cardName = (card.dataset.name || "").toLowerCase().trim();
		const cardCategory = (card.dataset.category || "").toLowerCase().trim();
		const matchesName = cardName.includes(query);
		const matchesCategory = selectedCategory.toLowerCase().trim() === "all" || cardCategory === selectedCategory.toLowerCase().trim();

		if (matchesName && matchesCategory) {
			card.style.display = "flex";
			card.hidden = false;
		} else {
			card.style.display = "none";
			card.hidden = true;
		}
	});
}

document.querySelectorAll(".category-filter").forEach(button => {
	button.addEventListener("click", () => {
		document.querySelectorAll(".category-filter").forEach(item => item.classList.remove("active"));
		button.classList.add("active");
		selectedCategory = button.dataset.category;
		filterProducts();
	});
});

if (productSearch) {
	productSearch.addEventListener("input", filterProducts);
	productSearch.addEventListener("keyup", filterProducts);
	productSearch.addEventListener("change", filterProducts);
	productSearch.addEventListener("search", filterProducts);
}
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
<script>
/*
 * Coffee Maker – Product image cropper (no libraries, works offline)
 * - Pops up a crop window whenever a picture is chosen OR dropped on any image upload box.
 * - The cropped picture replaces the file in the <input type="file">, so your normal PHP upload code
 *   saves the CROPPED image to products.image_path (POS + self-ordering read that same column).
 * - After cropping, the picture is shown inside the upload box (Add page) or under the input (Edit form).
 */
(function () {
  if (window.ImageCropper) return;

  var RATIOS = [["1:1", 1], ["4:3", 4 / 3], ["3:4", 3 / 4], ["16:9", 16 / 9]];
  var STAGE = 340, OUT = 800;

  var css =
    ".icr-overlay{position:fixed;inset:0;background:rgba(43,22,16,.65);display:flex;align-items:center;justify-content:center;z-index:2147483000;padding:16px;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif}" +
    ".icr-box{background:#fff;border-radius:12px;padding:20px;width:min(100%,430px);max-height:96vh;overflow:auto;box-shadow:0 18px 45px rgba(0,0,0,.35);color:#2b1610}" +
    ".icr-box h3{margin:0 0 4px;font-size:17px;font-weight:800}" +
    ".icr-box p{margin:0 0 12px;font-size:12px;color:#5c4f4a}" +
    ".icr-stage-wrap{display:flex;justify-content:center;margin-bottom:12px}" +
    ".icr-stage{position:relative;background:#222;border-radius:8px;overflow:hidden;touch-action:none;cursor:grab;outline:2px solid #74473b}" +
    ".icr-stage.icr-drag{cursor:grabbing}.icr-stage canvas{display:block}" +
    ".icr-ratios{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap}" +
    ".icr-ratios button{border:1px solid #ded5d1;background:#fff;color:#2b1610;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}" +
    ".icr-ratios button.on{background:#74473b;border-color:#74473b;color:#fff}" +
    ".icr-zoom{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:11px;font-weight:700;color:#5c4f4a}" +
    ".icr-zoom input{flex:1;accent-color:#74473b}" +
    ".icr-actions{display:flex;gap:10px}" +
    ".icr-actions button{flex:1;height:40px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}" +
    ".icr-cancel{border:1px solid #ded5d1;background:#fff;color:#2b1610}.icr-apply{border:0;background:#74473b;color:#fff}" +
    ".icr-preview{display:block;margin-top:8px;max-width:140px;border-radius:8px;border:1px solid #ded5d1}" +
    ".icr-fill{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#fff;border-radius:inherit;pointer-events:none;z-index:1}" +
    ".icr-fill-tag{position:absolute;right:10px;bottom:10px;background:rgba(43,22,16,.8);color:#fff;font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;pointer-events:none;z-index:2}";
  var st = document.createElement("style"); st.textContent = css; document.head.appendChild(st);

  function open(file, done, cancelled) {
    var url = URL.createObjectURL(file), img = new Image();
    img.onerror = function () { URL.revokeObjectURL(url); alert("Could not read that image."); if (cancelled) cancelled(); };
    img.onload = function () { build(img, file, url, done, cancelled); };
    img.src = url;
  }

  function build(img, file, url, done, cancelled) {
    var nw = img.naturalWidth, nh = img.naturalHeight;
    var ratio = RATIOS[0][1], zoom = 1, cx = nw / 2, cy = nh / 2, sw, sh;

    var overlay = document.createElement("div");
    overlay.className = "icr-overlay";
    overlay.innerHTML =
      '<div class="icr-box" role="dialog" aria-modal="true"><h3>Crop product picture</h3>' +
      '<p>Drag to move. Use the slider or mouse wheel to zoom.</p><div class="icr-ratios"></div>' +
      '<div class="icr-stage-wrap"><div class="icr-stage"><canvas></canvas></div></div>' +
      '<div class="icr-zoom"><span>ZOOM</span><input type="range" min="1" max="4" step="0.01" value="1"></div>' +
      '<div class="icr-actions"><button type="button" class="icr-cancel">Cancel</button>' +
      '<button type="button" class="icr-apply">Apply crop</button></div></div>';
    document.body.appendChild(overlay);

    var stage = overlay.querySelector(".icr-stage"), canvas = overlay.querySelector("canvas");
    var slider = overlay.querySelector('input[type="range"]'), ratioBox = overlay.querySelector(".icr-ratios");

    RATIOS.forEach(function (r, i) {
      var b = document.createElement("button"); b.type = "button"; b.textContent = r[0];
      if (i === 0) b.className = "on";
      b.addEventListener("click", function () {
        ratio = r[1];
        Array.prototype.forEach.call(ratioBox.children, function (c) { c.className = ""; });
        b.className = "on"; layout();
      });
      ratioBox.appendChild(b);
    });

    function base() { return Math.max(sw / nw, sh / nh); }
    function clamp(s) {
      var hw = sw / (2 * s), hh = sh / (2 * s);
      cx = Math.min(Math.max(cx, hw), nw - hw); cy = Math.min(Math.max(cy, hh), nh - hh);
    }
    function render(target, w, h, k) {
      var s = base() * zoom * k, c = target.getContext("2d");
      c.fillStyle = "#fff"; c.fillRect(0, 0, w, h); c.imageSmoothingQuality = "high";
      c.drawImage(img, w / 2 - cx * s, h / 2 - cy * s, nw * s, nh * s);
    }
    function draw() { clamp(base() * zoom); render(canvas, sw, sh, 1); }
    function layout() {
      if (ratio >= 1) { sw = STAGE; sh = Math.round(STAGE / ratio); } else { sh = STAGE; sw = Math.round(STAGE * ratio); }
      canvas.width = sw; canvas.height = sh; draw();
    }

    var dragging = false, lx = 0, ly = 0;
    stage.addEventListener("pointerdown", function (e) { dragging = true; lx = e.clientX; ly = e.clientY; stage.classList.add("icr-drag"); try { stage.setPointerCapture(e.pointerId); } catch (x) {} });
    stage.addEventListener("pointermove", function (e) {
      if (!dragging) return;
      var s = base() * zoom; cx -= (e.clientX - lx) / s; cy -= (e.clientY - ly) / s; lx = e.clientX; ly = e.clientY; draw();
    });
    function stop() { dragging = false; stage.classList.remove("icr-drag"); }
    stage.addEventListener("pointerup", stop); stage.addEventListener("pointercancel", stop);
    slider.addEventListener("input", function () { zoom = parseFloat(slider.value); draw(); });
    stage.addEventListener("wheel", function (e) {
      e.preventDefault(); zoom = Math.min(4, Math.max(1, zoom + (e.deltaY < 0 ? 0.1 : -0.1))); slider.value = zoom; draw();
    }, { passive: false });

    function close() { document.removeEventListener("keydown", onKey, true); overlay.remove(); URL.revokeObjectURL(url); }
    function onKey(e) { if (e.key === "Escape") { close(); if (cancelled) cancelled(); } }
    document.addEventListener("keydown", onKey, true);

    overlay.querySelector(".icr-cancel").addEventListener("click", function () { close(); if (cancelled) cancelled(); });
    overlay.querySelector(".icr-apply").addEventListener("click", function () {
      var ow, oh;
      if (ratio >= 1) { ow = OUT; oh = Math.round(OUT / ratio); } else { oh = OUT; ow = Math.round(OUT * ratio); }
      var out = document.createElement("canvas"); out.width = ow; out.height = oh;
      render(out, ow, oh, ow / sw);
      out.toBlob(function (blob) {
        var name = (file.name || "product").replace(/\.[^.]+$/, "") + ".jpg";
        close(); done(new File([blob], name, { type: "image/jpeg" }));
      }, "image/jpeg", 0.9);
    });
    layout();
  }

  /* ---------- wiring to the page's file inputs ---------- */
  function isDashed(el) { try { return /dashed/.test(getComputedStyle(el).borderTopStyle); } catch (e) { return false; } }
  function findZone(input) {
    var el = input.parentElement, n = 0;
    while (el && el !== document.body && n < 5) { if (isDashed(el)) return el; el = el.parentElement; n++; }
    return null;
  }

  function show(input, file) {
    var url = URL.createObjectURL(file), zone = findZone(input);
    if (zone) {
      if (getComputedStyle(zone).position === "static") zone.style.position = "relative";
      var old = zone.querySelectorAll(".icr-fill,.icr-fill-tag"); Array.prototype.forEach.call(old, function (o) { o.remove(); });
      var im = document.createElement("img"); im.className = "icr-fill"; im.src = url; im.alt = "Cropped product picture";
      var tag = document.createElement("div"); tag.className = "icr-fill-tag"; tag.textContent = "Click to change picture";
      zone.appendChild(im); zone.appendChild(tag);
    } else {
      var pv = input.nextElementSibling;
      if (!pv || !pv.classList.contains("icr-preview")) { pv = document.createElement("img"); pv.className = "icr-preview"; input.insertAdjacentElement("afterend", pv); }
      pv.src = url; pv.style.display = "block";
    }
  }
  function clearShown(input) {
    var zone = findZone(input);
    if (zone) Array.prototype.forEach.call(zone.querySelectorAll(".icr-fill,.icr-fill-tag"), function (o) { o.remove(); });
    var pv = input.nextElementSibling; if (pv && pv.classList.contains("icr-preview")) pv.style.display = "none";
  }

  function crop(input, file) {
    if (!file || !/^image\//.test(file.type)) return;
    open(file, function (cropped) {
      var dt = new DataTransfer(); dt.items.add(cropped); input.files = dt.files;
      input.dataset.icrPass = "1";                         // let the page's own handlers see the cropped file
      input.dispatchEvent(new Event("change", { bubbles: true }));
      delete input.dataset.icrPass;
      show(input, cropped);
    }, function () { input.value = ""; clearShown(input); });
  }

  function attach(input) {
    if (input.dataset.icrBound || input.hasAttribute("data-nocrop")) return;
    input.dataset.icrBound = "1";
    input.addEventListener("change", function (e) {
      if (input.dataset.icrPass) return;                   // our own re-dispatch
      var f = input.files && input.files[0];
      if (!f) { clearShown(input); return; }
      e.stopImmediatePropagation();                        // hide the uncropped file from the page
      crop(input, f);
    }, true);
  }

  // dropped on a zone whose own script reads e.dataTransfer directly
  document.addEventListener("drop", function (e) {
    var files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length || !/^image\//.test(files[0].type)) return;
    var el = e.target, n = 0, input = null;
    while (el && el !== document && n < 6 && !input) { input = el.querySelector && el.querySelector('input[type="file"]'); el = el.parentElement; n++; }
    if (!input || input.hasAttribute("data-nocrop")) return;
    e.preventDefault(); e.stopImmediatePropagation();
    crop(input, files[0]);
  }, true);

  function init() { Array.prototype.forEach.call(document.querySelectorAll('input[type="file"]'), attach); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
  new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });

  window.ImageCropper = { open: open, attach: attach };
})();

</script>
</body>
</html>