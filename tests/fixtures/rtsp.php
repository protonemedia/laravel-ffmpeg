<?php

// Minimal finite RTSP/RTP fixture for snapshot acceptance, not a production server.
[$script, $source, $ready, $mode] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0');
// Publish the address atomically: the test reads the file as soon as it exists.
file_put_contents($ready.'.tmp', stream_socket_get_name($server, false));
rename($ready.'.tmp', $ready);
$client = stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}
stream_set_timeout($client, 10);
$nals = array_values(array_filter(preg_split('/\x00\x00\x00?\x01/', file_get_contents($source))));
$sps = $pps = '';

foreach ($nals as $nal) {
    if ((ord($nal[0]) & 31) === 7) {
        $sps = base64_encode($nal);
    }

    if ((ord($nal[0]) & 31) === 8) {
        $pps = base64_encode($nal);
    }
}
$udp = null;

while (! feof($client)) {
    $request = '';

    while (($line = fgets($client)) !== false) {
        $request .= $line;

        if ($line === "\r\n") {
            break;
        }
    }

    if ($request === '') {
        break;
    }

    preg_match('/CSeq: (\d+)/i', $request, $sequence);
    $headers = 'CSeq: '.($sequence[1] ?? 1)."\r\n";

    if ($mode === 'denied') {
        fwrite($client, "RTSP/1.0 401 Unauthorized\r\n".$headers."WWW-Authenticate: Basic realm=\"test\"\r\n\r\n");

        continue;
    }

    if (str_starts_with($request, 'DESCRIBE')) {
        $sdp = "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=Snapshot fixture\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\n"
            ."m=video 0 RTP/AVP 96\r\na=rtpmap:96 H264/90000\r\na=fmtp:96 packetization-mode=1;sprop-parameter-sets=$sps,$pps\r\na=control:trackID=0\r\n";
        fwrite($client, "RTSP/1.0 200 OK\r\n".$headers."Content-Type: application/sdp\r\nContent-Length: ".strlen($sdp)."\r\n\r\n".$sdp);
    } elseif (str_starts_with($request, 'SETUP')) {
        if (preg_match('/client_port=(\d+)-(\d+)/', $request, $ports)) {
            $udp = stream_socket_server('udp://127.0.0.1:0', flags: STREAM_SERVER_BIND);
            $serverPort = (int) substr(strrchr(stream_socket_get_name($udp, false), ':'), 1);
            $destination = '127.0.0.1:'.$ports[1];
            $transport = 'RTP/AVP;unicast;client_port='.$ports[1].'-'.$ports[2].';server_port='.$serverPort.'-'.($serverPort + 1);
        } else {
            $transport = 'RTP/AVP/TCP;unicast;interleaved=0-1';
        }

        fwrite($client, "RTSP/1.0 200 OK\r\n".$headers."Session: snapshot\r\nTransport: $transport\r\n\r\n");
    } elseif (str_starts_with($request, 'PLAY')) {
        fwrite($client, "RTSP/1.0 200 OK\r\n".$headers."Session: snapshot\r\n\r\n");

        if ($mode === 'stall') {
            // Keep the connection open until the client's bounded operation stops it.
            while (! feof($client) && fgets($client) !== false) {
            }

            break;
        }

        $index = $timestamp = 0;

        foreach ($nals as $nal) {
            $type = ord($nal[0]) & 31;

            if ($type === 9) {
                $timestamp += 9000;
                usleep(20000);
            }

            $parts = strlen($nal) <= 1000 ? [$nal] : str_split(substr($nal, 1), 998);

            foreach ($parts as $i => $part) {
                $last = $i === count($parts) - 1;
                $payload = strlen($nal) <= 1000 ? $part
                    : chr((ord($nal[0]) & 224) | 28).chr($type | ($i === 0 ? 128 : 0) | ($last ? 64 : 0)).$part;
                $packet = pack('CCnNN', 128, 96 | ($last ? 128 : 0), $index++, $timestamp, 1234).$payload;
                $written = $udp ? @stream_socket_sendto($udp, $packet, 0, $destination)
                    : @fwrite($client, '$'.chr(0).pack('n', strlen($packet)).$packet);

                if ($written === false) {
                    break 2;
                }
            }
        }

        break;
    } else {
        fwrite($client, "RTSP/1.0 200 OK\r\n".$headers."Public: OPTIONS, DESCRIBE, SETUP, PLAY, TEARDOWN\r\n\r\n");
    }
}
fclose($client);
fclose($server);

if (is_resource($udp)) {
    fclose($udp);
}
