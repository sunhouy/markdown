#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
云打印服务器
- 接收前端的打印请求
- 转发到本地打印客户端
- 提供WebSocket连接
- 生成8位数字密码用于认证
- 提供API接口验证客户端连接
"""

import asyncio
import websockets
import json
import os
import sys
import argparse
import random
from datetime import datetime, timedelta
import ssl

class PrintServer:
    def __init__(self, host='0.0.0.0', port=8770):
        self.host = host
        self.port = port
        self.local_client_url = "ws://localhost:8771"  # 默认新客户端端口
        self.connections = set()
        self.clients = {}  # 存储已绑定的打印客户端
        self.auth_codes = {}  # 存储生成的临时密码
        self.client_bindings = {}  # 存储密码与客户端的永久绑定关系
        print(f"初始化打印服务器: {host}:{port}")
    
    def generate_auth_code(self):
        """生成8位数字认证密码"""
        code = ''.join(random.choices('0123456789', k=8))
        # 设置10分钟过期时间
        expiry = datetime.now() + timedelta(minutes=10)
        self.auth_codes[code] = expiry
        return code
    
    def validate_auth_code(self, code):
        """验证认证密码"""
        # 检查临时密码
        if code in self.auth_codes:
            if datetime.now() < self.auth_codes[code]:
                return True
            else:
                # 移除过期密码
                del self.auth_codes[code]
        # 检查永久绑定的密码
        if code in self.client_bindings:
            return True
        return False
    
    async def notify_frontend_clients(self):
        """通知所有前端客户端打印客户端状态变化"""
        client_connected = len(self.clients) > 0
        status_data = {
            'type': 'client_status',
            'connected': client_connected,
            'clients': list(self.clients.keys()),
            'message': '打印客户端已连接' if client_connected else '打印客户端已断开'
        }
        
        for connection in list(self.connections):
            try:
                await connection.send(json.dumps(status_data, ensure_ascii=False))
            except Exception as e:
                print(f"通知前端客户端时出错: {str(e)}")
    
    async def handle_client(self, websocket, path):
        """处理客户端连接（前端或打印客户端）"""
        self.connections.add(websocket)
        client_type = "前端客户端"
        
        try:
            async for message in websocket:
                try:
                    data = json.loads(message)
                    print(f"收到请求: {data.get('type')}")
                    
                    if data.get('type') == 'client_auth':
                        # 处理打印客户端认证
                        client_type = "打印客户端"
                        password = data.get('password')
                        client_id = data.get('client_id')
                        
                        print(f"收到打印客户端认证请求，客户端ID: {client_id}")
                        
                        if self.validate_auth_code(password):
                            # 认证成功，建立永久绑定
                            self.clients[client_id] = {
                                'websocket': websocket,
                                'connected_at': datetime.now(),
                                'password': password
                            }
                            # 建立密码与客户端的永久绑定
                            self.client_bindings[password] = client_id
                            # 从临时密码中移除（如果存在）
                            if password in self.auth_codes:
                                del self.auth_codes[password]
                            
                            await websocket.send(json.dumps({
                                'type': 'auth_success',
                                'message': '认证成功，已连接到打印服务器并永久绑定'
                            }, ensure_ascii=False))
                            print(f"打印客户端认证成功: {client_id}")
                            print(f"客户端绑定关系已建立: 密码 {password} -> 客户端 {client_id}")
                            
                            # 通知所有前端客户端
                            await self.notify_frontend_clients()
                        else:
                            # 认证失败
                            await websocket.send(json.dumps({
                                'type': 'auth_error',
                                'message': '密码错误或已过期，请重新获取密码'
                            }, ensure_ascii=False))
                            print(f"打印客户端认证失败，密码: {password}")
                    
                    elif data.get('type') == 'get_auth_code':
                        # 生成认证密码
                        code = self.generate_auth_code()
                        await websocket.send(json.dumps({
                            'type': 'auth_code_generated',
                            'code': code,
                            'message': '请在打印客户端中输入此密码以连接'
                        }, ensure_ascii=False))
                        print(f"生成认证密码: {code}")
                    
                    elif data.get('type') == 'check_client_status':
                        # 检查客户端连接状态
                        client_connected = len(self.clients) > 0
                        await websocket.send(json.dumps({
                            'type': 'client_status',
                            'connected': client_connected,
                            'clients': list(self.clients.keys())
                        }, ensure_ascii=False))
                    
                    elif data.get('type') == 'print_request':
                        # 处理打印请求
                        password = data.get('password')
                        # 查找对应的客户端
                        target_client = None
                        for client_id, info in self.clients.items():
                            if info.get('password') == password:
                                target_client = client_id
                                break
                        
                        if target_client and target_client in self.clients:
                            # 直接发送到绑定的客户端
                            client_info = self.clients[target_client]
                            try:
                                await client_info['websocket'].send(json.dumps(data, ensure_ascii=False))
                                await websocket.send(json.dumps({
                                    'type': 'print_queued',
                                    'message': '打印任务已发送到绑定的打印客户端'
                                }, ensure_ascii=False))
                                print(f"打印任务已发送到客户端: {target_client}")
                            except Exception as e:
                                print(f"发送打印任务失败: {str(e)}")
                                await websocket.send(json.dumps({
                                    'type': 'error',
                                    'message': '发送打印任务失败'
                                }, ensure_ascii=False))
                        else:
                            # 尝试转发到本地客户端
                            success = await self.forward_to_local_client(data)
                            
                            if success:
                                await websocket.send(json.dumps({
                                    'type': 'print_queued',
                                    'message': '打印任务已发送到本地客户端'
                                }, ensure_ascii=False))
                            else:
                                await websocket.send(json.dumps({
                                    'type': 'error',
                                    'message': '无法连接到打印客户端，请确保客户端已启动并绑定'
                                }, ensure_ascii=False))
                    
                except json.JSONDecodeError:
                    print("错误: 无效的JSON数据")
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': '无效的JSON数据'
                    }, ensure_ascii=False))
                except Exception as e:
                    print(f"处理消息时出错: {str(e)}")
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': str(e)
                    }, ensure_ascii=False))
                    
        except Exception as e:
            print(f"{client_type}连接错误: {str(e)}")
        finally:
            # 清理客户端连接
            if client_type == "打印客户端":
                # 移除断开连接的打印客户端
                for client_id, info in list(self.clients.items()):
                    if info['websocket'] == websocket:
                        del self.clients[client_id]
                        print(f"打印客户端已断开连接: {client_id}")
                        # 通知前端
                        await self.notify_frontend_clients()
                        break
            self.connections.remove(websocket)
            print(f"{client_type}已断开连接: {websocket.remote_address}")
    
    async def forward_to_local_client(self, print_data):
        """转发打印请求到本地客户端"""
        print(f"转发打印请求到本地客户端: {self.local_client_url}")
        
        try:
            async with websockets.connect(self.local_client_url, timeout=5) as websocket:
                # 发送打印数据
                await websocket.send(json.dumps(print_data, ensure_ascii=False))
                print("打印请求已发送到本地客户端")
                
                # 等待响应
                response = await asyncio.wait_for(websocket.recv(), timeout=10)
                response_data = json.loads(response)
                print(f"收到本地客户端响应: {response_data.get('type')}")
                
                if response_data.get('type') == 'print_queued':
                    print("本地客户端接收打印任务成功")
                    return True
                else:
                    print(f"本地客户端拒绝打印任务: {response_data.get('message')}")
                    return False
                    
        except asyncio.TimeoutError:
            print("错误: 连接本地客户端超时")
            return False
        except websockets.exceptions.ConnectionClosedError:
            print("错误: 本地客户端连接已关闭")
            return False
        except Exception as e:
            print(f"连接本地客户端时出错: {str(e)}")
            return False
    
    async def start_server(self):
        """启动WebSocket服务器"""
        print(f"启动打印服务器...")
        print(f"服务器地址: wss://print.yhsun.cn:{self.port}")
        print(f"本地客户端地址: {self.local_client_url}")
        print("按 Ctrl+C 停止服务器")
        
        try:
            # 配置SSL上下文
            ssl_context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
            
            # 尝试加载SSL证书（根据实际部署情况修改路径）
            try:
                # 常见的证书路径（Let's Encrypt）
                cert_path = '/etc/letsencrypt/live/print.yhsun.cn/fullchain.pem'
                key_path = '/etc/letsencrypt/live/print.yhsun.cn/privkey.pem'
                
                if os.path.exists(cert_path) and os.path.exists(key_path):
                    ssl_context.load_cert_chain(cert_path, key_path)
                    print("SSL证书加载成功")
                else:
                    # 尝试其他可能的路径
                    cert_path = '/root/ssl/fullchain.pem'
                    key_path = '/root/ssl/privkey.pem'
                    if os.path.exists(cert_path) and os.path.exists(key_path):
                        ssl_context.load_cert_chain(cert_path, key_path)
                        print("SSL证书加载成功")
                    else:
                        print("警告: 未找到SSL证书，将使用不安全连接")
                        ssl_context = None
            except Exception as e:
                print(f"SSL证书加载失败: {str(e)}")
                print("将使用不安全连接")
                ssl_context = None
            
            # 启动服务器
            if ssl_context:
                async with websockets.serve(self.handle_client, self.host, self.port, ssl=ssl_context):
                    await asyncio.Future()  # 无限运行
            else:
                async with websockets.serve(self.handle_client, self.host, self.port):
                    await asyncio.Future()  # 无限运行
        except KeyboardInterrupt:
            print("服务器已停止")
        except Exception as e:
            print(f"服务器启动失败: {str(e)}")
            raise

def main():
    """主函数"""
    parser = argparse.ArgumentParser(description='云打印服务器')
    parser.add_argument('--host', type=str, default='0.0.0.0', help='服务器监听地址')
    parser.add_argument('--port', type=int, default=8770, help='服务器监听端口')
    parser.add_argument('--local-port', type=int, default=8771, help='本地客户端端口')
    
    args = parser.parse_args()
    
    server = PrintServer(host=args.host, port=args.port)
    server.local_client_url = f"ws://localhost:{args.local_port}"
    
    asyncio.run(server.start_server())

if __name__ == "__main__":
    main()
