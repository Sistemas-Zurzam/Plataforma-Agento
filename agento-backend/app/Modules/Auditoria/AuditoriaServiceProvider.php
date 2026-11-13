<?php

namespace App\Modules\Auditoria;

use App\Modules\Auditoria\Support\AuditoriaContexto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditoriaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditoriaContexto::class);
    }

    public function boot(): void
    {
        // Solo created/updated/deleted: los eventos "retrieved"/"saving" son
        // demasiado frecuentes y no representan un cambio.
        foreach (['created', 'updated', 'deleted'] as $evento) {
            Event::listen("eloquent.{$evento}: *", function (string $nombre, array $datos) use ($evento): void {
                $modelo = $datos[0] ?? null;

                if ($modelo instanceof Model) {
                    $this->app->make(AuditoriaContexto::class)->registrar($evento, $modelo);
                }
            });
        }
    }
}
