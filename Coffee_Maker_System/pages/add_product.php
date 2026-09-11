<?php
session_start();

if (!isset($_SESSION["admin"])) {
	header("Location: ../index.php");
	exit();
}

require_once "../config/database.php";
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS addons TEXT NULL AFTER price");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER category");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER addons");

$upload_directory = "../assets/images/products/";
if (!is_dir($upload_directory)) {
	mkdir($upload_directory, 0755, true);
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$product_name = trim($_POST["product_name"] ?? "");
	$category = trim($_POST["category"] ?? "");
	$price = (float) ($_POST["price"] ?? 0);
	$description = trim($_POST["description"] ?? "");
	$addon_values = is_array($_POST["addons"] ?? null) ? $_POST["addons"] : [];
	$addon_prices = is_array($_POST["addon_prices"] ?? null) ? $_POST["addon_prices"] : [];
	$addon_records = [];
	foreach ($addon_values as $index => $addon_value) {
		$addon_name = trim((string) $addon_value);
		if ($addon_name !== "") {
			$addon_records[] = ["name" => $addon_name, "price" => max(0, (float) ($addon_prices[$index] ?? 0))];
		}
	}
	$image_path = "";
	$status = ($_POST["status"] ?? "Active") === "Inactive" ? "Inactive" : "Active";

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
		$addons = json_encode($addon_records, JSON_UNESCAPED_UNICODE);
		$statement = $conn->prepare("INSERT INTO products (product_name, category, description, price, addons, image_path, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
		$statement->bind_param("sssdsss", $product_name, $category, $description, $price, $addons, $image_path, $status);
		if ($statement->execute()) {
			header("Location: products.php");
			exit();
		}
		$message = "Unable to add the product.";
		$statement->close();
	} elseif ($message === "") {
		$message = "Enter a product name, category, and valid price.";
	}
}

$admin_name = $_SESSION["admin"];
$categories = [];
$result = $conn->query("SELECT name AS category FROM categories WHERE status != 'Inactive' UNION SELECT DISTINCT category FROM products WHERE status != 'Inactive' AND category <> '' ORDER BY category");
if ($result) {
	while ($row = $result->fetch_assoc()) $categories[] = $row["category"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Add Product | Coffee Maker</title>
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body { background: #f7f7f7; color: #2b1610; font-family: Arial, Helvetica, sans-serif; }
		.app-shell { min-height: 100vh; display: flex; }
		.sidebar { width: 245px; min-height: 100vh; padding: 30px 18px 20px; background: #2b1610; color: #fff; position: fixed; inset: 0 auto 0 0; display: flex; flex-direction: column; justify-content: space-between; }
		.brand { padding: 0 16px 35px; font-size: 20px; font-weight: 700; letter-spacing: 1px; }
		.nav { display: flex; flex-direction: column; gap: 8px; } .nav-item { padding: 14px 16px; color: #aeb3bd; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600; letter-spacing: .5px; } .nav-item:hover, .nav-item.active { background: #74473b; color: #fff; }
		.sidebar-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #492c25; padding: 18px 10px 0; } .user { display: flex; align-items: center; gap: 10px; color: #dfe2e8; font-size: 11px; font-weight: 600; } .avatar { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 50%; background: #60463e; }
		.content { width: calc(100% - 245px); margin-left: 245px; padding: 42px 35px 60px; } .topbar, .form-card, .message { width: min(100%, 805px); margin-left: auto; margin-right: auto; } .topbar { position: relative; margin-bottom: 22px; text-align: center; } .back-link { position: absolute; top: 3px; left: 0; display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid #d8c9c3; border-radius: 6px; color: #5d4036; background: #fff; text-decoration: none; font-size: 11px; font-weight: 700; } .back-link:hover { border-color: #74473b; background: #f4ece9; color: #2b1610; } h1 { margin-top: 0; font-size: 25px; } .subtitle { margin-top: 5px; color: #858585; font-size: 13px; }
		.form-card { padding: 26px; border: 1px solid #e7e1df; border-radius: 8px; background: #fff; box-shadow: 0 8px 24px rgba(43, 22, 16, .04); } .image-drop { display: grid; place-items: center; min-height: 165px; margin-bottom: 22px; border: 1px dashed #cdbbb3; border-radius: 6px; color: #76574d; text-align: center; font-size: 11px; cursor: pointer; } .image-drop strong { display: block; margin-bottom: 6px; } .image-drop input { display: none; }
		.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; } label { display: block; margin: 0 0 7px; color: #61443a; font-size: 10px; font-weight: 700; } .field { margin-bottom: 17px; } input, select, textarea { width: 100%; border: 1px solid #ded7d4; border-radius: 5px; padding: 10px; color: #403532; font: inherit; font-size: 12px; outline: none; } input, select { height: 38px; } textarea { min-height: 78px; resize: vertical; } input:focus, select:focus, textarea:focus { border-color: #74473b; }
		.addons-heading { display: flex; align-items: center; justify-content: space-between; margin: 3px 0 8px; } .addons-heading label { margin: 0; } .add-addon { border: 0; background: transparent; color: #74473b; min-width: auto; padding: 0; font-size: 0; } .add-addon::after { content: "+ ADD"; font-size: 10px; } .addon-row { display: grid; grid-template-columns: minmax(0, 1fr) 120px 34px; gap: 8px; margin-bottom: 8px; } .addon-row input { height: 36px; } .remove-addon { min-width: auto; padding: 0; border: 1px solid #ded7d4; background: #fff; color: #9b3024; }
		.status-field { display: flex; align-items: center; justify-content: space-between; height: 38px; color: #76574d; font-size: 11px; } .switch { position: relative; width: 32px; height: 18px; } .switch input { opacity: 0; width: 0; height: 0; } .slider { position: absolute; inset: 0; border-radius: 20px; background: #d5cbc7; cursor: pointer; } .slider:before { position: absolute; content: ''; width: 14px; height: 14px; left: 2px; top: 2px; border-radius: 50%; background: #fff; transition: .2s; } .switch input:checked + .slider { background: #238b4b; } .switch input:checked + .slider:before { transform: translateX(14px); }
		.actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 8px; padding-top: 17px; border-top: 1px solid #eee7e4; } button, .cancel { min-width: 108px; padding: 11px 15px; border-radius: 5px; font-size: 10px; font-weight: 700; cursor: pointer; text-align: center; } button { border: 0; background: #2b1610; color: #fff; } .cancel { border: 1px solid #cdbbb3; color: #76574d; text-decoration: none; background: #fff; } .cancel:hover { border-color: #74473b; background: #f4ece9; } .message { margin-bottom: 15px; padding: 11px; border-radius: 5px; background: #f1e8e4; color: #5d3226; font-size: 12px; }
		@media (max-width: 700px) { .sidebar { position: relative; width: 100%; min-height: auto; } .app-shell { display: block; } .content { width: 100%; margin-left: 0; padding: 28px 16px; } .topbar { text-align: left; } .back-link { position: static; margin-bottom: 12px; } .form-grid { grid-template-columns: 1fr; } }
	</style>
	<link rel="stylesheet" href="../assets/sidebar.css">
</head>
<body>
<main class="app-shell">
	<aside class="sidebar"><div><div class="brand">COFFEE MAKER</div><nav class="nav"><a class="nav-item" href="dashboard.php">DASHBOARD</a><a class="nav-item" href="pos.php">POS</a><a class="nav-item" href="orders.php">ORDERS</a><a class="nav-item active" href="products.php">PRODUCTS</a><a class="nav-item" href="users.php">USERS</a></nav></div><div class="sidebar-footer"><div class="user"><span class="avatar">♙</span><span><?= htmlspecialchars($admin_name) ?></span></div><a class="settings" href="settings.php" aria-label="Settings" title="Settings">⚙</a></div></aside>
	<section class="content"><header class="topbar"><a class="back-link" href="products.php"><span aria-hidden="true">←</span> Back</a><h1>Add New Product</h1><p class="subtitle">Create a new item for your coffee menu.</p></header>
		<?php if ($message !== ""): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
		<form class="form-card" method="post" enctype="multipart/form-data"><label class="image-drop" for="product-image"><span><strong>Click to upload or drag and drop</strong>JPG, PNG, WEBP up to 5MB<br><span id="file-name">No file selected</span></span><input id="product-image" name="product_image" type="file" accept="image/jpeg,image/png,image/webp"></label><div class="form-grid"><div class="field"><label for="product-name">PRODUCT NAME</label><input id="product-name" name="product_name" placeholder="e.g. Classic Macchiato" required></div><div class="field"><label for="category">CATEGORY</label><input id="category" name="category" list="category-options" placeholder="Select category..." required><datalist id="category-options"><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category) ?>"><?php endforeach; ?></datalist></div><div class="field"><label for="price">PRICE</label><input id="price" name="price" type="number" min="0" step="0.01" placeholder="₱ 0.00" required></div><div class="field"><label>STATUS</label><div class="status-field"><span>Visible on menu</span><label class="switch"><input name="status" value="Active" type="checkbox" checked><span class="slider"></span></label></div></div></div><div class="addons-heading"><label>ADD ONS &amp; TOPPINGS</label><button class="add-addon" id="add-addon" type="button">+ ADD ADD-ON</button></div><div id="addons-list"><div class="addon-row"><input name="addons[]" placeholder="e.g. Extra shot"><input name="addon_prices[]" type="number" min="0" step="0.01" placeholder="Price"><button class="remove-addon" type="button" aria-label="Remove add-on">×</button></div></div><div class="field"><label for="description">DESCRIPTION</label><textarea id="description" name="description" placeholder="Describe the product..."></textarea></div><div class="actions"><a class="cancel" href="products.php">Cancel</a><button type="submit">ADD PRODUCT</button></div></form>
	</section>
</main>
<script>
document.getElementById('product-image').addEventListener('change', event => { document.getElementById('file-name').textContent = event.target.files[0]?.name || 'No file selected'; });
const addonsList = document.getElementById('addons-list');
document.getElementById('add-addon').textContent = '';
document.getElementById('add-addon').addEventListener('click', () => {
	const row = document.createElement('div');
	row.className = 'addon-row';
	row.innerHTML = '<input name="addons[]" placeholder="e.g. Extra shot"><input name="addon_prices[]" type="number" min="0" step="0.01" placeholder="Price"><button class="remove-addon" type="button" aria-label="Remove add-on">×</button>';
	addonsList.appendChild(row);
});
addonsList.addEventListener('click', event => { if (event.target.classList.contains('remove-addon')) event.target.closest('.addon-row').remove(); });
</script>
</body>
</html>
