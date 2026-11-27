<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>

<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;color:#1f2933;">Simulador SPGTH</h1>

        @if ($purpose === 'verify_email')
            <p style="margin:0 0 12px;font-size:15px;line-height:22px;">
                Confirma tu correo para poder usar la plataforma.
            </p>
        @elseif ($purpose === 'set_password')
            <p style="margin:0 0 12px;font-size:15px;line-height:22px;">
                Recibimos una solicitud para establecer o cambiar la contraseña de tu cuenta desde una sesión abierta.
            </p>
        @else
            <p style="margin:0 0 12px;font-size:15px;line-height:22px;">
                Recibimos una solicitud para restablecer la contraseña de tu cuenta.
            </p>
        @endif

        <p style="margin:0 0 20px;font-size:15px;line-height:22px;">
            Tu código de verificación es:
        </p>

        <div style="margin:0 0 20px;padding:20px;text-align:center;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;">
            <span style="font-size:34px;font-weight:bold;letter-spacing:8px;color:#3730a3;font-family:Consolas,monospace;">{{ $code }}</span>
        </div>

        <p style="margin:0 0 8px;font-size:14px;line-height:20px;color:#52606d;">
            Este código expira en <strong>{{ $ttlMinutes }} minutos</strong> y solo puede usarse una vez.
        </p>

        @if ($purpose === 'reset_password' || $purpose === 'set_password')
            <p style="margin:0 0 8px;font-size:14px;line-height:20px;color:#52606d;">
                Si no solicitaste esto, puedes ignorar este mensaje: tu contraseña no cambia.
            </p>
        @else
            <p style="margin:0 0 8px;font-size:14px;line-height:20px;color:#52606d;">
                Si no solicitaste esto, puedes ignorar este mensaje sin más.
            </p>
        @endif
    </div>
</body>

</html>