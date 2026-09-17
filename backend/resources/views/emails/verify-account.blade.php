<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification de votre compte RBK</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px; border-radius: 12px 12px 0 0; text-align: center;">
        <h1 style="color: white; margin: 0; font-size: 28px;">RBK</h1>
        <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0;">Cadencier Commercial & Fiches Licences RBQ</p>
    </div>

    <div style="background: #f8fafc; padding: 30px; border-radius: 0 0 12px 12px; border: 1px solid #e2e8f0; border-top: none;">
        <h2 style="color: #1e293b; margin-top: 0;">Vérification de votre compte</h2>

        <p style="font-size: 16px;">Bonjour <strong>{{ $userName }}</strong>,</p>

        <p style="font-size: 16px;">Bienvenue sur RBK ! Votre compte a été créé avec succès. Pour activer votre compte et définir votre mot de passe initial, veuillez cliquer sur le bouton ci-dessous :</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $verificationUrl }}"
               style="display: inline-block; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px; box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.4);">
                Activer mon compte
            </a>
        </div>

        <p style="font-size: 14px; color: #64748b;">Ou copiez ce lien dans votre navigateur :</p>
        <p style="font-size: 13px; color: #3b82f6; word-break: break-all; background: #eff6ff; padding: 12px; border-radius: 6px;">{{ $verificationUrl }}</p>

        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">

        <p style="font-size: 13px; color: #94a3b8; margin: 0;">
            Ce lien expire dans 24 heures pour des raisons de sécurité.<br>
            Si vous n'avez pas demandé la création de ce compte, veuillez ignorer cet email.
        </p>
    </div>

    <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 12px;">
        <p style="margin: 0;">© 2024 RBK. Tous droits réservés.</p>
    </div>
</body>
</html>