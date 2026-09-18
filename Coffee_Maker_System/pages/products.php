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

$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS addons TEXT NULL AFTER price");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER category");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER addons");
$conn->query("CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'Active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$upload_directory = "../assets/images/products/";
if (!is_dir($upload_directory)) {
	mkdir($upload_directory, 0755, true);
}

$message = "";
$edit_product = null;
$active_tab = ($_GET["tab"] ?? "products") === "categories" ? "categories" : "products";
$edit_category = null;
$delete_category = null;

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
		$category_sort_order = max(1, (int) ($_POST["sort_order"] ?? 1));
		$category_status = ($_POST["category_status"] ?? "Active") === "Inactive" ? "Inactive" : "Active";
		if ($category_name === "") {
			$message = "Enter a category name.";
		} else {
			if ($category_id > 0) {
				$old_statement = $conn->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
				$old_statement->bind_param("i", $category_id);
				$old_statement->execute();
				$old_category = $old_statement->get_result()->fetch_assoc();
				$old_statement->close();
				$statement = $conn->prepare("UPDATE categories SET name = ?, description = ?, sort_order = ?, status = ? WHERE id = ?");
				$statement->bind_param("ssisi", $category_name, $category_description, $category_sort_order, $category_status, $category_id);
				if ($old_category && $old_category["name"] !== $category_name) {
					$rename_statement = $conn->prepare("UPDATE products SET category = ? WHERE category = ?");
					$rename_statement->bind_param("ss", $category_name, $old_category["name"]);
					$rename_statement->execute();
					$rename_statement->close();
				}
			} else {
				$sort_result = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM categories");
				$next_sort_order = (int) ($sort_result->fetch_assoc()["next_sort_order"] ?? 1);
				$statement = $conn->prepare("INSERT INTO categories (name, description, sort_order) VALUES (?, ?, ?)");
				$statement->bind_param("ssi", $category_name, $category_description, $next_sort_order);
			}
			$message = $statement->execute() ? "Category added successfully." : "That category already exists.";
			if ($category_id > 0 && $message === "Category added successfully.") $message = "Category updated successfully.";
			$statement->close();
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
			$clear_statement = $conn->prepare("UPDATE products SET status = 'Inactive' WHERE category = ?");
			$clear_statement->bind_param("s", $category["name"]);
			$clear_statement->execute();
			$clear_statement->close();
			$delete_statement = $conn->prepare("UPDATE categories SET status = 'Inactive' WHERE id = ?");
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

if (isset($_GET["edit_category"])) {
	$category_id = (int) $_GET["edit_category"];
	$statement = $conn->prepare("SELECT id, name, description, sort_order, status FROM categories WHERE id = ? AND status != 'Inactive' LIMIT 1");
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

$products = [];
$result = $conn->query("SELECT id, product_name, category, price, addons, image_path FROM products WHERE status != 'Inactive' ORDER BY product_name ASC");
if ($result) {
	while ($row = $result->fetch_assoc()) {
		$products[] = $row;
	}
}

$categories = [];
foreach ($products as $product) {
	if (!empty($product["category"]) && !in_array($product["category"], $categories, true)) {
		$categories[] = $product["category"];
	}
}
sort($categories);

$category_description = "Coffee menu category";
foreach ($categories as $category) {
	$sync_statement = $conn->prepare("INSERT IGNORE INTO categories (name, description, sort_order) VALUES (?, ?, ?)");
	$category_sort_order = array_search($category, $categories, true) + 1;
	$sync_statement->bind_param("ssi", $category, $category_description, $category_sort_order);
	$sync_statement->execute();
	$sync_statement->close();
}

$stored_categories = [];
$category_result = $conn->query("SELECT id, name, description, sort_order, status FROM categories WHERE status != 'Inactive' ORDER BY sort_order, name");
if ($category_result) {
	while ($row = $category_result->fetch_assoc()) {
		$stored_categories[$row["name"]] = $row;
		if (!in_array($row["name"], $categories, true)) {
			$categories[] = $row["name"];
		}
	}
}
sort($categories);

$category_rows = [];
foreach ($categories as $index => $category) {
	$product_count = 0;
	foreach ($products as $product) {
		if ($product["category"] === $category) {
			$product_count++;
		}
	}
	$category_rows[] = [
		"id" => $stored_categories[$category]["id"] ?? 0,
		"name" => $category,
		"description" => $stored_categories[$category]["description"] ?? ($product_count . " product" . ($product_count === 1 ? "" : "s") . " in this category"),
		"sort_order" => $stored_categories[$category]["sort_order"] ?? $index + 1,
		"product_count" => $product_count,
	];
}

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
		body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background: #f7f7f7; color: #000000; }
		.app-shell { min-height: 100vh; display: flex; }
		
		/* Sidebar & Popover Styles (Matched to POS) */
		.sidebar { width: 245px; min-height: 100vh; background: #2b1610; color: #fff; display: flex; flex-direction: column; justify-content: space-between; padding: 30px 18px 20px; position: fixed; inset: 0 auto 0 0; font-family: Arial, Helvetica, sans-serif; }
		.brand { font-size: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 16px 35px; }
		.nav { display: flex; flex-direction: column; gap: 8px; }
		.nav-item { color: #aeb3bd; text-decoration: none; padding: 14px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; letter-spacing: .5px; transition: .2s; }
		.nav-item:hover, .nav-item.active { background: #74473b; color: #fff; font-weight: 700; }
		.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; }

		.user-wrapper { position: relative; flex: 1; }
		.user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 12px; font-weight: 700; cursor: pointer; padding: 6px 8px; border-radius: 6px; transition: background 0.2s; user-select: none; }
		.user:hover { background: #3c2018; }
		.avatar { width: 34px; height: 34px; border-radius: 50%; background: #60463e; display: grid; place-items: center; font-size: 14px; color: #fff; flex-shrink: 0; }
		.user-details-text { display: flex; flex-direction: column; line-height: 1.25; }
		.user-name { color: #fff; font-size: 13px; font-weight: 700; }
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

		.settings { border: 0; background: transparent; color: #fff; font-size: 16px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }

		/* Content Area Styles Matched to POS */
		.content { margin-left: 245px; width: calc(100% - 245px); padding: 42px 35px; color: #000000; }
		.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 23px; }
		h1 { font-size: 26px; margin-bottom: 6px; color: #000000; font-weight: 700; } 
		.subtitle { color: #000000; font-size: 13px; }

		.add-button, .save-button { border: 1px solid #241f1d; border-radius: 7px; background: #241f1d; color: #ffffff; padding: 10px 16px; font-size: 11px; font-family: inherit; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
		.add-button:hover, .save-button:hover { background: #3a322e; }

		.menu-tabs { display: flex; gap: 8px; margin-top: 10px; } 
		.menu-tab { border: 0; border-radius: 16px; padding: 8px 14px; color: #000000; background: #ececee; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; text-decoration: none; } 
		.menu-tab.active, .menu-tab:hover { background: #74473b; color: #ffffff; }

		.product-toolbar { margin-bottom: 25px; } 
		.product-search { width: min(100%, 480px); height: 40px; border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; padding: 0 14px; font-size: 12px; font-family: inherit; outline: none; color: #000000; } 
		.product-search:focus { border-color: #74473b; }

		.category-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; } 
		.category-filter { border: 0; border-radius: 16px; padding: 8px 13px; color: #000000; background: #ececee; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; } 
		.category-filter.active, .category-filter:hover { background: #74473b; color: #ffffff; }

		.category-table-wrap { overflow-x: auto; border: 1px solid #ededed; border-radius: 8px; background: #fff; } 
		.category-table { width: 100%; min-width: 650px; border-collapse: collapse; color: #000000; } 
		.category-table th { padding: 14px 16px; background: #faf9f8; color: #000000; font-size: 10px; text-align: left; font-weight: 700; border-bottom: 1px solid #eee; } 
		.category-table td { padding: 14px 16px; border-top: 1px solid #f0edeb; color: #000000; font-size: 11px; } 
		.category-table td:first-child { width: 45px; text-align: center; } 
		.category-name { color: #000000; font-weight: 700; } 
		.category-status { display: inline-block; padding: 4px 8px; border-radius: 4px; background: #e2f2e5; color: #28763b; font-size: 9px; font-weight: 700; }

		.category-actions { display: flex; gap: 8px; } 
		.category-actions a { width: 28px; height: 28px; display: grid; place-items: center; border: 1px solid #ded5d1; border-radius: 5px; color: #000000; text-decoration: none; font-size: 12px; transition: background .15s; } 
		.category-actions a:hover { background: #f7f7f7; }
		.category-actions a.delete { color: #a34a3f; }

		.category-edit-page { background: #fff; border: 1px solid #ededed; border-radius: 8px; padding: 24px; color: #000000; } 
		.category-edit-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; } 
		.category-edit-heading a { color: #000000; text-decoration: none; font-size: 22px; font-weight: 700; } 
		.category-edit-heading h2 { font-size: 20px; color: #000000; font-weight: 700; } 
		.category-edit-heading p { margin-top: 5px; color: #000000; font-size: 11px; }
		.category-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; } 
		.category-form-grid textarea { min-height: 90px; } 
		.category-status-field { margin-top: 16px; color: #000000; font-size: 11px; } 
		.category-status-field input { width: auto; height: auto; margin-right: 7px; accent-color: #000000; } 
		.category-form-note { margin-top: 8px; color: #000000; font-size: 10px; }

		.category-modal { position: fixed; inset: 0; z-index: 30; display: grid; place-items: center; padding: 20px; background: rgba(32, 22, 19, .68); } 
		.category-confirm { width: min(100%, 420px); padding: 24px; border-radius: 10px; background: #fff; box-shadow: 0 18px 45px rgba(0,0,0,.25); text-align: center; color: #000000; } 
		.warning-icon { width: 48px; height: 48px; display: grid; place-items: center; margin: 0 auto 14px; border-radius: 50%; background: #fdf2f2; color: #a34a3f; font-size: 22px; } 
		.category-confirm h2 { font-size: 19px; color: #000000; font-weight: 700; } 
		.category-confirm p { margin: 8px auto 14px; max-width: 320px; color: #000000; font-size: 11px; line-height: 1.5; } 
		.impact-box { padding: 12px; border: 1px solid #ded5d1; border-radius: 6px; background: #faf9f8; color: #000000; text-align: left; font-size: 10px; line-height: 1.45; } 
		.confirm-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 18px; } 
		.confirm-actions form { width: 100%; } 
		.confirm-actions a, .confirm-actions button { width: 100%; min-height: 38px; padding: 10px; border: 1px solid #ded5d1; border-radius: 7px; background: #fff; color: #000000; text-decoration: none; font-size: 11px; font-family: inherit; font-weight: 600; cursor: pointer; text-align: center; display: flex; align-items: center; justify-content: center; } 
		.confirm-actions button { border-color: #a34a3f; background: #a34a3f; color: #ffffff; }

		.management-grid { display: grid; grid-template-columns: 1fr; gap: 25px; align-items: start; }
		.panel { background: #fff; border: 1px solid #ededed; border-radius: 8px; padding: 22px; color: #000000; }
		.panel h2 { font-size: 16px; margin-bottom: 18px; color: #000000; font-weight: 700; }

		.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 18px; }
		.product-card { border: 1px solid #ededed; background: #fff; border-radius: 7px; overflow: hidden; transition: transform .15s, box-shadow .15s; display: flex; flex-direction: column; justify-content: space-between; }
		.product-card:hover { transform: translateY(-2px); box-shadow: 0 5px 14px rgba(43,22,16,.1); }
		.product-image { height: 128px; background: #74473b; display: grid; place-items: center; color: #ffffff; font-size: 34px; overflow: hidden; } 
		.product-image img { width: 100%; height: 100%; object-fit: cover; }

		.add-product-card { color: #74473b; text-decoration: none; border: 1px dashed #bbaaa4; } 
		.add-product-image { height: 128px; display: grid; place-items: center; background: #ececee; } 
		.add-product-image span { width: 44px; height: 44px; display: grid; place-items: center; border: 1px solid #74473b; border-radius: 50%; color: #74473b; font-size: 26px; font-weight: 300; line-height: 1; } 
		.add-product-info { min-height: 102px; padding: 14px 13px; background: #fff; } 
		.add-product-info h3 { color: #000000; font-size: 13px; line-height: 1.35; font-weight: 700; }

		.product-info { min-height: 102px; padding: 14px 13px; display: flex; flex-direction: column; justify-content: space-between; flex-grow: 1; color: #000000; } 
		.product-info h3 { font-size: 13px; line-height: 1.35; margin-bottom: 4px; font-weight: 700; color: #000000; }
		.category-label { color: #000000; font-size: 10px; margin-bottom: 6px; font-weight: 600; text-transform: uppercase; } 
		.price { color: #000000; font-size: 13px; font-weight: 700; }

		.card-actions { display: flex; gap: 6px; margin-top: 10px; } 
		.icon-button, .delete-button { width: 28px; height: 26px; border: 1px solid #ded5d1; border-radius: 5px; color: #000000; background: #fff; font-size: 12px; font-family: inherit; cursor: pointer; text-decoration: none; display: grid; place-items: center; } 
		.delete-button { color: #a34a3f; }

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

		@media (max-width: 850px) { .sidebar { width: 190px; } .content { margin-left: 190px; width: calc(100% - 190px); padding: 25px; } .management-grid { grid-template-columns: 1fr; } }
		@media (max-width: 600px) { .app-shell { display: block; } .sidebar { position: relative; width: 100%; min-height: auto; padding: 20px; } .brand { padding-bottom: 20px; } .nav { flex-direction: row; flex-wrap: wrap; } .nav-item { padding: 10px; } .sidebar-footer { margin-top: 22px; } .content { margin-left: 0; width: 100%; padding: 22px 16px; } .topbar { align-items: flex-start; flex-direction: column; gap: 14px; } .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } }
	</style>
	<link rel="stylesheet" href="../assets/sidebar.css">
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div>
			<div class="brand">COFFEE MAKER</div>
			<nav class="nav">
				<a class="nav-item" href="dashboard.php">DASHBOARD</a>
				<a class="nav-item" href="pos.php">POS</a>
				<a class="nav-item" href="orders.php">ORDERS</a>
				<a class="nav-item active" href="products.php">PRODUCTS</a>
				<a class="nav-item" href="users.php">USERS</a>
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
					<div class="popover-body">
						<div class="info-row">
							<i class="fas fa-phone-alt"></i>
							<span><?= htmlspecialchars($user_phone) ?></span>
						</div>
					</div>
					<div class="popover-footer">
						<a href="../logout.php" class="popover-logout" onclick="return confirm('Are you sure you want to log out?');">
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
			<?php if ($active_tab === "categories"): ?>
				<a class="add-button" href="products.php?tab=categories&add_category=1">+ ADD CATEGORY</a>
			<?php endif; ?>
		</header>
		
		<?php if ($message !== ""): ?>
			<div class="message"><?= htmlspecialchars($message) ?></div>
		<?php endif; ?>

		<?php if ($active_tab === "categories"): ?>
			<?php if (isset($_GET["add_category"])): ?>
				<section class="panel" style="margin-bottom: 20px;">
					<h2>Add Category</h2>
					<form method="post">
						<input type="hidden" name="action" value="save_category">
						<label for="category-name">CATEGORY NAME</label>
						<input id="category-name" name="category_name" placeholder="e.g. Coffee" required>
						<label for="category-description">DESCRIPTION</label>
						<input id="category-description" name="category_description" placeholder="Premium coffee beverages">
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
					<form method="post">
						<input type="hidden" name="action" value="save_category">
						<input type="hidden" name="category_id" value="<?= (int) $edit_category["id"] ?>">
						<div class="category-form-grid">
							<div>
								<label for="edit-category-name">CATEGORY NAME</label>
								<input id="edit-category-name" name="category_name" value="<?= htmlspecialchars($edit_category["name"]) ?>" required>
							</div>
							<div>
								<label for="sort-order">SORT ORDER</label>
								<input id="sort-order" name="sort_order" type="number" min="1" value="<?= (int) $edit_category["sort_order"] ?>" required>
							</div>
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
			<?php else: ?>
				<section>
					<h2 style="font-size: 16px; margin: 0 0 18px; font-weight: 700;">Categories</h2>
					<div class="category-table-wrap">
						<table class="category-table">
							<thead>
								<tr>
									<th>#</th>
									<th>NAME</th>
									<th>DESCRIPTION</th>
									<th>SORT ORDER</th>
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
										<td><?= (int) $category["sort_order"] ?></td>
										<td><span class="category-status">Active</span></td>
										<td>
											<div class="category-actions">
												<a href="products.php?tab=categories&amp;edit_category=<?= (int) $category["id"] ?>" aria-label="Edit <?= htmlspecialchars($category["name"]) ?>">✎</a>
												<a class="delete" href="products.php?tab=categories&amp;delete_category=<?= (int) $category["id"] ?>" aria-label="Delete <?= htmlspecialchars($category["name"]) ?>">▮</a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
								<?php if (!$category_rows): ?>
									<tr><td colspan="6">No categories yet.</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endif; ?>

			<?php if ($delete_category): ?>
				<div class="category-modal">
					<section class="category-confirm">
						<div class="warning-icon">▲</div>
						<h2>Delete Category?</h2>
						<p>Are you sure you want to delete the “<?= htmlspecialchars($delete_category["name"]) ?>” category? This will also delete all products currently assigned to it.</p>
						<div class="impact-box">
							<strong>ⓘ &nbsp; Impact</strong><br>
							All products in this category will be removed from the menu and will no longer appear on the POS system.
						</div>
						<div class="confirm-actions">
							<a href="products.php?tab=categories">Cancel</a>
							<form method="post">
								<input type="hidden" name="action" value="delete_category">
								<input type="hidden" name="category_id" value="<?= (int) $delete_category["id"] ?>">
								<button type="submit">&#128465; Delete</button>
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
											<a class="icon-button" href="products.php?edit=<?= (int) $product["id"] ?>#product-form" aria-label="Edit <?= htmlspecialchars($product["product_name"]) ?>" title="Edit product">✎</a>
											<form method="post" onsubmit="return confirm('Remove this product from the menu?');">
												<input type="hidden" name="action" value="delete">
												<input type="hidden" name="product_id" value="<?= (int) $product["id"] ?>">
												<button class="delete-button" type="submit" aria-label="Delete <?= htmlspecialchars($product["product_name"]) ?>" title="Delete product">⌫</button>
											</form>
										</div>
									</div>
								</div>
							</article>
						<?php endforeach; ?>
						<a class="product-card add-product-card" href="add_product.php" aria-label="Add new product">
							<div class="add-product-image" aria-hidden="true"><span>＋</span></div>
							<div class="add-product-info"><h3>ADD NEW<br>PRODUCT</h3></div>
						</a>
						<?php if (count($products) === 0): ?>
							<p class="subtitle">No active products yet.</p>
						<?php endif; ?>
					</div>
				</section>

				<?php if ($edit_product): ?>
					<div class="edit-modal" id="edit-modal">
						<aside class="panel" id="product-form">
							<h2>Edit Product</h2>
							<form method="post" enctype="multipart/form-data">
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
								<input id="product-image" name="product_image" type="file" accept="image/jpeg,image/png,image/webp">
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
		const matchesName = card.dataset.name.includes(query);
		const matchesCategory = selectedCategory === "all" || card.dataset.category === selectedCategory;
		card.hidden = !matchesName || !matchesCategory;
	});
}

document.querySelectorAll(".category-filter").forEach(button => button.addEventListener("click", () => {
	document.querySelectorAll(".category-filter").forEach(item => item.classList.remove("active"));
	button.classList.add("active");
	selectedCategory = button.dataset.category;
	filterProducts();
}));

productSearch?.addEventListener("input", filterProducts);
</script>
</body>
</html>