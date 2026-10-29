<?php

namespace App\Modules\Reclutamiento\Domain;

use DomainException;

/**
 * Transición de estado no permitida por las reglas del dominio. Se responde
 * como 422 (ver bootstrap/app.php).
 */
class TransicionInvalidaException extends DomainException {}
