<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustes Sugeridos na Campanha - Nexa</title>
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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
            background-color: #f59e0b;
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
            background-color: #fff7ed;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #f59e0b;
        }
        .text-box {
            background-color: #f9fafb;
            padding: 12px 14px;
            border-radius: 6px;
            margin-top: 8px;
            white-space: pre-line;
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
            <div class="status">AJUSTES SUGERIDOS</div>
        </div>

        <div class="content">
            <h2>Olá, {{ $brand->name }}!</h2>

            <p>
                A equipe da Nexa revisou sua campanha <strong>{{ $campaign->title }}</strong> e sugeriu ajustes no texto
                antes da aprovação.
            </p>

            <div class="info-box">
                <h3>Campanha</h3>
                <p><strong>Título atual:</strong> {{ $suggestion->current_title }}</p>

                @if($suggestion->suggested_title)
                    <p><strong>Título sugerido:</strong></p>
                    <div class="text-box">{{ $suggestion->suggested_title }}</div>
                @endif

                @if($suggestion->suggested_description)
                    <p><strong>Descrição sugerida:</strong></p>
                    <div class="text-box">{{ $suggestion->suggested_description }}</div>
                @endif

                @if($suggestion->note)
                    <p><strong>Observação da Nexa:</strong></p>
                    <div class="text-box">{{ $suggestion->note }}</div>
                @endif

                @if($admin)
                    <p><strong>Responsável pela revisão:</strong> {{ $admin->name }}</p>
                @endif
            </div>

            <p>
                Acesse a campanha, ajuste o texto e salve as alterações para continuar o processo de aprovação.
            </p>

            <a href="{{ config('app.frontend_url', 'http://localhost:3000') }}/dashboard/campaigns/{{ $campaign->id }}?edit=1" class="button">
                Revisar campanha
            </a>
        </div>

        <div class="footer">
            <p>Este é um email automático da plataforma Nexa.</p>
            <p>Se você tiver alguma dúvida, entre em contato com a equipe.</p>
        </div>
    </div>
</body>
</html>
