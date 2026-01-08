<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milestone Aprovado - Nexa Platform</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        .logo {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
        .title {
            color: #28a745;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #6c757d;
            font-size: 16px;
        }
        .content {
            margin-bottom: 30px;
        }
        .milestone-info {
            background-color: #f8f9fa;
            border-left: 4px solid #28a745;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .milestone-title {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
        }
        .info-label {
            font-weight: bold;
            color: #495057;
        }
        .info-value {
            color: #6c757d;
        }
        .comment-section {
            background-color: #e8f5e8;
            border: 1px solid #c3e6c3;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
        }
        .comment-label {
            font-weight: bold;
            color: #155724;
            margin-bottom: 8px;
        }
        .comment-text {
            color: #155724;
            font-style: italic;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 14px;
        }
        .cta-button {
            display: inline-block;
            background-color: #007bff;
            color: #ffffff;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: bold;
        }
        .cta-button:hover {
            background-color: #0056b3;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">NEXA</div>
            <div class="title">🎉 Milestone Aprovado!</div>
            <div class="subtitle">Seu trabalho foi aprovado com sucesso</div>
        </div>

        <div class="content">
            <p>Olá <strong>{{ $creator->name }}</strong>,</p>

            <p>Parabéns! O milestone <strong>"{{ $milestone->title }}"</strong> do seu contrato foi aprovado pela marca <strong>{{ $brand->name }}</strong>.</p>

            <div class="milestone-info">
                <div class="milestone-title">{{ $milestone->title }}</div>
                <div class="info-row">
                    <span class="info-label">Contrato:</span>
                    <span class="info-value">{{ $contract->title }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Marca:</span>
                    <span class="info-value">{{ $brand->name }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Tipo:</span>
                    <span class="info-value">
                        @switch($milestone->milestone_type)
                            @case('script_submission')
                                📝 Envio do Roteiro
                                @break
                            @case('script_approval')
                                ✅ Aprovação do Roteiro
                                @break
                            @case('video_submission')
                                🎥 Envio do Vídeo
                                @break
                            @case('final_approval')
                                🏆 Aprovação Final
                                @break
                            @default
                                📋 Milestone
                        @endswitch
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Aprovado em:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($milestone->updated_at)->format('d/m/Y H:i') }}</span>
                </div>
            </div>

            @if($milestone->comment)
                <div class="comment-section">
                    <div class="comment-label">💬 Comentário da Marca:</div>
                    <div class="comment-text">"{{ $milestone->comment }}"</div>
                </div>
            @endif

            <p>Continue com o excelente trabalho! Se este milestone for aprovado, você pode prosseguir para a próxima etapa do projeto.</p>

            <div class="warning">
                <strong>💡 Dica:</strong> Mantenha a comunicação ativa com a marca e entregue os próximos milestones dentro do prazo para manter um fluxo de trabalho eficiente.
            </div>

            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/creator/chat" class="cta-button">
                    📱 Acessar Chat
                </a>
            </div>
        </div>

        <div class="footer">
            <p>Este é um email automático da plataforma Nexa.</p>
            <p>Se você tiver alguma dúvida, entre em contato conosco através do chat da plataforma.</p>
            <p>&copy; {{ date('Y') }} Nexa Platform. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>