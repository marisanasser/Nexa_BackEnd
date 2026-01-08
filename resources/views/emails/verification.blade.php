<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email Address - Nexa Platform</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
        }
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            display: block;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .brand-name {
            color: white;
            font-size: 28px;
            font-weight: bold;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 20px 0;
        }
        .message {
            font-size: 16px;
            color: #475569;
            margin-bottom: 30px;
            line-height: 1.7;
        }
        .button-container {
            text-align: center;
            margin: 40px 0;
        }
        .verify-button {
            background: #e91e63;
            color: white;
            padding: 16px 32px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            display: inline-block;
            box-shadow: 0 4px 6px -1px rgba(233, 30, 99, 0.3);
            transition: all 0.2s ease;
        }
        .verify-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(233, 30, 99, 0.4);
        }
        .info {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-left: 4px solid #e91e63;
            padding: 20px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .info-title {
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 10px 0;
        }
        .info-text {
            color: #475569;
            margin: 0;
            font-size: 14px;
        }
        .footer {
            background-color: #f8fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer-text {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }
        .expiry {
            color: #dc2626;
            font-weight: 500;
        }
        @media (max-width: 600px) {
            .container {
                margin: 20px;
                border-radius: 8px;
            }
            .header, .content, .footer {
                padding: 30px 20px;
            }
            .greeting {
                font-size: 20px;
            }
            .verify-button {
                padding: 14px 28px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ asset('assets/light-logo.png') }}" alt="Nexa Logo" class="logo">
            <h1 class="brand-name">Nexa</h1>
        </div>

        <div class="content">
            <h2 class="greeting">Hello {{ $user->name }}!</h2>

            <p class="message">
                Welcome to Nexa! 🎉 We're thrilled to have you join our vibrant community of creators and brands.
                To unlock all the amazing features and start your journey, please verify your email address below.
            </p>

            <div class="button-container">
                <a href="{{ $verificationUrl }}" class="verify-button">
                    Verify Email Address
                </a>
            </div>

            <div class="info">
                <h3 class="info-title">🚀 What happens next?</h3>
                <p class="info-text">
                    After verifying your email, you'll have full access to the Nexa platform where you can:
                    <br>• Connect with amazing brands and talented creators
                    <br>• Discover exciting campaigns and opportunities
                    <br>• Build your professional network and portfolio
                    <br>• Access exclusive features and premium content
                </p>
            </div>

            <p class="message">
                If you didn't create an account with Nexa, you can safely ignore this email.
                No action is required on your part.
            </p>
        </div>

        <div class="footer">
            <p class="footer-text">
                <strong>🔒 Security Note:</strong> This verification link will expire in
                <span class="expiry">60 minutes</span> for your protection.
            </p>
            <p class="footer-text">
                <strong>Need help?</strong> Contact our support team at support@nexa.com
            </p>
            <p class="footer-text">
                Best regards,<br>
                <strong>The Nexa Team</strong> 💜
            </p>
        </div>
    </div>
</body>
</html>