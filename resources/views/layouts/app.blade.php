<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Analisis Sentimen Coretax</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
    body {
        background-color: #f4f6f9;
        font-family: 'Segoe UI', sans-serif;
    }

    /* HEADER FULL WIDTH */
    .header {
        width: 100%;
        background: linear-gradient(90deg, #6aa9e9, #9bbce3);
        padding: 20px 0;
        color: white;
        font-weight: 600;
        text-align: center;
        letter-spacing: 1px;
        font-size: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    /* CONTAINER KONTEN */
    .container {
        max-width: 1200px;
    }

    .card-custom {
        border-radius: 16px;
        border: none;
        background: #ffffff;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        transition: 0.3s;
    }

    .card-custom:hover {
        transform: translateY(-3px);
    }

    .btn-custom {
        background: linear-gradient(90deg, #3aa0ff, #17ead9);
        color: #fff;
        border-radius: 12px;
        font-weight: 500;
        padding: 8px;
        border: none;
    }

    .btn-custom:hover {
        opacity: 0.9;
    }

    input.form-control {
        border-radius: 10px;
        font-size: 14px;
    }

    table th {
        font-weight: 600;
        background-color: #f1f3f6;
    }
    </style>
</head>

<body>

    <!-- HEADER FULL WIDTH -->
    <div class="header">
        ANALISIS SENTIMEN CORETAX
    </div>

    <!-- KONTEN -->
    <div class="container mt-4">
        @yield('content')
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    @yield('scripts')
</body>

</html>