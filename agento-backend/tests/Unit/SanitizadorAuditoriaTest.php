<?php

namespace Tests\Unit;

use App\Modules\Auditoria\Support\SanitizadorAuditoria;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SanitizadorAuditoriaTest extends TestCase
{
    #[Test]
    public function enmascara_claves_sensibles_en_cualquier_nivel(): void
    {
        $payload = (new SanitizadorAuditoria)->payload([
            'nombre' => 'Ana',
            'password' => 'secreto',
            'datos' => ['api_key' => 'abc', 'edad' => 30],
        ]);

        $this->assertSame('Ana', $payload['nombre']);
        $this->assertSame('[OCULTO]', $payload['password']);
        $this->assertSame('[OCULTO]', $payload['datos']['api_key']);
        $this->assertSame(30, $payload['datos']['edad']);
    }

    #[Test]
    public function trunca_payloads_que_exceden_el_tope(): void
    {
        Config::set('auditoria.max_payload_bytes', 100);

        $payload = (new SanitizadorAuditoria)->payload(['a' => str_repeat('x', 400), 'b' => str_repeat('y', 400)]);

        $this->assertTrue($payload['_truncado']);
        $this->assertSame(['a', 'b'], $payload['_claves']);
    }

    #[Test]
    public function devuelve_null_si_no_hay_datos(): void
    {
        $this->assertNull((new SanitizadorAuditoria)->payload([]));
    }
}
