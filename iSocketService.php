<?php
class WebSocketServer {
    private $host;
    private $port;
    private $socket;
    private $clients = [];
    
    public function __construct($host = '127.0.0.1', $port = 8080) {
        $this->host = $host;
        $this->port = $port;
    }
    
    public function start() {
        // Create socket
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            die("Could not create socket\n");
        }
        
        // Set socket options
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        // Bind socket
        if (!socket_bind($this->socket, $this->host, $this->port)) {
            die("Could not bind socket\n");
        }
        
        // Listen for connections
        if (!socket_listen($this->socket, 5)) {
            die("Could not listen on socket\n");
        }
        
        echo "WebSocket server started on {$this->host}:{$this->port}\n";
        
        while (true) {
            $read = array_merge([$this->socket], $this->clients);
            $write = null;
            $except = null;
            
            if (socket_select($read, $write, $except, 0, 10000) < 1) {
                continue;
            }
            
            // New connection
            if (in_array($this->socket, $read)) {
                $client = socket_accept($this->socket);
                if ($client) {
                    $this->clients[] = $client;
                    $this->performHandshake($client);
                    echo "New client connected\n";
                }
                $key = array_search($this->socket, $read);
                unset($read[$key]);
            }
            
            // Handle client messages
            foreach ($read as $client) {
                $data = socket_read($client, 2048);
                if ($data === false) {
                    $this->disconnect($client);
                    continue;
                }
                
                if (strlen($data) == 0) {
                    $this->disconnect($client);
                    continue;
                }
                
                $message = $this->decode($data);
                if ($message) {
                    echo "Received: {$message}\n";
                    $this->broadcast($message, $client);
                }
            }
        }
    }
    
    private function performHandshake($client) {
        $headers = socket_read($client, 2048);
        preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $matches);
        
        if (empty($matches[1])) {
            socket_close($client);
            return false;
        }
        
        $key = $matches[1];
        $acceptKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
        
        socket_write($client, $upgrade);
        return true;
    }
    
    private function decode($data) {
        $length = ord($data[1]) & 127;
        
        if ($length == 126) {
            $masks = substr($data, 4, 4);
            $data = substr($data, 8);
        } elseif ($length == 127) {
            $masks = substr($data, 10, 4);
            $data = substr($data, 14);
        } else {
            $masks = substr($data, 2, 4);
            $data = substr($data, 6);
        }
        
        $text = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        
        return $text;
    }
    
    private function encode($text) {
        $length = strlen($text);
        
        if ($length <= 125) {
            return pack('CC', 0x81, $length) . $text;
        } elseif ($length <= 65535) {
            return pack('CCn', 0x81, 126, $length) . $text;
        } else {
            return pack('CCNN', 0x81, 127, 0, $length) . $text;
        }
    }
    
    private function broadcast($message, $sender = null) {
        $response = json_encode([
            'type' => 'message',
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $encoded = $this->encode($response);
        
        foreach ($this->clients as $client) {
            if ($client !== $sender) {
                socket_write($client, $encoded);
            }
        }
    }
    
    private function disconnect($client) {
        $key = array_search($client, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
            socket_close($client);
            echo "Client disconnected\n";
        }
    }
    
    public function stop() {
        foreach ($this->clients as $client) {
            socket_close($client);
        }
        socket_close($this->socket);
    }
}

// Start the server
$server = new WebSocketServer('127.0.0.1', 8080);
$server->start();
?>