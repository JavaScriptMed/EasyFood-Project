<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas de cobertura y ubicación de las direcciones de clientes individuales.
 *
 * - `zonas`: cada zona es un polígono PostGIS que carga el administrador. Su nombre es el mismo que se usa en
 *   empresas.zona, repartidores.zona_base y rutas.zona, así las rutas juntan entregas de la misma zona.
 * - `addresses` (tabla del equipo) guarda su punto en el mapa (`ubicacion`, calculado de latitude/longitude) y la
 *   zona que lo cubre. Si ningún polígono activo lo cubre, `zona` queda nula y `sin_cobertura` es verdadera.
 * - Se recalcula sola al crear/editar la dirección y al crear/editar zonas.
 * - Una dirección sin cobertura no se puede usar para pedir (trigger en pedidos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 100)->unique();
            Esquema::columnasDeDesactivacion($tabla);
            Esquema::marcasDeTiempo($tabla);
        });
        DB::unprepared('ALTER TABLE zonas ADD COLUMN area geography(MultiPolygon, 4326) NOT NULL');
        DB::unprepared('CREATE INDEX zonas_area_gix ON zonas USING GIST (area)');
        Esquema::check('zonas', 'nombre_con_texto', 'length(btrim(nombre)) > 0');
        Esquema::check('zonas', 'area_valida', 'ST_IsValid(area::geometry)');
        Esquema::desactivacionLogica('zonas');

        DB::unprepared('ALTER TABLE addresses ADD COLUMN ubicacion geography(Point, 4326), ADD COLUMN zona varchar(100)');
        DB::unprepared('ALTER TABLE addresses ADD COLUMN sin_cobertura boolean GENERATED ALWAYS AS (zona IS NULL) STORED');
        DB::unprepared('CREATE INDEX addresses_ubicacion_gix ON addresses USING GIST (ubicacion)');
        DB::unprepared('CREATE INDEX addresses_zona_idx ON addresses (zona)');

        // Si dos zonas se traslapan gana la más pequeña (la más específica); a igualdad, la de menor id.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION calcular_zona_de_direccion() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.ubicacion := ST_SetSRID(ST_MakePoint(NEW.longitude::float8, NEW.latitude::float8), 4326)::geography;
                NEW.zona := (
                    SELECT z.nombre FROM zonas z
                    WHERE z.activo AND ST_Covers(z.area, NEW.ubicacion)
                    ORDER BY ST_Area(z.area), z.id
                    LIMIT 1
                );
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER addresses_calcular_zona BEFORE INSERT OR UPDATE OF latitude, longitude ON addresses FOR EACH ROW EXECUTE FUNCTION calcular_zona_de_direccion()');

        // Al cambiar las zonas (alta, edición o desactivación) se recalculan todas las direcciones.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recalcular_zonas_de_direcciones() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE addresses SET latitude = latitude;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER zonas_recalcular_direcciones AFTER INSERT OR UPDATE ON zonas FOR EACH STATEMENT EXECUTE FUNCTION recalcular_zonas_de_direcciones()');

        DB::unprepared('UPDATE addresses SET latitude = latitude'); // direcciones que ya existían

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exigir_cobertura_para_pedir() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM addresses a WHERE a.id = NEW.direccion_id AND a.zona IS NULL) THEN
                    RAISE EXCEPTION 'La dirección está fuera de cobertura: no se puede usar para pedir.' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER pedidos_exigir_cobertura BEFORE INSERT OR UPDATE OF direccion_id ON pedidos FOR EACH ROW EXECUTE FUNCTION exigir_cobertura_para_pedir()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pedidos_exigir_cobertura ON pedidos');
        DB::unprepared('DROP TRIGGER IF EXISTS zonas_recalcular_direcciones ON zonas');
        DB::unprepared('DROP TRIGGER IF EXISTS addresses_calcular_zona ON addresses');
        DB::unprepared('ALTER TABLE addresses DROP COLUMN sin_cobertura, DROP COLUMN zona, DROP COLUMN ubicacion');
        Schema::dropIfExists('zonas');
    }
};
