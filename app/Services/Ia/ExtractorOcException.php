<?php

namespace App\Services\Ia;

use RuntimeException;

/** Falla esperable al hablar con la IA; el mensaje ya es entendible para el usuario. */
class ExtractorOcException extends RuntimeException {}
