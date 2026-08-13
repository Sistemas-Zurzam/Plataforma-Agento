<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException) {
            return response()->json(['message' => 'Token expirado'], 401);
        } catch (TokenInvalidException) {
            return response()->json(['message' => 'Token inválido'], 401);
        } catch (\Throwable) {
            return response()->json(['message' => 'Token no proporcionado'], 401);
        }

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 401);
        }

        return $next($request);
    }
}
