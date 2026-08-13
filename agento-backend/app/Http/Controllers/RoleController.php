<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActivaId = $request->user('api')->empresa_id;

        $roles = Role::withCount(['empresaUsuarios as usuarios_count' => function ($query) use ($empresaActivaId) {
            $query->where('empresa_id', $empresaActivaId);
        }])->orderBy('id')->get();

        return RoleResource::collection($roles);
    }
}
