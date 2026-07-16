<?php

require_once __DIR__ . '/../models/ProductModel.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/SessionManager.php';

class ProductController {
    private $model;
    private $uploadDir = 'uploads/';
    private $validCrops = ['Carrot', 'Leeks', 'Tomato', 'Cabbage'];
    private $validStatus = ['Available', 'Sold', 'Unavailable'];
    private $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];

    public function __construct() {
        $this->model = new ProductModel();
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    public function getProducts() {
        try {
            $filters = [];
            if (isset($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (isset($_GET['crop_name'])) {
                $filters['crop_name'] = $_GET['crop_name'];
            }
            if (isset($_GET['is_featured'])) {
                $filters['is_featured'] = $_GET['is_featured'];
            }

            $products = $this->model->getAllProducts($filters);

            $baseUrl = $this->getBaseHostUrl(); // <-- use new helper
            foreach ($products as &$product) {
                $product['id'] = $product['product_id'];
                $product['name'] = $product['crop_name'];
                $product['price'] = $product['price_per_unit'];
                // Always return full image URL
                $product['image_url'] = $product['image_url']
                    ? $baseUrl . '/' . ltrim($product['image_url'], '/')
                    : '';
                // status already set by model
            }

            Response::success("Products retrieved successfully", $products);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getProduct($productId) {
        try {
            $product = $this->model->getProductById($productId);
            
            if (!$product) {
                Response::notFound("Product not found");
            }

            $baseUrl = $this->getBaseHostUrl(); // <-- use new helper
            $product['id'] = $product['product_id'];
            $product['name'] = $product['crop_name'];
            $product['price'] = $product['price_per_unit'];
            $product['image_url'] = $product['image_url']
                ? $baseUrl . '/' . ltrim($product['image_url'], '/')
                : '';
            
            Response::success("Product retrieved successfully", $product);
            
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function addProduct() {
        try {
            // Only allow Financial_Manager and Operational_Manager
            SessionManager::requireRole(['Financial_Manager', 'Operational_Manager']);

            $cropId = Validator::numeric($_POST["crop_id"] ?? 0, "Crop ID", 1);
            $pricePerUnit = Validator::numeric($_POST["price_per_unit"] ?? 0, "Price per unit", 0.01);
            $description = Validator::required($_POST["description"] ?? "", "Description");
            $isFeatured = isset($_POST["is_featured"]) ? intval($_POST["is_featured"]) : 0;

            // Handle image upload
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                Response::error("Image file is required");
            }
            $file = $_FILES['image'];
            if (!in_array($file['type'], $this->allowedTypes)) {
                Response::error("Invalid image type. Only jpg, jpeg, png allowed");
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uniqueName = uniqid('img_', true) . '.' . $ext;
            $targetPath = $this->uploadDir . $uniqueName;
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                Response::error("Failed to save image file");
            }
            $imagePath = $this->uploadDir . $uniqueName;

            $result = $this->model->addProduct($cropId, $pricePerUnit, $description, $imagePath, $isFeatured);

            if ($result['success']) {
                $baseUrl = $this->getBaseHostUrl();
                $result['image_url'] = $baseUrl . '/' . ltrim($imagePath, '/');
                Response::success($result['message'], $result, 201);
            } else {
                Response::error($result['message']);
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function updateProduct($productId) {
        try {
            // Only allow Financial_Manager and Operational_Manager
            SessionManager::requireRole(['Financial_Manager', 'Operational_Manager']);

            $pricePerUnit = Validator::numeric($_POST["price_per_unit"] ?? 0, "Price per unit", 0.01);
            $description = Validator::required($_POST["description"] ?? "", "Description");
            $isFeatured = isset($_POST["is_featured"]) ? intval($_POST["is_featured"]) : 0;

            $currentProduct = $this->model->getProductById($productId);
            if (!$currentProduct) {
                Response::notFound("Product not found");
            }
            $imagePath = $currentProduct['image_url'];

            // Handle new image upload if provided
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['image'];
                if (!in_array($file['type'], $this->allowedTypes)) {
                    Response::error("Invalid image type. Only jpg, jpeg, png allowed");
                }
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $uniqueName = uniqid('img_', true) . '.' . $ext;
                $targetPath = $this->uploadDir . $uniqueName;
                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    Response::error("Failed to save image file");
                }
                // Delete old image file
                if ($imagePath && file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $imagePath = $this->uploadDir . $uniqueName;
            }

            // Pass isFeatured to model
            $result = $this->model->updateProduct($productId, $pricePerUnit, $description, $imagePath, $isFeatured);

            if ($result['success']) {
                $baseUrl = $this->getBaseHostUrl();
                $result['image_url'] = $imagePath ? $baseUrl . '/' . ltrim($imagePath, '/') : '';
                Response::success($result['message'], $result);
            } else {
                if ($result['message'] === 'No changes were made.') {
                    Response::json(['status' => 'info', 'message' => $result['message']], 200);
                } else {
                    Response::error($result['message']);
                }
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function deleteProduct($productId) {
        try {
            // Only allow Financial_Manager and Operational_Manager
            SessionManager::requireRole(['Financial_Manager', 'Operational_Manager']);

            $result = $this->model->deleteProduct($productId);

            if ($result['success']) {
                Response::success($result['message']);
            } else {
                Response::error($result['message']);
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function updateProductQuantity($productId) {
        try {
            // Only allow Financial_Manager and Operational_Manager
            SessionManager::requireRole(['Financial_Manager', 'Operational_Manager']);

            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!$data) {
                Response::error("Invalid JSON data");
            }

            $quantity = Validator::numeric($data['quantity'] ?? 0, "Quantity", 0);

            $result = $this->model->updateProductQuantity($productId, $quantity);

            if ($result['success']) {
                Response::success($result['message']);
            } else {
                Response::error($result['message']);
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function searchProducts() {
        try {
            $searchTerm = $_GET['search'] ?? '';

            if (empty($searchTerm)) {
                Response::error("Search term is required");
            }

            $products = $this->model->searchProducts($searchTerm);

            // Add computed image_url for each product
            $baseUrl = $this->getBaseHostUrl();
            foreach ($products as &$product) {
                $product['image_url'] = $product['image_url'] ? $baseUrl . '/' . ltrim($product['image_url'], '/') : '';
            }

            Response::success("Search completed", $products);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getProductStats() {
        try {
            SessionManager::requireRole(['Operational_Manager', 'Financial_Manager']);

            $stats = $this->model->getProductStats();

            Response::success("Product statistics retrieved", $stats);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getLowStockProducts() {
        try {
            SessionManager::requireRole(['Operational_Manager', 'Landowner']);

            $threshold = $_GET['threshold'] ?? 10;
            $threshold = Validator::numeric($threshold, "Threshold", 1);

            $products = $this->model->getLowStockProducts($threshold);

            // Add computed image_url for each product
            $baseUrl = $this->getBaseHostUrl();
            foreach ($products as &$product) {
                $product['image_url'] = $product['image_url'] ? $baseUrl . '/' . ltrim($product['image_url'], '/') : '';
            }

            Response::success("Low stock products retrieved", $products);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getProductsByStatus($status) {
        try {
            $status = Validator::inArray($status, $this->validStatus, "Status");

            $products = $this->model->getProductsByStatus($status);

            // Add computed image_url for each product
            $baseUrl = $this->getBaseHostUrl();
            foreach ($products as &$product) {
                $product['image_url'] = $product['image_url'] ? $baseUrl . '/' . ltrim($product['image_url'], '/') : '';
            }

            Response::success("Products retrieved successfully", $products);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getProductsByCrop($cropName) {
        try {
            $cropName = Validator::inArray($cropName, $this->validCrops, "Crop name");

            $products = $this->model->getProductsByCrop($cropName);

            // Add computed image_url for each product
            $baseUrl = $this->getBaseHostUrl();
            foreach ($products as &$product) {
                $product['image_url'] = $product['image_url'] ? $baseUrl . '/' . ltrim($product['image_url'], '/') : '';
            }

            Response::success("Products retrieved successfully", $products);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getNewCropsForProduct() {
        try {
            $newCrops = $this->model->getNewCropsForProduct();
            Response::success("New crops for product", $newCrops);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function validateCartQuantity() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            
            if (!$input) {
                Response::error("Invalid JSON data", 400);
                return;
            }
            
            $productId = $input['product_id'] ?? null;
            $quantity = $input['quantity'] ?? 0;
            
            // Debug logging
            error_log("Validation request - Product ID: $productId, Quantity: $quantity");
            error_log("Full input: " . json_encode($input));
            
            if (!$productId) {
                Response::error("Product ID is required", 400);
                return;
            }
            
            if ($quantity <= 0) {
                Response::error("Quantity must be greater than 0", 400);
                return;
            }
            
            $result = $this->model->validateCartQuantity($productId, $quantity);
            
            // Debug logging
            error_log("Validation result: " . json_encode($result));
            
            if ($result['success']) {
                Response::success($result['message'], $result);
            } else {
                Response::error($result['message'], 400, $result);
            }
            
        } catch (Exception $e) {
            error_log("Validation error: " . $e->getMessage());
            Response::error($e->getMessage(), 500);
        }
    }

    public function getAvailableQuantity($productId) {
        try {
            $result = $this->model->getAvailableQuantity($productId);
            
            if ($result['success']) {
                Response::success("Available quantity retrieved", $result);
            } else {
                Response::error($result['message'], 404);
            }
            
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    private function getBaseHostUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', $dir);
        $dir = rtrim($dir, '/');
        return $protocol . '://' . $host . $dir;
    }
}

?>