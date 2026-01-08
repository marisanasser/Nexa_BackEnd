<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposta Aprovada - Nexa Platform</title>
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
        .congratulations {
            font-size: 18px;
            color: #E91E63;
            font-weight: bold;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">NEXA</div>
            <div class="status">💖 PARABÉNS! SEU PERFIL FOI SELECIONADO!</div>
        </div>

        <div class="content">
            <div class="congratulations">🎉 Parabéns! Sua proposta foi aprovada!</div>

            <p>Olá, {{ $application->creator->name ?? 'Criador' }}!</p>

            <p>Temos uma ótima notícia: sua proposta foi selecionada pela marca <strong>{{ $application->campaign->brand->name }}</strong>! Isso significa que você foi escolhido(a) para esta parceria e estamos muito animados para ver o resultado do seu trabalho.</p>

            <div class="info-box">
                <h3>📋 Informações da Parceria</h3>
                <p><strong>Campanha:</strong> {{ $application->campaign->title }}</p>
                <p><strong>Marca:</strong> {{ $application->campaign->brand->name }}</p>
                <p><strong>Data de Aprovação:</strong> {{ $application->approved_at->format('d/m/Y') }} às {{ $application->approved_at->format('H:i') }}</p>
            </div>

            <p><strong>Próximos passos:</strong></p>
            <ul style="line-height: 2;">
                <li>Acesse sua conta na plataforma NEXA</li>
                <li>Verifique o chat com a marca para alinhar os detalhes da parceria</li>
                <li>Fique atento(a) às mensagens e comunique-se de forma clara e profissional</li>
            </ul>

            <p style="margin-top: 25px;">Estamos aqui para apoiar você em cada etapa desta jornada. Se tiver qualquer dúvida, nossa equipe está à disposição!</p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/creator/applications" class="button" style="color: white; text-decoration: none;">
                    Acessar Minhas Propostas
            </a>
            </div>
        </div>

        <div class="footer">
            <p><strong>Equipe NEXA</strong></p>
            <p>Este é um email automático da plataforma NEXA.</p>
            <p>Precisa de ajuda? Entre em contato conosco através da plataforma ou responda este email.</p>
            <p style="margin-top: 15px; font-size: 12px; color: #999;">© {{ date('Y') }} NEXA Platform. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>
