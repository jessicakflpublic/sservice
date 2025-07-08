<?php
class WebSocketClient {
    private $socket;
    private $host;
    private $port;
    private $path;
    private $connected = false;
    private $key;
    
    public function __construct($host = '127.0.0.1', $port = 8080, $path = '/') {
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
        if (!$this->performHandshake()) {
            throw new Exception("WebSocket handshake failed");
        }
        
        $this->connected = true;
        $this->log("Connected to WebSocket server at {$this->host}:{$this->port}");
        return true;
    }
    
    private function performHandshake() {
        // Generate WebSocket key
        $this->key = base64_encode(random_bytes(16));
        
        // Build handshake request
        $request = "GET {$this->path} HTTP/1.1\r\n";
        $request .= "Host: {$this->host}:{$this->port}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$this->key}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";
        
        // Send handshake request
        socket_write($this->socket, $request);
        
        // Read handshake response
        $response = socket_read($this->socket, 2048);
        
        // Verify handshake response
        if (strpos($response, '101 Switching Protocols') === false) {
            return false;
        }
        
        // Verify Sec-WebSocket-Accept header
        $expectedAccept = base64_encode(pack('H*', sha1($this->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        if (strpos($response, "Sec-WebSocket-Accept: {$expectedAccept}") === false) {
            return false;
        }
        
        return true;
    }
    
    public function send($message) {
        if (!$this->connected) {
            throw new Exception("Not connected to WebSocket server");
        }
        
        $frame = $this->encodeFrame($message);
        socket_write($this->socket, $frame);
        $this->log("Sent: {$message}");
    }
    
    public function receive($timeout = 5) {
        if (!$this->connected) {
            throw new Exception("Not connected to WebSocket server");
        }
        
        $read = [$this->socket];
        $write = null;
        $except = null;
        
        if (socket_select($read, $write, $except, $timeout) > 0) {
            $data = socket_read($this->socket, 2048);
            if ($data === false || strlen($data) == 0) {
                $this->connected = false;
                throw new Exception("Connection lost");
            }
            
            $message = $this->decodeFrame($data);
            if ($message !== false) {
                $this->log("Received: {$message}");
                return $message;
            }
        }
        
        return false;
    }
    
    private function encodeFrame($message) {
        $length = strlen($message);
        
        // Create mask
        $mask = pack('N', rand(1, 0x7FFFFFFF));
        
        // Apply mask to message
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $message[$i] ^ $mask[$i % 4];
        }
        
        // Build frame
        $frame = pack('C', 0x81); // FIN + text frame
        
        if ($length <= 125) {
            $frame .= pack('C', $length | 0x80); // Length with mask bit
        } elseif ($length <= 65535) {
            $frame .= pack('C', 126 | 0x80); // Extended length with mask bit
            $frame .= pack('n', $length);
        } else {
            $frame .= pack('C', 127 | 0x80); // Extended length with mask bit
            $frame .= pack('NN', 0, $length);
        }
        
        $frame .= $mask . $masked;
        
        return $frame;
    }
    
    private function decodeFrame($data) {
        if (strlen($data) < 2) {
            return false;
        }
        
        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);
        
        $fin = ($firstByte & 0x80) === 0x80;
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) === 0x80;
        $length = $secondByte & 0x7F;
        
        $offset = 2;
        
        if ($length == 126) {
            if (strlen($data) < $offset + 2) return false;
            $length = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($length == 127) {
            if (strlen($data) < $offset + 8) return false;
            $length = unpack('N', substr($data, $offset + 4, 4))[1];
            $offset += 8;
        }
        
        if ($masked) {
            if (strlen($data) < $offset + 4) return false;
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }
        
        if (strlen($data) < $offset + $length) return false;
        
        $payload = substr($data, $offset, $length);
        
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $length; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }
        
        return $payload;
    }
    
    public function disconnect() {
        if ($this->connected) {
            // Send close frame
            $closeFrame = pack('C', 0x88) . pack('C', 0x00);
            socket_write($this->socket, $closeFrame);
            
            socket_close($this->socket);
            $this->connected = false;
            $this->log("Disconnected from WebSocket server");
        }
    }
    
    public function isConnected() {
        return $this->connected;
    }
    
    private function log($message) {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    }
    
    public function listen($callback = null) {
        if (!$this->connected) {
            throw new Exception("Not connected to WebSocket server");
        }
        
        $this->log("Listening for messages... (Press Ctrl+C to stop)");
        
        while ($this->connected) {
            try {
                $message = $this->receive(1);
                if ($message !== false) {
                    if ($callback && is_callable($callback)) {
                        $callback($message);
                    }
                }
            } catch (Exception $e) {
                $this->log("Error: " . $e->getMessage());
                break;
            }
        }
    }
}

// CLI Test Client
class WebSocketCLIClient {
    private $client;
    private $running = true;
    
    public function __construct() {
        $this->client = new WebSocketClient();
        
        // Handle Ctrl+C gracefully
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    public function run() {
        echo "WebSocket CLI Test Client\n";
        echo "========================\n\n";
        
        try {
            $this->client->connect();
            $this->showMenu();
            $this->handleInput();
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
    
    private function showMenu() {
        echo "\nCommands:\n";
        echo "  send <message>  - Send a message\n";
        echo "  listen          - Listen for incoming messages\n";
        echo "  status          - Show connection status\n";
        echo "  help            - Show this help\n";
        echo "  quit            - Disconnect and exit\n\n";
    }
    
    private function handleInput() {
        while ($this->running && $this->client->isConnected()) {
            echo "> ";
            $input = trim(fgets(STDIN));
            
            if (empty($input)) {
                continue;
            }
            
            $parts = explode(' ', $input, 2);
            $command = strtolower($parts[0]);
            
            switch ($command) {
                case 'send':
                    if (isset($parts[1])) {
                        try {
                            $this->client->send($parts[1]);
                        } catch (Exception $e) {
                            echo "Error sending message: " . $e->getMessage() . "\n";
                        }
                    } else {
                        echo "Usage: send <message>\n";
                    }
                    break;
                    
                case 'listen':
                    $this->startListening();
                    break;
                    
                case 'status':
                    echo "Connection status: " . ($this->client->isConnected() ? "Connected" : "Disconnected") . "\n";
                    break;
                    
                case 'help':
                    $this->showMenu();
                    break;
                    
                case 'quit':
                case 'exit':
                    $this->shutdown();
                    break;
                    
                default:
                    echo "Unknown command: {$command}\n";
                    echo "Type 'help' for available commands.\n";
                    break;
            }
        }
    }
    
    private function startListening() {
        echo "Listening for messages... (Press Enter to stop)\n";
        
        // Set non-blocking mode for stdin
        stream_set_blocking(STDIN, false);
        
        while ($this->running && $this->client->isConnected()) {
            // Check for incoming WebSocket messages
            try {
                $message = $this->client->receive(0.1);
                if ($message !== false) {
                    // Try to decode JSON
                    $data = json_decode($message, true);
                    if ($data && isset($data['message'])) {
                        echo "\n[MESSAGE] {$data['message']} (at {$data['timestamp']})\n";
                    } else {
                        echo "\n[MESSAGE] {$message}\n";
                    }
                    echo "Press Enter to stop listening> ";
                }
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
                break;
            }
            
            // Check for keyboard input
            $input = fgets(STDIN);
            if ($input !== false) {
                break;
            }
            
            // Handle signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        // Restore blocking mode
        stream_set_blocking(STDIN, true);
        echo "Stopped listening.\n";
    }
    
    public function shutdown() {
        echo "\nShutting down...\n";
        $this->running = false;
        $this->client->disconnect();
        exit(0);
    }
}

// Auto-test mode
function runAutoTest() {
    echo "WebSocket Auto Test Mode\n";
    echo "=======================\n\n";
    
    $client = new WebSocketClient();
    
    try {
        // Connect
        $client->connect();
        
        // Send test messages
        $testMessages = [
            "Hello from PHP CLI client!",
            "This is a test message",
            "Auto-test mode is working",
            "Timestamp: " . date('Y-m-d H:i:s')
        ];
        
        foreach ($testMessages as $message) {
            $client->send($message);
            sleep(1);
            
            // Try to receive any responses
            $response = $client->receive(2);
            if ($response !== false) {
                echo "Received response: {$response}\n";
            }
        }
        
        // Listen for a few seconds
        echo "\nListening for 5 seconds...\n";
        for ($i = 0; $i < 5; $i++) {
            $message = $client->receive(1);
            if ($message !== false) {
                echo "Received: {$message}\n";
            }
        }
        
        $client->disconnect();
        echo "Auto-test completed successfully!\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    // Check command line arguments
    if (isset($argv[1]) && $argv[1] === 'auto') {
        runAutoTest();
    } else {
        $cliClient = new WebSocketCLIClient();
        $cliClient->run();
    }
} else {
    echo "This script should be run from the command line.\n";
}
?>