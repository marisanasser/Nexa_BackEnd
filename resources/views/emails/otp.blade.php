<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Código de Verificação Nexa</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #9333ea; /* Purple-600 */
        }
        .code-container {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #f9fafb;
            border-radius: 8px;
            border: 1px dashed #e5e7eb;
        }
        .code {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 5px;
            color: #1f2937;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Nexa</div>
        </div>
        
        <p>Olá,</p>
        
        <p>Use o código abaixo para verificar seu cadastro na Nexa. Este código é válido por 10 minutos.</p>
        
        <div class="code-container">
            <div class="code">{{ $code }}</div>
        </div>
        
        <p>Se você não solicitou este código, por favor ignore este e-mail.</p>
        
        <div class="footer">
            <p>&copy; {{ date('Y') }} Nexa Creators. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>
