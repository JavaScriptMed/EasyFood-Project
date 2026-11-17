<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #172630; line-height: 1.5;">
    <p>Hola {{ $nombre }},</p>
    <p>Pediste restablecer tu contraseña de <strong>EasyLunch</strong>. Elige una nueva con este enlace:</p>
    <p><a href="{{ $url }}">{{ $url }}</a></p>
    <p>El enlace sirve una sola vez y vence en {{ $minutos }} minutos. Si no fuiste tú, ignora este correo: tu contraseña no cambia.</p>
    <p>— EasyLunch</p>
</body>
</html>
