@extends('layouts.app')

@section('content')

<h5 class="text-primary fw-bold">Input Data Analisis Sentimen</h5>
<p class="text-muted">
    Sebagai catatan, sistem hanya memproses data yang mengandung kata kunci <b>Coretax</b>
</p>

<!-- Alert Messages -->
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<!-- Model Info Badge -->
<div class="mb-3">
    <span class="badge bg-info">
        <i class="bi bi-cpu"></i> Algoritma: Decision Tree + AdaBoost
    </span>
</div>

<div class="row mb-4">
    <!-- ================= UPLOAD CSV ================= -->
    <div class="col-md-6 mb-4">
        <form action="{{ route('sentimen.csv') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="card card-custom text-center p-3 h-100">
                <h6><i class="bi bi-file-earmark-spreadsheet"></i> Unggah Dataset (CSV)</h6>
                <input type="file" name="dataset" class="form-control mb-2" required accept=".csv">
                <small class="text-muted d-block mt-2">File harus .csv (max 10MB)</small>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-upload"></i> Proses Dataset
                </button>
            </div>
        </form>
    </div>

    <!-- ================= SINGLE ULASAN ================= -->
    <div class="col-md-6 mb-4">
        <form action="{{ route('sentimen.single') }}" method="POST">
            @csrf
            <div class="card card-custom text-center p-3 h-100">
                <h6><i class="bi bi-chat-text"></i> Masukkan Single Ulasan</h6>
                <textarea name="ulasan" class="form-control mb-2" rows="3" placeholder="Contoh: Coretax sangat membantu"
                    required></textarea>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-search"></i> Analisis Teks
                </button>
            </div>
        </form>
    </div>

    <!-- ================= LINK POSTINGAN (URL) ================= -->
    <div class="col-md-6 mb-4">
        <form action="{{ route('sentimen.link') }}" method="POST">
            @csrf
            <div class="card card-custom text-center p-3 h-100">
                <h6><i class="bi bi-link-45deg"></i> Link URL (Postingan Utama)</h6>
                <input type="url" name="url" class="form-control mb-2" 
                    placeholder="Contoh: https://www.youtube.com/watch?v=..." required>
                <small class="text-muted d-block mt-2">Menganalisis konten + metadata (penulis, tanggal)</small>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-download"></i> Scrape Dari Link
                </button>
            </div>
        </form>
    </div>

    <!-- ================= SCRAPE BY PLATFORM ================= -->
    <div class="col-md-6 mb-4">
        <form action="{{ route('sentimen.scrape') }}" method="POST">
            @csrf
            <div class="card card-custom text-center p-3 h-100">
                <h6><i class="bi bi-search-heart"></i> Crawling Otomatis (Keyword: Coretax)</h6>
                <select name="platform" class="form-select mb-2" required>
                    <option value="YouTube">YouTube (Cari 50 Video Terbaru)</option>
                    <option value="Twitter" disabled>Twitter/X (API Berbayar)</option>
                    <option value="Instagram" disabled>Instagram (API Business)</option>
                </select>
                <small class="text-muted d-block mt-2">Scraping massal berdasarkan kata kunci</small>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-cpu"></i> Mulai Crawling
                </button>
            </div>
        </form>
    </div>
</div>

<div class="mb-3">
    <small class="text-muted">
        <i class="bi bi-info-circle-fill"></i> 
        Fitur URL: YouTube & Reddit mendukung analisis postingan & 50 komentar teratas. Platform lain terbatas pada postingan saja.
    </small>
</div>

<h6 class="fw-bold"><i class="bi bi-graph-up"></i> Hasil Analisis:</h6>

<div class="card card-custom p-3">
    <table class="table table-bordered text-center table-hover">
        <thead class="table-light">
            <tr>
                <th width="5%">No</th>
                <th width="20%">Sumber Data</th>
                <th width="45%">Teks Postingan / Komentar</th>
                <th width="15%">Sentimen</th>
                <th width="15%">Confidence</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data ?? [] as $d)
            <tr @if($d['is_post'] ?? false) class="table-primary" @endif>
                <td>{{ $loop->iteration }}</td>
                <td>
                    <small @if($d['is_post'] ?? false) class="fw-bold" @endif>
                        {{ $d['source'] ?? 'Manual' }}
                    </small>
                </td>
                <td class="text-start">
                    @if($d['is_post'] ?? false) 
                        @if(isset($d['author']))
                            <div class="fw-bold text-primary mb-1">
                                <i class="bi bi-person-circle"></i> {{ $d['author'] }} 
                                <span class="text-muted fw-normal ms-2">| <i class="bi bi-calendar3"></i> {{ $d['date'] ?? date('d/m/Y') }}</span>
                            </div>
                        @else
                            <div class="fw-bold text-primary mb-1">
                                <i class="bi bi-pin-angle-fill"></i> Post Utama
                            </div>
                        @endif
                    @endif
                    {{ $d['text'] }}
                </td>

                <td>
                    <span class="badge 
                        @if(strtolower($d['sentiment']) == 'positif') bg-success
                        @elseif(strtolower($d['sentiment']) == 'negatif') bg-danger
                        @else bg-secondary
                        @endif">
                        {{ $d['sentiment'] }}
                    </span>
                </td>
                <td>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar 
                            @if(strtolower($d['sentiment']) == 'positif') bg-success
                            @elseif(strtolower($d['sentiment']) == 'negatif') bg-danger
                            @else bg-secondary
                            @endif" role="progressbar" style="width: {{ $d['confidence'] ?? 0 }}%"
                            aria-valuenow="{{ $d['confidence'] ?? 0 }}" aria-valuemin="0" aria-valuemax="100">
                            {{ number_format($d['confidence'] ?? 0, 1) }}%
                        </div>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-muted">
                    <i class="bi bi-inbox"></i> Belum ada data. Silakan upload CSV, input manual, atau analisis link.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if(isset($data) && count($data) > 0)
<div class="mt-3">
    <div class="row">
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h3>{{ collect($data)->where('sentiment', 'Positif')->count() }}</h3>
                    <p class="mb-0">Positif</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h3>{{ collect($data)->where('sentiment', 'Negatif')->count() }}</h3>
                    <p class="mb-0">Negatif</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-secondary text-white">
                <div class="card-body text-center">
                    <h3>{{ collect($data)->where('sentiment', 'Netral')->count() }}</h3>
                    <p class="mb-0">Netral</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@section('scripts')
<script>
document.getElementById('form-link').addEventListener('submit', async function(e) {
    const urlInput = this.querySelector('input[name="url"]');
    const url = urlInput.value;

    // Hanya gunakan client-side fetch untuk Reddit karena blokir ISP
    if (url.includes('reddit.com')) {
        // Jika browser tidak punya VPN/akses, biarkan fallback ke server
        e.preventDefault();
        
        const btn = this.querySelector('button');
        const originalHtml = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scraping (VPN)...';

        try {
            // Bersihkan URL & tambahkan .json
            let cleanUrl = url.split('?')[0].replace(/\/$/, '') + '.json';
            
            // Menggunakan CORS Proxy publik untuk mengambil data JSON Reddit
            // Ini akan berhasil jika browser user menggunakan VPN/bebas blokir
            const proxyUrl = 'https://api.allorigins.win/raw?url=' + encodeURIComponent(cleanUrl);
            
            const response = await fetch(proxyUrl);
            if (!response.ok) throw new Error('Proxy error');
            
            const data = await response.json();

            // Kirim data yang didapat ke backend untuk dianalisis ML
            const hiddenForm = document.createElement('form');
            hiddenForm.method = 'POST';
            hiddenForm.action = '{{ route("sentimen.link_data") }}';
            
            const fields = {
                '_token': '{{ csrf_token() }}',
                'url': url,
                'platform': 'Reddit',
                'reddit_data': JSON.stringify(data)
            };

            for (const name in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = fields[name];
                hiddenForm.appendChild(input);
            }
            
            document.body.appendChild(hiddenForm);
            hiddenForm.submit();

        } catch (err) {
            console.error('Client-side fetch failed, falling back to server:', err);
            // Kembalikan tombol dan submit manual (server-side fallback)
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            this.submit();
        }
    }
});
</script>
@endsection
@endsection