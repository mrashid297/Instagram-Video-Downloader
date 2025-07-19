<?php
// api.php - PHP Backend for Instagram Video Downloader

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class InstagramDownloader {
    
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    
    public function download($url) {
        try {
            if (!$this->isValidInstagramUrl($url)) {
                throw new Exception('Please provide a valid Instagram URL');
            }
            
            $videoData = $this->extractInstagramData($url);
            
            if (!$videoData) {
                throw new Exception('Could not extract video data. Make sure the post is public.');
            }
            
            return $videoData;
            
        } catch (Exception $e) {
            throw new Exception('Error: ' . $e->getMessage());
        }
    }
    
    private function isValidInstagramUrl($url) {
        $patterns = [
            '/^https?:\/\/(www\.)?instagram\.com\/p\/[A-Za-z0-9_-]+/',
            '/^https?:\/\/(www\.)?instagram\.com\/reel\/[A-Za-z0-9_-]+/',
            '/^https?:\/\/(www\.)?instagram\.com\/tv\/[A-Za-z0-9_-]+/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        return false;
    }
    
    private function extractInstagramData($url) {
        // Method 1: Try JSON API
        $videoData = $this->tryJsonApi($url);
        
        // Method 2: Parse HTML if JSON fails
        if (!$videoData) {
            $videoData = $this->parseHtmlData($url);
        }
        
        // Method 3: Try embed method
        if (!$videoData) {
            $videoData = $this->tryEmbedMethod($url);
        }
        
        return $videoData;
    }
    
    private function tryJsonApi($url) {
        try {
            $apiUrl = strpos($url, '?') !== false ? $url . '&__a=1' : $url . '/?__a=1';
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: ' . $this->userAgent,
                        'Accept: application/json',
                        'Accept-Language: en-US,en;q=0.9'
                    ],
                    'timeout' => 30
                ]
            ]);
            
            $response = file_get_contents($apiUrl, false, $context);
            
            if ($response === false) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['graphql']['shortcode_media'])) {
                return $this->parseGraphqlData($data['graphql']['shortcode_media']);
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('JSON API method failed: ' . $e->getMessage());
            return null;
        }
    }
    
    private function parseHtmlData($url) {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: ' . $this->userAgent,
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: en-US,en;q=0.9'
                    ],
                    'timeout' => 30
                ]
            ]);
            
            $html = file_get_contents($url, false, $context);
            
            if ($html === false) {
                return null;
            }
            
            // Extract data from window._sharedData
            if (preg_match('/window\._sharedData\s*=\s*({.+?});/', $html, $matches)) {
                $sharedData = json_decode($matches[1], true);
                
                if (isset($sharedData['entry_data']['PostPage'][0]['graphql']['shortcode_media'])) {
                    return $this->parseGraphqlData($sharedData['entry_data']['PostPage'][0]['graphql']['shortcode_media']);
                }
            }
            
            // Extract video URL directly from HTML
            if (preg_match('/"video_url":"([^"]+)"/', $html, $videoMatches)) {
                preg_match('/"display_url":"([^"]+)"/', $html, $thumbnailMatches);
                preg_match('/"caption":"([^"]*)"/', $html, $captionMatches);
                
                return [
                    'title' => isset($captionMatches[1]) ? substr($this->cleanText($captionMatches[1]), 0, 50) . '...' : 'Instagram Video',
                    'description' => isset($captionMatches[1]) ? $this->cleanText($captionMatches[1]) : 'Downloaded from Instagram',
                    'thumbnail' => isset($thumbnailMatches[1]) ? str_replace('\\u0026', '&', $thumbnailMatches[1]) : '',
                    'downloadUrls' => [
                        [
                            'url' => str_replace('\\u0026', '&', $videoMatches[1]),
                            'quality' => 'HD',
                            'type' => 'video/mp4'
                        ]
                    ]
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('HTML parsing method failed: ' . $e->getMessage());
            return null;
        }
    }
    
    private function tryEmbedMethod($url) {
        try {
            $embedUrl = rtrim($url, '/') . '/embed/';
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: ' . $this->userAgent,
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                    ],
                    'timeout' => 30
                ]
            ]);
            
            $html = file_get_contents($embedUrl, false, $context);
            
            if ($html === false) {
                return null;
            }
            
            // Extract video source from embed page
            if (preg_match('/<video[^>]*src="([^"]+)"[^>]*>/', $html, $videoMatches)) {
                preg_match('/<video[^>]*poster="([^"]+)"[^>]*>/', $html, $posterMatches);
                
                return [
                    'title' => 'Instagram Video',
                    'description' => 'Downloaded from Instagram',
                    'thumbnail' => isset($posterMatches[1]) ? $posterMatches[1] : '',
                    'downloadUrls' => [
                        [
                            'url' => $videoMatches[1],
                            'quality' => 'HD',
                            'type' => 'video/mp4'
                        ]
                    ]
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('Embed method failed: ' . $e->getMessage());
            return null;
        }
    }
    
    private function parseGraphqlData($media) {
        try {
            $videoUrls = [];
            
            // Get video URL
            if (isset($media['video_url'])) {
                $videoUrls[] = [
                    'url' => $media['video_url'],
                    'quality' => 'HD',
                    'type' => 'video/mp4'
                ];
            }
            
            // Get additional quality options
            if (isset($media['display_resources'])) {
                foreach ($media['display_resources'] as $resource) {
                    if (isset($resource['src'])) {
                        $found = false;
                        foreach ($videoUrls as $existing) {
                            if ($existing['url'] === $resource['src']) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $videoUrls[] = [
                                'url' => $resource['src'],
                                'quality' => $resource['config_width'] . 'x' . $resource['config_height'],
                                'type' => 'video/mp4'
                            ];
                        }
                    }
                }
            }
            
            // Get caption
            $caption = '';
            if (isset($media['edge_media_to_caption']['edges'][0]['node']['text'])) {
                $caption = $media['edge_media_to_caption']['edges'][0]['node']['text'];
            }
            
            $result = [
                'title' => $caption ? substr($this->cleanText($caption), 0, 50) . '...' : 'Instagram Video',
                'description' => $caption ? $this->cleanText($caption) : 'Downloaded from Instagram',
                'thumbnail' => $media['display_url'] ?? $media['thumbnail_src'] ?? '',
                'duration' => $media['video_duration'] ?? 0,
                'downloadUrls' => count($videoUrls) > 0 ? $videoUrls : [
                    [
                        'url' => $media['display_url'] ?? '',
                        'quality' => 'Image',
                        'type' => 'image/jpeg'
                    ]
                ]
            ];
            
            return $result;
            
        } catch (Exception $e) {
            error_log('GraphQL parsing failed: ' . $e->getMessage());
            return null;
        }
    }
    
    private function cleanText($text) {
        // Remove extra slashes and decode unicode
        $text = stripslashes($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return $text;
    }
}

// API Routes
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    if ($method === 'POST' && $path === '/api/download') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['url'])) {
            throw new Exception('URL parameter is required');
        }
        
        $downloader = new InstagramDownloader();
        $result = $downloader->download($input['url']);
        
        echo json_encode($result);
        
    } elseif ($method === 'GET' && $path === '/health') {
        echo json_encode([
            'status' => 'OK',
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ]);
        
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>