<?php
session_start();

if (!isset($_SESSION["admin"])) {
	header("Location: ../index.php");
	exit();
}

require_once "../config/database.php";

// Keep the product page usable with existing installations created before these fields existed.
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS addons TEXT NULL AFTER price");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER addons");

$upload_directory = "../assets/images/products/";
if (!is_dir($upload_directory)) {
	mkdir($upload_directory, 0755, true);
}

$message = "";
$edit_product = null;

function decode_addons($value) {
	$decoded = json_decode((string) $value, true);
	if (is_array($decoded)) {
		return array_values(array_filter(array_map("trim", $decoded)));
	}
	return array_values(array_filter(array_map("trim", explode(",", (string) $value))));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$action = $_POST["action"] ?? "";

	if ($action === "save") {
		$product_id = (int) ($_POST["product_id"] ?? 0);
		$product_name = trim($_POST["product_name"] ?? "");
		$category = trim($_POST["category"] ?? "");
		$price = (float) ($_POST["price"] ?? 0);
		$addon_values = $_POST["addons"] ?? [];
		if (!is_array($addon_values)) {
			$addon_values = [$addon_values];
		}
		$addons = json_encode(array_values(array_filter(array_map("trim", $addon_values))), JSON_UNESCAPED_UNICODE);
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

$admin_name = $_SESSION["admin"];
$form_addons = $edit_product ? decode_addons($edit_product["addons"] ?? "") : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Coffee Maker Products</title>
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body { font-family: Arial, Helvetica, sans-serif; background: #f7f7f7; color: #2b1610; }
		.app-shell { min-height: 100vh; display: flex; }
		.sidebar { width: 245px; min-height: 100vh; background: #2b1610; color: #fff; display: flex; flex-direction: column; justify-content: space-between; padding: 30px 18px 20px; position: fixed; inset: 0 auto 0 0; }
		.brand { font-size: 20px; font-weight: 700; letter-spacing: 1px; padding: 0 16px 35px; }
		.nav { display: flex; flex-direction: column; gap: 8px; }
		.nav-item { color: #d5c6c1; text-decoration: none; padding: 14px 16px; border-radius: 4px; font-size: 13px; font-weight: 600; letter-spacing: .5px; }
		.nav-item:hover, .nav-item.active { background: #74473b; color: #fff; }
		.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; }
		.user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 11px; font-weight: 600; }
		.avatar { width: 34px; height: 34px; border-radius: 50%; background: #60463e; display: grid; place-items: center; }
		.settings { border: 0; background: transparent; color: #fff; font-size: 18px; }
		.content { margin-left: 245px; width: calc(100% - 245px); padding: 42px 35px; }
		.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
		h1 { font-size: 26px; margin-bottom: 6px; } .subtitle { color: #858585; font-size: 13px; }
		.add-button, .save-button { border: 0; border-radius: 6px; background: #2b1610; color: #fff; padding: 12px 17px; font-size: 11px; font-weight: 700; cursor: pointer; }
		.management-grid { display: grid; grid-template-columns: minmax(0, 1fr) 285px; gap: 25px; align-items: start; }
		.panel { background: #fff; border: 1px solid #e7e1df; border-radius: 7px; padding: 22px; }
		.panel h2 { font-size: 15px; margin-bottom: 18px; }
		.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 17px; }
		.product-card { border: 1px solid #eee; border-radius: 6px; overflow: hidden; background: #fff; }
		.product-image { height: 112px; background: #74473b; display: grid; place-items: center; color: #fff; font-size: 32px; overflow: hidden; } .product-image img { width: 100%; height: 100%; object-fit: cover; }
		.product-info { padding: 13px; } .product-info h3 { font-size: 13px; line-height: 1.35; min-height: 34px; margin-bottom: 6px; }
		.category-label { color: #999; font-size: 10px; margin-bottom: 9px; } .price { font-size: 13px; font-weight: 700; }
		.card-actions { display: flex; gap: 8px; margin-top: 14px; } .icon-button, .delete-button { width: 32px; height: 30px; border: 1px solid #ddd3cf; border-radius: 5px; color: #56362c; background: #fff; font-size: 15px; text-decoration: none; cursor: pointer; } .icon-button { display: grid; place-items: center; } .delete-button { color: #9b3024; }
		label { display: block; color: #61443a; font-size: 10px; font-weight: 700; margin: 15px 0 7px; } input, textarea, select { width: 100%; border: 1px solid #ded7d4; border-radius: 5px; padding: 10px; font: inherit; font-size: 12px; outline: none; } input { height: 38px; } textarea { min-height: 65px; resize: vertical; } input:focus, textarea:focus { border-color: #74473b; }
		.form-actions { display: flex; gap: 8px; margin-top: 20px; } .cancel-link { padding: 12px 10px; color: #76574d; font-size: 11px; text-decoration: none; }
		.message { margin-bottom: 18px; border-radius: 5px; padding: 11px 13px; background: #f1e8e4; color: #5d3226; font-size: 12px; }
		.category-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; } .category-chip { background: #efe9e7; border-radius: 15px; padding: 7px 11px; color: #66473d; font-size: 10px; }
		.addons-heading { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; } .addons-heading label { margin: 0; }
		.custom-addon { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; margin-top: 8px; } .custom-addon input { height: 34px; } .custom-addon button { border: 1px solid #ded5d1; border-radius: 5px; background: #f7f1ef; color: #74473b; padding: 0 10px; font-size: 10px; font-weight: 700; cursor: pointer; }
		.addon-row { display: grid; grid-template-columns: minmax(0, 1fr) 32px; gap: 8px; margin-top: 8px; } .remove-addon { border: 1px solid #e0d5d1; border-radius: 5px; background: #fff; color: #9b3024; cursor: pointer; font-size: 17px; } .add-addon { border: 0; background: transparent; color: #74473b; cursor: pointer; font-size: 10px; font-weight: 700; }
		@media (max-width: 850px) { .sidebar { width: 190px; } .content { margin-left: 190px; width: calc(100% - 190px); padding: 25px; } .management-grid { grid-template-columns: 1fr; } }
		@media (max-width: 600px) { .app-shell { display: block; } .sidebar { position: relative; width: 100%; min-height: auto; padding: 20px; } .brand { padding-bottom: 20px; } .nav { flex-direction: row; flex-wrap: wrap; } .nav-item { padding: 10px; } .sidebar-footer { margin-top: 22px; } .content { margin-left: 0; width: 100%; padding: 22px 16px; } .topbar { align-items: flex-start; flex-direction: column; gap: 14px; } }
	</style>
</head>
<body>
<main class="app-shell">
	<aside class="sidebar">
		<div><div class="brand">COFFEE MAKER</div><nav class="nav"><a class="nav-item" href="dashboard.php">DASHBOARD</a><a class="nav-item" href="pos.php">POS</a><a class="nav-item" href="orders.php">ORDERS</a><a class="nav-item active" href="products.php">PRODUCTS</a><a class="nav-item" href="users.php">USERS</a></nav></div>
		<div class="sidebar-footer"><div class="user"><div class="avatar">♙</div><span><?= htmlspecialchars($admin_name) ?></span></div><button class="settings" type="button" aria-label="Settings">⚙</button></div>
	</aside>
	<section class="content">
		<header class="topbar"><div><h1>Menu Management</h1><p class="subtitle">Add, edit, and organize your coffee menu.</p></div><a class="add-button" href="products.php#product-form">+ ADD PRODUCT</a></header>
		<?php if ($message !== ""): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
		<div class="management-grid">
			<section class="panel"><h2>Products</h2><div class="product-grid">
				<?php foreach ($products as $product): ?><article class="product-card"><div class="product-image"><?php if (!empty($product["image_path"])): ?><img src="../<?= htmlspecialchars($product["image_path"]) ?>" alt="<?= htmlspecialchars($product["product_name"]) ?>"><?php else: ?>☕<?php endif; ?></div><div class="product-info"><div class="category-label"><?= htmlspecialchars($product["category"]) ?></div><h3><?= htmlspecialchars($product["product_name"]) ?></h3><div class="price">₱<?= number_format((float) $product["price"], 2) ?></div><?php $product_addons = decode_addons($product["addons"] ?? ""); if (count($product_addons) > 0): ?><div class="category-label">Add-ons: <?= htmlspecialchars(implode(", ", $product_addons)) ?></div><?php endif; ?><div class="card-actions"><a class="icon-button" href="products.php?edit=<?= (int) $product["id"] ?>#product-form" aria-label="Edit <?= htmlspecialchars($product["product_name"]) ?>" title="Edit product">✎</a><form method="post" onsubmit="return confirm('Remove this product from the menu?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int) $product["id"] ?>"><button class="delete-button" type="submit" aria-label="Delete <?= htmlspecialchars($product["product_name"]) ?>" title="Delete product">⌫</button></form></div></div></article><?php endforeach; ?>
				<?php if (count($products) === 0): ?><p class="subtitle">No active products yet.</p><?php endif; ?>
			</div></section>
			<aside class="panel" id="product-form"><h2><?= $edit_product ? "Edit Product" : "Add New Product" ?></h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="<?= (int) ($edit_product["id"] ?? 0) ?>"><input type="hidden" name="current_image" value="<?= htmlspecialchars($edit_product["image_path"] ?? "") ?>"><label for="product-name">PRODUCT NAME</label><input id="product-name" name="product_name" value="<?= htmlspecialchars($edit_product["product_name"] ?? "") ?>" placeholder="e.g. Classic Beef Tapa" required><label for="category">CATEGORY</label><input id="category" name="category" list="category-options" value="<?= htmlspecialchars($edit_product["category"] ?? "") ?>" placeholder="e.g. Snacks" required><datalist id="category-options"><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category) ?>"><?php endforeach; ?></datalist><label for="price">PRICE</label><input id="price" name="price" type="number" min="0" step="0.01" value="<?= htmlspecialchars($edit_product["price"] ?? "") ?>" placeholder="0.00" required><div class="addons-heading"><label>ADD-ONS</label><button class="add-addon" id="add-addon" type="button">+ ADD ADD-ON</button></div><div id="addons-list"><?php foreach ($form_addons as $addon): ?><div class="addon-row"><select name="addons[]"><option value="">Select an add-on</option><option value="Extra shot"<?= $addon === "Extra shot" ? " selected" : "" ?>>Extra shot</option><option value="Whipped cream"<?= $addon === "Whipped cream" ? " selected" : "" ?>>Whipped cream</option><option value="Caramel syrup"<?= $addon === "Caramel syrup" ? " selected" : "" ?>>Caramel syrup</option><option value="Vanilla syrup"<?= $addon === "Vanilla syrup" ? " selected" : "" ?>>Vanilla syrup</option><option value="Pearl topping"<?= $addon === "Pearl topping" ? " selected" : "" ?>>Pearl topping</option></select><button class="remove-addon" type="button" aria-label="Remove add-on">×</button></div><?php endforeach; ?></div><label for="product-image">PRODUCT PICTURE</label><input id="product-image" name="product_image" type="file" accept="image/jpeg,image/png,image/webp"><div class="form-actions"><button class="save-button" type="submit"><?= $edit_product ? "SAVE CHANGES" : "ADD PRODUCT" ?></button><?php if ($edit_product): ?><a class="cancel-link" href="products.php">CANCEL</a><?php endif; ?></div></form><div class="category-list"><?php foreach ($categories as $category): ?><span class="category-chip"><?= htmlspecialchars($category) ?></span><?php endforeach; ?></div></aside>
		</div>
	</section>
</main>
<template id="addon-template"><div class="addon-row"><input type="text" name="addons[]" placeholder="e.g. Extra shot"><button class="remove-addon" type="button" aria-label="Remove add-on">×</button></div></template>
<script>
const addonsList = document.getElementById("addons-list");
const addonTemplate = document.getElementById("addon-template");
const savedAddons = <?= json_encode($form_addons, JSON_UNESCAPED_UNICODE) ?>;

function convertAddonSelect(select) {
	const input = document.createElement("input");
	input.type = "text";
	input.name = "addons[]";
	input.placeholder = "e.g. Extra shot";
	input.value = select.value;
	select.replaceWith(input);
}

document.querySelectorAll("#addons-list select").forEach((select, index) => {
	if (savedAddons[index]) select.value = savedAddons[index];
	convertAddonSelect(select);
});
document.getElementById("add-addon").addEventListener("click", () => {
	const row = addonTemplate.content.cloneNode(true);
	addonsList.appendChild(row);
	addonsList.lastElementChild.querySelector("input").focus();
});
addonsList.addEventListener("click", event => {
	if (event.target.classList.contains("remove-addon")) event.target.closest(".addon-row").remove();
});
</script>
</body>
</html>
