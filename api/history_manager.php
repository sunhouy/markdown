<?php
// history_manager.php - 历史版本管理类
class HistoryManager {
    private $db;
    private $config;

    public function __construct($db, $config) {
        $this->db = $db;
        $this->config = $config;
    }

    // 创建历史版本
    public function createHistory($username, $filename, $content) {
        try {
            // 获取用户ID
            $user = $this->getUserByUsername($username);
            if (!$user) {
                return [
                    'code' => 404,
                    'message' => '用户不存在'
                ];
            }

            $userId = $user['id'];

            // 计算内容哈希
            $contentHash = hash('sha256', $content);
            $contentLength = strlen($content);

            // 检查最近版本是否有相同内容
            $recentVersion = $this->getLatestVersion($userId, $filename);

            if ($recentVersion && $recentVersion['content_hash'] === $contentHash) {
                // 内容相同，不创建新版本
                return [
                    'code' => 304,
                    'message' => '内容无变化，不创建历史版本'
                ];
            }

            // 开始事务
            $conn = $this->db->getConnection();
            $conn->begin_transaction();

            try {
                // 获取下一个版本号
                $nextVersion = $recentVersion ? $recentVersion['version_id'] + 1 : 1;

                // 插入历史版本记录 - 使用 Database 的 execute 方法
                $result = $this->db->execute("
                    INSERT INTO file_history 
                    (user_id, filename, version_id, content_hash, content_length, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())",
                    [$userId, $filename, $nextVersion, $contentHash, $contentLength]
                );

                if (!$result['success']) {
                    throw new Exception('创建历史版本记录失败');
                }

                $historyId = $result['insert_id'];

                if (!$historyId) {
                    throw new Exception('创建历史版本记录失败，无法获取插入ID');
                }

                // 决定存储方式：如果是第一个版本或版本数较少，存储完整内容
                if ($nextVersion <= 5) {
                    // 存储完整内容
                    $this->saveFullContent($historyId, $content);
                } else {
                    // 尝试存储差异
                    $prevVersion = $this->getVersionContent($userId, $filename, $nextVersion - 1);

                    if ($prevVersion && isset($prevVersion['content'])) {
                        // 计算差异并存储
                        $diff = $this->computeDiff($prevVersion['content'], $content);
                        if ($this->shouldSaveDiff($prevVersion['content'], $content, $diff)) {
                            $this->saveDiffContent($historyId, $diff, $nextVersion - 1);
                        } else {
                            // 如果差异过大，存储完整内容
                            $this->saveFullContent($historyId, $content);
                        }
                    } else {
                        $this->saveFullContent($historyId, $content);
                    }
                }

                // 清理旧版本，只保留最多1000个版本
                $this->cleanupOldVersions($userId, $filename, 1000);

                $conn->commit();

                return [
                    'code' => 200,
                    'message' => '历史版本创建成功',
                    'data' => [
                        'version_id' => $nextVersion,
                        'history_id' => $historyId
                    ]
                ];

            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            error_log("创建历史版本失败: " . $e->getMessage());
            return [
                'code' => 500,
                'message' => '创建历史版本失败: ' . $e->getMessage()
            ];
        }
    }

    // 获取文件历史版本列表
    public function getHistoryList($username, $filename) {
        try {
            $user = $this->getUserByUsername($username);
            if (!$user) {
                return [
                    'code' => 404,
                    'message' => '用户不存在'
                ];
            }

            $userId = $user['id'];

            // 使用 Database 的 query 方法
            $result = $this->db->query("
                SELECT 
                    h.id,
                    h.version_id,
                    h.content_hash,
                    h.content_length,
                    h.created_at,
                    c.content_type,
                    c.base_version_id
                FROM file_history h
                LEFT JOIN file_content c ON h.id = c.history_id
                WHERE h.user_id = ? AND h.filename = ?
                ORDER BY h.version_id DESC
                LIMIT 100",
                [$userId, $filename]
            );

            $history = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    // 获取版本内容（如果是差异存储，需要重建）
                    $content = $this->getVersionContentById($row['id']);

                    // 获取最新版本号
                    $latestVersion = $this->getLatestVersion($userId, $filename);
                    $isCurrent = $latestVersion && $row['version_id'] == $latestVersion['version_id'];

                    $history[] = [
                        'version_id' => $row['version_id'],
                        'history_id' => $row['id'],
                        'content' => $content,
                        'content_hash' => $row['content_hash'],
                        'content_length' => $row['content_length'],
                        'content_type' => $row['content_type'],
                        'timestamp' => $row['created_at'],
                        'is_current' => $isCurrent
                    ];
                }
            }

            return [
                'code' => 200,
                'message' => '获取历史版本成功',
                'data' => [
                    'history' => $history
                ]
            ];

        } catch (Exception $e) {
            error_log("获取历史版本列表失败: " . $e->getMessage());
            return [
                'code' => 500,
                'message' => '获取历史版本列表失败: ' . $e->getMessage()
            ];
        }
    }

    // 恢复历史版本
    public function restoreHistory($username, $filename, $versionId) {
        try {
            $user = $this->getUserByUsername($username);
            if (!$user) {
                return [
                    'code' => 404,
                    'message' => '用户不存在'
                ];
            }

            $userId = $user['id'];

            // 获取指定版本的内容 - 使用 Database 的 query 方法
            $result = $this->db->query("
                SELECT h.id, h.version_id, c.content_data, c.content_type, c.base_version_id
                FROM file_history h
                LEFT JOIN file_content c ON h.id = c.history_id
                WHERE h.user_id = ? AND h.filename = ? AND h.version_id = ?",
                [$userId, $filename, $versionId]
            );

            if (!$result) {
                return [
                    'code' => 404,
                    'message' => '历史版本不存在'
                ];
            }

            $row = $result->fetch_assoc();

            if (!$row) {
                return [
                    'code' => 404,
                    'message' => '历史版本不存在'
                ];
            }

            // 重建内容
            $content = $this->reconstructContent($row);

            if ($content === false) {
                return [
                    'code' => 500,
                    'message' => '无法重建版本内容'
                ];
            }

            return [
                'code' => 200,
                'message' => '获取版本内容成功',
                'data' => [
                    'content' => $content,
                    'version_id' => $row['version_id']
                ]
            ];

        } catch (Exception $e) {
            error_log("恢复历史版本失败: " . $e->getMessage());
            return [
                'code' => 500,
                'message' => '恢复历史版本失败: ' . $e->getMessage()
            ];
        }
    }

    // 删除历史版本
    public function deleteHistory($username, $filename, $versionId = 0) {
        try {
            // 获取用户ID
            $user = $this->getUserByUsername($username);
            if (!$user) {
                return [
                    'code' => 404,
                    'message' => '用户不存在'
                ];
            }

            $userId = $user['id'];

            // 开始事务
            $conn = $this->db->getConnection();
            $conn->begin_transaction();

            try {
                if ($versionId > 0) {
                    // 删除指定版本
                    return $this->deleteSpecificVersion($userId, $filename, $versionId);
                } else {
                    // 删除该文件的所有历史版本
                    return $this->deleteAllVersions($userId, $filename);
                }

            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            error_log("删除历史版本失败: " . $e->getMessage());
            return [
                'code' => 500,
                'message' => '删除历史版本失败: ' . $e->getMessage()
            ];
        }
    }

    // 删除指定版本
    private function deleteSpecificVersion($userId, $filename, $versionId) {
        // 获取历史版本信息
        $result = $this->db->query("
            SELECT id FROM file_history 
            WHERE user_id = ? AND filename = ? AND version_id = ?",
            [$userId, $filename, $versionId]
        );

        if (!$result) {
            return [
                'code' => 404,
                'message' => '指定的历史版本不存在'
            ];
        }

        $history = $result->fetch_assoc();

        if (!$history) {
            return [
                'code' => 404,
                'message' => '指定的历史版本不存在'
            ];
        }

        $historyId = $history['id'];

        // 获取最新版本号
        $latestVersion = $this->getLatestVersion($userId, $filename);
        $isLatestVersion = $latestVersion && $latestVersion['version_id'] == $versionId;

        // 1. 先删除对应的内容记录
        $this->db->execute("DELETE FROM file_content WHERE history_id = ?", [$historyId]);

        // 2. 删除历史版本记录
        $this->db->execute("DELETE FROM file_history WHERE id = ?", [$historyId]);

        // 提交事务
        $conn = $this->db->getConnection();
        $conn->commit();

        return [
            'code' => 200,
            'message' => '历史版本删除成功',
            'data' => [
                'deleted_version_id' => $versionId,
                'filename' => $filename
            ]
        ];
    }

    // 删除所有版本
    private function deleteAllVersions($userId, $filename) {
        // 获取所有历史版本ID
        $result = $this->db->query("
            SELECT id FROM file_history 
            WHERE user_id = ? AND filename = ?",
            [$userId, $filename]
        );

        $deletedCount = 0;

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $historyId = $row['id'];

                // 删除内容记录
                $this->db->execute("DELETE FROM file_content WHERE history_id = ?", [$historyId]);

                // 删除历史记录
                $this->db->execute("DELETE FROM file_history WHERE id = ?", [$historyId]);

                $deletedCount++;
            }
        }

        // 提交事务
        $conn = $this->db->getConnection();
        $conn->commit();

        return [
            'code' => 200,
            'message' => '所有历史版本删除成功',
            'data' => [
                'deleted_count' => $deletedCount,
                'filename' => $filename
            ]
        ];
    }

    // 获取历史版本数量
    public function getHistoryCount($username, $filename) {
        try {
            $user = $this->getUserByUsername($username);
            if (!$user) {
                return 0;
            }

            $userId = $user['id'];

            $result = $this->db->query("
                SELECT COUNT(*) as count FROM file_history 
                WHERE user_id = ? AND filename = ?",
                [$userId, $filename]
            );

            if (!$result) {
                return 0;
            }

            $row = $result->fetch_assoc();
            return $row['count'] ?? 0;

        } catch (Exception $e) {
            error_log("获取历史版本数量失败: " . $e->getMessage());
            return 0;
        }
    }

    // 获取版本内容（通过版本ID）
    private function getVersionContentById($historyId) {
        $result = $this->db->query("
            SELECT content_data, content_type, base_version_id
            FROM file_content
            WHERE history_id = ?",
            [$historyId]
        );

        if (!$result) {
            return null;
        }

        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        return $this->reconstructContent($row);
    }

    // 重建内容（如果是差异存储）
    private function reconstructContent($row) {
        if ($row['content_type'] === 'full') {
            return $row['content_data'];
        }

        if ($row['content_type'] === 'diff') {
            // 获取基准版本内容
            $baseContent = $this->getVersionContentById($row['base_version_id']);
            if (!$baseContent) {
                return false;
            }

            // 应用差异
            $diff = json_decode($row['content_data'], true);
            if (!$diff) {
                return false;
            }

            return $this->applyDiff($baseContent, $diff);
        }

        return false;
    }

    // 计算两个内容之间的差异
    private function computeDiff($oldContent, $newContent) {
        // 使用简单的行级差异算法
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);

        $ops = [];

        // 简化的LCS算法
        $m = count($oldLines);
        $n = count($newLines);

        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if (trim($oldLines[$i - 1]) === trim($newLines[$j - 1])) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        // 回溯构建差异
        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && trim($oldLines[$i - 1]) === trim($newLines[$j - 1])) {
                $ops[] = ['=', $oldLines[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i == 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $ops[] = ['+', $newLines[$j - 1]];
                $j--;
            } else {
                $ops[] = ['-', $oldLines[$i - 1]];
                $i--;
            }
        }

        return array_reverse($ops);
    }

    // 应用差异到内容
    private function applyDiff($baseContent, $diff) {
        $baseLines = explode("\n", $baseContent);
        $result = [];
        $i = 0;

        foreach ($diff as $op) {
            $type = $op[0];
            $line = $op[1];

            if ($type === '=') {
                if ($i < count($baseLines) && trim($baseLines[$i]) === trim($line)) {
                    $result[] = $baseLines[$i];
                    $i++;
                }
            } elseif ($type === '+') {
                $result[] = $line;
            } elseif ($type === '-') {
                $i++;
            }
        }

        // 添加剩余的行
        while ($i < count($baseLines)) {
            $result[] = $baseLines[$i];
            $i++;
        }

        return implode("\n", $result);
    }

    // 判断是否应该存储差异
    private function shouldSaveDiff($oldContent, $newContent, $diff) {
        $oldSize = strlen($oldContent);
        $newSize = strlen($newContent);

        // 如果差异的大小超过新内容的50%，存储完整内容可能更优
        $diffSize = strlen(json_encode($diff));
        return ($diffSize < $newSize * 0.5);
    }

    // 存储完整内容
    private function saveFullContent($historyId, $content) {
        $result = $this->db->execute("
            INSERT INTO file_content (history_id, content_type, content_data, base_version_id)
            VALUES (?, 'full', ?, NULL)",
            [$historyId, $content]
        );
        return $result['success'];
    }

    // 存储差异内容
    private function saveDiffContent($historyId, $diff, $baseVersionId) {
        $diffJson = json_encode($diff);
        $result = $this->db->execute("
            INSERT INTO file_content (history_id, content_type, content_data, base_version_id)
            VALUES (?, 'diff', ?, ?)",
            [$historyId, $diffJson, $baseVersionId]
        );
        return $result['success'];
    }

    // 获取最新版本
    private function getLatestVersion($userId, $filename) {
        $result = $this->db->query("
            SELECT version_id, content_hash, content_length
            FROM file_history
            WHERE user_id = ? AND filename = ?
            ORDER BY version_id DESC
            LIMIT 1",
            [$userId, $filename]
        );

        if (!$result) {
            return null;
        }

        return $result->fetch_assoc();
    }

    // 获取指定版本的内容
    private function getVersionContent($userId, $filename, $versionId) {
        $result = $this->db->query("
            SELECT h.id, c.content_data, c.content_type, c.base_version_id
            FROM file_history h
            LEFT JOIN file_content c ON h.id = c.history_id
            WHERE h.user_id = ? AND h.filename = ? AND h.version_id = ?",
            [$userId, $filename, $versionId]
        );

        if (!$result) {
            return null;
        }

        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        return [
            'id' => $row['id'],
            'content' => $this->reconstructContent($row)
        ];
    }

    // 清理旧版本
    private function cleanupOldVersions($userId, $filename, $keepCount) {
        // 找出需要删除的版本ID
        $result = $this->db->query("
            SELECT id FROM file_history
            WHERE user_id = ? AND filename = ?
            ORDER BY version_id DESC
            LIMIT 18446744073709551615 OFFSET ?",
            [$userId, $filename, $keepCount]
        );

        if (!$result) {
            return;
        }

        $idsToDelete = [];
        while ($row = $result->fetch_assoc()) {
            $idsToDelete[] = (int)$row['id'];
        }

        if (empty($idsToDelete)) {
            return;
        }

        // 构建参数占位符
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));

        // 删除内容记录
        $this->db->execute("
            DELETE FROM file_content
            WHERE history_id IN ($placeholders)",
            $idsToDelete
        );

        // 删除历史记录
        $this->db->execute("
            DELETE FROM file_history
            WHERE id IN ($placeholders)",
            $idsToDelete
        );
    }

    // 删除文件的所有历史版本
    public function deleteFileHistory($username, $filename) {
        try {
            $user = $this->getUserByUsername($username);
            if (!$user) {
                return false;
            }

            $userId = $user['id'];

            // 删除内容记录（通过子查询）
            $result = $this->db->execute("
                DELETE c FROM file_content c
                JOIN file_history h ON c.history_id = h.id
                WHERE h.user_id = ? AND h.filename = ?",
                [$userId, $filename]
            );

            // 删除历史记录
            $result2 = $this->db->execute("
                DELETE FROM file_history
                WHERE user_id = ? AND filename = ?",
                [$userId, $filename]
            );

            return $result['success'] && $result2['success'];

        } catch (Exception $e) {
            error_log("删除文件历史版本失败: " . $e->getMessage());
            return false;
        }
    }

    // 获取用户ID - 修复使用 Database 的 query 方法
    private function getUserByUsername($username) {
        $result = $this->db->query("SELECT id FROM users WHERE username = ?", [$username]);

        if (!$result) {
            return null;
        }

        return $result->fetch_assoc();
    }
}
?>