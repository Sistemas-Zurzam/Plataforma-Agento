<?php

namespace App\Modules\Asistencia\Http\Requests;

/**
 * Misma forma que la creación: el modal de edición envía siempre la
 * configuración semanal completa (reemplazo total, no un parche parcial).
 */
class UpdateHorarioRequest extends StoreHorarioRequest {}
