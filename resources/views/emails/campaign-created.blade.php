<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campanha Criada - Nexa Platform</title>
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
            background-color: #2196F3;
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
        .campaign-details {
            background-color: #f0f8ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .campaign-details h3 {
            margin-top: 0;
            color: #E91E63;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            color: #666;
        }
        .detail-value {
            color: #333;
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
            <div class="status">📋 CAMPANHA CRIADA</div>
        </div>

        <div class="content">
            <h2>Olá, {{ $brand->name }}! 👋</h2>
            
            <p>Obrigado por criar sua campanha na plataforma Nexa! Sua campanha "<strong>{{ $campaign->title }}</strong>" foi recebida com sucesso e está aguardando aprovação da nossa equipe.</p>

            <div class="campaign-details">
                <h3>📌 Detalhes da Campanha</h3>
                <div class="detail-row">
                    <span class="detail-label">Título:</span>
                    <span class="detail-value">{{ $campaign->title }}</span>
                </div>
                @if($campaign->budget)
                <div class="detail-row">
                    <span class="detail-label">Orçamento:</span>
                    <span class="detail-value">R$ {{ number_format($campaign->budget, 2, ',', '.') }}</span>
                </div>
                @endif
                <div class="detail-row">
                    <span class="detail-label">Tipo:</span>
                    <span class="detail-value">{{ ucfirst($campaign->remuneration_type) }}</span>
                </div>
                @if($campaign->deadline)
                <div class="detail-row">
                    <span class="detail-label">Prazo:</span>
                    <span class="detail-value">{{ \Carbon\Carbon::parse($campaign->deadline)->format('d/m/Y') }}</span>
                </div>
                @endif
                @if($campaign->campaign_type)
                <div class="detail-row">
                    <span class="detail-label">Categoria:</span>
                    <span class="detail-value">{{ $campaign->campaign_type }}</span>
                </div>
                @endif
            </div>

            <div class="info-box">
                <p style="margin: 0;"><strong>📅 Próximos Passos:</strong></p>
                <p style="margin: 5px 0;">A campanha será revisada pela nossa equipe e você será notificado por email assim que ela for aprovada ou se precisar de algum ajuste.</p>
            </div>

            <p style="text-align: center;">
                <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/brand/campaigns" class="button" style="color: white;">
                    Ver Minhas Campanhas
                </a>
            </p>
        </div>

        <div class="footer">
            <p>Este é um email automático da plataforma Nexa.</p>
            <p>Se você tiver alguma dúvida, entre em contato conosco.</p>
        </div>
    </div>
</body>
</html>

