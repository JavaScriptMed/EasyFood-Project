<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #172630; line-height: 1.5;">
    <p>Hola {{ $nombre }},</p>
    <p>Te invitaron a entrar a <strong>EasyLunch</strong> como parte de <strong>{{ $organizacion }}</strong>.</p>
    <p>Crea tu contraseña con este enlace:</p>
    <p><a href="{{ $url }}">{{ $url }}</a></p>
    <p>El enlace sirve hasta el {{ $venceEn->locale('es')->isoFormat('D [de] MMMM [a las] H:mm') }}. Si no lo esperabas, ignora este correo.</p>
    <p>— EasyLunch</p>
</body>
</html>
