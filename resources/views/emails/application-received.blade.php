<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Candidatura Recebida - Nexa Platform</title>
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
            background-color: #FF9800;
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
        .application-details {
            background-color: #f0f8ff;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .application-details h3 {
            margin-top: 0;
            color: #E91E63;
        }
        .detail-row {
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            color: #666;
            display: block;
            margin-bottom: 5px;
        }
        .detail-value {
            color: #333;
        }
        .creator-info {
            background-color: #fff4e6;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #FF9800;
        }
        .proposal-box {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
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
            <div class="status">📝 NOVA CANDIDATURA</div>
        </div>

        <div class="content">
            <h2>Olá, {{ $brand->name }}! 👋</h2>
            
            <p>Você recebeu uma nova candidatura para sua campanha "<strong>{{ $campaign->title }}</strong>"</p>

            <div class="creator-info">
                <h3 style="margin-top: 0; color: #E91E63;">👤 Criador</h3>
                <p style="margin: 5px 0; font-size: 18px; font-weight: bold;">@{{ $creator->name }}</p>
                @if($creator->instagram_handle)
                <p style="margin: 5px 0; color: #666;">Instagram: @{{ $creator->instagram_handle }}</p>
                @endif
            </div>

            <div class="application-details">
                <h3>📋 Detalhes da Candidatura</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Campanha:</span>
                    <span class="detail-value">{{ $campaign->title }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value" style="color: #FF9800; font-weight: bold;">Pendente de Revisão</span>
                </div>

                @if($application->estimated_delivery_days)
                <div class="detail-row">
                    <span class="detail-label">Prazo Estimado:</span>
                    <span class="detail-value">{{ $application->estimated_delivery_days }} dia{{ $application->estimated_delivery_days > 1 ? 's' : '' }}</span>
                </div>
                @endif

                @if($application->proposed_budget)
                <div class="detail-row">
                    <span class="detail-label">Orçamento Proposto:</span>
                    <span class="detail-value">R$ {{ number_format($application->proposed_budget, 2, ',', '.') }}</span>
                </div>
                @endif
            </div>

            @if($application->proposal)
            <div class="proposal-box">
                <h4 style="margin-top: 0; color: #E91E63;">📝 Proposta do Criador</h4>
                <p style="margin: 0; white-space: pre-wrap;">{{ $application->proposal }}</p>
            </div>
            @endif

            @if($application->portfolio_links && count($application->portfolio_links) > 0)
            <div class="info-box">
                <h4 style="margin-top: 0;">🔗 Links do Portfólio</h4>
                @foreach($application->portfolio_links as $link)
                    <p style="margin: 5px 0;">
                        <a href="{{ $link }}" target="_blank" style="color: #E91E63;">{{ $link }}</a>
                    </p>
                @endforeach
            </div>
            @endif

            <p style="text-align: center;">
                <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/brand/applications" class="button" style="color: white;">
                    Ver Todas as Candidaturas
                </a>
            </p>

            <div class="info-box">
                <p style="margin: 0;"><strong>💡 Próximos Passos:</strong></p>
                <p style="margin: 5px 0;">Revise a candidatura e decida se deseja aprovar ou rejeitar o criador para sua campanha.</p>
            </div>
        </div>

        <div class="footer">
            <p>Este é um email automático da plataforma Nexa.</p>
            <p>Se você tiver alguma dúvida, entre em contato conosco.</p>
        </div>
    </div>
</body>
</html>

