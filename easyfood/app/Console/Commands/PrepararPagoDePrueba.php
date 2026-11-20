<?php

namespace App\Console\Commands;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Role;
use App\Models\User;
use App\Services\CalendarioDeServicio;
use App\Services\ContratacionDePaquetes;
use App\Services\Pagos\ErrorDePasarela;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\PagosDePedidos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solo para desarrollo local: deja listo todo para probar el pago con Stripe a mano.
 * Crea un cliente de prueba con un pedido por pagar, imprime su id y un token, y arma el curl del pago y el comando de Stripe CLI.
 * No escribe nada en archivos ni guarda claves: el token solo se imprime en la terminal.
 */
class PrepararPagoDePrueba extends Command
{
    protected $signature = 'pagos:preparar-prueba
        {--url=http://localhost:8000 : dirección donde corre el backend (la que ve tu terminal)}
        {--con-intento : además crea el PaymentIntent en Stripe (usa STRIPE_SECRET) para imprimir el comando de confirmación con su id real}';

    protected $description = 'Solo local: crea un cliente y un pedido por pagar, y arma el curl del pago y el comando de Stripe CLI para probarlo';

    public function handle(CalendarioDeServicio $calendario, ContratacionDePaquetes $paquetes, PagosDePedidos $pagos, PasarelaDePagos $pasarela): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Este comando es solo para desarrollo local (APP_ENV=local). No se ejecuta en '.app()->environment().'.');

            return self::FAILURE;
        }

        try {
            [$cliente, $pedido] = DB::transaction(fn () => $this->crearClienteYPedido($calendario, $paquetes));
        } catch (ReglaDeNegocioException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $token = $cliente->createToken('prueba-de-pagos')->plainTextToken;
        $url = rtrim((string) $this->option('url'), '/');
        $curl = "curl -s -X POST {$url}/api/v1/cliente/pedidos/{$pedido->id}/pago -H \"Authorization: Bearer {$token}\" -H \"Accept: application/json\"";

        $this->newLine();
        $this->line("Cliente de prueba: {$cliente->email} (usuario {$cliente->id})");
        $this->line("Pedido: {$pedido->id}  folio {$pedido->folio}  total {$pedido->total} MXN  estado {$pedido->estado}");
        $this->line("Token: {$token}");
        $this->newLine();

        $this->info('1) Pedir el intento de pago (devuelve payment_intent_id y client_secret):');
        $this->line($curl);
        $this->newLine();

        $intento = null;
        if ($this->option('con-intento')) {
            try {
                if (! $pasarela->configurada()) {
                    throw new ErrorDePasarela('faltan STRIPE_SECRET y STRIPE_WEBHOOK_SECRET en tu .env');
                }
                $intento = $pagos->iniciar($cliente, $pedido->id)['payment_intent_id'];
            } catch (ErrorDePasarela $e) {
                $this->warn("No se creó el intento en Stripe: {$e->getMessage()}. Sigue con el curl de arriba.");
            }
        }

        $this->info('2) Confirmar el pago con la tarjeta de prueba Visa (Stripe CLI):');
        if ($intento) {
            $this->line("stripe payment_intents confirm {$intento} --payment-method pm_card_visa --return-url https://example.com");
        } else {
            $this->line('stripe payment_intents confirm pi_XXXX --payment-method pm_card_visa --return-url https://example.com   # pi_XXXX = payment_intent_id del paso 1');
            $this->newLine();
            $this->info('O los dos pasos juntos (necesita jq):');
            $this->line("PI=$({$curl} | jq -r .payment_intent_id) && stripe payment_intents confirm \"\$PI\" --payment-method pm_card_visa --return-url https://example.com");
        }

        $this->newLine();
        $this->comment("Para que el pedido pase a «recibido» tiene que estar corriendo: stripe listen --forward-to {$url}/api/v1/webhooks/stripe --events payment_intent.succeeded,payment_intent.payment_failed,charge.refunded");
        $this->comment('Otras tarjetas: pm_card_mastercard (4444), pm_card_chargeDeclined (rechazada), pm_card_threeDSecure2Required (3D Secure).');

        return self::SUCCESS;
    }

    /** @return array{0: User, 1: \App\Models\Pedido} */
    private function crearClienteYPedido(CalendarioDeServicio $calendario, ContratacionDePaquetes $paquetes): array
    {
        $rol = Role::query()->where('name', 'customer')->first() ?? throw new \RuntimeException('No existe el rol «customer». Corre: php artisan db:seed --class=InitialDataSeeder (o los datos de ejemplo).');
        $paquete = Paquete::query()->where('dias', 1)->where('activo', true)->first() ?? throw new \RuntimeException('No hay un paquete de 1 día. Carga los datos de ejemplo: php artisan db:seed --class=DatosDeEjemploSeeder');
        $punto = DB::selectOne('select ST_X(p::geometry) as lon, ST_Y(p::geometry) as lat from (select ST_PointOnSurface(area::geometry) as p from zonas order by id limit 1) z')
            ?? throw new \RuntimeException('No hay zonas de cobertura cargadas: sin ellas nadie puede pedir. Carga los datos de ejemplo o las zonas reales.');

        $fecha = $calendario->diasDeComidaDeUnPedido(1, $calendario->fechaDeEntregaDeIndividuales())[0]; // el día en que se comería: el platillo aparta su porción ahí
        $menu = Menu::query()->where('estado_revision', 'publicado')->where('is_available', true)
            ->whereHas('disponibilidad', fn ($q) => $q->whereDate('fecha', $fecha)->whereColumn('porciones_tomadas', '<', 'porciones_maximas'))->orderBy('id')->first()
            ?? throw new \RuntimeException("No hay un platillo publicado con porciones para el {$fecha}. Carga los datos de ejemplo o publica uno y abre su disponibilidad.");

        $sufijo = Str::lower(Str::random(6));
        $cliente = User::create(['role_id' => $rol->id, 'name' => 'Cliente de prueba '.$sufijo, 'email' => "cliente.prueba.{$sufijo}@easylunch.test", 'password' => Str::random(32), 'status' => 'active']);
        $direccion = Address::create(['user_id' => $cliente->id, 'label' => 'Casa de prueba', 'street_address' => 'Calle de prueba 100', 'latitude' => $punto->lat, 'longitude' => $punto->lon, 'is_default' => true]);

        return [$cliente, $paquetes->pedir($cliente, $paquete, $direccion, [$menu])];
    }
}
