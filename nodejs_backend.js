// server.js - Node.js Backend for Instagram Video Downloader

const express = require('express');
const cors = require('cors');
const axios = require('axios');
const cheerio = require('cheerio');
const path = require('path');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.static('public'));

// Serve the HTML file
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// Instagram video downloader API endpoint
app.post('/api/download', async (req, res) => {
    try {
        const { url } = req.body;

        if (!url || !isValidInstagramUrl(url)) {
            return res.status(400).json({
                error: 'Please provide a valid Instagram URL'
            });
        }

        console.log('Processing URL:', url);
        
        // Extract video data from Instagram
        const videoData = await extractInstagramData(url);
        
        if (!videoData) {
            return res.status(404).json({
                error: 'Could not extract video data. Make sure the post is public.'
            });
        }

        res.json(videoData);

    } catch (error) {
        console.error('Error processing request:', error.message);
        res.status(500).json({
            error: 'Failed to process the video. Please try again.'
        });
    }
});

// Function to validate Instagram URLs
function isValidInstagramUrl(url) {
    const patterns = [
        /^https?:\/\/(www\.)?instagram\.com\/p\/[A-Za-z0-9_-]+/,
        /^https?:\/\/(www\.)?instagram\.com\/reel\/[A-Za-z0-9_-]+/,
        /^https?:\/\/(www\.)?instagram\.com\/tv\/[A-Za-z0-9_-]+/
    ];
    
    return patterns.some(pattern => pattern.test(url));
}

// Function to extract Instagram data
async function extractInstagramData(url) {
    try {
        // Add /?__a=1 to get JSON response from Instagram
        const apiUrl = url.includes('?') ? url + '&__a=1' : url + '/?__a=1';
        
        const headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Accept-Encoding': 'gzip, deflate',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
        };

        // Try different methods to extract data
        let videoData = null;

        // Method 1: Try to get JSON data directly
        try {
            const response = await axios.get(apiUrl, { headers });
            if (response.data && response.data.graphql) {
                videoData = parseGraphqlData(response.data.graphql);
            }
        } catch (error) {
            console.log('Method 1 failed, trying alternative method...');
        }

        // Method 2: Parse HTML and extract from script tags
        if (!videoData) {
            try {
                const response = await axios.get(url, { headers });
                videoData = await parseHtmlData(response.data);
            } catch (error) {
                console.log('Method 2 failed:', error.message);
            }
        }

        // Method 3: Use embed URL
        if (!videoData) {
            try {
                const embedUrl = url.replace('/p/', '/p/').replace('/reel/', '/reel/').replace('/tv/', '/tv/') + 'embed/';
                const response = await axios.get(embedUrl, { headers });
                videoData = await parseEmbedData(response.data);
            } catch (error) {
                console.log('Method 3 failed:', error.message);
            }
        }

        return videoData;

    } catch (error) {
        console.error('Error extracting Instagram data:', error.message);
        return null;
    }
}

// Parse GraphQL data
function parseGraphqlData(graphql) {
    try {
        const media = graphql.shortcode_media;
        
        if (!media) return null;

        const videoUrls = [];
        
        if (media.video_url) {
            videoUrls.push({
                url: media.video_url,
                quality: 'HD',
                type: 'video/mp4'
            });
        }

        if (media.display_resources) {
            media.display_resources.forEach(resource => {
                if (resource.src && !videoUrls.find(v => v.url === resource.src)) {
                    videoUrls.push({
                        url: resource.src,
                        quality: `${resource.config_width}x${resource.config_height}`,
                        type: 'video/mp4'
                    });
                }
            });
        }

        return {
            title: media.edge_media_to_caption?.edges[0]?.node?.text?.substring(0, 50) + '...' || 'Instagram Video',
            description: media.edge_media_to_caption?.edges[0]?.node?.text || 'Downloaded from Instagram',
            thumbnail: media.display_url || media.thumbnail_src,
            duration: media.video_duration || 0,
            downloadUrls: videoUrls.length > 0 ? videoUrls : [{
                url: media.display_url,
                quality: 'Image',
                type: 'image/jpeg'
            }]
        };

    } catch (error) {
        console.error('Error parsing GraphQL data:', error.message);
        return null;
    }
}

// Parse HTML data
async function parseHtmlData(html) {
    try {
        const $ = cheerio.load(html);
        const scripts = $('script[type="text/javascript"]');
        
        let videoData = null;

        scripts.each((i, script) => {
            const content = $(script).html();
            
            if (content && content.includes('window._sharedData')) {
                try {
                    const dataMatch = content.match(/window\._sharedData\s*=\s*({.+?});/);
                    if (dataMatch) {
                        const sharedData = JSON.parse(dataMatch[1]);
                        const media = Object.values(sharedData.entry_data?.PostPage?.[0]?.graphql?.shortcode_media || {})[0];
                        
                        if (media) {
                            videoData = parseGraphqlData({ shortcode_media: media });
                        }
                    }
                } catch (error) {
                    console.log('Error parsing shared data:', error.message);
                }
            }

            if (content && content.includes('video_url')) {
                try {
                    const videoUrlMatch = content.match(/"video_url":"([^"]+)"/);
                    const thumbnailMatch = content.match(/"display_url":"([^"]+)"/);
                    
                    if (videoUrlMatch) {
                        videoData = {
                            title: 'Instagram Video',
                            description: 'Downloaded from Instagram',
                            thumbnail: thumbnailMatch ? thumbnailMatch[1].replace(/\\u0026/g, '&') : '',
                            downloadUrls: [{
                                url: videoUrlMatch[1].replace(/\\u0026/g, '&'),
                                quality: 'HD',
                                type: 'video/mp4'
                            }]
                        };
                    }
                } catch (error) {
                    console.log('Error parsing video URL:', error.message);
                }
            }
        });

        return videoData;

    } catch (error) {
        console.error('Error parsing HTML data:', error.message);
        return null;
    }
}

// Parse embed data
async function parseEmbedData(html) {
    try {
        const $ = cheerio.load(html);
        const videoElement = $('video');
        
        if (videoElement.length > 0) {
            const videoUrl = videoElement.attr('src');
            const posterUrl = videoElement.attr('poster');
            
            if (videoUrl) {
                return {
                    title: 'Instagram Video',
                    description: 'Downloaded from Instagram',
                    thumbnail: posterUrl || '',
                    downloadUrls: [{
                        url: videoUrl,
                        quality: 'HD',
                        type: 'video/mp4'
                    }]
                };
            }
        }

        return null;

    } catch (error) {
        console.error('Error parsing embed data:', error.message);
        return null;
    }
}

// Health check endpoint
app.get('/health', (req, res) => {
    res.json({ status: 'OK', timestamp: new Date().toISOString() });
});

// Error handling middleware
app.use((error, req, res, next) => {
    console.error('Unhandled error:', error);
    res.status(500).json({
        error: 'Internal server error'
    });
});

// 404 handler
app.use((req, res) => {
    res.status(404).json({
        error: 'Endpoint not found'
    });
});

// Start server
app.listen(PORT, () => {
    console.log(`\n🚀 Instagram Downloader Server Running!`);
    console.log(`📍 Local: http://localhost:${PORT}`);
    console.log(`📋 API: http://localhost:${PORT}/api/download`);
    console.log(`💻 Frontend: http://localhost:${PORT}`);
    console.log(`\n⚡ Ready to download Instagram videos!\n`);
});

// Handle graceful shutdown
process.on('SIGTERM', () => {
    console.log('SIGTERM received, shutting down gracefully');
    process.exit(0);
});

process.on('SIGINT', () => {
    console.log('SIGINT received, shutting down gracefully');
    process.exit(0);
});