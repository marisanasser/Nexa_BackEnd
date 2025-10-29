<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #EC4899 0%, #BE185D 100%); padding: 40px 20px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold;">
                                Redefinir Senha
                            </h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px; color: #333333; font-size: 16px; line-height: 1.6;">
                                Olá!
                            </p>
                            <p style="margin: 0 0 20px; color: #333333; font-size: 16px; line-height: 1.6;">
                                Você solicitou a redefinição de senha da sua conta na <strong>Nexa Platform</strong>.
                            </p>
                            <p style="margin: 0 0 20px; color: #333333; font-size: 16px; line-height: 1.6;">
                                Clique no botão abaixo para criar uma nova senha:
                            </p>
                            
                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $resetUrl }}" style="display: inline-block; padding: 14px 40px; background-color: #EC4899; color: #ffffff; text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 16px; transition: background-color 0.3s;">
                                            Redefinir Senha
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 20px 0 0; color: #666666; font-size: 14px; line-height: 1.6;">
                                Se o botão não funcionar, copie e cole o link abaixo no seu navegador:
                            </p>
                            <p style="margin: 10px 0 0; color: #EC4899; font-size: 14px; word-break: break-all;">
                                {{ $resetUrl }}
                            </p>

                            <p style="margin: 30px 0 0; color: #666666; font-size: 14px; line-height: 1.6;">
                                <strong>Atenção:</strong> Este link expira em 60 minutos por motivos de segurança.
                            </p>
                            
                            <p style="margin: 20px 0 0; color: #666666; font-size: 14px; line-height: 1.6;">
                                Se você não solicitou a redefinição de senha, apenas ignore este email. Sua senha atual permanecerá inalterada.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9f9f9; padding: 30px; text-align: center; border-top: 1px solid #eeeeee;">
                            <p style="margin: 0 0 10px; color: #999999; font-size: 12px;">
                                Você está recebendo este email porque uma solicitação de redefinição de senha foi feita para esta conta.
                            </p>
                            <p style="margin: 10px 0 0; color: #999999; font-size: 12px;">
                                © {{ date('Y') }} Nexa Platform. Todos os direitos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

