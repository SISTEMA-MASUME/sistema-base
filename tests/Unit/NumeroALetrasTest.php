<?php

namespace Tests\Unit;

use App\Support\NumeroALetras;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumeroALetrasTest extends TestCase
{
    #[DataProvider('casos')]
    public function test_pesos(float|int $importe, string $esperado): void
    {
        $this->assertSame($esperado, NumeroALetras::pesos($importe));
    }

    public static function casos(): array
    {
        return [
            [47753.22, 'Cuarenta y siete mil setecientos cincuenta y tres pesos 22/100 M.N.'],
            [0, 'Cero pesos 00/100 M.N.'],
            [1, 'Un peso 00/100 M.N.'],
            [21, 'Veintiún pesos 00/100 M.N.'],
            [100, 'Cien pesos 00/100 M.N.'],
            [101.5, 'Ciento un pesos 50/100 M.N.'],
            [1000, 'Mil pesos 00/100 M.N.'],
            [1000.99, 'Mil pesos 99/100 M.N.'],
            [21000, 'Veintiún mil pesos 00/100 M.N.'],
            [31000, 'Treinta y un mil pesos 00/100 M.N.'],
            [1000000, 'Un millón de pesos 00/100 M.N.'],
            [2500000.05, 'Dos millones quinientos mil pesos 05/100 M.N.'],
            [1234567.89, 'Un millón doscientos treinta y cuatro mil quinientos sesenta y siete pesos 89/100 M.N.'],
            [116.999, 'Ciento diecisiete pesos 00/100 M.N.'],
        ];
    }
}
