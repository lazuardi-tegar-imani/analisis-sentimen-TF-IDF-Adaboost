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
            
            $csv = array_map('str_getcsv', file($path));
            $header = array_shift($csv); // Ambil header
            
            // Cari index kolom teks
            $textColIndex = -1;
            $possibleColumns = ['text', 'content', 'tweet', 'ulasan', 'komentar', 'caption'];
            
            foreach ($header as $index => $colName) {
                if (in_array(strtolower(trim($colName)), $possibleColumns)) {
                    $textColIndex = $index;
                    break;
                }
            }

            $texts = [];
            
            foreach ($csv as $row) {
                // Jika kolom teks ditemukan, pakai index itu. Jika tidak, coba kolom pertama atau gabungkan semua
                if ($textColIndex !== -1 && isset($row[$textColIndex])) {
                    $text = $row[$textColIndex];
                } else {
                    // Fallback: Coba kolom pertama jika ada
                    $text = $row[0] ?? ''; 
                    
                    // Fallback 2: Jika kolom pertama angka (seperti ID), cari kolom terpanjang yang mungkin teks
                    if (is_numeric($text) && count($row) > 1) {
                         // Cari kolom dengan string terpanjang
                         $longest = '';
                         foreach ($row as $col) {
                             if (strlen($col) > strlen($longest)) {
                                 $longest = $col;
                             }
                         }
                         $text = $longest;
                    }
                }
                
                // Filter hanya yang mengandung "Coretax" (case-insensitive)
                if (stripos($text, 'coretax') !== false) {
                    $texts[] = $text;
                }
            }

            if (empty($texts)) {
                return redirect()->route('sentimen.index')
                    ->with('error', 'Tidak ada data yang mengandung kata kunci "Coretax"');
            }

            // Kirim ke ML API untuk prediksi batch
            $response = Http::timeout(300)->post($this->mlApiUrl . '/predict_batch', [
                'texts' => $texts
            ]);

            if ($response->successful()) {
                $predictions = $response->json()['results'];
                
                $results = [];
                foreach ($predictions as $index => $prediction) {
                    $sentiment = $prediction['sentiment'];
                    $confidence = $this->getMaxProbability($prediction['probabilities']);

                    // Optimasi Threshold Probabilitas (Bab 4.7)
                    // Jika kelas Positif diprediksi dengan confidence rendah (50-60%), 
                    // kita tetap tandai sebagai hasil valid yang dipercaya.
                    $isReliable = true;
                    if ($sentiment === 'positif' && $confidence < 60) {
                        // Logika khusus: Tandai atau beri catatan jika perlu
                        // Di sini kita pastikan data tidak 'dibuang' oleh filter sistem kedepan
                        Log::info("Optimasi Bab 4.7: Prediksi positif diterima dengan confidence: " . $confidence . "%");
                    }

                    $results[] = [
                        'source' => 'CSV Upload',
                        'text' => $prediction['original_text'],
                        'sentiment' => ucfirst($sentiment),
                        'confidence' => $confidence,
                        'is_reliable' => $isReliable
                    ];
                }

                return redirect()->route('sentimen.index')
                    ->with('results', $results)
                    ->with('success', 'Berhasil menganalisis ' . count($results) . ' data dari CSV');
            } else {
                throw new \Exception('ML API Error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('CSV Analysis Error: ' . $e->getMessage());
            return redirect()->route('sentimen.index')
                ->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
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
                    'text' => $extractedText,
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

                // Bersihkan whitespace berlebih
                $text = preg_replace('/\s+/', ' ', trim($text));

                // Hapus bagian "— Author (@handle) Date" di akhir
                $text = preg_replace('/\s*—\s*[^—]+$/', '', $text);

                if (!empty(trim($text))) {
                    return trim($text);
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
                        return $combined;
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

            // Prioritas: og:description > description > og:title > title
            // Gabungkan yang unik untuk mendapat konteks lebih kaya
            $unique = array_unique(array_filter($texts));

            return implode('. ', $unique);

        } catch (\Exception $e) {
            Log::warning('Meta extraction failed for ' . $url . ': ' . $e->getMessage());
            return '';
        }
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