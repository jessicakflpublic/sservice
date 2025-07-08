<?php
// Check if sockets extension is loaded
echo "=== PHP Socket Extension Check ===\n";

if (extension_loaded('sockets')) {
    echo "✓ Sockets extension is loaded\n";
    echo "Available socket functions:\n";
    $socket_functions = get_extension_funcs('sockets');
    foreach (array_slice($socket_functions, 0, 10) as $func) {
        echo "  - $func\n";
    }
    if (count($socket_functions) > 10) {
        echo "  ... and " . (count($socket_functions) - 10) . " more\n";
    }
} else {
    echo "✗ Sockets extension is NOT loaded\n";
    echo "You need to enable the sockets extension in your PHP configuration\n";
}

echo "\n=== How to Enable Sockets Extension ===\n";
echo "1. Linux/Ubuntu: sudo apt-get install php-sockets\n";
echo "2. Windows XAMPP: Uncomment ;extension=sockets in php.ini\n";
echo "3. Windows standalone: Add extension=sockets to php.ini\n";
echo "4. macOS with Homebrew: brew install php --with-sockets\n";
echo "5. Docker: Use official PHP image with sockets extension\n";

echo "\n=== Alternative: Stream-based WebSocket Client ===\n";

// Alternative WebSocket client using streams instead of sockets
class StreamWebSocketClient {
    private $connection;
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
        // Create stream context
        $context = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ]
        ]);

        // Connect using stream
        $this->connection = stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->connection) {
            throw new Exception("Could not connect to server: $errstr ($errno)");
        }

        // Set non-blocking mode
        stream_set_blocking($this->connection, false);

        // Perform WebSocket handshake
        $this->performHandshake();
        
        echo "Connected to WebSocket server at {$this->host}:{$this->port}\n";
        $this->isConnected = true;
    }

    private function performHandshake() {
        // Generate WebSocket key
        $this->key = base64_encode(random_bytes(16));
        
        // Create handshake request
        $request = "GET {$this->path} HTTP/1.1\r\n";
        $request .= "Host: {$this->host}:{$this->port}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$this->key}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";

        // Send handshake request
        fwrite($this->connection, $request);

        // Read handshake response
        $response = '';
        $startTime = time();
        
        while (time() - $startTime < 5) {
            $data = fread($this->connection, 1024);
            if ($data !== false && $data !== '') {
                $response .= $data;
                if (strpos($response, "\r\n\r\n") !== false) {
                    break;
                }
            }
            usleep(10000); // 10ms delay
        }
        
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
        fwrite($this->connection, $frame);
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

        $data = fread($this->connection, 1024);
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
            fwrite($this->connection, $closeFrame);
            
            fclose($this->connection);
            $this->isConnected = false;
            echo "Disconnected from WebSocket server\n";
        }
    }

    public function isConnected() {
        return $this->isConnected;
    }

    public function hasData() {
        if (!$this->isConnected) {
            return false;
        }

        // Check if there's data available to read
        $read = [$this->connection];
        $write = null;
        $except = null;
        
        return stream_select($read, $write, $except, 0, 0) > 0;
    }

    public function listen($duration = 10) {
        if (!$this->isConnected) {
            echo "Not connected to server\n";
            return;
        }

        echo "Listening for messages for {$duration} seconds...\n";
        $startTime = time();
        
        while (time() - $startTime < $duration) {
            if ($this->hasData()) {
                $message = $this->receiveMessage();
                if ($message !== false) {
                    echo "Received: $message\n";
                }
            }
            usleep(100000); // 100ms delay
        }
    }
}

// Test the stream-based client
echo "\n=== Testing Stream-based WebSocket Client ===\n";

try {
    $client = new StreamWebSocketClient('localhost', 8080);
    echo "Stream-based client created successfully\n";
    
    // Uncomment the lines below to test (make sure your WebSocket server is running)
    /*
    $client->connect();
    $client->sendMessage("Hello from stream client!");
    $client->listen(5);
    $client->disconnect();
    */
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Docker Example ===\n";
echo "If you're using Docker, here's a Dockerfile that includes the sockets extension:\n\n";
echo "FROM php:8.1-cli\n";
echo "RUN docker-php-ext-install sockets\n";
echo "COPY . /app\n";
echo "WORKDIR /app\n";
echo "CMD [\"php\", \"websocket_server.php\"]\n";
?>