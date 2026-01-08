<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milestone Rejeitado - Nexa Platform</title>
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
            color: #dc3545;
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
            border-left: 4px solid #dc3545;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .milestone-title {
            font-size: 18px;
            font-weight: bold;
            color: #dc3545;
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
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
        }
        .comment-label {
            font-weight: bold;
            color: #721c24;
            margin-bottom: 8px;
        }
        .comment-text {
            color: #721c24;
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
        .next-steps {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">NEXA</div>
            <div class="title">⚠️ Milestone Rejeitado</div>
            <div class="subtitle">Revisão necessária para continuar</div>
        </div>

        <div class="content">
            <p>Olá <strong>{{ $creator->name }}</strong>,</p>

            <p>O milestone <strong>"{{ $milestone->title }}"</strong> do seu contrato foi rejeitado pela marca <strong>{{ $brand->name }}</strong>.</p>

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
                    <span class="info-label">Rejeitado em:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($milestone->updated_at)->format('d/m/Y H:i') }}</span>
                </div>
            </div>

            @if($comment)
                <div class="comment-section">
                    <div class="comment-label">💬 Comentário da Marca:</div>
                    <div class="comment-text">"{{ $comment }}"</div>
                </div>
            @endif

            <div class="next-steps">
                <strong>🔄 Próximos Passos:</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>Revise o feedback fornecido pela marca</li>
                    <li>Faça as correções necessárias</li>
                    <li>Reenvie o milestone revisado</li>
                    <li>Mantenha a comunicação ativa para esclarecer dúvidas</li>
                </ul>
            </div>

            <div class="warning">
                <strong>💡 Dica:</strong> Use o feedback da marca para melhorar seu trabalho. A comunicação clara é essencial para o sucesso do projeto.
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