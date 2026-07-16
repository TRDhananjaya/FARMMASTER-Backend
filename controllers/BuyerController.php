<?php

require_once __DIR__ . '/../models/BuyerModel.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/SessionManager.php';

class BuyerController {
    private $model;
    private $uploadDir = 'uploads/';
    private $validCrops = ['Carrot', 'Leeks', 'Tomato', 'Cabbage'];
    private $validStatus = ['Available', 'Sold', 'Unavailable'];
    private $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];

    public function __construct() {
        $this->model = new BuyerModel();
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    public function getDashboardData() {
        try {
            // Read JSON input instead of $_POST
            $input = json_decode(file_get_contents("php://input"), true);
            $userId = $input['userId'] ?? null;

            if (!$userId) {
                Response::error("Missing userId", 400);
                return;
            }

            // Get all orders (no duplicates)
            $allOrders = $this->model->getAllRecentOrdersByUserId($userId);
            
            // Get recent activities (last 5)
            $recentActivities = $this->model->getRecentActivitiesByUserId($userId);
            
            // Get statistics
            $totalOrders = $this->model->getTotalOrderCountByUserId($userId);
            $totalSpending = $this->model->getTotalSpendingByUserId($userId);

            // Build response with proper structure
            $response = [
                'recent_orders' => $allOrders,
                'purchase_history' => [], // Empty to avoid duplicates in frontend
                'recent_activities' => $recentActivities,
                'statistics' => [
                    'total_orders' => (int)$totalOrders,
                    'total_spending' => (float)$totalSpending
                ]
            ];

            Response::success("Buyer dashboard data retrieved successfully", $response);

        } catch (Exception $e) {
            Response::error("Internal error: " . $e->getMessage(), 500);
        }
    }

    public function getOrders() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $userId = $input['userId'] ?? null;

            if (!$userId) {
                Response::error("Missing userId", 400);
                return;
            }

            $orders = $this->model->getAllOrdersWithItemsByUserId($userId);

            if (!$orders || count($orders) === 0) {
                Response::success("No orders found.", ['orders' => []]);
                return;
            }

            Response::success("Orders fetched successfully", ['orders' => $orders]);
        } catch (Exception $e) {
            Response::error("Internal error: " . $e->getMessage(), 500);
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