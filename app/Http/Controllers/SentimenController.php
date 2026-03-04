<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SentimenController extends Controller
{
    // URL Python ML API
    private $mlApiUrl = 'http://127.0.0.1:5000';
    
    // YouTube API Key (Optional, untuk ambil komentar)
    private $youtubeApiKey;

    public function __construct()
    {
        $this->youtubeApiKey = env('YOUTUBE_API_KEY');
    }

    /**
     * Menampilkan halaman utama
     */
    public function index()
    {
        $data = session('results', []);
        return view('sentimen.index', compact('data'));
    }

    /**
     * Proses upload CSV
     */
    public function csv(Request $request)
    {
        $request->validate([
            'dataset' => 'required|file|mimes:csv,txt|max:10240'
        ]);

        try {
            $file = $request->file('dataset');
            $path = $file->getRealPath();
            
            // Membaca file CSV
            $csvData = array_map('str_getcsv', file($path));
            if (empty($csvData)) {
                return redirect()->route('sentimen.index')->with('error', 'File CSV kosong.');
            }

            $header = array_shift($csvData);
            
            // Deteksi Kolom Teks (Twitter/Ulasan) secara fleksibel
            $possibleColumns = ['text', 'content', 'tweet', 'ulasan', 'komentar', 'caption', 'cuitan', 'isi'];
            $textColIndex = -1;
            $hasHeader = false;

            foreach ($header as $index => $colName) {
                $cleanCol = strtolower(trim($colName, " \t\n\r\0\x0B\"'"));
                if (in_array($cleanCol, $possibleColumns)) {
                    $textColIndex = $index;
                    $hasHeader = true;
                    break;
                }
            }

            // Jika baris pertama bukan header (tidak cocok dng possibleColumns), kembalikan ke data
            if (!$hasHeader) {
                array_unshift($csvData, $header);
            }

            $texts = [];
            foreach ($csvData as $row) {
                if (empty(array_filter($row))) continue; // Lewati baris kosong

                $text = '';
                if ($textColIndex !== -1 && isset($row[$textColIndex])) {
                    $text = $row[$textColIndex];
                } else if (count($row) == 1) {
                    $text = $row[0];
                } else {
                    // Cari kolom dengan string terpanjang (asumsi itu teks ulasan)
                    $longest = '';
                    foreach ($row as $col) {
                        if (strlen((string)$col) > strlen($longest)) {
                            $longest = (string)$col;
                        }
                    }
                    $text = $longest;
                }

                $text = trim($text);
                
                // Filter ketat: Harus mengandung kata "Coretax"
                if (!empty($text) && stripos($text, 'coretax') !== false) {
                    $texts[] = $text;
                }
            }

            if (empty($texts)) {
                return redirect()->route('sentimen.index')
                    ->with('error', 'Tidak ada ulasan dalam file yang mengandung kata kunci "Coretax"');
            }

            // Kirim ke ML API untuk prediksi batch
            $response = Http::timeout(300)->post($this->mlApiUrl . '/predict_batch', [
                'texts' => $texts
            ]);

            if ($response->successful()) {
                $predictions = $response->json()['results'];
                $results = [];

                foreach ($predictions as $prediction) {
                    $results[] = [
                        'source' => 'CSV Upload',
                        'text' => $prediction['original_text'],
                        'sentiment' => ucfirst($prediction['sentiment']),
                        'confidence' => $this->getMaxProbability($prediction['probabilities']),
                        'is_reliable' => true
                    ];
                }

                return redirect()->route('sentimen.index')
                    ->with('results', $results)
                    ->with('success', 'Berhasil menganalisis ' . count($results) . ' ulasan dari file CSV.');
            } else {
                throw new \Exception('Gagal menghubungi ML API: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('CSV Analysis Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')
                ->with('error', 'Terjadi kesalahan saat memproses CSV: ' . $e->getMessage());
        }
    }

    /**
     * Proses single ulasan
     */
    public function single(Request $request)
    {
        $request->validate([
            'ulasan' => 'required|string|max:1000'
        ]);

        $text = $request->ulasan;
        
        // Filter hanya yang mengandung "Coretax"
        if (stripos($text, 'coretax') === false) {
            return redirect()->route('sentimen.index')
                ->with('error', 'Ulasan harus mengandung kata kunci "Coretax"');
        }

        try {
            // Kirim ke ML API
            $response = Http::timeout(30)->post($this->mlApiUrl . '/predict', [
                'text' => $text
            ]);

            if ($response->successful()) {
                $prediction = $response->json();
                $sentiment = $prediction['sentiment'];
                $confidence = $this->getMaxProbability($prediction['probabilities']);

                // Optimasi Threshold Probabilitas (Bab 4.7)
                if ($sentiment === 'positif' && $confidence < 60) {
                    Log::info("Optimasi Bab 4.7: Prediksi positif (single) diterima dengan confidence: " . $confidence . "%");
                }

                $results = [[
                    'source' => 'Input Manual',
                    'text' => $text,
                    'sentiment' => ucfirst($sentiment),
                    'confidence' => $confidence,
                    'is_reliable' => true
                ]];

                return redirect()->route('sentimen.index')
                    ->with('results', $results)
                    ->with('success', 'Analisis sentimen berhasil');
            } else {
                throw new \Exception('ML API Error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Single Analysis Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')
                ->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    /**
     * Proses link postingan — Multi-platform URL extraction
     * Mengekstrak teks publik (meta tags / public API) dari URL yang diberikan
     */
    /**
     * Endpoint baru: Menerima data Reddit yang sudah di-fetch oleh browser.
     * Digunakan untuk bypass blokir ISP Indonesia via client-side fetch (VPN browser).
     */
    public function linkWithData(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'platform' => 'required|string',
            'reddit_data' => 'required'
        ]);

        try {
            $url = $request->url;
            $platform = $request->platform;
            $rawData = is_array($request->reddit_data) ? $request->reddit_data : json_decode($request->reddit_data, true);

            if (!$rawData) {
                throw new \Exception('Data Reddit tidak valid.');
            }

            // Parse data JSON yang dikirim browser
            $extractedData = $this->parseRedditData($rawData);
            $postText = $extractedData['post'];
            $comments = $extractedData['comments'] ?? [];

            // Lanjut ke analisis batch (sama dengan alur link biasa)
            return $this->processLinkAnalysisBatch($url, $platform, $postText, $comments);

        } catch (\Exception $e) {
            Log::error('Link Data Analysis Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')
                ->with('error', 'Gagal memproses data dari browser: ' . $e->getMessage());
        }
    }

    /**
     * Helper: Alur analisis batch untuk Link (Post + Komentar)
     */
    private function processLinkAnalysisBatch($url, $platform, $postText, $comments)
    {
        if (empty(trim($postText))) {
            if ($url) {
                throw new \Exception('Gagal mengekstrak teks postingan.');
            }
            return null; // Skip if no text during search
        }

        // Filter: Postingan utama harus mengandung "Coretax"
        if (stripos($postText, 'coretax') === false) {
             return redirect()->route('sentimen.index')
                ->with('error', 'Konten postingan tidak mengandung kata kunci "Coretax".');
        }

        // Siapkan batch
        $batchTexts = [];
        $batchTexts[] = ['text' => $postText, 'type' => 'Postingan'];
        foreach ($comments as $index => $comment) {
            if ($index >= 50) break;
            $batchTexts[] = ['text' => $comment, 'type' => 'Komentar #' . ($index + 1)];
        }

        // Prediksi
        $response = Http::timeout(60)->post($this->mlApiUrl . '/predict_batch', [
            'texts' => array_column($batchTexts, 'text')
        ]);

        if ($response->successful()) {
            $predictions = $response->json()['results'];
            $results = [];

            foreach ($predictions as $i => $prediction) {
                $type = $batchTexts[$i]['type'];
                $sentiment = $prediction['sentiment'];
                $confidence = $this->getMaxProbability($prediction['probabilities']);

                $results[] = [
                    'source' => $type . ' (' . $platform . ')',
                    'text' => $i === 0 ? $this->cleanExtractedContent($postText) : $prediction['original_text'],
                    'sentiment' => ucfirst($sentiment),
                    'confidence' => $confidence,
                    'is_post' => ($i === 0)
                ];
            }

            return redirect()->route('sentimen.index')
                ->with('results', $results)
                ->with('success', 'Analisis berhasil: 1 Postingan' . (count($results) > 1 ? ' dan ' . (count($results) - 1) . ' komentar.' : ''));
        }

        throw new \Exception('ML API Error: ' . $response->body());
    }

    public function link(Request $request)
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        $url = $request->url;

        try {
            $platform = $this->detectPlatform($url);
            
            // Ekstrak Teks + Metadata (Penulis, Tanggal)
            $extracted = $this->extractPostWithMetadata($url, $platform);
            $postText = $extracted['text'];
            $metadata = $extracted['metadata'];

            if (empty(trim($postText))) {
                throw new \Exception('Gagal mengekstrak konten dari URL tersebut.');
            }

            // Filter: Harus mengandung "Coretax"
            if (stripos($postText, 'coretax') === false) {
                 return redirect()->route('sentimen.index')
                    ->with('error', 'Konten postingan tidak mengandung kata kunci "Coretax".');
            }

            // Kirim ke ML API
            $response = Http::timeout(30)->post($this->mlApiUrl . '/predict', [
                'text' => $postText
            ]);

            if ($response->successful()) {
                $prediction = $response->json();
                
                $results = [[
                    'source' => $platform,
                    'author' => $metadata['author'] ?? 'Anonim',
                    'date' => $metadata['date'] ?? date('d/m/Y'),
                    'text' => $this->cleanExtractedContent($postText),
                    'sentiment' => ucfirst($prediction['sentiment']),
                    'confidence' => $this->getMaxProbability($prediction['probabilities']),
                    'is_post' => true
                ]];

                return redirect()->route('sentimen.index')
                    ->with('results', $results)
                    ->with('success', 'Analisis postingan berhasil.');
            }

            throw new \Exception('ML API Error: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Link Analysis Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    /**
     * Scraping otomatis berdasarkan platform dengan keyword "Coretax"
     */
    public function scrapeByPlatform(Request $request)
    {
        $platform = $request->platform;
        $keyword = 'coretax';

        try {
            if ($platform === 'YouTube') {
                $results = $this->searchYouTube($keyword, 50);
                
                if (empty($results)) {
                    return redirect()->route('sentimen.index')
                        ->with('error', 'Tidak ditemukan konten YouTube terbaru mengenai "' . $keyword . '".');
                }

                return redirect()->route('sentimen.index')
                    ->with('results', $results)
                    ->with('success', 'Berhasil melakukan crawling ' . count($results) . ' konten dari ' . $platform);
            }

            return redirect()->route('sentimen.index')
                ->with('error', 'Platform ' . $platform . ' belum didukung untuk pencarian otomatis.');

        } catch (\Exception $e) {
            Log::error('Search Scraping Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')
                ->with('error', 'Terjadi kesalahan saat mencari konten: ' . $e->getMessage());
        }
    }

    /**
     * Ekstrak postingan dan komentar dari URL
     */
    private function extractPostAndComments($url, $platform)
    {
        switch ($platform) {
            case 'Reddit':
                $data = $this->extractFromRedditWithComments($url);
                // Fallback: Jika JSON API gagal, coba Meta Tags (Dapat post tapi tanpa komentar)
                if (empty(trim($data['post']))) {
                    Log::info("Reddit JSON failed, falling back to Meta for: " . $url);
                    return ['post' => $this->extractFromMeta($url), 'comments' => []];
                }
                return $data;
            case 'YouTube':
                $data = $this->extractFromYouTube($url);
                // Fallback: Jika Invidious/OEmbed gagal total, coba Meta Tags
                if (empty(trim($data['post']))) {
                    Log::info("YouTube API/OEmbed failed, falling back to Meta for: " . $url);
                    return ['post' => $this->extractFromMeta($url), 'comments' => []];
                }
                return $data;
            case 'Twitter/X':
                return ['post' => $this->extractFromTwitter($url), 'comments' => []];
            default:
                return ['post' => $this->extractFromMeta($url), 'comments' => []];
        }
    }

    /**
     * Reddit: Ekstrak Post + Komentar via JSON API
     */
    private function extractFromRedditWithComments($url)
    {
        // 1. Bersihkan URL
        $cleanUrl = $this->cleanRedditUrl($url);
        $jsonUrl = rtrim($cleanUrl, '/') . '.json';
        $userAgent = 'SentimenAnalysis/1.0 (Educational Project; contact: user@example.com)';

        $fetchReddit = function($targetUrl) use ($userAgent) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => $userAgent])
                    ->get($targetUrl);
                
                if ($response->successful()) {
                    return $response->json();
                }
            } catch (\Exception $e) {
                Log::warning("Reddit Fetch Exception: " . $e->getMessage());
            }
            return null;
        };

        // Tahap 1: Direct
        $data = $fetchReddit($jsonUrl);

        // Tahap 2: Proxy
        if (!$data) {
            $proxyUrl = 'https://api.allorigins.win/raw?url=' . urlencode($jsonUrl);
            $data = $fetchReddit($proxyUrl);
        }

        if ($data) {
            return $this->parseRedditData($data);
        }

        return ['post' => '', 'comments' => []];
    }

    /**
     * Helper: Parsing struktur JSON Reddit (Post + Komentar)
     */
    private function parseRedditData($data)
    {
        $result = ['post' => '', 'comments' => []];

        // 1. Post (Index 0)
        if (isset($data[0]['data']['children'][0]['data'])) {
            $post = $data[0]['data']['children'][0]['data'];
            $result['post'] = trim(($post['title'] ?? '') . '. ' . ($post['selftext'] ?? ''));
        }

        // 2. Komentar (Index 1)
        if (isset($data[1]['data']['children'])) {
            foreach ($data[1]['data']['children'] as $child) {
                if ($child['kind'] === 't1') {
                    $commentData = $child['data'];
                    $body = $commentData['body'] ?? '';
                    if (!empty($body) && !in_array($body, ['[deleted]', '[removed]'])) {
                        $result['comments'][] = $this->cleanExtractedContent($body);
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Membersihkan URL Reddit dari query parameters agar penambahan .json tidak error
     */
    private function cleanRedditUrl($url)
    {
        $parsed = parse_url($url);
        $clean = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.reddit.com') . ($parsed['path'] ?? '');
        return rtrim($clean, '/');
    }

    /**
     * Helper: Dispatcher untuk Ekstraksi Post + Metadata
     */
    private function extractPostWithMetadata($url, $platform)
    {
        $data = ['text' => '', 'metadata' => ['author' => 'Anonim', 'date' => date('d/m/Y')]];

        switch ($platform) {
            case 'YouTube':
                $videoId = $this->extractVideoId($url);
                if ($videoId) {
                    try {
                        $oembedUrl = "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=$videoId&format=json";
                        $res = Http::timeout(10)->get($oembedUrl);
                        if ($res->successful()) {
                            $json = $res->json();
                            $data['text'] = $json['title'] ?? '';
                            $data['metadata']['author'] = $json['author_name'] ?? 'YouTube Channel';
                        }
                    } catch (\Exception $e) {}
                }
                break;

            case 'Twitter/X':
                try {
                    $oembedUrl = 'https://publish.twitter.com/oembed?url=' . urlencode($url);
                    $res = Http::timeout(10)->get($oembedUrl);
                    if ($res->successful()) {
                        $json = $res->json();
                        $data['text'] = strip_tags($json['html'] ?? '');
                        $data['metadata']['author'] = $json['author_name'] ?? 'Twitter User';
                    }
                } catch (\Exception $e) {}
                break;

            default:
                // Meta Tags
                try {
                    $res = Http::timeout(10)->get($url);
                    if ($res->successful()) {
                        $html = $res->body();
                        // Author
                        if (preg_match('/<meta\s[^>]*property=["\']og:site_name["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) $data['metadata']['author'] = $m[1];
                        // Text (Description)
                        if (preg_match('/<meta\s[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) $data['text'] = $m[1];
                        elseif (preg_match('/<meta\s[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) $data['text'] = $m[1];
                    }
                } catch (\Exception $e) {}
                break;
        }

        if (empty($data['text'])) {
            $data['text'] = $this->extractFromMeta($url);
        }

        return $data;
    }

    /**
     * Deteksi platform dari URL
     */
    private function detectPlatform($url)
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $host = preg_replace('/^www\./', '', $host);

        if (str_contains($host, 'twitter.com') || str_contains($host, 'x.com')) {
            return 'Twitter/X';
        } elseif (str_contains($host, 'instagram.com')) {
            return 'Instagram';
        } elseif (str_contains($host, 'reddit.com')) {
            return 'Reddit';
        } elseif (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
            return 'YouTube';
        } elseif (str_contains($host, 'shopee.co.id') || str_contains($host, 'shopee.com')) {
            return 'Shopee';
        } else {
            return 'Website';
        }
    }

    /**
     * Dispatcher: panggil metode ekstraksi sesuai platform
     */
    private function extractText($url, $platform)
    {
        switch ($platform) {
            case 'Twitter/X':
                return $this->extractFromTwitter($url);
            case 'Reddit':
                return $this->extractFromReddit($url);
            case 'YouTube':
                $yt = $this->extractFromYouTube($url);
                return $yt['post'];
            default:
                // Instagram, Shopee, Website umum → meta tags
                return $this->extractFromMeta($url);
        }
    }

    /**
     * YouTube: Ekstrak Info Video via OEmbed + Komentar via Data API (Jika ada Key)
     */
    private function extractFromYouTube($url)
    {
        $videoId = $this->extractVideoId($url);
        if (!$videoId) return ['post' => '', 'comments' => []];

        $result = ['post' => '', 'comments' => []];

        // 1. Ambil Judul Video via YouTube OEmbed (Gratis, tanpa key, sangat handal)
        try {
            $oembedUrl = "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=$videoId&format=json";
            $response = Http::timeout(10)->get($oembedUrl);
            if ($response->successful()) {
                $result['post'] = $response->json()['title'] ?? '';
                Log::info("YouTube Title extracted via OEmbed: " . $result['post']);
            }
        } catch (\Exception $e) {
            Log::warning("YouTube OEmbed failed: " . $e->getMessage());
        }

        // 2. Ambil Komentar (Membutuhkan API Key di .env)
        if (!empty($this->youtubeApiKey)) {
            try {
                $commentUrl = "https://www.googleapis.com/youtube/v3/commentThreads?part=snippet&videoId=$videoId&maxResults=50&order=relevance&key=" . $this->youtubeApiKey;
                $response = Http::timeout(10)->get($commentUrl);
                
                if ($response->successful()) {
                    $items = $response->json()['items'] ?? [];
                    foreach ($items as $item) {
                        $comment = $item['snippet']['topLevelComment']['snippet']['textDisplay'] ?? '';
                        if (!empty($comment)) {
                            // Bersihkan tag HTML dari YouTube (seperti <br>)
                            $cleanComment = strip_tags($comment);
                            $result['comments'][] = $this->cleanExtractedContent($cleanComment);
                        }
                    }
                    Log::info("YouTube Comments extracted: " . count($result['comments']));
                } else {
                    Log::warning("YouTube Data API Error: " . $response->body());
                }
            } catch (\Exception $e) {
                Log::warning("YouTube Data API failed: " . $e->getMessage());
            }
        } else {
            Log::info("YouTube API Key not found in .env, skipping comments.");
        }

        // Jika OEmbed gagal tapi ada API Key, deskripsi video bisa diambil via Data API sebagai cadangan
        if (empty($result['post']) && !empty($this->youtubeApiKey)) {
             try {
                $videoUrl = "https://www.googleapis.com/youtube/v3/videos?part=snippet&id=$videoId&key=" . $this->youtubeApiKey;
                $response = Http::timeout(10)->get($videoUrl);
                if ($response->successful()) {
                    $snippet = $response->json()['items'][0]['snippet'] ?? null;
                    if ($snippet) {
                        $result['post'] = trim(($snippet['title'] ?? '') . '. ' . ($snippet['description'] ?? ''));
                    }
                }
            } catch (\Exception $e) {}
        }

        return $result;
    }

    /**
     * Search YouTube videos by keyword (Needs API Key)
     */
    private function searchYouTube($keyword, $maxResults = 50)
    {
        if (empty($this->youtubeApiKey)) {
            throw new \Exception('API Key YouTube tidak ditemukan di .env. Silakan buat API Key di Google Cloud Console.');
        }

        try {
            Log::info("Starting YouTube Search for: $keyword");
            $response = Http::timeout(30)->get('https://www.googleapis.com/youtube/v3/search', [
                'part' => 'snippet',
                'q' => $keyword,
                'maxResults' => $maxResults,
                'type' => 'video',
                'order' => 'date', // Ambil yang terbaru
                'key' => $this->youtubeApiKey
            ]);

            if (!$response->successful()) {
                throw new \Exception('YouTube Search Error: ' . $response->body());
            }

            $items = $response->json()['items'] ?? [];
            $allTexts = [];

            foreach ($items as $item) {
                $title = $item['snippet']['title'] ?? '';
                $description = $item['snippet']['description'] ?? '';
                $combinedText = trim($title . '. ' . $description);
                $videoId = $item['id']['videoId'] ?? null;

                if (!empty($combinedText)) {
                    $allTexts[] = [
                        'text' => $combinedText,
                        'source' => 'YouTube Search: ' . $title,
                        'id' => $videoId
                    ];
                }
            }

            if (empty($allTexts)) return [];

            // Kirim ke ML API secara batch
            $mlResponse = Http::timeout(300)->post($this->mlApiUrl . '/predict_batch', [
                'texts' => array_column($allTexts, 'text')
            ]);

            if ($mlResponse->successful()) {
                $predictions = $mlResponse->json()['results'];
                $finalResults = [];

                foreach ($predictions as $i => $prediction) {
                     $finalResults[] = [
                        'source' => $allTexts[$i]['source'],
                        'text' => $prediction['original_text'],
                        'sentiment' => ucfirst($prediction['sentiment']),
                        'confidence' => $this->getMaxProbability($prediction['probabilities']),
                        'is_post' => true
                    ];
                }
                return $finalResults;
            }

            throw new \Exception('ML API Error: ' . $mlResponse->body());

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Helper: Extract Video ID dari berbagai format URL YouTube inkluiding Shorts
     */
    private function extractVideoId($url)
    {
        // Mendukung: watch?v=, youtu.be/, embed/, shorts/
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)|shorts)\/|.*[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i';
        
        if (preg_match($pattern, $url, $match)) {
            return $match[1];
        }
        
        return null;
    }

    /**
     * Ekstrak teks tweet via Twitter OEmbed API (publik, tanpa auth)
     * https://developer.twitter.com/en/docs/twitter-for-websites/oembed-api
     */
    private function extractFromTwitter($url)
    {
        try {
            $oembedUrl = 'https://publish.twitter.com/oembed?url=' . urlencode($url) . '&omit_script=true';

            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SentimenAnalysis/1.0)'])
                ->get($oembedUrl);

            if ($response->successful()) {
                $data = $response->json();
                $html = $data['html'] ?? '';

                // Ambil teks dari <blockquote> — isi tweet ada di dalamnya
                $text = strip_tags($html);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

                // Bersihkan whitespace berlebih
                $text = preg_replace('/\s+/', ' ', trim($text));

                // Hapus bagian "— Author (@handle) Date" di akhir
                $text = preg_replace('/\s*—\s*[^—]+$/', '', $text);

                if (!empty(trim($text))) {
                    return $this->cleanExtractedContent($text);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Twitter OEmbed failed: ' . $e->getMessage());
        }

        // Fallback ke meta tags
        return $this->extractFromMeta($url);
    }

    /**
     * Ekstrak teks post Reddit via JSON API publik (append .json ke URL)
     * Tetap dipertahankan seandainya dipanggil secara independen
     */
    private function extractFromReddit($url)
    {
        $data = $this->extractFromRedditWithComments($url);
        return $data['post'];
    }

    /**
     * Ekstrak teks dari meta tags SEO (og:description, meta description, title, og:title)
     * Digunakan untuk Instagram, Shopee, dan website umum.
     * Meta tags adalah informasi publik yang disediakan website untuk SEO.
     */
    private function extractFromMeta($url)
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SentimenAnalysis/1.0)',
                    'Accept' => 'text/html',
                ])
                ->get($url);

            if (!$response->successful()) {
                return '';
            }

            $html = $response->body();
            $texts = [];

            // Extract <title>
            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $match)) {
                $texts['title'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extract meta description (name="description" content="...")
            if (preg_match('/<meta\s[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/si', $html, $match)) {
                $texts['description'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }
            // Juga cek urutan terbalik (content="..." name="description")
            if (!isset($texts['description']) && preg_match('/<meta\s[^>]*content=["\'](.*?)["\']\s[^>]*name=["\']description["\']/si', $html, $match)) {
                $texts['description'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extract og:description
            if (preg_match('/<meta\s[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/si', $html, $match)) {
                $texts['og_description'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }
            if (!isset($texts['og_description']) && preg_match('/<meta\s[^>]*content=["\'](.*?)["\']\s[^>]*property=["\']og:description["\']/si', $html, $match)) {
                $texts['og_description'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }

            // Extract og:title
            if (preg_match('/<meta\s[^>]*property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/si', $html, $match)) {
                $texts['og_title'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }
            if (!isset($texts['og_title']) && preg_match('/<meta\s[^>]*content=["\'](.*?)["\']\s[^>]*property=["\']og:title["\']/si', $html, $match)) {
                $texts['og_title'] = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            }

            // Prioritas: og:description > description > title
            $content = '';
            if (isset($texts['og_description'])) {
                $content = $texts['og_description'];
            } elseif (isset($texts['description'])) {
                $content = $texts['description'];
            } elseif (isset($texts['og_title'])) {
                $content = $texts['og_title'];
            } elseif (isset($texts['title'])) {
                $content = $texts['title'];
            }

            return $this->cleanExtractedContent($content);

        } catch (\Exception $e) {
            Log::warning('Meta extraction failed for ' . $url . ': ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Membersihkan metadata lintas platform dari teks yang diekstrak
     */
    private function cleanExtractedContent($text)
    {
        if (empty($text)) return '';

        // 1. Hapus info "— Author (@handle) Date" (Twitter style)
        $text = preg_replace('/\s*—\s*[^—]+$/', '', $text);
        $text = preg_replace('/\s*&mdash;\s*[^&]+$/', '', $text);

        // Hapus pola "Nama () Month DD, YYYY" di akhir teks (Fallback Twitter OEmbed)
        $text = preg_replace('/\s+\w[\w\s]*\(\)\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\s*$/i', '', $text);
        
        // 2. Hapus pola tanggal (e.g., "Jan 1, 2024", "12 hours ago", "5h", "2024-03-01")
        $text = preg_replace('/\b\d{1,2}\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{4}\b/i', '', $text);
        $text = preg_replace('/\b\d{1,2}\s+(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+\d{4}\b/i', '', $text);
        $text = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', '', $text);
        $text = preg_replace('/\s*·\s*\d+[hmdw]/i', '', $text); // Twitter time suffix like "· 5h"
        
        // 3. Hapus pola engagement/statistik (e.g., "1.2k Likes", "200 Retweets", "Reply")
        $text = preg_replace('/\b\d+[,.]?\d*[KMB]?\s+(Likes|Retweets|Comments|Views|Suka|Balasan)\b/i', '', $text);
        $text = preg_replace('/\b(Retweeted|Replying to|Dibalas oleh)\b.*?:\s?/i', '', $text);
        
        // 4. Hapus username/handle (@username)
        $text = preg_replace('/@\w+/', '', $text);
        
        // 5. Hapus URL atau link promosi yang tersisa
        $text = preg_replace('/https?:\/\/\S+/', '', $text);

        // 6. Bersihkan whitespace berlebih
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    /**
     * Persingkat URL untuk tampilan di kolom "Sumber Data"
     */
    private function shortenUrl($url)
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        if (strlen($path) > 30) {
            $path = mb_substr($path, 0, 30) . '...';
        }

        return $host . $path;
    }

    /**
     * Helper: Get max probability
     */
    private function getMaxProbability($probabilities)
    {
        return max($probabilities) * 100; // Convert to percentage
    }

    /**
     * Get model info
     */
    public function modelInfo()
    {
        try {
            $response = Http::get($this->mlApiUrl . '/model_info');
            
            if ($response->successful()) {
                return response()->json($response->json());
            }
            
            return response()->json(['error' => 'Cannot connect to ML API'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}