<?php
/**
 * 文件管理类
 * 负责处理用户的Markdown文件操作
 */
class FileManager {
    private $db;
    private $config;

    public function __construct($db, $config) {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * 获取用户文件列表
     */
    public function getUserFiles($username) {
        try {
            $conn = $this->db->getConnection();

            // 查询用户的所有文件
            $sql = "SELECT filename, content, last_modified FROM user_files WHERE username = ? ORDER BY last_modified DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            $files = [];
            while ($row = $result->fetch_assoc()) {
                $files[] = [
                    'name' => $row['filename'],
                    'content' => $row['content'],
                    'last_modified' => $row['last_modified']
                ];
            }

            $stmt->close();

            return [
                'code' => 200,
                'message' => '获取文件列表成功',
                'data' => [
                    'username' => $username,
                    'files' => $files,
                    'count' => count($files)
                ]
            ];

        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/error.log', "[" . date('Y-m-d H:i:s') . "] 获取用户文件列表失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'code' => 500,
                'message' => '获取文件列表失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 获取单个文件内容
     */
    public function getFileContent($username, $filename) {
        try {
            $conn = $this->db->getConnection();

            // 查询文件内容
            $sql = "SELECT filename, content, last_modified FROM user_files WHERE username = ? AND filename = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $username, $filename);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $stmt->close();
                return [
                    'code' => 404,
                    'message' => '文件不存在'
                ];
            }

            $row = $result->fetch_assoc();
            $stmt->close();

            return [
                'code' => 200,
                'message' => '获取文件内容成功',
                'data' => [
                    'filename' => $row['filename'],
                    'content' => $row['content'],
                    'last_modified' => $row['last_modified']
                ]
            ];

        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/error.log', "[" . date('Y-m-d H:i:s') . "] 获取文件内容失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'code' => 500,
                'message' => '获取文件内容失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 保存文件到服务器
     */
    public function saveFile($username, $filename, $content = '') {
        try {
            $conn = $this->db->getConnection();

            // 检查文件是否已存在
            $checkSql = "SELECT id FROM user_files WHERE username = ? AND filename = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ss", $username, $filename);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $checkStmt->close();

            if ($checkResult->num_rows > 0) {
                // 文件存在，更新内容
                $sql = "UPDATE user_files SET content = ?, last_modified = NOW() WHERE username = ? AND filename = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $content, $username, $filename);
                $message = '文件更新成功';
            } else {
                // 文件不存在，插入新记录
                $sql = "INSERT INTO user_files (username, filename, content, last_modified) VALUES (?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $username, $filename, $content);
                $message = '文件保存成功';
            }

            if ($stmt->execute()) {
                $stmt->close();
                return [
                    'code' => 200,
                    'message' => $message,
                    'data' => [
                        'username' => $username,
                        'filename' => $filename,
                        'content_length' => strlen($content)
                    ]
                ];
            } else {
                $error = $stmt->error;
                $stmt->close();
                throw new Exception("数据库操作失败: " . $error);
            }

        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/error.log', "[" . date('Y-m-d H:i:s') . "] 保存文件失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'code' => 500,
                'message' => '保存文件失败: ' . $e->getMessage()
            ];
        }
    }

    // 保存文件时自动创建历史版本
    public function saveFileWithHistory($username, $filename, $content, $createHistory = false) {
        try {
            // 先保存文件
            $result = $this->saveFile($username, $filename, $content);

            if ($result['code'] !== 200) {
                return $result;
            }

            // 如果需要创建历史版本
            if ($createHistory) {
                $historyResult = $this->historyManager->createHistory($username, $filename, $content);

                // 即使历史版本创建失败，文件保存仍算成功
                if ($historyResult['code'] !== 200 && $historyResult['code'] !== 304) {
                    file_put_contents(__DIR__ . '/error.log', "[" . date('Y-m-d H:i:s') . "] 历史版本创建失败，但文件已保存: " . json_encode($historyResult) . "\n", FILE_APPEND);
                }
            }

            return $result;

        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/error.log', "[" . date('Y-m-d H:i:s') . "] 保存文件失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'code' => 500,
                'message' => '保存文件失败: ' . $e->getMessage()
            ];
        }
    }

    // 删除文件
    public function deleteFile($username, $filename) {
        try {
            error_log("开始删除文件: 用户={$username}, 文件名={$filename}");

            $conn = $this->db->getConnection();

            // 开始事务
            if (!$conn->begin_transaction()) {
                error_log("开始事务失败: " . $conn->error);
                return [
                    'code' => 500,
                    'message' => '数据库事务初始化失败'
                ];
            }

            try {
                // 1. 检查文件是否存在（移除 FOR UPDATE）
                $checkSql = "SELECT id FROM user_files WHERE username = ? AND filename = ?";
                $checkStmt = $conn->prepare($checkSql);

                if ($checkStmt === false) {
                    error_log("准备SQL语句失败: " . $conn->error);
                    $conn->rollback();
                    return [
                        'code' => 500,
                        'message' => '数据库操作失败: ' . $conn->error
                    ];
                }

                $checkStmt->bind_param("ss", $username, $filename);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();

                if ($checkResult->num_rows === 0) {
                    $checkStmt->close();
                    $conn->rollback();
                    error_log("文件不存在: {$username}/{$filename}");
                    return [
                        'code' => 404,
                        'message' => '文件不存在'
                    ];
                }

                $row = $checkResult->fetch_assoc();
                $fileId = $row['id'];
                $checkStmt->close();

                // 2. 从主表删除记录
                $deleteSql = "DELETE FROM user_files WHERE username = ? AND filename = ?";
                $deleteStmt = $conn->prepare($deleteSql);

                if ($deleteStmt === false) {
                    error_log("准备删除语句失败: " . $conn->error);
                    $conn->rollback();
                    return [
                        'code' => 500,
                        'message' => '数据库操作失败: ' . $conn->error
                    ];
                }

                $deleteStmt->bind_param("ss", $username, $filename);

                if (!$deleteStmt->execute()) {
                    $error = $deleteStmt->error;
                    $deleteStmt->close();
                    $conn->rollback();
                    error_log("数据库删除失败: " . $error);
                    return [
                        'code' => 500,
                        'message' => '数据库操作失败: ' . $error
                    ];
                }

                $affectedRows = $deleteStmt->affected_rows;
                $deleteStmt->close();

                // 3. 删除对应的历史版本记录
                try {
                    $historyTableExists = false;
                    $checkTableSql = "SHOW TABLES LIKE 'file_history'";
                    $tableResult = $conn->query($checkTableSql);
                    if ($tableResult && $tableResult->num_rows > 0) {
                        $historyTableExists = true;
                    }
                    if ($tableResult) {
                        $tableResult->free();
                    }

                    if ($historyTableExists) {
                        $deleteHistorySql = "DELETE FROM file_history WHERE username = ? AND filename = ?";
                        $historyStmt = $conn->prepare($deleteHistorySql);
                        if ($historyStmt) {
                            $historyStmt->bind_param("ss", $username, $filename);
                            $historyStmt->execute();
                            $historyDeleted = $historyStmt->affected_rows;
                            $historyStmt->close();
                            error_log("删除历史版本记录: {$historyDeleted} 条");
                        }
                    }
                } catch (Exception $e) {
                    error_log("删除历史版本记录时出错: " . $e->getMessage());
                }

                // 4. 提交事务
                if (!$conn->commit()) {
                    error_log("提交事务失败: " . $conn->error);
                    $conn->rollback();
                    return [
                        'code' => 500,
                        'message' => '数据库操作失败: ' . $conn->error
                    ];
                }

                error_log("文件删除成功: {$username}/{$filename}, 影响行数: {$affectedRows}");

                return [
                    'code' => 200,
                    'message' => '文件删除成功',
                    'data' => [
                        'filename' => $filename,
                        'affected_rows' => $affectedRows
                    ]
                ];

            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            error_log("删除文件失败: " . $e->getMessage());
            error_log("堆栈跟踪: " . $e->getTraceAsString());
            return [
                'code' => 500,
                'message' => '删除文件失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 清理文件缓存
     */
    private function clearFileCache($username, $filename) {
        // 清理可能的缓存
        $cacheKey = "file_{$username}_{$filename}";
        // 如果有使用缓存系统（如Redis、Memcached），这里清理缓存
        // 例如：$this->cache->delete($cacheKey);

        // 也可以清理文件列表缓存
        $listCacheKey = "filelist_{$username}";
        // $this->cache->delete($listCacheKey);

        error_log("已清理文件缓存: {$cacheKey}");
    }

    /**
     * 同步文件（批量上传）
     */
    public function syncFiles($username, $files) {
        try {
            $conn = $this->db->getConnection();
            $successCount = 0;
            $errorFiles = [];

            // 开始事务
            $conn->begin_transaction();

            foreach ($files as $file) {
                $filename = $file['name'] ?? '';
                $content = $file['content'] ?? '';

                if (empty($filename)) {
                    $errorFiles[] = ['filename' => 'unknown', 'error' => '缺少文件名'];
                    continue;
                }

                try {
                    // 检查文件是否已存在
                    $checkSql = "SELECT id FROM user_files WHERE username = ? AND filename = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->bind_param("ss", $username, $filename);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $checkStmt->close();

                    if ($checkResult->num_rows > 0) {
                        // 更新文件
                        $sql = "UPDATE user_files SET content = ?, last_modified = NOW() WHERE username = ? AND filename = ?";
                    } else {
                        // 插入新文件
                        $sql = "INSERT INTO user_files (username, filename, content, last_modified) VALUES (?, ?, ?, NOW())";
                    }

                    $stmt = $conn->prepare($sql);
                    if ($checkResult->num_rows > 0) {
                        $stmt->bind_param("sss", $content, $username, $filename);
                    } else {
                        $stmt->bind_param("sss", $username, $filename, $content);
                    }

                    if ($stmt->execute()) {
                        $successCount++;
                    } else {
                        $errorFiles[] = ['filename' => $filename, 'error' => $stmt->error];
                    }

                    $stmt->close();

                } catch (Exception $e) {
                    $errorFiles[] = ['filename' => $filename, 'error' => $e->getMessage()];
                }
            }

            // 提交事务
            $conn->commit();

            return [
                'code' => 200,
                'message' => '文件同步完成',
                'data' => [
                    'username' => $username,
                    'total' => count($files),
                    'success' => $successCount,
                    'failed' => count($errorFiles),
                    'errors' => $errorFiles
                ]
            ];

        } catch (Exception $e) {
            // 回滚事务
            if (isset($conn)) {
                $conn->rollback();
            }

            file_put_contents(__DIR__ . '/error.log', "[" . date('Y-m-d H:i:s') . "] 同步文件失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'code' => 500,
                'message' => '同步文件失败: ' . $e->getMessage()
            ];
        }
    }
}