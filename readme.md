# markdown
基于Vditor的移动端轻量化在线markdown编辑器，可进行多文件管理。支持可视化插入格式、公式、图表，上传文件和图片，降低学习和操作门槛；支持多端文件同步，历史版本，文件分享，日间、夜间模式切换，导出文档，云打印功能

项目采用JavaScript+PHP+Python架构，登录注册、文件上传、分享、历史版本使用PHP80，云打印服务端和客户端使用Python实现。
### 使用
网页提供日间模式和夜间模式两种配色
![日间模式](/api/demo/main_light.png)
![夜间模式](/api/demo/main_dark.png)

### 部署
- 前端代码直接运行，无需额外配置。前端代码均在js目录下，为方便，部分位置使用了硬链接，自行替换即可。
- 后端运行方式：PHP后端代码在api目录下，请自行在该目录下添加config.php，请提前建立数据库，数据库格式在db.sql，可直接导入。格式如下：
```php
<?php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'username' => '数据库用户名',
        'password' => '数据库密码',
        'database' => '数据库名'
    ],
    'admin' => [
        'username' => '管理员用户名',
        'password' => '管理员密码'
    ],
    'ai' => [  // 用于云打印智能排版
        'api_key' => 'sk-xxxxxxxxxx',
        'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',  // 供参考
        'model' => 'qwen3-max'  // 供参考
    ]
];
```
- print文件夹下放置Python后端和打印客户端脚本。print_server.py需要部署在服务器上。通过`python3 start_print_service.py`命令启动服务器脚本。请自行部署ssl证书，并通过反向代理映射到8770端口。
- 运行打印客户端前需要安装`wkhtmltox`用于html转换为pdf，否则云打印客户端可能无法正确运行。