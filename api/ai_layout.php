<?php
/**
 * AI智能排版接口
 */

header('Content-Type: application/json; charset=utf-8');

// 允许跨域请求（根据需要调整）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['code' => 400, 'message' => '仅支持POST请求']);
    exit;
}

// 读取配置
$config = require __DIR__ . '/config.php';
$apiKey = $config['ai']['api_key'] ?? '';
$baseUrl = $config['ai']['base_url'] ?? 'https://dashscope.aliyuncs.com/compatible-mode/v1';
$model = $config['ai']['model'] ?? 'qwen3-max';

if (empty($apiKey)) {
    echo json_encode(['code' => 500, 'message' => 'AI API密钥未配置']);
    exit;
}

// 获取请求数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['code' => 400, 'message' => '无效的JSON数据']);
    exit;
}

$markdown = $data['markdown'] ?? '';
$description = $data['description'] ?? '';
$settings = $data['settings'] ?? [];
$keepFormatSettings = $data['keepFormatSettings'] ?? false;
$referenceHtml = $data['referenceHtml'] ?? '';

if (empty($markdown)) {
    echo json_encode(['code' => 400, 'message' => '文档内容不能为空']);
    exit;
}

if (empty($description)) {
    echo json_encode(['code' => 400, 'message' => '排版需求不能为空']);
    exit;
}

// 构建系统提示
$systemPrompt = '你是一个专业的文档排版助手。请根据用户的Markdown文档内容和排版需求，生成美观、专业的HTML文档。';
$systemPrompt .= "\n\n排版需求：\n" . $description . "\n";
$systemPrompt .= "\n要求：\n1. 生成完整的HTML文档，包含<!DOCTYPE html>, <html>, <head>, <body>等标签\n2. 在<head>中添加必要的CSS样式\n3. 保留Markdown中的所有公式和图表的原始格式（如$$...$$, \\[...\\], ```mermaid等）\n4. 确保文档可以正常打印\n5. 不要修改Markdown内容本身，只进行排版\n6. 代码块使用<code>或<pre>标签包裹，公式保持原样\n7. 指定中文编码，防止乱码问题";

if ($keepFormatSettings && !empty($settings)) {
    $systemPrompt .= "\n\n用户已选择的格式设置：\n";
    $systemPrompt .= "- 标题字号: " . ($settings['titleFontSize'] ?? '24') . "pt\n";
    $systemPrompt .= "- 正文字号: " . ($settings['bodyFontSize'] ?? '12') . "pt\n";
    $systemPrompt .= "- 页边距: " . ($settings['pageMargin'] ?? '15') . "mm\n";
    $systemPrompt .= "- 行距: " . ($settings['lineHeight'] ?? '1.2') . "倍\n";
    $systemPrompt .= "- 段落间距: " . ($settings['paragraphSpacing'] ?? '0.5') . "倍\n";
    $systemPrompt .= "- 标题间距: " . ($settings['titleSpacing'] ?? '0.8') . "倍\n";
    $systemPrompt .= "- 内容对齐: " . ($settings['alignment'] ?? 'left') . "\n";
    $systemPrompt .= "- 标题对齐: " . ($settings['titleAlignment'] ?? 'center') . "\n";
    $systemPrompt .= "- 段落首行缩进: " . (empty($settings['indentParagraph']) ? '否' : '是') . "\n";
    $systemPrompt .= "- 请尽量遵循这些设置进行排版";
}

if (!empty($referenceHtml)) {
    $systemPrompt .= "\n\n参考HTML文档（根据用户选择的格式生成，供您参考）：\n```html\n" . $referenceHtml . "\n```\n";
}

// 构建用户消息
$userMessage = 'Markdown文档内容：' . "\n";
$userMessage .= '```markdown' . "\n" . $markdown . "\n```";

// 构建请求
$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => $userMessage]
];

$requestBody = [
    'model' => $model,
    'messages' => $messages,
    'temperature' => 0.7,
    'max_tokens' => 4096
];

// 调用DeepSeek API
$ch = curl_init($baseUrl . '/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['code' => 500, 'message' => 'CURL错误: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['code' => 500, 'message' => 'API请求失败: HTTP ' . $httpCode, 'details' => $response]);
    exit;
}

$result = json_decode($response, true);

if (!$result || !isset($result['choices']) || empty($result['choices'])) {
    echo json_encode(['code' => 500, 'message' => 'API返回格式错误', 'details' => $response]);
    exit;
}

$htmlContent = $result['choices'][0]['message']['content'] ?? '';

if (empty($htmlContent)) {
    echo json_encode(['code' => 500, 'message' => 'AI返回内容为空']);
    exit;
}

// 尝试提取HTML
$finalHtml = $htmlContent;
if (preg_match('/<!DOCTYPE html[\s\S]*<\/html>/i', $htmlContent, $matches)) {
    $finalHtml = $matches[0];
} elseif (preg_match('/<body[\s\S]*<\/body>/i', $htmlContent, $matches)) {
    $finalHtml = $matches[0];
}

echo json_encode([
    'code' => 200,
    'data' => [
        'html' => $finalHtml,
        'raw' => $htmlContent
    ]
]);
