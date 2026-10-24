<?php

namespace App\Console\Commands;

use App\Models\ErrorSistema;
use Illuminate\Console\Command;

class LimpiarErrores extends Command
{
    protected $signature = 'masume:limpiar-errores';

    protected $description = 'Borra los errores resueltos con más de 90 días';

    public function handle(): int
    {
        $limite = now()->subDays(90);
        $borrados = ErrorSistema::where('resuelto', true)
            ->where(fn ($q) => $q->where('resuelto_en', '<', $limite)
                ->orWhere(fn ($q2) => $q2->whereNull('resuelto_en')->where('ultima_vez', '<', $limite)))
            ->delete();

        $this->info("Errores borrados: {$borrados}");

        return self::SUCCESS;
    }
}
