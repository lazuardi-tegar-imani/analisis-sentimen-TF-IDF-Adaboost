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
    <div class="col-md-4">
        <form action="{{ route('sentimen.csv') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="card card-custom text-center p-3">
                <h6><i class="bi bi-file-earmark-spreadsheet"></i> Unggah Dataset (CSV)</h6>
                <input type="file" name="dataset" class="form-control mb-2" required accept=".csv">
                <small class="text-muted d-block mt-2">File harus .csv (max 10MB)</small>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-upload"></i> Proses
                </button>
            </div>
        </form>
    </div>

    <!-- ================= SINGLE ULASAN ================= -->
    <div class="col-md-4">
        <form action="{{ route('sentimen.single') }}" method="POST">
            @csrf
            <div class="card card-custom text-center p-3">
                <h6><i class="bi bi-chat-text"></i> Masukkan Single Ulasan</h6>
                <textarea name="ulasan" class="form-control mb-2" rows="3" placeholder="Contoh: Coretax sangat membantu"
                    required></textarea>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-search"></i> Analisis
                </button>
            </div>
        </form>
    </div>

    <!-- ================= LINK POSTINGAN ================= -->
    <div class="col-md-4">
        <form action="{{ route('sentimen.link') }}" method="POST">
            @csrf
            <div class="card card-custom text-center p-3">
                <h6><i class="bi bi-link-45deg"></i> Masukkan Link Postingan</h6>
                <input type="url" name="url" class="form-control mb-2" placeholder="https://twitter.com/... atau https://reddit.com/..." required>
                <small class="text-muted d-block">Twitter/X, Instagram, Reddit, dll</small>
                <button type="submit" class="btn btn-custom btn-sm w-100 mt-2">
                    <i class="bi bi-download"></i> Scrape & Analisis
                </button>
            </div>
        </form>
    </div>

</div>

<div class="mb-3">
    <small class="text-muted">
        <i class="bi bi-shield-check"></i> 
        Fitur URL hanya mengekstrak metadata publik (SEO). Data tidak disimpan permanen.
    </small>
</div>

<h6 class="fw-bold"><i class="bi bi-graph-up"></i> Hasil Analisis:</h6>

<div class="card card-custom p-3">
    <table class="table table-bordered text-center table-hover">
        <thead class="table-light">
            <tr>
                <th width="5%">No</th>
                <th width="15%">Sumber Data</th>
                <th width="50%">Komentar Pengguna</th>
                <th width="15%">Sentimen</th>
                <th width="15%">Confidence</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data ?? [] as $d)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td><small>{{ $d['source'] ?? 'Manual' }}</small></td>
                <td class="text-start">{{ $d['text'] }}</td>
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
                    <i class="bi bi-inbox"></i> Belum ada data. Silakan upload CSV atau input ulasan.
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

@endsection