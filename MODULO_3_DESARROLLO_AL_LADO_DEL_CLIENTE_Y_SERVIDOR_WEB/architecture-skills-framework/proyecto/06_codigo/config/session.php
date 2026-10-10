<?php
declare(strict_types=1);

// CP-BACK-03: Configuración de sesión segura PHP para S3IH (RNF-030)
return [
    'name' => 's3ih_session',
    'lifetime' => 1800,        // 30 minutos según RNF-030
    'httponly' => true,        // Mitigación XSS: JS no puede leer la cookie
    'samesite' => 'Lax',
    'strict' => true,          // use_strict_mode: rechaza IDs de sesión no generados por el servidor
];
