<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebSocket Test Client</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .connection-status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            font-weight: bold;
            text-align: center;
        }
        
        .connected {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .disconnected {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .controls {
            margin: 20px 0;
        }
        
        button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            margin: 5px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        button:hover {
            background-color: #0056b3;
        }
        
        button:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
        
        .message-area {
            margin: 20px 0;
        }
        
        .message-input {
            display: flex;
            gap: 10px;
            margin: 10px 0;
        }
        
        input[type="text"] {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .messages {
            height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background-color: #f9f9f9;
            font-family: monospace;
            font-size: 12px;
        }
        
        .message {
            margin: 5px 0;
            padding: 5px;
            border-radius: 3px;
        }
        
        .message.sent {
            background-color: #e3f2fd;
            border-left: 3px solid #2196f3;
        }
        
        .message.received {
            background-color: #f3e5f5;
            border-left: 3px solid #9c27b0;
        }
        
        .message.system {
            background-color: #fff3e0;
            border-left: 3px solid #ff9800;
        }
        
        .timestamp {
            color: #666;
            font-size: 10px;
        }
        
        .server-info {
            background-color: #e7f3ff;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        
        .server-info h3 {
            margin-top: 0;
            color: #0066cc;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>WebSocket Test Client</h1>
        
        <div class="server-info">
            <h3>Server Information</h3>
            <p><strong>URL:</strong> ws://127.0.0.1:8080</p>
            <p><strong>Protocol:</strong> WebSocket</p>
            <p>Make sure the PHP WebSocket server is running before connecting.</p>
        </div>
        
        <div id="connectionStatus" class="connection-status disconnected">
            Disconnected
        </div>
        
        <div class="controls">
            <button id="connectBtn" onclick="connect()">Connect</button>
            <button id="disconnectBtn" onclick="disconnect()" disabled>Disconnect</button>
            <button onclick="clearMessages()">Clear Messages</button>
        </div>
        
        <div class="message-area">
            <div class="message-input">
                <input type="text" id="messageInput" placeholder="Enter your message..." onkeypress="handleKeyPress(event)" disabled>
                <button onclick="sendMessage()" id="sendBtn" disabled>Send</button>
            </div>
            
            <div id="messages" class="messages"></div>
        </div>
    </div>

    <script>
        let ws = null;
        let isConnected = false;
        
        function connect() {
            try {
                ws = new WebSocket('ws://127.0.0.1:8080');
                
                ws.onopen = function(event) {
                    isConnected = true;
                    updateConnectionStatus();
                    addMessage('Connected to WebSocket server', 'system');
                };
                
                ws.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        addMessage(`${data.message} (${data.timestamp})`, 'received');
                    } catch (e) {
                        addMessage(event.data, 'received');
                    }
                };
                
                ws.onclose = function(event) {
                    isConnected = false;
                    updateConnectionStatus();
                    addMessage('Disconnected from WebSocket server', 'system');
                };
                
                ws.onerror = function(error) {
                    addMessage('Connection error: ' + error, 'system');
                };
                
            } catch (error) {
                addMessage('Failed to connect: ' + error.message, 'system');
            }
        }
        
        function disconnect() {
            if (ws) {
                ws.close();
            }
        }
        
        function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (message && ws && isConnected) {
                ws.send(message);
                addMessage(message, 'sent');
                input.value = '';
            }
        }
        
        function addMessage(message, type) {
            const messagesDiv = document.getElementById('messages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            
            const timestamp = new Date().toLocaleTimeString();
            messageDiv.innerHTML = `
                <div>${message}</div>
                <div class="timestamp">${timestamp}</div>
            `;
            
            messagesDiv.appendChild(messageDiv);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        function clearMessages() {
            document.getElementById('messages').innerHTML = '';
        }
        
        function updateConnectionStatus() {
            const statusDiv = document.getElementById('connectionStatus');
            const connectBtn = document.getElementById('connectBtn');
            const disconnectBtn = document.getElementById('disconnectBtn');
            const messageInput = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            
            if (isConnected) {
                statusDiv.textContent = 'Connected';
                statusDiv.className = 'connection-status connected';
                connectBtn.disabled = true;
                disconnectBtn.disabled = false;
                messageInput.disabled = false;
                sendBtn.disabled = false;
            } else {
                statusDiv.textContent = 'Disconnected';
                statusDiv.className = 'connection-status disconnected';
                connectBtn.disabled = false;
                disconnectBtn.disabled = true;
                messageInput.disabled = true;
                sendBtn.disabled = true;
            }
        }
        
        function handleKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        }
        
        // Initialize the UI
        updateConnectionStatus();
    </script>
</body>
</html>