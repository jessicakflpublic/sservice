<?php
class WebSocketClient {
    private $socket;
    private $host;
    private $port;
    private $path;
    private $isConnected = false;
    private $key;

    public function __construct($host = 'localhost', $port = 8080, $path = '/') {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
    }

    public function connect() {
        // Create socket
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            throw new Exception("Could not create socket");
        }

        // Connect to server
        if (!socket_connect($this->socket, $this->host, $this->port)) {
            throw new Exception("Could not connect to server");
        }

        // Perform WebSocket handshake
        $this->performHandshake();
        
        echo "Connected to WebSocket server at {$this->host}:{$this->port}\n";
        $this->isConnected = true;
    }

    private function performHandshake() {
        // Generate WebSocket key
        $this->key = base64_encode(openssl_random_pseudo_bytes(16));
        
        // Create handshake request
        $request = "GET {$this->path} HTTP/1.1\r\n";
        $request .= "Host: {$this->host}:{$this->port}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$this->key}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";

        // Send handshake request
        socket_write($this->socket, $request, strlen($request));

        // Read handshake response
        $response = socket_read($this->socket, 1024);
        
        // Validate handshake response
        if (!$this->validateHandshake($response)) {
            throw new Exception("WebSocket handshake failed");
        }
    }

    private function validateHandshake($response) {
        // Check for HTTP 101 Switching Protocols
        if (strpos($response, '101 Switching Protocols') === false) {
            return false;
        }

        // Extract Sec-WebSocket-Accept header
        preg_match('/Sec-WebSocket-Accept: (.+)\r\n/', $response, $matches);
        if (!$matches) {
            return false;
        }

        $serverAccept = trim($matches[1]);
        $expectedAccept = base64_encode(hash('sha1', $this->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        return $serverAccept === $expectedAccept;
    }

    public function sendMessage($message) {
        if (!$this->isConnected) {
            throw new Exception("Not connected to WebSocket server");
        }

        $frame = $this->encodeFrame($message);
        socket_write($this->socket, $frame, strlen($frame));
        echo "Sent: $message\n";
    }

    private function encodeFrame($message) {
        $length = strlen($message);
        $frame = chr(129); // FIN + text frame

        if ($length < 126) {
            $frame .= chr($length | 128); // Mask bit set
        } elseif ($length < 65536) {
            $frame .= chr(126 | 128) . pack('n', $length); // Mask bit set
        } else {
            $frame .= chr(127 | 128) . pack('J', $length); // Mask bit set
        }

        // Generate mask
        $mask = pack('N', rand());
        $frame .= $mask;

        // Mask payload
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= chr(ord($message[$i]) ^ ord($mask[$i % 4]));
        }
        $frame .= $masked;

        return $frame;
    }

    public function receiveMessage() {
        if (!$this->isConnected) {
            return false;
        }

        $data = socket_read($this->socket, 1024);
        if ($data === false || $data === '') {
            return false;
        }

        return $this->decodeFrame($data);
    }

    private function decodeFrame($data) {
        if (strlen($data) < 2) {
            return false;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $fin = ($firstByte >> 7) & 1;
        $opcode = $firstByte & 15;
        $masked = ($secondByte >> 7) & 1;
        $payloadLength = $secondByte & 127;

        if (!$fin || $opcode != 1) { // Only handle text frames
            return false;
        }

        $offset = 2;
        if ($payloadLength == 126) {
            $payloadLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLength == 127) {
            $payloadLength = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        if ($masked) {
            $mask = substr($data, $offset, 4);
            $offset += 4;
            $payload = substr($data, $offset, $payloadLength);
            
            // Unmask payload
            $unmasked = '';
            for ($i = 0; $i < $payloadLength; $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
            return $unmasked;
        } else {
            return substr($data, $offset, $payloadLength);
        }
    }

    public function disconnect() {
        if ($this->isConnected) {
            // Send close frame
            $closeFrame = chr(136) . chr(128) . pack('N', rand()); // Close frame with mask
            socket_write($this->socket, $closeFrame, strlen($closeFrame));
            
            socket_close($this->socket);
            $this->isConnected = false;
            echo "Disconnected from WebSocket server\n";
        }
    }

    public function isConnected() {
        return $this->isConnected;
    }

    public function listen() {
        while ($this->isConnected) {
            $read = [$this->socket];
            $write = null;
            $except = null;

            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                $message = $this->receiveMessage();
                if ($message !== false) {
                    echo "Received: $message\n";
                }
            }
        }
    }
}

// Interactive Test Client
class InteractiveWebSocketClient {
    private $client;
    private $running = true;

    public function __construct($host = 'localhost', $port = 8080) {
        $this->client = new WebSocketClient($host, $port);
    }

    public function start() {
        echo "=== PHP WebSocket Test Client ===\n";
        echo "Commands:\n";
        echo "  connect - Connect to WebSocket server\n";
        echo "  send <message> - Send a message\n";
        echo "  listen - Start listening for messages\n";
        echo "  disconnect - Disconnect from server\n";
        echo "  test - Run automated test\n";
        echo "  quit - Exit client\n";
        echo "=====================================\n\n";

        while ($this->running) {
            echo "> ";
            $input = trim(fgets(STDIN));
            $this->processCommand($input);
        }
    }

    private function processCommand($input) {
        $parts = explode(' ', $input, 2);
        $command = strtolower($parts[0]);
        $args = isset($parts[1]) ? $parts[1] : '';

        switch ($command) {
            case 'connect':
                $this->connect();
                break;
            
            case 'send':
                if (empty($args)) {
                    echo "Usage: send <message>\n";
                } else {
                    $this->sendMessage($args);
                }
                break;
            
            case 'listen':
                $this->listen();
                break;
            
            case 'disconnect':
                $this->disconnect();
                break;
            
            case 'test':
                $this->runTest();
                break;
            
            case 'quit':
            case 'exit':
                $this->quit();
                break;
            
            default:
                echo "Unknown command: $command\n";
                break;
        }
    }

    private function connect() {
        try {
            $this->client->connect();
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    private function sendMessage($message) {
        try {
            $this->client->sendMessage($message);
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    private function listen() {
        if (!$this->client->isConnected()) {
            echo "Not connected to server\n";
            return;
        }

        echo "Listening for messages... (Press Ctrl+C to stop)\n";
        $this->client->listen();
    }

    private function disconnect() {
        $this->client->disconnect();
    }

    private function runTest() {
        echo "Running automated test...\n";
        
        try {
            // Connect
            echo "1. Connecting to server...\n";
            $this->client->connect();
            
            // Send test messages
            $testMessages = [
                "Hello WebSocket!",
                "This is a test message",
                "Testing special characters: àáâãäåæçèéêë",
                "Numbers: 123456789",
                "Symbols: !@#$%^&*()",
                "Long message: " . str_repeat("Lorem ipsum dolor sit amet, consectetur adipiscing elit. ", 10)
            ];
            
            echo "2. Sending test messages...\n";
            foreach ($testMessages as $i => $message) {
                echo "   Sending message " . ($i + 1) . "...\n";
                $this->client->sendMessage($message);
                usleep(500000); // 500ms delay
            }
            
            echo "3. Listening for responses...\n";
            $startTime = time();
            $messageCount = 0;
            
            while (time() - $startTime < 10 && $messageCount < count($testMessages)) {
                $read = [$this->client->socket ?? null];
                $write = null;
                $except = null;
                
                if ($read[0] && socket_select($read, $write, $except, 0, 100000) > 0) {
                    $message = $this->client->receiveMessage();
                    if ($message !== false) {
                        echo "   Received: $message\n";
                        $messageCount++;
                    }
                }
            }
            
            echo "4. Disconnecting...\n";
            $this->client->disconnect();
            
            echo "Test completed successfully!\n";
            echo "Messages sent: " . count($testMessages) . "\n";
            echo "Messages received: $messageCount\n";
            
        } catch (Exception $e) {
            echo "Test failed: " . $e->getMessage() . "\n";
        }
    }

    private function quit() {
        echo "Goodbye!\n";
        $this->client->disconnect();
        $this->running = false;
    }
}

// Usage
if (php_sapi_name() === 'cli') {
    // Check command line arguments
    $host = $argv[1] ?? 'localhost';
    $port = $argv[2] ?? 8080;
    
    echo "Starting WebSocket client for $host:$port\n";
    
    $testClient = new InteractiveWebSocketClient($host, $port);
    $testClient->start();
} else {
    echo "This script must be run from the command line\n";
}
?>