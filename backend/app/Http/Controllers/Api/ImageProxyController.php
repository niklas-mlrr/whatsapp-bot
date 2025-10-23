<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ImageProxyController extends Controller
{
    public function avatar(Request $request)
    {
        $url = $request->query('url');
        if (!$url || !is_string($url)) {
            return response()->json(['error' => 'Missing url'], 400);
        }

        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme']) || !isset($parts['host'])) {
            return response()->json(['error' => 'Invalid url'], 400);
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (!in_array($scheme, ['https'])) {
            return response()->json(['error' => 'Only https scheme allowed'], 400);
        }

        $allowedHosts = [
            'pps.whatsapp.net',
            'mmg.whatsapp.net',
            'static.whatsapp.net',
            'lookaside.whatsapp.net',
        ];

        $allowed = false;
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || (str_ends_with($host, '.' . $allowedHost))) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return response()->json(['error' => 'Host not allowed'], 403);
        }

        $headersRaw = '';
        $body = '';
        $status = 200;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            curl_setopt($ch, CURLOPT_HEADER, true);

            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return response()->json(['error' => $err ?: 'Request failed'], 502);
            }

            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersRaw = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            curl_close($ch);
        } else {
            // Fallback: use PHP streams with manual redirect handling (up to 3 redirects)
            $finalUrl = $url;
            for ($i = 0; $i < 3; $i++) {
                $respHeaders = @get_headers($finalUrl, 1);
                if (!$respHeaders || !is_array($respHeaders)) {
                    break;
                }
                $statusLine = is_array($respHeaders[0]) ? end($respHeaders[0]) : $respHeaders[0];
                if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
                    $code = (int)$m[1];
                    if ($code >= 300 && $code < 400 && isset($respHeaders['Location'])) {
                        $location = is_array($respHeaders['Location']) ? end($respHeaders['Location']) : $respHeaders['Location'];
                        if ($location) {
                            // Resolve relative redirects without external libraries
                            if (preg_match('#^https?://#i', $location)) {
                                $finalUrl = $location;
                            } else {
                                $base = parse_url($finalUrl);
                                if (!$base) break;
                                $scheme = $base['scheme'] ?? 'https';
                                $host = $base['host'] ?? '';
                                $port = isset($base['port']) ? ':' . $base['port'] : '';
                                $path = $base['path'] ?? '/';
                                if (strpos($location, '/') === 0) {
                                    // Absolute path
                                    $finalUrl = $scheme . '://' . $host . $port . $location;
                                } else {
                                    // Relative path
                                    $dir = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/');
                                    $finalUrl = $scheme . '://' . $host . $port . $dir . '/' . $location;
                                }
                            }
                            continue;
                        }
                    }
                    $status = $code;
                }
                break;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0',
                        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                    ],
                    'timeout' => 10,
                    'ignore_errors' => true,
                ]
            ]);

            $body = @file_get_contents($finalUrl, false, $context);
            if ($body === false) {
                return response()->json(['error' => 'Upstream fetch failed'], 502);
            }
            // Build headersRaw from $http_response_header
            $headersRaw = is_array($http_response_header ?? null) ? implode("\r\n", $http_response_header) : '';
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m2)) {
                $status = (int)$m2[1];
            }
        }

        if ($status < 200 || $status >= 300) {
            return response()->json(['error' => 'Upstream error', 'status' => $status], 502);
        }

        $contentType = 'image/jpeg';
        foreach (explode("\r\n", (string)$headersRaw) as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $parts = explode(':', $headerLine, 2);
                if (isset($parts[1])) {
                    $contentType = trim($parts[1]);
                }
                break;
            }
        }

        if (stripos($contentType, 'image/') !== 0) {
            return response()->json(['error' => 'Invalid content type'], 502);
        }

        return response($body, 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
