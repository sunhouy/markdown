<?php

class Database {
    private $host;
    private $port;
    private $username;
    private $password;
    private $database;
    private $conn;

    public function __construct($config) {
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->database = $config['database'];
        $this->connect();
    }

    private function connect() {
        try {
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->database, $this->port);
            if ($this->conn->connect_error) {
                throw new Exception("连接失败: " . $this->conn->connect_error);
            }
            $this->conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            die(json_encode([
                'code' => 500,
                'message' => '数据库连接失败',
                'error' => $e->getMessage()
            ]));
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function query($sql, $params = []) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL准备失败: " . $this->conn->error);
        }

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("SQL执行失败: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    public function execute($sql, $params = []) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL准备失败: " . $this->conn->error);
        }

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        $result = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $insert_id = $stmt->insert_id;
        $stmt->close();

        return [
            'success' => $result,
            'affected_rows' => $affected_rows,
            'insert_id' => $insert_id
        ];
    }

    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
