<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Aprovado - Nexa Platform</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #E91E63;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #E91E63;
            margin-bottom: 10px;
        }
        .status {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            display: inline-block;
            font-weight: bold;
        }
        .content {
            margin: 30px 0;
        }
        .info-box {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #E91E63;
        }
        .button {
            display: inline-block;
            background-color: #E91E63;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">NEXA</div>
            <div class="status">✅ MATERIAL APROVADO</div>
        </div>

        <div class="content">
            <h2>Parabéns, {{ $creator->name }}! 🎉</h2>

            <p>Seu material foi <strong>aprovado</strong> pela marca!</p>

            <div class="info-box">
                <h3>📋 Detalhes do Material</h3>
                <p><strong>Arquivo:</strong> {{ $material->file_name }}</p>
                <p><strong>Contrato:</strong> {{ $contract->title }}</p>
                <p><strong>Marca:</strong> {{ $brand->name }}</p>
                <p><strong>Data de Aprovação:</strong> {{ $material->reviewed_at->format('d/m/Y H:i') }}</p>

                @if($material->comment)
                <p><strong>Comentário da Marca:</strong></p>
                <p style="font-style: italic; color: #666;">"{{ $material->comment }}"</p>
                @endif
            </div>

            <p>Continue enviando materiais de qualidade para manter o excelente trabalho!</p>

            <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/creator/contracts" class="button">
                Ver Contratos
            </a>
        </div>

        <div class="footer">
            <p>Este é um email automático da plataforma Nexa.</p>
            <p>Se você tiver alguma dúvida, entre em contato conosco.</p>
        </div>
    </div>
</body>
</html>