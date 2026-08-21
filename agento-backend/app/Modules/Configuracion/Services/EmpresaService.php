<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class EmpresaService
{
    /**
     * Create a new empresa and attach the creator as its administrator.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearParaUsuario(array $datos, User $usuario): Empresa
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $empresa = Empresa::create([...$datos, 'activa' => true]);

            $empresa->users()->attach($usuario->id, ['role_id' => Role::administrador()->id]);

            return $empresa->setRelation(
                'pivot',
                $empresa->users()->find($usuario->id)?->pivot,
            );
        });
    }

    /**
     * Switch the user's currently active empresa.
     *
     * @throws AuthorizationException if the user has no access to the empresa.
     */
    public function activarParaUsuario(Empresa $empresa, User $usuario): void
    {
        if (! $this->tieneAcceso($empresa, $usuario)) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }

        if (! $empresa->activa) {
            throw new AuthorizationException('No puedes seleccionar una empresa inactiva.');
        }

        $usuario->update(['empresa_id' => $empresa->id]);
    }

    /**
     * Update an empresa's own data.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws AuthorizationException if the user is not an administrador of the empresa.
     */
    public function actualizar(Empresa $empresa, array $datos, User $usuario): Empresa
    {
        if (! $this->puedeEditar($empresa, $usuario)) {
            throw new AuthorizationException('No puedes editar esta empresa.');
        }

        $empresa->update($datos);

        return $empresa->setRelation(
            'pivot',
            $empresa->users()->find($usuario->id)?->pivot,
        );
    }

    /**
     * A diferencia de los documentos de colaborador (privados, servidos por
     * descarga autenticada), el logo es información de marca sin
     * sensibilidad — se guarda en el disco "public" con URL directa, sin
     * necesidad de un endpoint de descarga aparte.
     *
     * El archivo subido NUNCA se guarda tal cual: se redimensiona (sin
     * agrandar imágenes ya pequeñas) y se recomprime a WebP antes de tocar
     * disco — un logo de varios MB en 4000x4000px no tiene sentido servirlo
     * así para un avatar de 48px. SVG se guarda intacto (es vectorial).
     *
     * @throws AuthorizationException if the user is not an administrador of the empresa.
     */
    public function guardarLogo(Empresa $empresa, UploadedFile $archivo, User $usuario): Empresa
    {
        if (! $this->puedeEditar($empresa, $usuario)) {
            throw new AuthorizationException('No puedes editar esta empresa.');
        }

        $rutaAnterior = $empresa->logo_path;
        $esSvg = $archivo->getClientMimeType() === 'image/svg+xml'
            || strtolower($archivo->getClientOriginalExtension()) === 'svg';

        Storage::disk('public')->makeDirectory("empresas/{$empresa->id}");

        if ($esSvg) {
            $ruta = $archivo->store("empresas/{$empresa->id}", 'public');
        } else {
            $ruta = "empresas/{$empresa->id}/".Str::random(20).'.webp';

            ImageManager::gd()
                ->read($archivo->getRealPath())
                ->scaleDown(width: 512, height: 512)
                ->save(Storage::disk('public')->path($ruta), quality: 80);
        }

        try {
            $empresa->update(['logo_path' => $ruta]);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($ruta);
            throw $exception;
        }

        if ($rutaAnterior && $rutaAnterior !== $ruta) {
            Storage::disk('public')->delete($rutaAnterior);
        }

        return $empresa->setRelation(
            'pivot',
            $empresa->users()->find($usuario->id)?->pivot,
        );
    }

    public function actualizarEstado(Empresa $empresa, bool $activa, User $usuario): Empresa
    {
        if (! $this->puedeEditar($empresa, $usuario)) {
            throw new AuthorizationException('No puedes cambiar el estado de esta empresa.');
        }

        $empresa->update(['activa' => $activa]);

        return $empresa->setRelation(
            'pivot',
            $empresa->users()->find($usuario->id)?->pivot,
        );
    }

    /**
     * Autoriza una acción usando el rol que el usuario tiene en la empresa
     * objetivo, no el rol de la empresa que esté activa en ese momento. Un
     * administrador global siempre pasa, aunque no tenga una fila propia en
     * empresa_user para esta empresa puntual (ver User::esAdministradorGlobal).
     */
    public function autorizarAccion(Empresa $empresa, User $usuario, string $permiso): void
    {
        if ($usuario->esAdministradorGlobal()) {
            return;
        }

        $vinculo = $usuario->empresas()
            ->where('empresas.id', $empresa->id)
            ->first();

        if (! $vinculo) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }

        $rol = Role::with('permissions')->find($vinculo->pivot->role_id);
        $autorizado = $rol?->clave === Role::ADMINISTRADOR
            || $rol?->permissions->contains('clave', $permiso);

        if (! $autorizado) {
            throw new AuthorizationException('No tienes permiso para realizar esta acción en esta empresa.');
        }
    }

    public function esAdministradorEn(Empresa $empresa, User $usuario): bool
    {
        return $usuario->esAdministradorGlobal()
            || $usuario->empresas()
                ->where('empresas.id', $empresa->id)
                ->wherePivot('role_id', Role::administrador()->id)
                ->exists();
    }

    private function tieneAcceso(Empresa $empresa, User $usuario): bool
    {
        return $usuario->tieneAccesoA($empresa);
    }

    private function puedeEditar(Empresa $empresa, User $usuario): bool
    {
        return $this->esAdministradorEn($empresa, $usuario);
    }
}
