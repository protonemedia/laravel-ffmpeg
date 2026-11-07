<?php

// A loopback-only, range-capable fixture. Owned and stopped by NativeUrlTest.
$context = stream_context_create(isset($argv[3]) ? ['ssl' => [
    'local_cert' => $argv[3], 'local_pk' => $argv[4],
]] : []);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    exit(1);
}

echo stream_socket_get_name($server, false)."\n";
flush();

while ($client = stream_socket_accept($server, 15)) {
    stream_set_timeout($client, 5);

    if (isset($argv[3]) && ! @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($client);
        continue;
    }

    $line = fgets($client);
    $path = parse_url(explode(' ', $line ?: '')[1] ?? '/', PHP_URL_PATH);
    $headers = [];

    while (($line = fgets($client)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }

    $source = match ($headers['authorization'] ?? '') {
        'Bearer first-secret' => 'first', 'Bearer second-secret' => 'second', default => null,
    };
    file_put_contents($argv[2], json_encode(['path' => $path, 'source' => $source])."\n", FILE_APPEND);
    $expected = match ($path) { '/first' => 'Bearer first-secret', '/second' => 'Bearer second-secret', default => null };

    if (($expected !== null && ($headers['authorization'] ?? null) !== $expected) || ($path === '/private' && $source === null)) {
        fwrite($client, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    } elseif ($path === '/redirect') {
        fwrite($client, "HTTP/1.1 302 Found\r\nLocation: /stolen\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    } elseif ($path === '/truncated') {
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 1000\r\nConnection: close\r\n\r\nshort");
    } else {
        $size = filesize($argv[1]);
        $start = 0;
        $end = $size - 1;
        $status = '200 OK';
        $range = '';

        if (preg_match('/^bytes=(\d+)-(\d*)$/', $headers['range'] ?? '', $matches)) {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $end : min($end, (int) $matches[2]);
            $status = '206 Partial Content';
            $range = "Content-Range: bytes $start-$end/$size\r\n";
        }

        $length = $end - $start + 1;
        fwrite($client, "HTTP/1.1 $status\r\nContent-Length: $length\r\nAccept-Ranges: bytes\r\n{$range}Connection: close\r\n\r\n");
        $input = fopen($argv[1], 'rb');
        fseek($input, $start);
        @stream_copy_to_stream($input, $client, $length);
        fclose($input);
    }

    fclose($client);
}
