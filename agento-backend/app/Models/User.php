<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaUsuario;
use App\Modules\Configuracion\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'username', 'email', 'password', 'empresa_id', 'area_id', 'activo', 'token_version'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'token_version' => 'integer',
        ];
    }

    /**
     * The empresa currently active for this user (drives multitenant scoping).
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * All empresas this user has access to, with their role in each.
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class)
            ->using(EmpresaUsuario::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * This user's role in their currently active empresa. Un administrador
     * global sigue resolviendo a Administrador acá aunque no tenga una fila
     * propia en empresa_user para esta empresa puntual — si no, quedaría
     * bloqueado por EnsurePermission/EnsureIsEmpresaAdmin (que leen este
     * método) apenas cambiara a una empresa sin vínculo explícito.
     */
    public function currentRole(): ?Role
    {
        $rolEnEmpresaActiva = $this->empresas()
            ->where('empresas.id', $this->empresa_id)
            ->first()
            ?->pivot
            ?->role;

        return $rolEnEmpresaActiva ?? ($this->esAdministradorGlobal() ? Role::administrador() : null);
    }

    /**
     * Un Administrador en CUALQUIER empresa ve y opera en TODAS las
     * empresas del sistema, sin necesitar una fila en empresa_user por
     * cada una — así una empresa nueva queda visible de inmediato para
     * todos los administradores existentes, sin resincronizar nada. Los
     * demás roles siguen viendo solo las empresas donde tienen un vínculo
     * explícito (aislamiento multiempresa normal).
     */
    public function esAdministradorGlobal(): bool
    {
        return $this->empresas()->wherePivot('role_id', Role::administrador()->id)->exists();
    }

    /**
     * Punto único de verificación "¿puede este usuario operar en esta
     * empresa?" — un administrador global siempre puede, sin importar si
     * tiene o no una fila explícita en empresa_user para ESA empresa en
     * particular.
     */
    public function tieneAccesoA(Empresa $empresa): bool
    {
        return $this->esAdministradorGlobal()
            || $this->empresas()->where('empresas.id', $empresa->id)->exists();
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'empresa_id' => $this->empresa_id,
            'token_version' => $this->token_version,
        ];
    }
}
