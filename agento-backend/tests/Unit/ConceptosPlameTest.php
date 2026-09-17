<?php

namespace Tests\Unit;

use App\Modules\Nominas\Domain\Plame\ConceptosPlame;
use PHPUnit\Framework\TestCase;

class ConceptosPlameTest extends TestCase
{
    public function test_onp_es_administrado_por_sunat_y_no_se_exporta_como_linea_rem(): void
    {
        $this->assertContains('ONP', ConceptosPlame::NO_EXPORTABLES_REM);
        $this->assertContains('0607', ConceptosPlame::CODIGOS_EXCLUIDOS_REM);
    }
}
