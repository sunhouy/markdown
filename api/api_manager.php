<?php

class ApiManager {
    private $apiFile;
    private $encryptionKey;
    
    public function __construct() {
        // API信息存储文件路径
        $this->apiFile = dirname(__FILE__) . '/api_info.json';
        // 加密密钥（建议在生产环境中从环境变量获取）
        $this->encryptionKey = 'educoder_api_encryption_key_2025';
    }
    
    // 加密数据
    private function encrypt($data) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt(
            json_encode($data),
            'aes-256-cbc',
            $this->encryptionKey,
            0,
            $iv
        );
        return base64_encode($iv . $encrypted);
    }
    
    // 解密数据
    private function decrypt($encryptedData) {
        $encryptedData = base64_decode($encryptedData);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($encryptedData, 0, $ivLength);
        $encrypted = substr($encryptedData, $ivLength);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            $this->encryptionKey,
            0,
            $iv
        );
        
        return json_decode($decrypted, true);
    }
    
    // 获取所有API信息
    public function getAllApiInfo() {
        if (!file_exists($this->apiFile)) {
            return ['config' => [], 'preferred_product' => null];
        }
        
        $content = file_get_contents($this->apiFile);
        if (empty($content)) {
            return ['config' => [], 'preferred_product' => null];
        }
        
        $decrypted = $this->decrypt($content);
        
        // 兼容旧版本数据格式
        if (isset($decrypted['config'])) {
            // 处理旧版本的preferred_model键
            if (isset($decrypted['preferred_model']) && !isset($decrypted['preferred_product'])) {
                $decrypted['preferred_product'] = $decrypted['preferred_model'];
                unset($decrypted['preferred_model']);
            }
            return $decrypted;
        } else {
            // 旧版本只有配置数据，转换为新版本格式
            return ['config' => $decrypted, 'preferred_product' => null];
        }
    }
    
    // 保存API信息
    private function saveApiInfo($apiInfo) {
        $encryptedData = $this->encrypt($apiInfo);
        return file_put_contents($this->apiFile, $encryptedData);
    }
    
    // 添加商品信息
    public function addProductInfo($product, $baseUrl, $apiKey) {
        $apiInfo = $this->getAllApiInfo();
        $config = $apiInfo['config'];
        
        // 检查是否已存在相同的base_url和api_key
        $baseUrlKey = md5($baseUrl . $apiKey);
        
        if (isset($config[$baseUrlKey])) {
            // 如果已存在，添加商品到该配置
            if (!in_array($product, $config[$baseUrlKey]['products'])) {
                $config[$baseUrlKey]['products'][] = $product;
            }
        } else {
            // 如果不存在，创建新配置
            $config[$baseUrlKey] = [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
                'products' => [$product]
            ];
        }
        
        $apiInfo['config'] = $config;
        return $this->saveApiInfo($apiInfo);
    }
    
    // 删除API信息
    public function deleteApiInfo($baseUrl, $apiKey) {
        $apiInfo = $this->getAllApiInfo();
        $config = $apiInfo['config'];
        $baseUrlKey = md5($baseUrl . $apiKey);
        
        if (isset($config[$baseUrlKey])) {
            unset($config[$baseUrlKey]);
            $apiInfo['config'] = $config;
            return $this->saveApiInfo($apiInfo);
        }
        
        return false;
    }
    
    // 从base_url和api_key中移除特定商品
    public function removeProduct($baseUrl, $apiKey, $product) {
        $apiInfo = $this->getAllApiInfo();
        $config = $apiInfo['config'];
        $baseUrlKey = md5($baseUrl . $apiKey);
        
        if (isset($config[$baseUrlKey])) {
            $productIndex = array_search($product, $config[$baseUrlKey]['products']);
            if ($productIndex !== false) {
                array_splice($config[$baseUrlKey]['products'], $productIndex, 1);
                
                // 如果该配置下没有商品了，删除整个配置
                if (empty($config[$baseUrlKey]['products'])) {
                    unset($config[$baseUrlKey]);
                }
                
                $apiInfo['config'] = $config;
                
                // 如果移除的是首选商品，清空首选商品设置
                if ($apiInfo['preferred_product'] === $product) {
                    $apiInfo['preferred_product'] = null;
                }
                
                return $this->saveApiInfo($apiInfo);
            }
        }
        
        return false;
    }
    
    // 获取所有可用商品
    public function getAllProducts() {
        $apiInfo = $this->getAllApiInfo();
        $config = $apiInfo['config'];
        $products = [];
        
        foreach ($config as $configItem) {
            $products = array_merge($products, $configItem['products']);
        }
        
        // 去重并排序
        $products = array_unique($products);
        sort($products);
        
        return $products;
    }
    
    // 获取所有带序号的商品和配置信息
    public function getAllProductsWithDetails() {
        $apiInfo = $this->getAllApiInfo();
        $config = $apiInfo['config'];
        $productDetails = [];
        $index = 0;
        
        foreach ($config as $configItem) {
            foreach ($configItem['products'] as $product) {
                $productDetails[] = [
                    'index' => $index++,
                    'product' => $product,
                    'base_url' => $configItem['base_url'],
                    'api_key' => $configItem['api_key']
                ];
            }
        }
        
        return $productDetails;
    }
    
    // 通过序号删除商品
    public function deleteProductByIndex($index) {
        $productDetails = $this->getAllProductsWithDetails();
        
        if (isset($productDetails[$index])) {
            $productInfo = $productDetails[$index];
            return $this->removeProduct($productInfo['base_url'], $productInfo['api_key'], $productInfo['product']);
        }
        
        return false;
    }
    
    // 获取特定商品的配置
    public function getProductConfig($product) {
        $apiInfo = $this->getAllApiInfo();
        $config = $apiInfo['config'];
        
        foreach ($config as $configItem) {
            if (in_array($product, $configItem['products'])) {
                return [
                    'base_url' => $configItem['base_url'],
                    'api_key' => $configItem['api_key']
                ];
            }
        }
        
        return null;
    }
    
    // 设置首选商品
    public function setPreferredProduct($product) {
        $apiInfo = $this->getAllApiInfo();
        $allProducts = $this->getAllProducts();
        
        // 检查商品是否存在
        if (in_array($product, $allProducts)) {
            $apiInfo['preferred_product'] = $product;
            return $this->saveApiInfo($apiInfo);
        }
        
        return false;
    }
    
    // 获取首选商品
    public function getPreferredProduct() {
        $apiInfo = $this->getAllApiInfo();
        return $apiInfo['preferred_product'];
    }
    
    // 获取首选商品的配置
    public function getPreferredProductConfig() {
        $preferredProduct = $this->getPreferredProduct();
        if ($preferredProduct) {
            return $this->getProductConfig($preferredProduct);
        }
        return null;
    }
    
    // 搜索商品
    public function searchProducts($keyword) {
        $allProducts = $this->getAllProducts();
        $matchedProducts = [];
        
        foreach ($allProducts as $product) {
            if (stripos($product, $keyword) !== false) {
                $matchedProducts[] = $product;
            }
        }
        
        // 排序
        sort($matchedProducts);
        
        return $matchedProducts;
    }
}
