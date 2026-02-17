<?php
/**
 * Markdown编辑器 - 文件上传处理脚本
 * 支持图片和文件上传，包含重复文件检测
 */

// 关闭错误显示，返回JSON格式的错误信息
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 设置响应头为JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 基础配置
$config = [
    // 网站域名和路径
    'base_url' => 'api/uploads/',
    // 上传目录（相对于脚本位置）
    'upload_dir' => 'uploads',
    // 最大文件大小（1000MB）
    'max_file_size' => 1000 * 1024 * 1024,
    // 允许的文件类型
    'allowed_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
        'archive' => ['zip', 'rar', '7z'],
        'media' => ['mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv']
    ],
    // 重复文件检测：开启后相同文件只保存一份
    'detect_duplicates' => true,
    // 重复文件检测方式：md5（基于文件内容）或 filename（基于文件名）
    'duplicate_check_method' => 'md5'
];

// 设置PHP错误处理函数
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 设置异常处理函数
set_exception_handler(function($exception) {
    error_log("上传异常: " . $exception->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误: ' . $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
});

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '只支持POST请求'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 检查是否有文件上传
if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
    echo json_encode([
        'success' => false,
        'message' => '没有选择文件'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // 合并所有允许的文件类型
    $allowedTypes = [];
    foreach ($config['allowed_types'] as $typeGroup) {
        $allowedTypes = array_merge($allowedTypes, $typeGroup);
    }
    
    // 获取上传目录
    $uploadDir = isset($_POST['uploadDir']) ? trim($_POST['uploadDir']) : $config['upload_dir'];
    
    // 确保上传目录存在
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('无法创建上传目录');
        }
    }
    
    // 按日期创建子目录
    $dateDir = date('Y/m/d');
    $targetDir = $uploadDir . '/' . $dateDir;
    
    // 确保日期目录存在
    if (!file_exists($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            throw new Exception('无法创建日期目录');
        }
    }
    
    // 检查目录是否可写
    if (!is_writable($targetDir)) {
        throw new Exception('上传目录不可写，请检查权限');
    }
    
    // 创建或读取文件索引（用于重复文件检测）
    $indexFile = $uploadDir . '/file_index.json';
    $fileIndex = [];
    
    if ($config['detect_duplicates'] && file_exists($indexFile)) {
        $indexContent = file_get_contents($indexFile);
        if ($indexContent !== false) {
            $fileIndex = json_decode($indexContent, true);
            if ($fileIndex === null) {
                $fileIndex = [];
            }
        }
    }
    
    // 处理上传的文件
    $files = $_FILES['files'];
    $uploadedFiles = [];
    $errors = [];
    $urls = [];
    
    // 遍历所有上传的文件
    for ($i = 0; $i < count($files['name']); $i++) {
        $fileName = $files['name'][$i];
        $fileTmpName = $files['tmp_name'][$i];
        $fileSize = $files['size'][$i];
        $fileError = $files['error'][$i];
        
        // 检查上传错误
        if ($fileError !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器限制',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
                UPLOAD_ERR_EXTENSION => 'PHP扩展阻止了文件上传'
            ];
            $errors[] = "文件 '{$fileName}' 上传失败: " . ($errorMessages[$fileError] ?? "未知错误 (代码: {$fileError})");
            continue;
        }
        
        // 检查文件是否存在
        if (!file_exists($fileTmpName)) {
            $errors[] = "文件 '{$fileName}' 临时文件不存在";
            continue;
        }
        
        // 检查文件大小
        if ($fileSize > $config['max_file_size']) {
            $errors[] = "文件 '{$fileName}' 超过最大限制 (10MB)";
            continue;
        }
        
        // 检查文件大小是否为0
        if ($fileSize === 0) {
            $errors[] = "文件 '{$fileName}' 大小为0，可能为空文件";
            continue;
        }
        
        // 获取文件扩展名
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // 检查文件类型
        if (!in_array($fileExt, $allowedTypes)) {
            $errors[] = "文件 '{$fileName}' 的类型 (.{$fileExt}) 不被允许";
            continue;
        }
        
        // 检查是否为图片文件
        $isImage = in_array($fileExt, $config['allowed_types']['image']);
        
        // 如果是图片，验证图片文件
        if ($isImage && $fileExt !== 'svg') {
            $imageInfo = @getimagesize($fileTmpName);
            if ($imageInfo === false) {
                $errors[] = "文件 '{$fileName}' 不是有效的图片文件";
                continue;
            }
        }
        
        // 处理重复文件检测
        $existingFileUrl = null;
        $newFileName = '';
        
        if ($config['detect_duplicates']) {
            // 计算文件标识符
            if ($config['duplicate_check_method'] === 'md5') {
                // 基于文件内容计算MD5
                $fileIdentifier = md5_file($fileTmpName);
            } else {
                // 基于文件名（不推荐，但提供选项）
                $fileIdentifier = md5($fileName . '_' . $fileSize);
            }
            
            // 检查是否已存在相同文件
            if (isset($fileIndex[$fileIdentifier])) {
                $existingFile = $fileIndex[$fileIdentifier];
                
                // 验证文件仍然存在
                if (file_exists($existingFile['path'])) {
                    $existingFileUrl = $existingFile['url'];
                    $newFileName = basename($existingFile['path']);
                    
                    // 记录重复文件信息
                    $uploadedFiles[] = [
                        'originalName' => $fileName,
                        'savedName' => $newFileName,
                        'path' => $existingFile['path'],
                        'url' => $existingFile['url'],
                        'size' => $fileSize,
                        'type' => $isImage ? 'image' : 'file',
                        'extension' => $fileExt,
                        'duplicate' => true, // 标记为重复文件
                        'originalUploadTime' => $existingFile['upload_time'] ?? '未知'
                    ];
                    
                    // 构建完整URL
                    $fullUrl = $config['base_url'] . $existingFile['url'];
                    $urls[] = $fullUrl;
                    
                    $errors[] = "文件 '{$fileName}' 已存在，使用已有文件链接";
                    continue; // 跳过文件移动步骤
                }
            }
        }
        
        // 生成安全的文件名（如果文件是新的或重复检测未开启）
        if (empty($newFileName)) {
            $safeFileName = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
            $safeFileName = substr($safeFileName, 0, 100); // 限制文件名长度
            $newFileName = $safeFileName . '_' . time() . '_' . rand(1000, 9999) . '.' . $fileExt;
        }
        
        $targetFile = $targetDir . '/' . $newFileName;
        
        // 检查目标文件是否已存在（避免命名冲突）
        $counter = 1;
        while (file_exists($targetFile)) {
            $newFileName = $safeFileName . '_' . time() . '_' . rand(1000, 9999) . '_' . $counter . '.' . $fileExt;
            $targetFile = $targetDir . '/' . $newFileName;
            $counter++;
        }
        
        // 移动文件到目标位置
        if (move_uploaded_file($fileTmpName, $targetFile)) {
            // 验证移动后的文件
            if (!file_exists($targetFile) || filesize($targetFile) === 0) {
                $errors[] = "文件 '{$fileName}' 保存后验证失败";
                continue;
            }
            
            // 如果是图片，再次验证图片文件
            if ($isImage && $fileExt !== 'svg') {
                $imageInfo = @getimagesize($targetFile);
                if ($imageInfo === false) {
                    unlink($targetFile);
                    $errors[] = "文件 '{$fileName}' 不是有效的图片文件";
                    continue;
                }
            }
            
            // 构建相对路径和完整URL
            $relativePath = $dateDir . '/' . $newFileName;
            $fullUrl = $config['base_url'] . $relativePath;
            
            // 添加到索引（如果是新文件）
            if ($config['detect_duplicates'] && !$existingFileUrl) {
                if ($config['duplicate_check_method'] === 'md5') {
                    $fileIdentifier = md5_file($targetFile);
                } else {
                    $fileIdentifier = md5($fileName . '_' . $fileSize);
                }
                
                $fileIndex[$fileIdentifier] = [
                    'url' => $relativePath,
                    'path' => $targetFile,
                    'upload_time' => date('Y-m-d H:i:s'),
                    'file_size' => $fileSize,
                    'file_type' => $fileExt
                ];
                
                // 保存索引文件
                file_put_contents($indexFile, json_encode($fileIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            $urls[] = $fullUrl;
            $uploadedFiles[] = [
                'originalName' => $fileName,
                'savedName' => $newFileName,
                'path' => $targetFile,
                'url' => $relativePath,
                'full_url' => $fullUrl,
                'size' => $fileSize,
                'type' => $isImage ? 'image' : 'file',
                'extension' => $fileExt,
                'duplicate' => false
            ];
        } else {
            $errors[] = "文件 '{$fileName}' 保存失败，请检查目录权限";
        }
    }
    
    // 返回结果
    if (!empty($uploadedFiles)) {
        // 统计信息
        $newFiles = array_filter($uploadedFiles, function($file) {
            return !isset($file['duplicate']) || $file['duplicate'] === false;
        });
        $duplicateFiles = array_filter($uploadedFiles, function($file) {
            return isset($file['duplicate']) && $file['duplicate'] === true;
        });
        
        echo json_encode([
            'success' => true,
            'message' => sprintf(
                '上传完成！成功上传%d个文件，跳过%d个重复文件',
                count($newFiles),
                count($duplicateFiles)
            ),
            'files' => $uploadedFiles,
            'urls' => $urls,
            'full_urls' => $urls, // 兼容旧版本
            'count' => [
                'total' => count($uploadedFiles),
                'new' => count($newFiles),
                'duplicate' => count($duplicateFiles)
            ],
            'errors' => $errors,
            'config' => [
                'base_url' => $config['base_url'],
                'duplicate_detection' => $config['detect_duplicates']
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '所有文件上传失败: ' . implode('; ', $errors),
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    error_log("上传捕获异常: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>