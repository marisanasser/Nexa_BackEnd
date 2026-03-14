<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificacao estudantil - Nexa</title>
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
            background-color: #f44336;
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            display: inline-block;
            font-weight: bold;
        }
        .info-box {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #f44336;
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
            <div class="status">VERIFICACAO NAO APROVADA</div>
        </div>

        <div class="content">
            <h2>Ola, {{ $user->name }}!</h2>

            <p>Neste momento nao foi possivel aprovar sua verificacao estudantil.</p>

            @if(!empty($rejectionData['rejection_reason']))
                <div class="info-box">
                    <p><strong>Motivo informado:</strong></p>
                    <p style="font-style: italic; color: #666;">"{{ $rejectionData['rejection_reason'] }}"</p>
                </div>
            @endif

            <p>Voce pode revisar os dados enviados e reenviar a solicitacao quando quiser.</p>

            <a href="{{ config('app.frontend_url', 'http://localhost:5000') }}/dashboard/student-verify" class="button" style="color: white;">
                Revisar solicitacao
            </a>
        </div>

        <div class="footer">
            <p>Este e um email automatico da plataforma Nexa.</p>
            <p>Se voce tiver alguma duvida, entre em contato conosco.</p>
        </div>
    </div>
</body>
</html>
