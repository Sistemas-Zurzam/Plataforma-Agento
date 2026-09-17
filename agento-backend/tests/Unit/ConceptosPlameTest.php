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

    public function test_renta_de_quinta_se_exporta_con_codigo_0605(): void
    {
        $this->assertNotContains('RENTA_5TA', ConceptosPlame::NO_EXPORTABLES_REM);
        $this->assertNotContains('0605', ConceptosPlame::CODIGOS_EXCLUIDOS_REM);
    }

    public function test_sis_se_exporta_con_codigo_0811(): void
    {
        $this->assertNotContains('SIS_APORTACION', ConceptosPlame::NO_EXPORTABLES_REM);
        $this->assertNotContains('0811', ConceptosPlame::CODIGOS_EXCLUIDOS_REM);
    }

    public function test_essalud_se_exporta_con_codigo_0804(): void
    {
        $this->assertNotContains('ESSALUD', ConceptosPlame::NO_EXPORTABLES_REM);
        $this->assertNotContains('0804', ConceptosPlame::CODIGOS_EXCLUIDOS_REM);
    }
}
