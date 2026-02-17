<?php
// 开启错误日志（不直接输出到页面，避免返回 HTML）
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

// 开启输出缓冲，避免 require 的文件中有 BOM/空格等导致先输出非 JSON
ob_start();

// 记录请求开始时间
$requestStartTime = microtime(true);

// 记录请求信息
error_log("=== Request Start ===");
error_log("Time: " . date('Y-m-d H:i:s'));
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
error_log("Action: " . ($_GET['action'] ?? 'unknown'));
error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("Remote IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
error_log("User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    error_log("OPTIONS request handled successfully");
    exit;
}

// 记录POST数据（不包括敏感信息）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postData = file_get_contents('php://input');
    error_log("POST data length: " . strlen($postData));

    // 记录上传文件信息
    if (!empty($_FILES)) {
        error_log("Files uploaded: " . count($_FILES));
        foreach ($_FILES as $key => $file) {
            error_log("File: {$key}, Name: {$file['name']}, Size: {$file['size']}, Type: {$file['type']}, Error: {$file['error']}");
        }
    }
}

// 加载配置和核心文件
try {
    error_log("Loading configuration files...");
    $config = require 'config.php';
    require 'db.php';
    require 'user.php';
    require 'api_manager.php';
    require 'file_manager.php';
    require 'history_manager.php';
    require 'share_manager.php';



    // 初始化数据库连接和用户管理对象
    error_log("Initializing database connection...");
    $db = new Database($config['db']);
    $user = new User($db, $config);
    $apiManager = new ApiManager();
    $fileManager = new FileManager($db, $config);
    $historyManager = new HistoryManager($db, $config);
    // 初始化分享管理器
    $shareManager = new ShareManager($db, $config);
    error_log("Configuration and objects loaded successfully");
} catch (Exception $e) {
    error_log("Error loading configuration or core files: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'code' => 500,
        'message' => '服务器内部错误',
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取请求方法和参数
$method = $_SERVER['REQUEST_METHOD'];
$request = $_GET['action'] ?? '';

error_log("Processing request: {$request}");

// 清掉 require 阶段可能产生的任何输出，保证只输出 JSON
if (ob_get_length()) {
    ob_clean();
}

// 处理不同的API请求
try {
    switch ($request) {
        case 'login':
            // 用户登录
            error_log("Processing login request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            if (empty($username) || empty($password)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->login($username, $password);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Login result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'message' => $result['message'] ?? '']));
            break;

        case 'register':
            // 用户注册
            error_log("Processing register request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $inviteCode = $data['invite_code'] ?? null;

            if (empty($username) || empty($password)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->register($username, $password, $inviteCode);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Register result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'message' => $result['message'] ?? '']));
            break;

        // 文件管理相关接口
        case 'getfiles':
            // 获取用户文件列表
            error_log("Processing getfiles request");
            if ($method !== 'GET') {
                throw new Exception('请求方法错误，请使用GET方法');
            }

            $username = $_GET['username'] ?? '';

            if (empty($username)) {
                throw new Exception('缺少必要参数: username');
            }

            $result = $fileManager->getUserFiles($username);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Get files result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'count' => count($result['data']['files'] ?? [])]));
            break;

        case 'getfile':
            // 获取单个文件内容
            error_log("Processing getfile request");
            if ($method !== 'GET') {
                throw new Exception('请求方法错误，请使用GET方法');
            }

            $username = $_GET['username'] ?? '';
            $filename = $_GET['filename'] ?? '';

            if (empty($username) || empty($filename)) {
                throw new Exception('缺少必要参数: username 或 filename');
            }

            $result = $fileManager->getFileContent($username, $filename);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Get file result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename]));
            break;

        case 'save':
            // 保存文件到服务器
            error_log("Processing save request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误，请使用POST方法');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $filename = $data['filename'] ?? '';
            $content = $data['content'] ?? '';
            $createHistory = $data['create_history'] ?? false; // 新增：是否创建历史版本

            if (empty($username) || empty($filename)) {
                throw new Exception('缺少必要参数: username 或 filename');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            // 使用新的保存方法，支持历史版本
            $result = $fileManager->saveFileWithHistory($username, $filename, $content, $createHistory);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Save file result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename, 'create_history' => $createHistory]));
            break;

        case 'delete':
            // 删除文件
            error_log("Processing delete request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误，请使用POST方法');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $filename = $data['filename'] ?? '';

            if (empty($username) || empty($filename)) {
                throw new Exception('缺少必要参数: username 或 filename');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $result = $fileManager->deleteFile($username, $filename);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Delete file result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename]));
            break;

        // 历史版本相关接口 - 新增
        case 'create_history':
            // 创建历史版本
            error_log("Processing create_history request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $filename = $data['filename'] ?? '';
            $content = $data['content'] ?? '';

            if (empty($username) || empty($filename) || empty($content)) {
                throw new Exception('缺少必要参数: username, filename 或 content');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $result = $historyManager->createHistory($username, $filename, $content);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Create history result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename]));
            break;

        case 'get_history':
            // 获取历史版本列表
            error_log("Processing get_history request");

            // 同时支持 GET 和 POST 方法
            if ($method !== 'GET' && $method !== 'POST') {
                throw new Exception('请求方法错误，请使用GET或POST方法');
            }

            if ($method === 'GET') {
                $username = $_GET['username'] ?? '';
                $filename = $_GET['filename'] ?? '';
            } else {
                $data = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON decode error: " . json_last_error_msg());
                    throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
                }
                $username = $data['username'] ?? '';
                $filename = $data['filename'] ?? '';
            }

            if (empty($username) || empty($filename)) {
                throw new Exception('缺少必要参数: username 或 filename');
            }

            $result = $historyManager->getHistoryList($username, $filename);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Get history result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename]));
            break;

        case 'restore_history':
            // 恢复历史版本
            error_log("Processing restore_history request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误，请使用POST方法');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $filename = $data['filename'] ?? '';
            $version_id = $data['version_id'] ?? 0;

            if (empty($username) || empty($filename) || empty($version_id)) {
                throw new Exception('缺少必要参数: username, filename 或 version_id');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $result = $historyManager->restoreHistory($username, $filename, $version_id);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Restore history result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename, 'version_id' => $version_id]));
            break;


        case 'delete_history':
            // 删除历史版本
            error_log("Processing delete_history request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误，请使用POST方法');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $filename = $data['filename'] ?? '';
            $version_id = $data['version_id'] ?? 0;

            if (empty($username) || empty($filename)) {
                throw new Exception('缺少必要参数: username 或 filename');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            // 调用历史版本管理器删除方法
            $result = $historyManager->deleteHistory($username, $filename, $version_id);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Delete history result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'filename' => $filename, 'version_id' => $version_id]));
            break;


        case 'sync':
            // 同步文件（批量上传）
            error_log("Processing sync request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误，请使用POST方法');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $files = $data['files'] ?? [];

            if (empty($username) || empty($files)) {
                throw new Exception('缺少必要参数: username 或 files');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $result = $fileManager->syncFiles($username, $files);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Sync files result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'count' => count($files)]));
            break;

        // 原有其他接口保持不变...
        case 'check_member':
            // 查询会员状态
            error_log("Processing check_member request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';

            if (empty($username)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->checkMemberStatus($username);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'admin_login':
            // 管理员登录
            error_log("Processing admin_login request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            if (empty($username) || empty($password)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->adminLogin($username, $password);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // 在已有的 switch ($request) { 中添加以下 case：

        case 'create_share':
            // 创建文档分享链接
            error_log("Processing create_share request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $filename = $data['filename'] ?? '';
            $mode = $data['mode'] ?? 'view';
            $password = $data['password'] ?? null;
            $sharePassword = $data['share_password'] ?? null;
            $expireDays = (int)($data['expire_days'] ?? 7);

            if (empty($username) || empty($filename)) {
                throw new Exception('缺少必要参数: username 或 filename');
            }

            // 验证模式
            if (!in_array($mode, ['view', 'edit'])) {
                throw new Exception('无效的分享模式，只允许 view 或 edit');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->createShare($username, $password, $filename, $mode, $sharePassword, $expireDays);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Create share result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'share_id' => $result['data']['share_id'] ?? '']));
            break;

        case 'get_share':
            // 获取分享信息
            error_log("Processing get_share request");
            if ($method !== 'GET' && $method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $shareId = $_GET['share_id'] ?? '';
            $password = $_GET['password'] ?? null;

            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON decode error: " . json_last_error_msg());
                    throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
                }
                $shareId = $data['share_id'] ?? $shareId;
                $password = $data['password'] ?? $password;
            }

            if (empty($shareId)) {
                throw new Exception('缺少必要参数: share_id');
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->getSharedFile($shareId, $password);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Get share result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'share_id' => $shareId]));
            break;

        case 'view_share':
            // 查看分享的文档（前端使用）
            error_log("Processing view_share request");
            if ($method !== 'GET') {
                throw new Exception('请求方法错误');
            }

            $shareId = $_GET['share_id'] ?? '';
            $password = $_GET['password'] ?? null;

            if (empty($shareId)) {
                throw new Exception('缺少必要参数: share_id');
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->getSharedFile($shareId, $password);

            if ($result['code'] === 401) {
                header('Content-Type: text/html; charset=utf-8');
                echo generatePasswordForm($shareId);
                exit;
            }
            if ($result['code'] === 200) {
                // 重定向到index.html并带上分享参数
                $baseUrl = $config['base_url'] ?? getBaseUrl();
                
                // 移除baseUrl中的/api路径，因为index.html在根目录
                $baseUrl = preg_replace('#/api$#', '', $baseUrl);
                
                $redirectUrl = rtrim($baseUrl, '/') . '/index.html?share_id=' . urlencode($shareId);
                if ($password) {
                    $redirectUrl .= '&share_password=' . urlencode($password);
                }
                header('Location: ' . $redirectUrl);
                exit;
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'update_share':
            // 更新分享的文档（用于编辑模式）
            error_log("Processing update_share request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $shareId = $data['share_id'] ?? '';
            $content = $data['content'] ?? '';
            $password = $data['password'] ?? null;

            if (empty($shareId) || empty($content)) {
                throw new Exception('缺少必要参数: share_id 或 content');
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->updateSharedFile($shareId, $content, $password);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Update share result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'share_id' => $shareId]));
            break;

        case 'list_shares':
            // 获取用户的分享列表
            error_log("Processing list_shares request");
            if ($method !== 'GET' && $method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            if ($method === 'GET') {
                $username = $_GET['username'] ?? '';
            } else {
                $data = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON decode error: " . json_last_error_msg());
                    throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
                }
                $username = $data['username'] ?? '';
            }

            if (empty($username)) {
                throw new Exception('缺少必要参数: username');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data ?? $_GET);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->getUserShares($username);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("List shares result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'count' => count($result['data']['shares'] ?? [])]));
            break;

        case 'delete_share':
            // 删除分享链接
            error_log("Processing delete_share request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $shareId = $data['share_id'] ?? '';

            if (empty($username) || empty($shareId)) {
                throw new Exception('缺少必要参数: username 或 share_id');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->deleteShare($username, $shareId);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Delete share result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'share_id' => $shareId]));
            break;

        case 'update_share_properties':
            // 更新分享链接属性
            error_log("Processing update_share_properties request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $shareId = $data['share_id'] ?? '';
            $mode = $data['mode'] ?? 'view';
            $expireDays = (int)($data['expire_days'] ?? 7);

            if (empty($username) || empty($shareId)) {
                throw new Exception('缺少必要参数: username 或 share_id');
            }

            // 验证模式
            if (!in_array($mode, ['view', 'edit'])) {
                throw new Exception('无效的分享模式，只允许 view 或 edit');
            }

            // 验证用户身份
            $authResult = verifyTokenOrPassword($user, $data);
            if ($authResult['code'] !== 200) {
                echo json_encode($authResult, JSON_UNESCAPED_UNICODE);
                break;
            }

            $shareManager = new ShareManager($db, $config);
            $result = $shareManager->updateShareProperties($username, $shareId, $mode, $expireDays);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Update share properties result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'share_id' => $shareId, 'mode' => $mode, 'expire_days' => $expireDays]));
            break;


        // ... 其他原有接口
        case 'admin_get_users':
            // 管理员查询所有用户
            error_log("Processing admin_get_users request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            // 验证管理员身份
            if ($username != $config['admin']['username'] || $password != $config['admin']['password']) {
                error_log("Admin authentication failed for user: {$username}");
                echo json_encode([
                    'code' => 401,
                    'message' => '管理员身份验证失败'
                ]);
                break;
            }

            $result = $user->getAllUsers();
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Admin get users: " . count($result['data'] ?? []) . " users retrieved");
            break;

        case 'add_auth_code':
            // 添加授权码（管理员功能）
            error_log("Processing add_auth_code request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $adminUsername = $data['username'] ?? '';
            $adminPassword = $data['password'] ?? '';
            $code = $data['code'] ?? '';
            $memberDays = $data['member_days'] ?? '';

            if (empty($adminUsername) || empty($adminPassword) || empty($code) || empty($memberDays)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->addAuthorizationCode($adminUsername, $adminPassword, $code, $memberDays);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'activate_member':
            // 开通会员
            error_log("Processing activate_member request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $code = $data['code'] ?? '';

            if (empty($username) || empty($code)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->activateMember($username, $code);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'check_update':
            // 检查版本更新
            error_log("Processing check_update request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $currentVersion = $data['current_version'] ?? '';

            if (empty($currentVersion)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->checkUpdate($currentVersion);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'admin_submit_update':
            // 管理员提交更新
            error_log("Processing admin_submit_update request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $adminUsername = $data['username'] ?? '';
            $adminPassword = $data['password'] ?? '';
            $latestVersion = $data['latest_version'] ?? '';
            $updateContent = $data['update_content'] ?? '';
            $downloadUrl = $data['download_url'] ?? '';
            $minVersion = $data['min_version'] ?? '';

            if (empty($adminUsername) || empty($adminPassword) || empty($latestVersion) || empty($updateContent) || empty($downloadUrl) || empty($minVersion)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->submitUpdate($adminUsername, $adminPassword, $latestVersion, $updateContent, $downloadUrl, $minVersion);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'admin_add_product':
            // 管理员添加商品信息
            error_log("Processing admin_add_product request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $adminUsername = $data['username'] ?? '';
            $adminPassword = $data['password'] ?? '';
            $product = $data['product'] ?? '';
            $baseUrl = $data['base_url'] ?? '';
            $apiKey = $data['api_key'] ?? '';

            // 验证管理员身份
            if ($adminUsername != $config['admin']['username'] || $adminPassword != $config['admin']['password']) {
                error_log("Admin authentication failed for user: {$adminUsername}");
                echo json_encode([
                    'code' => 401,
                    'message' => '管理员身份验证失败'
                ]);
                break;
            }

            if (empty($product) || empty($baseUrl) || empty($apiKey)) {
                throw new Exception('缺少必要参数');
            }

            $result = $apiManager->addProductInfo($product, $baseUrl, $apiKey);
            if ($result !== false) {
                echo json_encode([
                    'code' => 200,
                    'message' => '商品信息添加成功'
                ]);
            } else {
                echo json_encode([
                    'code' => 500,
                    'message' => '商品信息添加失败'
                ]);
            }
            break;

        case 'admin_delete_api':
            // 管理员删除API信息
            error_log("Processing admin_delete_api request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $adminUsername = $data['username'] ?? '';
            $adminPassword = $data['password'] ?? '';
            $baseUrl = $data['base_url'] ?? '';
            $apiKey = $data['api_key'] ?? '';

            // 验证管理员身份
            if ($adminUsername != $config['admin']['username'] || $adminPassword != $config['admin']['password']) {
                error_log("Admin authentication failed for user: {$adminUsername}");
                echo json_encode([
                    'code' => 401,
                    'message' => '管理员身份验证失败'
                ]);
                break;
            }

            if (empty($baseUrl) || empty($apiKey)) {
                throw new Exception('缺少必要参数');
            }

            $result = $apiManager->deleteApiInfo($baseUrl, $apiKey);
            if ($result !== false) {
                echo json_encode([
                    'code' => 200,
                    'message' => 'API信息删除成功'
                ]);
            } else {
                echo json_encode([
                    'code' => 500,
                    'message' => 'API信息删除失败'
                ]);
            }
            break;

        case 'admin_remove_product':
            // 管理员移除商品
            error_log("Processing admin_remove_product request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $adminUsername = $data['username'] ?? '';
            $adminPassword = $data['password'] ?? '';
            $baseUrl = $data['base_url'] ?? '';
            $apiKey = $data['api_key'] ?? '';
            $product = $data['product'] ?? '';

            // 验证管理员身份
            if ($adminUsername != $config['admin']['username'] || $adminPassword != $config['admin']['password']) {
                error_log("Admin authentication failed for user: {$adminUsername}");
                echo json_encode([
                    'code' => 401,
                    'message' => '管理员身份验证失败'
                ]);
                break;
            }

            if (empty($baseUrl) || empty($apiKey) || empty($product)) {
                throw new Exception('缺少必要参数');
            }

            $result = $apiManager->removeProduct($baseUrl, $apiKey, $product);
            if ($result !== false) {
                echo json_encode([
                    'code' => 200,
                    'message' => '商品移除成功'
                ]);
            } else {
                echo json_encode([
                    'code' => 500,
                    'message' => '商品移除失败'
                ]);
            }
            break;

        case 'admin_get_all_api':
            // 管理员获取所有API信息
            error_log("Processing admin_get_all_api request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $adminUsername = $data['username'] ?? '';
            $adminPassword = $data['password'] ?? '';

            // 验证管理员身份
            if ($adminUsername != $config['admin']['username'] || $adminPassword != $config['admin']['password']) {
                error_log("Admin authentication failed for user: {$adminUsername}");
                echo json_encode([
                    'code' => 401,
                    'message' => '管理员身份验证失败'
                ]);
                break;
            }

            $apiInfo = $apiManager->getAllApiInfo();
            echo json_encode([
                'code' => 200,
                'message' => '获取API信息成功',
                'data' => $apiInfo
            ]);
            break;

        case 'get_available_products':
            // 用户获取可用商品
            error_log("Processing get_available_products request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            // 验证用户身份
            $loginResult = $user->login($username, $password);
            if ($loginResult['code'] != 200) {
                error_log("User authentication failed for: {$username}");
                echo json_encode([
                    'code' => 401,
                    'message' => '用户身份验证失败'
                ]);
                break;
            }

            $products = $apiManager->getAllProducts();
            echo json_encode([
                'code' => 200,
                'message' => '获取可用商品成功',
                'data' => [
                    'products' => $products
                ]
            ]);
            break;

        case 'get_product_config':
            // 获取特定商品的配置
            error_log("Processing get_product_config request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $product = $data['product'] ?? '';

            // 验证用户身份
            $loginResult = $user->login($username, $password);
            if ($loginResult['code'] != 200) {
                error_log("User authentication failed for: {$username}");
                echo json_encode([
                    'code' => 401,
                    'message' => '用户身份验证失败'
                ]);
                break;
            }

            if (empty($product)) {
                throw new Exception('缺少必要参数');
            }

            $config = $apiManager->getProductConfig($product);
            if ($config) {
                echo json_encode([
                    'code' => 200,
                    'message' => '获取商品配置成功',
                    'data' => $config
                ]);
            } else {
                echo json_encode([
                    'code' => 404,
                    'message' => '商品配置不存在'
                ]);
            }
            break;

        case 'upload_screenshot':
            // 上传截图
            error_log("Processing upload_screenshot request");

            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            // 记录POST数据用于调试
            error_log("POST data: " . json_encode($_POST, JSON_UNESCAPED_UNICODE));
            error_log("FILES data: " . json_encode($_FILES, JSON_UNESCAPED_UNICODE));

            // 验证请求是否包含文件
            if (!isset($_FILES['screenshot'])) {
                error_log("No screenshot file found in FILES");
                throw new Exception('缺少截图文件');
            }

            $file = $_FILES['screenshot'];
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            error_log("Username from POST: {$username}");
            error_log("File details: Name={$file['name']}, Size={$file['size']}, Type={$file['type']}, Error={$file['error']}, Tmp_name={$file['tmp_name']}");

            // 添加用户身份验证
            if (empty($username) || empty($password)) {
                error_log("Missing username or password");
                throw new Exception('缺少必要参数（需要用户名和密码）');
            }

            // 验证用户身份
            error_log("Authenticating user: {$username}");
            $loginResult = $user->login($username, $password);
            if ($loginResult['code'] != 200) {
                error_log("User authentication failed for: {$username}");
                echo json_encode([
                    'code' => 401,
                    'message' => '用户身份验证失败'
                ]);
                break;
            }

            // 检查文件上传错误
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => '上传的文件超过了php.ini中upload_max_filesize指令限制的大小',
                    UPLOAD_ERR_FORM_SIZE => '上传文件的大小超过了HTML表单中MAX_FILE_SIZE选项指定的值',
                    UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                    UPLOAD_ERR_NO_FILE => '没有文件被上传',
                    UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                    UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                    UPLOAD_ERR_EXTENSION => 'PHP扩展停止了文件上传'
                ];
                $errorMsg = $errorMessages[$file['error']] ?? '未知上传错误';
                error_log("File upload error: {$file['error']} - {$errorMsg}");
                throw new Exception('文件上传失败: ' . $errorMsg);
            }

            // 验证文件类型
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                error_log("Invalid file type: {$file['type']}");
                throw new Exception('不支持的文件类型: ' . $file['type']);
            }

            // 验证文件大小 (限制为10MB)
            $maxSize = 10 * 1024 * 1024;
            if ($file['size'] > $maxSize) {
                error_log("File size too large: {$file['size']} bytes");
                throw new Exception('文件大小超过限制（最大10MB）');
            }

            // 生成唯一文件名
            $fileName = uniqid($username . '_' . date('Ymd_')) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
            error_log("Generated filename: {$fileName}");

            // 修改：将截图目录放在项目目录内，避免open_basedir限制
            $screenshotsDir = __DIR__ . '/screenshots';
            error_log("Screenshots directory path: {$screenshotsDir}");

            // 确保目录存在
            if (!is_dir($screenshotsDir)) {
                error_log("Creating screenshots directory: {$screenshotsDir}");
                if (!mkdir($screenshotsDir, 0755, true)) {
                    error_log("Failed to create screenshots directory");
                    throw new Exception('无法创建截图目录: ' . $screenshotsDir);
                }
            } else {
                error_log("Screenshots directory exists: {$screenshotsDir}");
            }

            // 检查目录是否可写
            if (!is_writable($screenshotsDir)) {
                error_log("Screenshots directory is not writable");
                throw new Exception('截图目录不可写');
            }

            $absolutePath = $screenshotsDir . '/' . $fileName;
            error_log("Absolute path for file: {$absolutePath}");

            // 检查临时文件是否存在
            if (!file_exists($file['tmp_name'])) {
                error_log("Temporary file does not exist: {$file['tmp_name']}");
                throw new Exception('临时文件不存在');
            }

            // 移动上传的文件
            error_log("Attempting to move uploaded file from {$file['tmp_name']} to {$absolutePath}");
            if (move_uploaded_file($file['tmp_name'], $absolutePath)) {
                error_log("File moved successfully");

                // 检查文件是否确实存在
                if (file_exists($absolutePath)) {
                    $fileSize = filesize($absolutePath);
                    error_log("File saved successfully, size: {$fileSize} bytes");

                    // 动态生成文件URL
                    $baseUrl = $config['base_url'] ?? getBaseUrl();
                    $fileUrl = rtrim($baseUrl, '/') . '/screenshots/' . $fileName;

                    echo json_encode([
                        'code' => 200,
                        'message' => '截图上传成功',
                        'data' => [
                            'file_name' => $fileName,
                            'file_path' => '/screenshots/' . $fileName,
                            'file_url' => $fileUrl,
                            'file_size' => $fileSize
                        ]
                    ]);
                } else {
                    error_log("File does not exist after move_uploaded_file");
                    throw new Exception('文件保存后不存在');
                }
            } else {
                // 获取更详细的错误信息
                $lastError = error_get_last();
                error_log("move_uploaded_file failed. Last error: " . json_encode($lastError));

                // 检查PHP错误
                $errorMsg = $lastError['message'] ?? '未知错误';

                // 检查权限问题
                if (strpos($errorMsg, 'Permission denied') !== false) {
                    error_log("Permission denied error detected");
                    throw new Exception('文件保存失败: 权限被拒绝，请检查目录权限');
                }

                throw new Exception('文件保存失败: ' . $errorMsg);
            }
            break;

        case 'search_products':
            // 搜索商品
            error_log("Processing search_products request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $keyword = $data['keyword'] ?? '';

            // 验证用户身份
            $loginResult = $user->login($username, $password);
            if ($loginResult['code'] != 200) {
                error_log("User authentication failed for: {$username}");
                echo json_encode([
                    'code' => 401,
                    'message' => '用户身份验证失败'
                ]);
                break;
            }

            if (empty($keyword)) {
                throw new Exception('缺少搜索关键词');
            }

            $matchedProducts = $apiManager->searchProducts($keyword);
            echo json_encode([
                'code' => 200,
                'message' => '商品搜索成功',
                'data' => [
                    'keyword' => $keyword,
                    'products' => $matchedProducts,
                    'total' => count($matchedProducts)
                ]
            ]);
            break;

        case 'upload_avatar':
            // 上传头像
            error_log("Processing upload_avatar request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            // 记录POST数据用于调试
            error_log("POST data for avatar: " . json_encode($_POST, JSON_UNESCAPED_UNICODE));
            error_log("FILES data for avatar: " . json_encode($_FILES, JSON_UNESCAPED_UNICODE));

            // 验证请求是否包含文件
            if (!isset($_FILES['avatar'])) {
                error_log("No avatar file found in FILES");
                throw new Exception('缺少头像文件');
            }

            $file = $_FILES['avatar'];
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            error_log("Avatar upload: Username={$username}, File name={$file['name']}, Size={$file['size']}");

            if (empty($username) || empty($password)) {
                error_log("Missing username or password for avatar upload");
                throw new Exception('缺少必要参数');
            }

            // 验证用户身份
            $loginResult = $user->login($username, $password);
            if ($loginResult['code'] != 200) {
                error_log("User authentication failed for avatar upload: {$username}");
                echo json_encode([
                    'code' => 401,
                    'message' => '用户身份验证失败'
                ]);
                break;
            }

            $result = $user->uploadAvatar($username, $file);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            error_log("Avatar upload result: " . json_encode(['code' => $result['code'] ?? 'unknown', 'message' => $result['message'] ?? '']));
            break;

        case 'get_avatar':
            // 获取头像
            error_log("Processing get_avatar request");
            if ($method !== 'POST') {
                throw new Exception('请求方法错误');
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                throw new Exception('JSON数据格式错误: ' . json_last_error_msg());
            }

            $username = $data['username'] ?? '';

            if (empty($username)) {
                throw new Exception('缺少必要参数');
            }

            $result = $user->getAvatar($username);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            // 如果没有指定action，返回默认信息
            if ($request === '') {
                echo json_encode([
                    'code' => 200,
                    'message' => 'API服务运行正常',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                error_log("Default API response sent");
            } else {
                error_log("Invalid API request: {$request}");
                throw new Exception('无效的API请求');
            }
    }
} catch (Exception $e) {
    error_log("Exception caught in main switch: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'code' => 400,
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}

// 关闭数据库连接
try {
    $db->close();
    error_log("Database connection closed");
} catch (Exception $e) {
    error_log("Error closing database connection: " . $e->getMessage());
}

// 记录请求处理时间
$requestEndTime = microtime(true);
$processingTime = round(($requestEndTime - $requestStartTime) * 1000, 2);
error_log("Request processing time: {$processingTime} ms");
error_log("=== Request End ===\n");

// 辅助函数：验证token或密码
function verifyTokenOrPassword($user, $data) {
    $username = $data['username'] ?? '';
    $token = $data['token'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($username)) {
        return [
            'code' => 400,
            'message' => '缺少必要参数: username'
        ];
    }

    // 优先使用token验证
    if (!empty($token)) {
        // 这里简单实现：token就是用户名（可以根据需要修改为更复杂的验证逻辑）
        if ($token !== $username) {
            return [
                'code' => 401,
                'message' => 'Token验证失败'
            ];
        }
        return ['code' => 200];
    }

    // 如果没有token，使用密码验证
    if (!empty($password)) {
        $loginResult = $user->login($username, $password);
        return $loginResult;
    }

    // 既没有token也没有密码
    return [
        'code' => 401,
        'message' => '需要提供token或密码进行身份验证'
    ];
}

// 辅助函数：获取当前基础URL
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https://" : "http://";
    $domain = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);

    // 如果路径是根目录，则返回协议和域名，否则加上路径
    if ($path === '/') {
        return $protocol . $domain;
    } else {
        return $protocol . $domain . $path;
    }
}

function generatePasswordForm($shareId) {
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>输入访问密码</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .password-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        input[type="password"]:focus {
            border-color: #667eea;
            outline: none;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
            width: 100%;
        }
        button:hover {
            transform: translateY(-2px);
        }
        .error {
            color: #e74c3c;
            margin-top: 10px;
            display: none;
        }
    </style>
</head>
<body>
    <div class="password-container">
        <h2>🔒 需要访问密码</h2>
        <p>该文档已设置访问密码，请输入密码继续查看</p>
        <input type="password" id="password" placeholder="请输入访问密码">
        <div class="error" id="errorMsg">密码错误，请重试</div>
        <button onclick="submitPassword()">确认</button>
    </div>
    
    <script>
        function submitPassword() {
            const password = document.getElementById('password').value;
            const shareId = '{$shareId}';
            
            if (!password) {
                showError('请输入密码');
                return;
            }
            
            // 使用POST方式提交密码（与当前页面同路径的 API）
            var apiPath = window.location.pathname;
            fetch(apiPath + '?action=get_share', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    share_id: shareId,
                    password: password
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.code === 200) {
                    // 密码正确，跳转到查看页面
                    window.location.href = apiPath + '?action=view_share&share_id=' + shareId + '&password=' + encodeURIComponent(password);
                } else {
                    showError(data.message || '密码错误');
                }
            })
            .catch(error => {
                showError('网络错误，请重试');
            });
        }
        
        function showError(message) {
            const errorEl = document.getElementById('errorMsg');
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
        
        // 回车键提交
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                submitPassword();
            }
        });
    </script>
</body>
</html>
HTML;
}

/**
 * 生成分享查看页面
 */
function generateShareViewPage($shareData) {
    $filename = htmlspecialchars($shareData['filename']);
    $content = $shareData['content'];
    $mode = $shareData['mode'];
    $isEditable = $mode === 'edit';
    $expiresAt = $shareData['expires_at'];
    $isExpired = $shareData['is_expired'];
    $shareId = $shareData['share_id'];

    $expiryInfo = '';
    if ($expiresAt) {
        $expiryDate = date('Y-m-d H:i', strtotime($expiresAt));
        if ($isExpired) {
            $expiryInfo = "<div class='expired'>⚠️ 此分享链接已于 {$expiryDate} 过期</div>";
        } else {
            $expiryInfo = "<div class='expiry'>此分享链接将于 {$expiryDate} 过期</div>";
        }
    }

    $editControls = '';
    if ($isEditable && !$isExpired) {
        $editControls = <<<HTML
        <div class="edit-controls">
            <button onclick="enableEdit()" id="editBtn">✏️ 编辑文档</button>
            <button onclick="saveChanges()" id="saveBtn" style="display:none">💾 保存修改</button>
            <button onclick="cancelEdit()" id="cancelBtn" style="display:none">❌ 取消</button>
        </div>
HTML;
    }

    // 处理三元运算符（PHP 8.0 兼容）
    $modeText = $isEditable ? '可编辑' : '仅查看';
    $isEditableJS = $isEditable ? 'true' : 'false';
    $isExpiredJS = $isExpired ? 'true' : 'false';
    $contentJS = json_encode($content);

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{$filename} - 文档分享</title>
    <link rel="stylesheet" href="../css/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../css/styles.css" />
    <link rel="stylesheet" href="../vditor@3.11.2/dist/index.css" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f6f8fa;
            color: #24292e;
            line-height: 1.6;
        }
        .header {
            background: white;
            border-bottom: 1px solid #e1e4e8;
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .title {
            font-size: 18px;
            font-weight: 600;
            color: #24292e;
        }
        .title span {
            color: #6a737d;
            font-size: 14px;
        }
        .mode-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        .mode-view {
            background: #f1f8ff;
            color: #0366d6;
        }
        .mode-edit {
            background: #dcffe4;
            color: #28a745;
        }
        .info-box {
            font-size: 14px;
            color: #6a737d;
            background: #f6f8fa;
            border-radius: 6px;
            padding: 8px 12px;
            border: 1px solid #e1e4e8;
        }
        .info-box .expired {
            color: #cf222e;
            font-weight: 500;
        }
        .info-box .expiry {
            color: #0969da;
        }
        .edit-controls {
            display: flex;
            gap: 8px;
        }
        button {
            padding: 8px 16px;
            border: 1px solid #d1d9e0;
            border-radius: 6px;
            background: white;
            color: #24292e;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        button:hover {
            background: #f6f8fa;
            border-color: #8b949e;
        }
        button:active {
            background: #ebf0f4;
        }
        #saveBtn {
            background: #2da44e;
            color: white;
            border-color: #2da44e;
        }
        #saveBtn:hover {
            background: #2c974b;
            border-color: #2c974b;
        }
        #cancelBtn {
            background: #f6f8fa;
            color: #cf222e;
            border-color: #d1d9e0;
        }
        .container {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 24px;
        }
        .markdown-body {
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 6px;
            padding: 32px;
            min-height: 400px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        #vditor {
            height: 600px;
            border: 1px solid #e1e4e8;
            border-radius: 6px;
            display: none;
        }
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 12px 24px;
            border-radius: 6px;
            background: #24292e;
            color: white;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s;
        }
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        .toast.success {
            background: #2da44e;
        }
        .toast.error {
            background: #cf222e;
        }
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            .edit-controls {
                width: 100%;
                justify-content: flex-start;
            }
            .container {
                padding: 0 16px;
            }
            #vditor {
                height: 500px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="title">
                {$filename}
                <span class="mode-badge mode-{$mode}">
                    {$modeText}
                </span>
            </div>
            <div class="info-box">
                {$expiryInfo}
            </div>
            {$editControls}
        </div>
    </div>
    
    <div class="container">
        <div id="viewer" class="markdown-body">
            <!-- Markdown内容将通过JavaScript渲染 -->
        </div>
        <div id="vditor"></div>
    </div>
    
    <div id="toast" class="toast"></div>
    
    <!-- 加载Vditor资源 -->
    <script src="../vditor@3.11.2/dist/index.min.js"></script>
    <script>
        // 初始化变量
        let originalContent = {$contentJS};
        let isEditing = false;
        let shareId = '{$shareId}';
        let isEditable = {$isEditableJS};
        let isExpired = {$isExpiredJS};
        let vditor = null; // 全局声明vditor变量
        
        // 初始化页面
        document.addEventListener('DOMContentLoaded', async function() {
            // 渲染Markdown
            await renderMarkdown(originalContent);
            
            // 如果已过期且可编辑，禁用编辑功能
            if (isExpired && isEditable) {
                const editBtn = document.getElementById('editBtn');
                if (editBtn) {
                    editBtn.disabled = true;
                    editBtn.innerHTML = '⏰ 链接已过期';
                    editBtn.style.opacity = '0.6';
                    editBtn.style.cursor = 'not-allowed';
                }
            }
        });
        
        // 渲染Markdown内容
        async function renderMarkdown(content) {
            const viewer = document.getElementById('viewer');
            try {
                // 使用Vditor的HTML渲染能力（处理Promise返回值）
                const html = await Vditor.md2html(content, {
                    mark: true,
                    footnotes: true,
                    toc: true,
                    autoSpace: true,
                    code: {
                        highlight: true
                    },
                    math: {
                        engine: 'KaTeX'
                    },
                    emoji: {
                        enable: true
                    },
                    mermaid: {
                        enable: true
                    }
                });
                viewer.innerHTML = html;
                
                // 手动渲染mermaid图表
                if (window.mermaid) {
                    mermaid.init();
                }
            } catch (error) {
                console.error('渲染Markdown失败:', error);
                viewer.innerHTML = '<p style="color: red;">渲染失败，请刷新页面重试</p>';
            }
        }
        
        // 启用编辑模式
        function enableEdit() {
            if (isExpired) {
                showToast('分享链接已过期，无法编辑', 'error');
                return;
            }
            
            isEditing = true;
            document.getElementById('viewer').style.display = 'none';
            document.getElementById('vditor').style.display = 'block';
            document.getElementById('editBtn').style.display = 'none';
            document.getElementById('saveBtn').style.display = 'flex';
            document.getElementById('cancelBtn').style.display = 'flex';
            
            // 初始化Vditor编辑器
            initVditor(originalContent);
        }
        
        // 初始化Vditor
        function initVditor(content) {
            try {
                if (vditor) {
                    // 安全销毁Vditor实例
                    if (typeof vditor.destroy === 'function') {
                        vditor.destroy();
                    }
                    vditor = null;
                }
                
                vditor = new Vditor('vditor', {
                    height: '100%',
                    width: '100%',
                    placeholder: '开始编辑...支持 Markdown 语法',
                    toolbar: [],
                    customWysiwygToolbar: undefined,
                    theme: 'classic',
                    mode: 'ir',
                    cache: { enable: false },
                    outline: { enable: false },
                    hint: { emoji: {} },
                    value: content,
                    math: {
                        engine: 'KaTeX'
                    },
                    mermaid: {
                        enable: true
                    },
                    after: function() {
                        // 编辑器初始化完成
                    }
                });
            } catch (error) {
                console.error('初始化Vditor失败:', error);
                showToast('编辑器初始化失败，请刷新页面重试', 'error');
            }
        }
        
        // 取消编辑
        function cancelEdit() {
            isEditing = false;
            document.getElementById('viewer').style.display = 'block';
            document.getElementById('vditor').style.display = 'none';
            document.getElementById('editBtn').style.display = 'flex';
            document.getElementById('saveBtn').style.display = 'none';
            document.getElementById('cancelBtn').style.display = 'none';
            
            // 销毁Vditor实例
            try {
                if (vditor) {
                    // 安全销毁Vditor实例
                    if (typeof vditor.destroy === 'function') {
                        vditor.destroy();
                    }
                    vditor = null;
                }
            } catch (error) {
                console.error('销毁Vditor失败:', error);
            }
        }
        
        // 保存修改
        function saveChanges() {
            if (isExpired) {
                showToast('分享链接已过期，无法保存', 'error');
                return;
            }
            
            const newContent = vditor.getValue();
            
            // 获取密码（如果有）
            const urlParams = new URLSearchParams(window.location.search);
            const password = urlParams.get('password');
            
            // 发送更新请求
            fetch('api/index.php?action=update_share', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    share_id: shareId,
                    content: newContent,
                    password: password
                })
            })
            .then(response => response.json())
            .then(async data => {
                if (data.code === 200) {
                    // 更新成功
                    originalContent = newContent;
                    await renderMarkdown(newContent);
                    cancelEdit();
                    showToast('文档更新成功', 'success');
                } else {
                    showToast('保存失败: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('网络错误，请重试', 'error');
            });
        }
        
        // 显示提示消息
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        // 复制分享链接
        function copyShareLink() {
            const url = window.location.href.split('?')[0] + '?action=view_share&share_id=' + shareId;
            navigator.clipboard.writeText(url)
                .then(() => showToast('链接已复制到剪贴板', 'success'))
                .catch(() => showToast('复制失败，请手动复制', 'error'));
        }
    </script>
</body>
</html>
HTML;
}