<?php

// 引入ApiManager类
require_once 'api_manager.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 允许跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 创建ApiManager实例
$apiManager = new ApiManager();

// 管理员认证信息
$ADMIN_USERNAME = 'admin';
$ADMIN_PASSWORD = '127127sun';

// 获取请求方法和路径
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

// 认证函数
function authenticate() {
    global $ADMIN_USERNAME, $ADMIN_PASSWORD;
    
    // 获取Authorization头
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (empty($authHeader)) {
        return false;
    }
    
    // 解析Basic Auth
    list($authType, $authCredentials) = explode(' ', $authHeader, 2);
    if (strtolower($authType) !== 'basic') {
        return false;
    }
    
    // 解码凭证
    $credentials = base64_decode($authCredentials);
    list($username, $password) = explode(':', $credentials, 2);
    
    // 验证用户名和密码
    return $username === $ADMIN_USERNAME && $password === $ADMIN_PASSWORD;
}

// 需要认证的路径列表
$protectedPaths = [
    'products',
    'get_products_with_index',
    'preferred_product',
    'preferred_product_config',
    'delete_product'
];

// 检查是否需要认证
if (in_array($path, $protectedPaths)) {
    if (!authenticate()) {
        header('WWW-Authenticate: Basic realm="API Manager"');
        sendResponse(['error' => '未授权访问'], 401);
    }
}

// 处理请求
switch ($method) {
    case 'GET':
        handleGetRequest($apiManager, $path);
        break;
    case 'POST':
        handlePostRequest($apiManager, $path);
        break;
    default:
        sendResponse(['error' => '不支持的请求方法'], 405);
}

// 处理GET请求
function handleGetRequest($apiManager, $path) {
    switch ($path) {
        case 'products':
            // 获取所有商品
            $products = $apiManager->getAllProducts();
            sendResponse(['products' => $products]);
            break;
        case 'get_products_with_index':
            // 获取带序号的商品列表
            $productDetails = $apiManager->getAllProductsWithDetails();
            sendResponse(['products' => $productDetails]);
            break;
        case 'preferred_product':
            // 获取首选商品
            $preferredProduct = $apiManager->getPreferredProduct();
            sendResponse(['preferred_product' => $preferredProduct]);
            break;
        case 'preferred_product_config':
            // 获取首选商品配置
            $config = $apiManager->getPreferredProductConfig();
            sendResponse(['config' => $config]);
            break;
        default:
            sendResponse(['error' => '无效的API路径'], 404);
    }
}

// 处理POST请求
function handlePostRequest($apiManager, $path) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($path) {
        case 'add_api':
            // 添加API信息
            $product = $data['product'] ?? '';
            $baseUrl = $data['base_url'] ?? '';
            $apiKey = $data['api_key'] ?? '';
            
            if (empty($product) || empty($baseUrl) || empty($apiKey)) {
                sendResponse(['error' => '缺少必要参数'], 400);
            }
            
            $result = $apiManager->addProductInfo($product, $baseUrl, $apiKey);
            sendResponse(['success' => $result]);
            break;
        case 'set_preferred_product':
            // 设置首选商品
            $product = $data['product'] ?? '';
            
            if (empty($product)) {
                sendResponse(['error' => '缺少商品参数'], 400);
            }
            
            $result = $apiManager->setPreferredProduct($product);
            sendResponse(['success' => $result]);
            break;
        case 'delete_product':
            // 删除商品
            $index = $data['index'] ?? null;
            $product = $data['product'] ?? '';
            $baseUrl = $data['base_url'] ?? '';
            $apiKey = $data['api_key'] ?? '';
            
            if ($index !== null) {
                // 通过序号删除
                $result = $apiManager->deleteProductByIndex($index);
                sendResponse(['success' => $result]);
            } elseif (!empty($product) && !empty($baseUrl) && !empty($apiKey)) {
                // 通过商品信息删除
                $result = $apiManager->removeProduct($baseUrl, $apiKey, $product);
                sendResponse(['success' => $result]);
            } else {
                sendResponse(['error' => '缺少必要参数'], 400);
            }
            break;
        default:
            sendResponse(['error' => '无效的API路径'], 404);
    }
}

// 发送响应
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}
