<?php

namespace App\Modules\Reclutamiento;

use App\Modules\Reclutamiento\Console\CerrarIntentosVencidosCommand;
use App\Modules\Reclutamiento\Console\VencerCandidatosCommand;
use App\Modules\Reclutamiento\Domain\Contracts\ExtractorCv;
use App\Modules\Reclutamiento\Domain\Contracts\IniciadorOnboarding;
use App\Modules\Reclutamiento\Domain\Contracts\ProveedorCompatibilidad;
use App\Modules\Reclutamiento\Infrastructure\OnboardingPorTareaPendiente;
use App\Modules\Reclutamiento\Infrastructure\SinProveedorAts;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ReclutamientoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Solo existe el adaptador "ninguno". Un proveedor real se agrega
        // aquí según config('reclutamiento.ats.proveedor').
        $this->app->bind(ExtractorCv::class, SinProveedorAts::class);
        $this->app->bind(ProveedorCompatibilidad::class, SinProveedorAts::class);
        $this->app->bind(IniciadorOnboarding::class, OnboardingPorTareaPendiente::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VencerCandidatosCommand::class, CerrarIntentosVencidosCommand::class]);
        }

        RateLimiter::for('seleccion-codigos', function (Request $request) {
            $limite = (int) config('reclutamiento.acceso.limite_codigos_por_minuto', 3);
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute($limite)->by('ip:'.$request->ip()),
                Limit::perMinute($limite)->by('email:'.hash('sha256', $email)),
                Limit::perHour($limite * 5)->by('ip-hora:'.$request->ip()),
            ];
        });

        // Verificar tiene su propio límite: además, cada código admite un
        // número máximo de intentos (config reclutamiento.acceso.codigo_max_intentos).
        RateLimiter::for('seleccion-verificacion', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('seleccion-publico', function (Request $request) {
            return Limit::perMinute((int) config('reclutamiento.acceso.limite_publico_por_minuto', 60))->by($request->ip());
        });
    }
}
