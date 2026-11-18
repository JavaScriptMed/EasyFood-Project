# Despliegue de EasyLunch (backend) en un servidor

Guía paso a paso para poner la API en un servidor Linux propio, con el front en Vercel. Cada comando se puede copiar tal cual; lo que va en MAYÚSCULAS o entre `<>` lo cambias por tu valor.

**Cómo queda al final**

```
Navegador ──HTTPS──> Vercel (front Next.js)
                        │  fetch (CORS)
                        ▼
              https://api.TU-DOMINIO.com
                        │
         Caddy/Nginx del servidor (HTTPS)   ← único que escucha en 80 y 443
                        │  http://127.0.0.1:8080
        ┌───────────────┴───────────────────────────────┐
        │ Docker (docker-compose.prod.yml)              │
        │  web (nginx) → app (php-fpm)                  │
        │  scheduler (tareas) · worker (cola de correos)│
        │  volumen «storage» (fotos, adjuntos, logs)    │
        └───────────────┬───────────────────────────────┘
                        │ host.docker.internal:5432
                 PostgreSQL + PostGIS (instalado en el servidor)
```

No hay contenedor de base de datos: usas el PostgreSQL del servidor.

---

## 1. Requisitos

| Qué | Detalle |
|---|---|
| Servidor | Linux (Ubuntu 22.04 o 24.04), mínimo 2 vCPU, 2 GB de RAM y 20 GB de disco |
| Dominio | Uno para la API, por ejemplo `api.tu-dominio.com`, con un registro DNS **A** que apunte a la IP del servidor |
| Front | Un proyecto en Vercel con el código de `frontend-reference` (su dominio será, por ejemplo, `https://tu-proyecto.vercel.app`) |
| Correo | Un proveedor SMTP (Mailtrap, Brevo, Amazon SES, Gmail Workspace…) con usuario y clave |
| Pagos | Cuenta de Stripe con las claves de **producción** (`sk_live_…`) |

Mientras sigas la guía, abre **otra terminal con el servidor** y ve palomeando cada paso.

## 2. Preparar el servidor

Entra por SSH como administrador y ejecuta:

```bash
# Actualizaciones y herramientas
sudo apt update && sudo apt -y upgrade
sudo apt -y install git ufw curl ca-certificates openssl

# Firewall: solo SSH, HTTP y HTTPS. La API (8080) y PostgreSQL (5432) NO se abren al mundo.
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable

# Docker con su plugin de compose (repositorio oficial)
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
newgrp docker
docker compose version      # debe mostrar la versión 2.x
```

Recomendado: entra solo con llave SSH (`PasswordAuthentication no` en `/etc/ssh/sshd_config`) y no uses `root`.

## 3. PostgreSQL con PostGIS en el servidor

```bash
sudo apt -y install postgresql postgresql-contrib postgis
sudo systemctl enable --now postgresql
psql --version              # 16 o 17 está bien
```

Crea el usuario y la base. **Genera una clave y guárdala**: la vas a poner en `.env.production`.

```bash
openssl rand -base64 24     # copia el resultado: es DB_PASSWORD

sudo -u postgres psql <<'SQL'
CREATE ROLE easylunch LOGIN PASSWORD 'PEGA-AQUI-LA-CLAVE';
CREATE DATABASE easylunch OWNER easylunch;
\c easylunch
CREATE EXTENSION IF NOT EXISTS postgis;
SQL
```

La extensión PostGIS la crea el superusuario `postgres` (por eso se hace aquí y no desde la app). Las migraciones del proyecto usan zonas, puntos y distancias de PostGIS.

### Dejar que Docker llegue a PostgreSQL

Los contenedores ven al servidor como `host.docker.internal`. Hay que permitir que PostgreSQL escuche en esa dirección y aceptar solo la subred fija de EasyLunch (`172.31.250.0/24`, definida en `docker-compose.prod.yml`).

```bash
ip -4 addr show docker0 | grep inet      # normalmente 172.17.0.1: esa es la dirección del servidor para Docker
```

Edita (cambia `16` por tu versión si es otra):

```bash
sudo nano /etc/postgresql/16/main/postgresql.conf
#   listen_addresses = 'localhost,172.17.0.1'

sudo nano /etc/postgresql/16/main/pg_hba.conf
#   agrega al final:
#   host  easylunch  easylunch  172.31.250.0/24  scram-sha-256

sudo systemctl restart postgresql
sudo ufw allow from 172.31.250.0/24 to any port 5432 proto tcp
```

PostgreSQL queda escuchando solo en `localhost` y en la dirección interna de Docker: **no** en internet.

## 4. Bajar el código

```bash
sudo mkdir -p /opt/easylunch && sudo chown "$USER": /opt/easylunch
git clone https://github.com/JavaScriptMed/EasyFood-Project.git /opt/easylunch
cd /opt/easylunch/easyfood
git checkout master            # o la rama que vayas a desplegar
```

Para no escribir tanto, define este atajo (úsalo en cada comando de las siguientes secciones; ponlo también en `~/.bashrc`):

```bash
alias dcp='docker compose --env-file .env.production -f docker-compose.prod.yml'
```

## 5. Variables de producción

```bash
cp .env.production.example .env.production
chmod 600 .env.production
nano .env.production
```

Llena **todas** estas (el archivo trae comentarios; no escribas valores en el repositorio):

| Variable | Qué poner |
|---|---|
| `APP_KEY` | Genérala: `docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` |
| `APP_URL` | `https://api.tu-dominio.com` (con https) |
| `APP_DEBUG` | `false` (déjalo así: con otro valor el contenedor no arranca) |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `easylunch` / `easylunch` / la clave del paso 3 |
| `DB_HOST` | `host.docker.internal` (déjalo) |
| `CORS_ALLOWED_ORIGINS` | El dominio de tu front en Vercel, **sin barra final**: `https://tu-proyecto.vercel.app`. Varios, separados por coma. **No** `*` (el contenedor no arranca) |
| `CORS_ALLOWED_ORIGINS_PATTERNS` | Opcional, para las vistas previas de Vercel: `#^https://tu-proyecto-[a-z0-9-]+\.vercel\.app$#` |
| `FRONTEND_URL` | El mismo dominio del front: se usa en los enlaces de los correos |
| `TRUSTED_PROXIES` | `*` (déjalo: solo el proxy del servidor llega a la app) |
| `SANCTUM_EXPIRATION` | `10080` (7 días). Al vencer, la persona vuelve a iniciar sesión |
| `MAIL_*` | Los datos SMTP de tu proveedor y `MAIL_FROM_ADDRESS` (un correo de tu dominio) |
| `STRIPE_SECRET` | La clave secreta de **producción** (`sk_live_…`) |
| `STRIPE_WEBHOOK_SECRET` | Se llena en el paso 11 |

> `.env.production` está en `.gitignore`. No lo subas, no lo mandes por chat y respáldalo en un lugar seguro (si pierdes `APP_KEY`, los correos que estén en la cola cifrados ya no se pueden leer).

## 6. Construir y arrancar

```bash
dcp build                  # tarda unos minutos la primera vez
dcp up -d
dcp ps                     # app, web, scheduler y worker deben estar «Up»; app y web «healthy»
dcp logs --tail=30 app     # debe terminar con «ready to handle connections»
```

Si algún contenedor se reinicia en bucle, mira la causa: `dcp logs app`. El arranque te avisa con un mensaje claro si falta `APP_KEY`, `DB_PASSWORD`, `CORS_ALLOWED_ORIGINS` o si `APP_DEBUG` no es `false`.

## 7. Preparar la base de datos

```bash
# 1) Crear las tablas
dcp exec app php artisan migrate --force

# 2) Cargar los roles (cliente, administrador, repartidor, comedor, soporte, empresa, empleado)
dcp exec app php artisan db:seed --class=InitialDataSeeder --force

# 3) Crear tu administrador (te pide la contraseña dos veces, sin mostrarla)
dcp exec app php artisan easylunch:crear-admin tu-correo@tu-dominio.com --nombre="Tu Nombre"
```

**Zonas de entrega** (sin zonas no se pueden registrar empresas, repartidores ni direcciones con cobertura). Lo más cómodo: entra al panel como administrador → **Zonas** → **Importar varias** con un solo archivo GeoJSON (un polígono por zona, con su nombre en `properties.nombre`). También se puede por comando; prepara un archivo GeoJSON por zona (puedes dibujarlas en https://geojson.io y guardar como `zapopan.geojson`; las coordenadas van como `[longitud, latitud]`):

```bash
dcp exec -T app php artisan easylunch:cargar-zona "Zapopan" - < ./zapopan.geojson
```

(el `-` le dice que lea el archivo de la entrada estándar; los contenedores son de solo lectura, por eso no se copia el archivo adentro).

El nombre de la zona es el que después se escribe en empresas, comedores y repartidores. Si repites el comando con el mismo nombre, reemplaza el polígono y recalcula las direcciones.

**Datos de ejemplo**: NO cargues `DatosDeEjemploSeeder` en producción (es solo para la demo).

Todo lo demás (planes, comedores, empresas, repartidores, platillos) se da de alta desde el panel de administración.

## 8. HTTPS con Caddy (lo más simple)

Caddy consigue y renueva el certificado solo. Con el DNS del paso 1 ya apuntando al servidor:

```bash
sudo apt -y install debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt -y install caddy

sudo tee /etc/caddy/Caddyfile >/dev/null <<'EOF'
api.tu-dominio.com {
    encode gzip
    request_body {
        max_size 8MB
    }
    reverse_proxy 127.0.0.1:8080
    header Strict-Transport-Security "max-age=31536000; includeSubDomains"
}
EOF
sudo systemctl reload caddy
```

Caddy manda solo la cabecera `X-Forwarded-For` con la IP real del cliente, que es lo que la API usa para limitar intentos.

<details>
<summary>Alternativa con Nginx + certbot</summary>

```bash
sudo apt -y install nginx certbot python3-certbot-nginx
sudo tee /etc/nginx/sites-available/easylunch >/dev/null <<'EOF'
server {
    server_name api.tu-dominio.com;
    client_max_body_size 8M;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF
sudo ln -s /etc/nginx/sites-available/easylunch /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d api.tu-dominio.com
```

Con certbot, agrega también `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;` al bloque `server` con HTTPS.
</details>

## 9. Conectar el front de Vercel

0. Al crear el proyecto en Vercel: **Root Directory = `frontend-reference`** (el front vive en esa carpeta del repositorio, con su propio `package.json`). Todo lo demás lo detecta solo.
1. En Vercel → tu proyecto → **Settings → Environment Variables** (entorno Production):
   - `NEXT_PUBLIC_API_URL` = `https://api.tu-dominio.com`
   - `NEXT_PUBLIC_USAR_MOCK` = `false`
2. **Redeploy** del front.
3. Copia el dominio final del front (por ejemplo `https://tu-proyecto.vercel.app`) a `CORS_ALLOWED_ORIGINS` y `FRONTEND_URL` en `.env.production` y recrea los contenedores para que lean el cambio:

```bash
dcp up -d --force-recreate
```

## 10. Comprobar que todo funciona

```bash
# La API y la base de datos
curl -s https://api.tu-dominio.com/api/v1/salud
#   → "estado":"ok", "entorno":"production" y la base de datos en "ok"

# Que el puerto de la API NO está abierto al mundo (desde TU computadora; debe fallar o agotar el tiempo)
curl -m 5 http://IP-DEL-SERVIDOR:8080/api/v1/salud

# CORS: debe devolver tu dominio de Vercel; con otro origen NO debe devolver ese otro origen
curl -si -X OPTIONS https://api.tu-dominio.com/api/v1/salud \
  -H 'Origin: https://tu-proyecto.vercel.app' -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin

# El administrador puede iniciar sesión
curl -s -X POST https://api.tu-dominio.com/api/auth/login -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"tu-correo@tu-dominio.com","password":"TU-CONTRASEÑA"}' | head -c 120
```

Después, en el navegador:

- [ ] Entras a `https://tu-proyecto.vercel.app/login` con tu administrador.
- [ ] Creas un plan (Menús → Planes) y una empresa de prueba: **la persona de contacto recibe el correo** de invitación. Si no llega, revisa `dcp logs worker` (cola de correos) y los datos `MAIL_*`.
- [ ] Subes la foto de un platillo y se ve (viene de `https://api.tu-dominio.com/storage/...`).
- [ ] `dcp logs scheduler` muestra los comandos programados corriendo cada minuto.

## 11. Pagos con Stripe

1. En el panel de Stripe (modo **producción**) → Developers → Webhooks → **Add endpoint**:
   - URL: `https://api.tu-dominio.com/api/v1/webhooks/stripe`
   - Eventos: `payment_intent.succeeded`, `payment_intent.payment_failed` y `charge.refunded`
2. Copia el **Signing secret** (`whsec_…`) a `STRIPE_WEBHOOK_SECRET` en `.env.production`.
3. `dcp up -d --force-recreate`
4. Haz un pago real de monto mínimo y reembólsalo desde el panel para confirmar el ciclo completo.

Sin `STRIPE_SECRET` o `STRIPE_WEBHOOK_SECRET`, pagar responde 503 (a propósito).

## 12. Respaldos automáticos

El script `scripts/respaldo-db.sh` hace un `pg_dump` comprimido, lo verifica y borra lo que tenga más de 7 días (si el respaldo de hoy falla, **no** borra los anteriores). Con `--archivos` respalda también las fotos y adjuntos.

```bash
sudo apt -y install postgresql-client
sudo mkdir -p /var/backups/easylunch && sudo chown "$USER": /var/backups/easylunch

# Prueba manual
cd /opt/easylunch/easyfood && scripts/respaldo-db.sh --archivos
ls -lh /var/backups/easylunch

# Todos los días a las 3:00
( crontab -l 2>/dev/null; echo '0 3 * * * cd /opt/easylunch/easyfood && scripts/respaldo-db.sh --archivos >> /var/log/easylunch-respaldo.log 2>&1' ) | crontab -
sudo touch /var/log/easylunch-respaldo.log && sudo chown "$USER": /var/log/easylunch-respaldo.log
```

El script toma los datos de conexión de `.env.production`. Una copia en el mismo servidor no protege si el servidor se pierde: **copia `/var/backups/easylunch` a otro lugar** (otro servidor con `rsync`, o un bucket con `rclone`).

### Restaurar (y probar que sirve)

Hazlo al menos una vez para estar seguro de que tus respaldos funcionan. Primero en una base de prueba:

```bash
sudo -u postgres psql -c "CREATE DATABASE easylunch_restauracion OWNER easylunch;"
sudo -u postgres psql -d easylunch_restauracion -c "CREATE EXTENSION IF NOT EXISTS postgis;"
pg_restore --no-owner --role=easylunch -d easylunch_restauracion -h 127.0.0.1 -U easylunch /var/backups/easylunch/easylunch-AAAAMMDD-HHMMSS.dump
sudo -u postgres psql -d easylunch_restauracion -c "SELECT count(*) FROM users;"
sudo -u postgres psql -c "DROP DATABASE easylunch_restauracion;"
```

Para restaurar de verdad (desastre): para la app (`dcp stop app scheduler worker web`), recrea la base vacía con PostGIS, corre el mismo `pg_restore` sobre `easylunch` y vuelve a arrancar (`dcp up -d`). Las fotos: 

```bash
docker run --rm -v easylunch-prod_storage:/datos -v /var/backups/easylunch:/respaldos alpine:3.20 \
  tar -xzf /respaldos/archivos-AAAAMMDD-HHMMSS.tar.gz -C /datos
```

## 13. Actualizar a una versión nueva

```bash
cd /opt/easylunch/easyfood
scripts/respaldo-db.sh                          # respaldo antes de tocar nada
docker tag easylunch-app:prod easylunch-app:anterior   # para poder volver atrás
git pull
dcp build
dcp up -d                                       # recrea solo lo que cambió; el worker toma el código nuevo
dcp exec app php artisan migrate --force        # solo si hay migraciones nuevas
dcp ps && curl -s https://api.tu-dominio.com/api/v1/salud
```

**Volver atrás** si algo sale mal: `git checkout <commit-anterior>`, `docker tag easylunch-app:anterior easylunch-app:prod`, `dcp up -d --no-build`. Si la versión nueva traía migraciones, restaura el respaldo del paso anterior.

## 14. Operación del día a día

| Qué | Comando |
|---|---|
| Ver estado | `dcp ps` |
| Logs (los secretos van tachados) | `dcp logs -f --tail=100 app` · `web` · `scheduler` · `worker` |
| Reiniciar todo | `dcp restart` |
| Correos que fallaron | `dcp exec app php artisan queue:failed` y reintentar con `dcp exec app php artisan queue:retry all` |
| Corte diario | Lo ejecuta el `scheduler` a la hora configurada (por defecto 18:00, hora de Guadalajara). Se ve en el panel de administración → Corte |
| Espacio en disco | `df -h` y `docker system df` (limpiar: `docker image prune -f`) |
| Cambiar una variable | Edita `.env.production` y `dcp up -d --force-recreate` |
| Ver cuánta memoria usa cada contenedor | `docker stats --no-stream` |

Límites de memoria por contenedor (en `docker-compose.prod.yml`): app 512 MB, worker 384 MB, scheduler 256 MB, web 128 MB. Si el servidor tiene más RAM, súbelos ahí.

## 15. Lo que ya viene protegido

- Solo `web` publica un puerto y únicamente en `127.0.0.1`; el HTTPS lo pone Caddy/Nginx. PostgreSQL no está abierto a internet.
- `APP_DEBUG=false` obligatorio, CORS solo para tu dominio del front, tokens de acceso que vencen y se limpian solos.
- Límites de intentos en login, registro y «olvidé mi contraseña» (por IP real del cliente).
- Los logs (de la app y de nginx) no guardan contraseñas, tokens, enlaces de invitación ni claves de Stripe.
- Los correos viajan por la cola con la información cifrada y reintentos si el proveedor falla.
- Contenedores con sistema de archivos de solo lectura, sin capacidades extra y con tope de memoria.

Lo que **te toca a ti**: mantener el servidor actualizado (`sudo apt update && sudo apt upgrade`), entrar solo con llave SSH, guardar `.env.production` y los respaldos fuera del servidor, y rotar las claves si alguna vez se expone.

## 16. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| `app` se reinicia y el log dice «Falta APP_KEY» / «CORS_ALLOWED_ORIGINS debe ser…» | Falta ese valor en `.env.production`. Corrígelo y `dcp up -d --force-recreate` |
| `/api/v1/salud` muestra la base de datos en error | PostgreSQL no deja entrar al contenedor: revisa `listen_addresses`, la línea de `pg_hba.conf`, la regla de `ufw` (paso 3) y la clave. Prueba: `docker run --rm --add-host host.docker.internal:host-gateway -e PGPASSWORD=TU-CLAVE postgres:17-alpine psql -h host.docker.internal -U easylunch -d easylunch -c "select 1"` |
| El front dice «No se pudo conectar con el servidor» | `NEXT_PUBLIC_API_URL` mal puesto o CORS: tu dominio de Vercel debe estar **exacto** (con `https://`, sin barra final) en `CORS_ALLOWED_ORIGINS` |
| 429 «Demasiados intentos» | Límite de intentos (por ejemplo, 5 inicios de sesión fallidos por minuto con la misma cuenta). Espera un minuto. Si les pasa a **todos** a la vez, falta `TRUSTED_PROXIES=*` o tu proxy no manda `X-Forwarded-For` |
| No llegan los correos | `dcp logs worker`; revisa `MAIL_*` y `dcp exec app php artisan queue:failed` |
| Las fotos no cargan | El volumen `storage` debe estar montado: `dcp exec app ls storage/app/public`. La URL debe empezar con `APP_URL` |
| No hay corte diario | `dcp logs scheduler`; revisa la hora de corte en Configuración y que haya día de servicio |
| `permission denied` al escribir en `storage` | `dcp exec -u root app chown -R www-data:www-data storage` |

## 17. Apagar o desinstalar

```bash
dcp down            # apaga los contenedores y conserva el volumen con las fotos
dcp down -v         # ¡también borra las fotos y adjuntos! (la base de datos no se toca: está en PostgreSQL)
```
