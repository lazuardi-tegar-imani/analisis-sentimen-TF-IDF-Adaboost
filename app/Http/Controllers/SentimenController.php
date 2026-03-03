<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SentimenController extends Controller
{
    // URL Python ML API
    private $mlApiUrl = 'http://127.0.0.1:5000';

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
    public function link(Request $request)
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        $url = $request->url;

        try {
            // 1. Deteksi platform
            $platform = $this->detectPlatform($url);

            // 2. Ekstrak teks berdasarkan platform
            $extractedText = $this->extractText($url, $platform);

            if (empty(trim($extractedText))) {
                return redirect()->route('sentimen.index')
                    ->with('error', 'Tidak dapat mengekstrak teks dari URL tersebut. Pastikan URL valid dan dapat diakses.');
            }

            // 3. Filter: harus mengandung "Coretax"
            if (stripos($extractedText, 'coretax') === false) {
                return redirect()->route('sentimen.index')
                    ->with('error', 'Konten dari URL tidak mengandung kata kunci "Coretax". Teks yang ditemukan: "' . mb_substr($extractedText, 0, 100) . '..."');
            }

            // 4. Kirim ke ML API untuk prediksi
            $response = Http::timeout(30)->post($this->mlApiUrl . '/predict', [
                'text' => $extractedText
            ]);

            if ($response->successful()) {
                $prediction = $response->json();
                $sentiment = $prediction['sentiment'];
                $confidence = $this->getMaxProbability($prediction['probabilities']);

                // Optimasi Threshold Probabilitas (Bab 4.7)
                if ($sentiment === 'positif' && $confidence < 60) {
                    Log::info("Optimasi Bab 4.7: Prediksi positif (link) diterima dengan confidence: " . $confidence . "%");
                }

                $results = [[
                    'source' => $platform . ': ' . $this->shortenUrl($url),
                    'text' => $this->cleanExtractedContent($extractedText),
                    'sentiment' => ucfirst($sentiment),
                    'confidence' => $confidence,
                    'is_reliable' => true
                ]];

                return redirect()->route('sentimen.index')
                    ->with('results', $results)
                    ->with('success', 'Analisis sentimen dari ' . $platform . ' berhasil. (Data tidak disimpan)');
            } else {
                throw new \Exception('ML API Error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Link Analysis Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')
                ->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    // ============================================================
    //  HELPER METHODS — URL Extraction
    // ============================================================

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
            default:
                // Instagram, Shopee, Website umum → meta tags
                return $this->extractFromMeta($url);
        }
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
     */
    private function extractFromReddit($url)
    {
        try {
            // Reddit public JSON API
            $jsonUrl = rtrim($url, '/') . '.json';

            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'SentimenAnalysis/1.0 (Educational Project)'])
                ->get($jsonUrl);

            if ($response->successful()) {
                $data = $response->json();

                // Struktur Reddit JSON: array[0] = post, array[1] = comments
                if (isset($data[0]['data']['children'][0]['data'])) {
                    $post = $data[0]['data']['children'][0]['data'];
                    $title = $post['title'] ?? '';
                    $selftext = $post['selftext'] ?? '';

                    $combined = trim($title . '. ' . $selftext);
                    if (!empty($combined) && $combined !== '.') {
                        return $this->cleanExtractedContent($combined);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Reddit JSON API failed: ' . $e->getMessage());
        }

        // Fallback ke meta tags
        return $this->extractFromMeta($url);
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