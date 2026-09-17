<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code de vérification RBK</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px; border-radius: 12px 12px 0 0; text-align: center;">
        <h1 style="color: white; margin: 0; font-size: 28px;">RBK</h1>
        <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0;">Sécurité du compte</p>
    </div>

    <div style="background: #f8fafc; padding: 30px; border-radius: 0 0 12px 12px; border: 1px solid #e2e8f0; border-top: none;">
        <h2 style="color: #1e293b; margin-top: 0;">Code de vérification</h2>

        <p style="font-size: 16px;">Bonjour <strong>{{ $userName }}</strong>,</p>

        <p style="font-size: 16px;">Utilisez ce code pour autoriser la modification de votre mot de passe :</p>

        <div style="text-align: center; margin: 30px 0;">
            <div style="display: inline-block; background: #eff6ff; color: #1e3a8a; padding: 16px 28px; border-radius: 8px; font-size: 28px; font-weight: 700; letter-spacing: 8px;">
                {{ $code }}
            </div>
        </div>

        <p style="font-size: 13px; color: #64748b;">
            Ce code expire dans {{ $expiresInMinutes }} minutes. Si vous n'avez pas demandé cette modification, ignorez cet email.
        </p>
    </div>
</body>
</html>
