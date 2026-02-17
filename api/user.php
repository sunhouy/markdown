<?php

class User {
    private $db;
    private $config;

    public function __construct($db, $config) {
        $this->db = $db;
        $this->config = $config;
    }

    // 密码加密
    public function encryptPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    // 密码验证
    public function verifyPassword($password, $hashedPassword) {
        return password_verify($password, $hashedPassword);
    }

    // 用户注册
    public function register($username, $password, $inviteCode = null) {
        // 检查用户名是否已存在
        $sql = "SELECT id FROM users WHERE username = ?";
        $result = $this->db->query($sql, [$username]);
        if ($result->num_rows > 0) {
            return [
                'code' => 400,
                'message' => '用户名已存在'
            ];
        }

        // 加密密码
        $hashedPassword = $this->encryptPassword($password);

        // 开始事务
        $this->db->getConnection()->begin_transaction();

        try {
            // 验证邀请码（如果提供了邀请码）
            if ($inviteCode) {
                // 检查邀请码是否是已注册用户的用户名
                $sql = "SELECT id FROM users WHERE username = ?";
                $result = $this->db->query($sql, [$inviteCode]);
                if ($result->num_rows == 0) {
                    throw new Exception('无效的邀请码');
                }
            }
            
            // 插入用户数据，包含邀请人信息
            $sql = "INSERT INTO users (username, password, inviter) VALUES (?, ?, ?)";
            $result = $this->db->execute($sql, [$username, $hashedPassword, $inviteCode]);
            if (!$result['success']) {
                throw new Exception('插入用户数据失败');
            }

            $today = date('Y-m-d');
            
            // 如果有邀请码，奖励会员
            if ($inviteCode) {
                // 给新注册用户送1天会员
                $userNewExpireDate = date('Y-m-d', strtotime("$today + 1 day"));
                $sql = "UPDATE users SET is_member = 1, expire_date = ? WHERE username = ?";
                $result = $this->db->execute($sql, [$userNewExpireDate, $username]);
                if (!$result['success']) {
                    throw new Exception('更新新用户会员状态失败');
                }
                
                // 添加新用户会员记录
                $sql = "INSERT INTO member_records (username, type, source, added_days, start_date, end_date) VALUES (?, 'invite', ?, 1, ?, ?)";
                $result = $this->db->execute($sql, [$username, $inviteCode, $today, $userNewExpireDate]);
                if (!$result['success']) {
                    throw new Exception('添加新用户会员记录失败');
                }
                
                // 奖励邀请人1天会员
                // 检查邀请人是否存在（前面已经验证过，这里再次查询是为了获取详细信息）
                $sql = "SELECT id, is_member, expire_date FROM users WHERE username = ?";
                $result = $this->db->query($sql, [$inviteCode]);
                if ($result->num_rows > 0) {
                    $inviter = $result->fetch_assoc();
                    $inviterCurrentExpireDate = $inviter['expire_date'] ?? $today;
                    $inviterNewExpireDate = $inviter['is_member'] ? 
                        date('Y-m-d', strtotime("$inviterCurrentExpireDate + 1 day")) : 
                        date('Y-m-d', strtotime("$today + 1 day"));
                    $inviterStartDate = $inviter['is_member'] ? $inviterCurrentExpireDate : $today;

                    // 更新邀请人的会员状态
                    $sql = "UPDATE users SET is_member = 1, expire_date = ? WHERE username = ?";
                    $result = $this->db->execute($sql, [$inviterNewExpireDate, $inviteCode]);
                    if (!$result['success']) {
                        throw new Exception('更新邀请人会员状态失败');
                    }

                    // 添加邀请人会员记录
                    $sql = "INSERT INTO member_records (username, type, source, added_days, start_date, end_date) VALUES (?, 'invite', ?, 1, ?, ?)";
                    $result = $this->db->execute($sql, [$inviteCode, $username, $inviterStartDate, $inviterNewExpireDate]);
                    if (!$result['success']) {
                        throw new Exception('添加邀请人会员记录失败');
                    }
                }
            }

            // 提交事务
            $this->db->getConnection()->commit();

            // 确定返回的会员状态
            $isMember = 0;
            if ($inviteCode) {
                $isMember = 1;
            }
            
            return [
                'code' => 200,
                'message' => '注册成功',
                'data' => [
                    'username' => $username,
                    'is_member' => $isMember,
                    'inviter' => $inviteCode
                ]
            ];
        } catch (Exception $e) {
            // 回滚事务
            $this->db->getConnection()->rollback();
            return [
                'code' => 500,
                'message' => '注册失败: ' . $e->getMessage()
            ];
        }
    }

    // 用户登录
    public function login($username, $password) {
        // 先查询是否存在该用户名
        $sql = "SELECT * FROM users WHERE username = ?";
        $result = $this->db->query($sql, [$username]);

        if ($result->num_rows == 0) {
            return [
                'code' => 401,
                'message' => '用户名不存在'
            ];
        }

        $user = $result->fetch_assoc();

        // 验证密码
        if (!$this->verifyPassword($password, $user['password'])) {
            return [
                'code' => 401,
                'message' => '密码错误'
            ];
        }

        // 更新登录信息
        $sql = "UPDATE users SET last_login = CURRENT_TIMESTAMP, login_count = login_count + 1 WHERE id = ?";
        $this->db->execute($sql, [$user['id']]);

        return [
            'code' => 200,
            'message' => '登录成功',
            'data' => [
                'username' => $user['username'],
                'is_member' => $user['is_member'],
                'last_login' => $user['last_login'],
                'login_count' => $user['login_count'] + 1
            ]
        ];
    }

    // 查询会员状态
    public function checkMemberStatus($username) {
        $sql = "SELECT is_member, expire_date, created_at, last_login FROM users WHERE username = ?";
        $result = $this->db->query($sql, [$username]);

        if ($result->num_rows == 0) {
            return [
                'code' => 404,
                'message' => '用户不存在'
            ];
        }

        $user = $result->fetch_assoc();

        return [
            'code' => 200,
            'message' => '查询成功',
            'data' => [
                'username' => $username,
                'is_member' => $user['is_member'],
                'expire_date' => $user['expire_date'],
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login']
            ]
        ];
    }

    // 添加授权码（管理员功能）
    public function addAuthorizationCode($adminUsername, $adminPassword, $code, $memberDays) {
        // 验证管理员身份
        if ($adminUsername != $this->config['admin']['username'] || $adminPassword != $this->config['admin']['password']) {
            return [
                'code' => 401,
                'message' => '管理员身份验证失败'
            ];
        }

        // 检查授权码是否已存在
        $sql = "SELECT id FROM authorization_codes WHERE plain_code = ?";
        $result = $this->db->query($sql, [$code]);
        if ($result->num_rows > 0) {
            return [
                'code' => 400,
                'message' => '授权码已存在'
            ];
        }

        // 加密授权码
        $hashedCode = $this->encryptPassword($code);

        // 插入授权码
        $sql = "INSERT INTO authorization_codes (code, plain_code, member_days) VALUES (?, ?, ?)";
        $result = $this->db->execute($sql, [$hashedCode, $code, $memberDays]);

        if ($result['success']) {
            return [
                'code' => 200,
                'message' => '授权码添加成功',
                'data' => [
                    'code' => $code,
                    'member_days' => $memberDays
                ]
            ];
        } else {
            return [
                'code' => 500,
                'message' => '授权码添加失败'
            ];
        }
    }

    // 使用授权码开通会员
    public function activateMember($username, $code) {
        // 检查用户是否存在
        $sql = "SELECT id, is_member, expire_date FROM users WHERE username = ?";
        $result = $this->db->query($sql, [$username]);
        if ($result->num_rows == 0) {
            return [
                'code' => 404,
                'message' => '用户不存在'
            ];
        }
        $user = $result->fetch_assoc();

        // 检查授权码是否有效，并获取会员天数
        $sql = "SELECT id, member_days FROM authorization_codes WHERE plain_code = ? AND is_used = 0";
        $result = $this->db->query($sql, [$code]);
        if ($result->num_rows == 0) {
            return [
                'code' => 400,
                'message' => '无效的授权码'
            ];
        }

        $authCode = $result->fetch_assoc();
        $memberDays = $authCode['member_days'];

        // 计算新的到期时间
        $today = date('Y-m-d');
        $currentExpireDate = $user['expire_date'] ?? $today;
        $newExpireDate = $user['is_member'] ? 
            date('Y-m-d', strtotime("$currentExpireDate + $memberDays days")) : 
            date('Y-m-d', strtotime("$today + $memberDays days"));
        $startDate = $user['is_member'] ? $currentExpireDate : $today;

        // 开始事务
        $this->db->getConnection()->begin_transaction();

        try {
            // 更新用户为会员，并设置新的到期时间
            $sql = "UPDATE users SET is_member = 1, expire_date = ? WHERE username = ?";
            $result = $this->db->execute($sql, [$newExpireDate, $username]);
            if (!$result['success']) {
                throw new Exception('更新会员状态失败');
            }

            // 标记授权码为已使用
            $sql = "UPDATE authorization_codes SET is_used = 1, username = ?, used_at = CURRENT_TIMESTAMP WHERE plain_code = ?";
            $result = $this->db->execute($sql, [$username, $code]);
            if (!$result['success']) {
                throw new Exception('更新授权码状态失败');
            }

            // 添加会员开通记录
            $sql = "INSERT INTO member_records (username, type, source, added_days, start_date, end_date) VALUES (?, 'auth_code', ?, ?, ?, ?)";
            $result = $this->db->execute($sql, [$username, $code, $memberDays, $startDate, $newExpireDate]);
            if (!$result['success']) {
                throw new Exception('添加会员记录失败');
            }

            // 提交事务
            $this->db->getConnection()->commit();

            return [
                'code' => 200,
                'message' => '会员开通成功',
                'data' => [
                    'expire_date' => $newExpireDate,
                    'added_days' => $memberDays
                ]
            ];
        } catch (Exception $e) {
            // 回滚事务
            $this->db->getConnection()->rollback();
            return [
                'code' => 500,
                'message' => '开通会员失败: ' . $e->getMessage()
            ];
        }
    }

    // 管理员登录
    public function adminLogin($username, $password) {
        if ($username != $this->config['admin']['username'] || $password != $this->config['admin']['password']) {
            return [
                'code' => 401,
                'message' => '管理员账号或密码错误'
            ];
        }

        return [
            'code' => 200,
            'message' => '管理员登录成功'
        ];
    }

    // 查询所有用户（管理员功能）
    public function getAllUsers() {
        $sql = "SELECT username, is_member, created_at, last_login FROM users ORDER BY created_at DESC";
        $result = $this->db->query($sql);

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        return [
            'code' => 200,
            'message' => '查询成功',
            'data' => $users
        ];
    }
    
    // 检查版本更新
    public function checkUpdate($currentVersion) {
        // 版本文件路径
        $versionFile = dirname(__FILE__) . '/version_info.txt';
        
        // 检查文件是否存在
        if (!file_exists($versionFile)) {
            return [
                'code' => 200,
                'message' => '不需要更新',
                'data' => [
                    'need_update' => 0,
                    'force_update' => 0
                ]
            ];
        }
        
        // 读取版本信息
        $versionContent = file_get_contents($versionFile);
        $versionInfo = json_decode($versionContent, true);
        
        // 检查JSON解析是否成功
        if (!$versionInfo) {
            return [
                'code' => 500,
                'message' => '版本信息解析失败'
            ];
        }
        
        // 比较版本号，判断是否需要更新
        $needUpdate = 0;
        $forceUpdate = 0;
        
        // 版本号比较函数
        function compareVersions($v1, $v2) {
            $v1Parts = explode('.', $v1);
            $v2Parts = explode('.', $v2);
            
            $maxLength = max(count($v1Parts), count($v2Parts));
            
            for ($i = 0; $i < $maxLength; $i++) {
                $v1Part = isset($v1Parts[$i]) ? intval($v1Parts[$i]) : 0;
                $v2Part = isset($v2Parts[$i]) ? intval($v2Parts[$i]) : 0;
                
                if ($v1Part > $v2Part) {
                    return 1;
                } elseif ($v1Part < $v2Part) {
                    return -1;
                }
            }
            
            return 0;
        }
        
        // 判断是否需要更新
        if (compareVersions($currentVersion, $versionInfo['latest_version']) < 0) {
            $needUpdate = 1;
            
            // 判断是否需要强制更新
            if (compareVersions($currentVersion, $versionInfo['min_version']) < 0) {
                $forceUpdate = 1;
            }
        }
        
        // 构建返回结果
        $result = [
            'code' => 200,
            'message' => $needUpdate ? '有新版本' : '不需要更新',
            'data' => [
                'need_update' => $needUpdate,
                'force_update' => $forceUpdate
            ]
        ];
        
        // 如果需要更新，添加新版本信息
        if ($needUpdate) {
            $result['data']['latest_version'] = $versionInfo['latest_version'];
            $result['data']['update_content'] = $versionInfo['update_content'];
            $result['data']['download_url'] = $versionInfo['download_url'];
        }
        
        return $result;
    }
    
    // 管理员提交更新
    public function submitUpdate($adminUsername, $adminPassword, $latestVersion, $updateContent, $downloadUrl, $minVersion) {
        // 验证管理员身份
        if ($adminUsername != $this->config['admin']['username'] || $adminPassword != $this->config['admin']['password']) {
            return [
                'code' => 401,
                'message' => '管理员身份验证失败'
            ];
        }
        
        // 构建版本信息
        $versionInfo = [
            'latest_version' => $latestVersion,
            'update_content' => $updateContent,
            'download_url' => $downloadUrl,
            'min_version' => $minVersion
        ];
        
        // 版本文件路径
        $versionFile = dirname(__FILE__) . '/version_info.txt';
        
        // 写入版本信息
        $result = file_put_contents($versionFile, json_encode($versionInfo, JSON_UNESCAPED_UNICODE));
        
        if ($result === false) {
            return [
                'code' => 500,
                'message' => '版本信息写入失败'
            ];
        }
        
        return [
            'code' => 200,
            'message' => '更新信息提交成功',
            'data' => $versionInfo
        ];
    }
    
    // 上传头像
    public function uploadAvatar($username, $file) {
        // 检查用户是否存在
        $sql = "SELECT id FROM users WHERE username = ?";
        $result = $this->db->query($sql, [$username]);
        if ($result->num_rows == 0) {
            return [
                'code' => 404,
                'message' => '用户不存在'
            ];
        }
        
        // 验证文件类型
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            return [
                'code' => 400,
                'message' => '不支持的文件类型'
            ];
        }
        
        // 验证文件大小 (限制为5MB)
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return [
                'code' => 400,
                'message' => '文件大小超过限制'
            ];
        }
        
        // 确保头像目录存在
        $avatarDir = dirname(__FILE__) . '/avatars';
        if (!is_dir($avatarDir)) {
            mkdir($avatarDir, 0755, true);
        }
        
        // 生成唯一文件名
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $avatarFilename = $username . '_' . uniqid() . '.' . $ext;
        $avatarPath = $avatarDir . '/' . $avatarFilename;
        $relativePath = 'avatars/' . $avatarFilename;
        
        // 移动上传的文件
        if (move_uploaded_file($file['tmp_name'], $avatarPath)) {
            // 更新用户头像路径
            $sql = "UPDATE users SET avatar = ? WHERE username = ?";
            $this->db->execute($sql, [$relativePath, $username]);
            
            return [
                'code' => 200,
                'message' => '头像上传成功',
                'data' => [
                    'avatar_path' => $relativePath,
                    'avatar_url' => (rtrim($this->config['base_url'] ?? '', '/') ?: getBaseUrl()) . '/' . ltrim($relativePath, '/')
                ]
            ];
        } else {
            return [
                'code' => 500,
                'message' => '头像上传失败'
            ];
        }
    }
    
    // 获取头像
    public function getAvatar($username) {
        // 查询用户头像路径
        $sql = "SELECT avatar FROM users WHERE username = ?";
        $result = $this->db->query($sql, [$username]);
        
        if ($result->num_rows == 0) {
            return [
                'code' => 404,
                'message' => '用户不存在'
            ];
        }
        
        $user = $result->fetch_assoc();
        $avatarPath = $user['avatar'];
        
        if (empty($avatarPath)) {
            return [
                'code' => 404,
                'message' => '用户未设置头像'
            ];
        }
        
        // 检查头像文件是否存在
        $fullPath = dirname(__FILE__) . '/' . $avatarPath;
        if (!file_exists($fullPath)) {
            return [
                'code' => 404,
                'message' => '头像文件不存在'
            ];
        }
        
        return [
            'code' => 200,
            'message' => '获取头像成功',
            'data' => [
                'avatar_path' => $avatarPath,
                'avatar_url' => 'https://localhost:8000/user/' . $avatarPath
            ]
        ];
    }
}
