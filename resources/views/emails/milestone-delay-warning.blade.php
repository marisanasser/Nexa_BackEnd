<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aviso de Atraso - Milestone - Nexa Platform</title>
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
            color: #fd7e14;
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
            border-left: 4px solid #fd7e14;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .milestone-title {
            font-size: 18px;
            font-weight: bold;
            color: #fd7e14;
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
        .warning-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
            color: #856404;
        }
        .warning-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #fd7e14;
        }
        .penalty-info {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            color: #721c24;
        }
        .penalty-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .action-required {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            color: #0c5460;
        }
        .action-title {
            font-weight: bold;
            margin-bottom: 10px;
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
            background-color: #fd7e14;
            color: #ffffff;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: bold;
        }
        .cta-button:hover {
            background-color: #e8690b;
        }
        .urgent {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            color: #721c24;
        }
        .urgent-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">NEXA</div>
            <div class="title">⚠️ Aviso de Atraso - Milestone</div>
            <div class="subtitle">Ação imediata necessária</div>
        </div>

        <div class="content">
            <p>Olá <strong>{{ $creator->name }}</strong>,</p>

            <div class="urgent">
                <div class="urgent-title">🚨 ATENÇÃO URGENTE!</div>
                <p>O milestone <strong>"{{ $milestone->title }}"</strong> do seu contrato está <strong>ATRASADO</strong>.</p>
            </div>

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
                    <span class="info-label">Prazo Original:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($milestone->deadline)->format('d/m/Y H:i') }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Dias em Atraso:</span>
                    <span class="info-value">{{ $milestone->getDaysOverdue() }} dias</span>
                </div>
            </div>

            <div class="warning-section">
                <div class="warning-title">⚠️ Consequências do Atraso</div>
                <p>Se você não justificar este atraso ou não entregar o milestone em breve, as seguintes penalidades serão aplicadas automaticamente:</p>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li><strong>7 dias sem novos convites</strong> para campanhas</li>
                    <li><strong>Redução da pontuação</strong> no ranking da plataforma</li>
                    <li><strong>Possível suspensão temporária</strong> da conta</li>
                </ul>
            </div>

            <div class="penalty-info">
                <div class="penalty-title">🚫 Penalidade Automática</div>
                <p>O sistema aplicará automaticamente uma penalidade de <strong>7 dias sem novos convites</strong> se o atraso não for justificado ou resolvido.</p>
            </div>

            <div class="action-required">
                <div class="action-title">✅ Ação Imediata Necessária</div>
                <p>Para evitar penalidades, você deve:</p>
                <ol style="margin: 10px 0; padding-left: 20px;">
                    <li><strong>Justificar o atraso</strong> através da plataforma</li>
                    <li><strong>Entregar o milestone</strong> o mais rápido possível</li>
                    <li><strong>Comunicar-se com a marca</strong> sobre o status</li>
                    <li><strong>Solicitar extensão</strong> se necessário</li>
                </ol>
            </div>

            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/creator/chat" class="cta-button">
                    🚀 Justificar Atraso Agora
                </a>
            </div>

            <p style="margin-top: 20px; text-align: center; color: #6c757d;">
                <strong>⏰ Tempo é essencial!</strong> Aja rapidamente para evitar penalidades.
            </p>
        </div>

        <div class="footer">
            <p>Este é um email automático da plataforma Nexa.</p>
            <p>Se você tiver alguma dúvida, entre em contato conosco através do chat da plataforma.</p>
            <p>&copy; {{ date('Y') }} Nexa Platform. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>