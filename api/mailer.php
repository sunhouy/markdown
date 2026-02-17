<?php
/**
 * 邮箱验证码API接口
 * 文件: email_api.php
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 如果是OPTIONS请求，直接返回
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_USER', 'ershoumao');
define('DB_PASS', '123456');
define('DB_NAME', 'ershoumao');
define('DB_CHARSET', 'utf8mb4');

// 邮件配置
define('PYTHON_SCRIPT', '/www/wwwroot/mail/mail.py');
define('SENDER_EMAIL', 'sunhouyun@yhsun.cn');
define('SENDER_PASSWORD', '127127suN!');
define('SMTP_SERVER', 'smtp.exmail.qq.com');
define('SMTP_PORT', 465);

// 验证码配置
define('CODE_EXPIRE_MINUTES', 10);
define('CODE_LENGTH', 6);
define('MAX_ATTEMPTS', 5); // 最大尝试次数
define('RATE_LIMIT_SECONDS', 60); // 60秒内只能发送一次

// 错误处理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器内部错误',
        'debug' => (isset($_GET['debug']) ? "$errstr in $errfile on line $errline" : null)
    ]);
    exit;
});

// 数据库连接
class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            throw new Exception("数据库连接失败: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

// 邮箱验证类
class EmailVerification {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 生成验证码
     */
    public function generateCode() {
        return str_pad(mt_rand(0, pow(10, CODE_LENGTH) - 1), CODE_LENGTH, '0', STR_PAD_LEFT);
    }
    
    /**
     * 发送验证码
     */
    public function sendCode($email) {
        // 验证邮箱格式
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => '邮箱格式不正确'];
        }
        
        // 检查发送频率限制
        if (!$this->checkRateLimit($email)) {
            return ['status' => 'error', 'message' => '发送过于频繁，请稍后再试'];
        }
        
        // 清理过期验证码
        $this->cleanExpiredCodes();
        
        // 生成验证码
        $code = $this->generateCode();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . CODE_EXPIRE_MINUTES . ' minutes'));
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        try {
            // 保存验证码到数据库
            $stmt = $this->db->prepare("
                INSERT INTO email_verification_codes 
                (email, code, expires_at, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$email, $code, $expiresAt, $ip, $userAgent]);
            $codeId = $this->db->lastInsertId();
            
            // 调用Python脚本发送邮件
            $command = escapeshellcmd(
                'python3 ' . PYTHON_SCRIPT . ' ' .
                escapeshellarg($email) . ' ' .
                escapeshellarg($code) . ' ' .
                escapeshellarg(SENDER_EMAIL) . ' ' .
                escapeshellarg(SENDER_PASSWORD) . ' ' .
                escapeshellarg(SMTP_SERVER) . ' ' .
                escapeshellarg(SMTP_PORT)
            );
            
            $output = shell_exec($command . ' 2>&1');
            $result = json_decode($output, true);
            
            // 记录发送日志
            $logStatus = isset($result['status']) && $result['status'] === 'success' ? 'success' : 'failed';
            $errorMsg = isset($result['message']) ? $result['message'] : null;
            
            $this->logEmailSend($email, $logStatus, $errorMsg);
            
            if ($logStatus === 'success') {
                return [
                    'status' => 'success',
                    'message' => '验证码发送成功',
                    'expires_in' => CODE_EXPIRE_MINUTES * 60
                ];
            } else {
                // 发送失败，删除验证码记录
                $this->db->prepare("DELETE FROM email_verification_codes WHERE id = ?")->execute([$codeId]);
                return [
                    'status' => 'error',
                    'message' => '发送失败: ' . ($errorMsg ?: '未知错误')
                ];
            }
            
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => '服务器错误: ' . $e->getMessage()];
        }
    }
    
    /**
     * 验证验证码
     */
    public function verifyCode($email, $code) {
        try {
            // 查找验证码记录
            $stmt = $this->db->prepare("
                SELECT id, code, expires_at, status 
                FROM email_verification_codes 
                WHERE email = ? AND status = 0 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $record = $stmt->fetch();
            
            if (!$record) {
                return ['status' => 'error', 'message' => '验证码不存在或已使用'];
            }
            
            // 检查是否过期
            if (strtotime($record['expires_at']) < time()) {
                $this->markCodeExpired($record['id']);
                return ['status' => 'error', 'message' => '验证码已过期'];
            }
            
            // 检查尝试次数
            if (!$this->checkAttempts($record['id'])) {
                return ['status' => 'error', 'message' => '尝试次数过多，请重新获取验证码'];
            }
            
            // 验证验证码
            if ($record['code'] === $code) {
                $this->markCodeVerified($record['id']);
                return ['status' => 'success', 'message' => '验证成功'];
            } else {
                $this->recordAttempt($record['id'], false);
                return ['status' => 'error', 'message' => '验证码错误'];
            }
            
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => '验证失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 检查发送频率限制
     */
    private function checkRateLimit($email) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM email_verification_codes 
            WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$email, RATE_LIMIT_SECONDS]);
        $result = $stmt->fetch();
        
        return $result['count'] == 0;
    }
    
    /**
     * 清理过期验证码
     */
    private function cleanExpiredCodes() {
        $this->db->exec("
            UPDATE email_verification_codes 
            SET status = 2 
            WHERE status = 0 AND expires_at < NOW()
        ");
    }
    
    /**
     * 标记验证码已验证
     */
    private function markCodeVerified($id) {
        $stmt = $this->db->prepare("
            UPDATE email_verification_codes 
            SET status = 1, verified_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
    
    /**
     * 标记验证码已过期
     */
    private function markCodeExpired($id) {
        $stmt = $this->db->prepare("
            UPDATE email_verification_codes 
            SET status = 2 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
    
    /**
     * 检查尝试次数
     */
    private function checkAttempts($codeId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as attempts 
            FROM email_verification_attempts 
            WHERE code_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$codeId]);
        $result = $stmt->fetch();
        
        return $result['attempts'] < MAX_ATTEMPTS;
    }
    
    /**
     * 记录尝试
     */
    private function recordAttempt($codeId, $success) {
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $this->db->prepare("
            INSERT INTO email_verification_attempts 
            (code_id, success, ip_address, user_agent) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$codeId, $success ? 1 : 0, $ip, $userAgent]);
    }
    
    /**
     * 记录邮件发送日志
     */
    private function logEmailSend($email, $status, $errorMsg = null) {
        $stmt = $this->db->prepare("
            INSERT INTO email_send_logs 
            (email, status, error_message) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$email, $status, $errorMsg]);
    }
    
    /**
     * 记录API请求日志
     */
    private function logApiRequest($endpoint, $requestData, $responseData) {
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];
        
        $stmt = $this->db->prepare("
            INSERT INTO api_request_logs 
            (endpoint, method, ip_address, user_agent, request_data, response_data) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $endpoint, 
            $method, 
            $ip, 
            $userAgent, 
            json_encode($requestData), 
            json_encode($responseData)
        ]);
    }
    
    /**
     * 获取客户端IP
     */
    private function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        
        return $ip;
    }
}

// 主程序
try {
    // 获取请求方法和内容
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $action = basename($path);
    
    // 只处理POST请求
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => '只支持POST请求']);
        exit;
    }
    
    // 获取JSON输入
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '请求数据为空']);
        exit;
    }
    
    // 创建验证实例
    $verifier = new EmailVerification();
    $response = null;
    
    // 根据action路由
    switch ($action) {
        case 'send-code':
            if (empty($input['email'])) {
                $response = ['status' => 'error', 'message' => '邮箱地址不能为空'];
                break;
            }
            
            $response = $verifier->sendCode($input['email']);
            break;
            
        case 'verify-code':
            if (empty($input['email']) || empty($input['code'])) {
                $response = ['status' => 'error', 'message' => '邮箱和验证码不能为空'];
                break;
            }
            
            $response = $verifier->verifyCode($input['email'], $input['code']);
            break;
            
        case 'health':
            $response = [
                'status' => 'success',
                'message' => '服务正常',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        default:
            http_response_code(404);
            $response = ['status' => 'error', 'message' => '接口不存在'];
    }
    
    // 记录请求日志（可选）
    // $verifier->logApiRequest($action, $input, $response);
    
    // 返回响应
    $httpCode = isset($response['status']) && $response['status'] === 'success' ? 200 : 400;
    http_response_code($httpCode);
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器内部错误'
    ]);
}
?>