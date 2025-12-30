<?php
/**
 * Configuración de Google Analytics 4
 * 
 * INSTRUCCIONES PARA OBTENER TUS CREDENCIALES:
 * 
 * 1. Ve a https://analytics.google.com
 * 2. Selecciona tu propiedad
 * 3. Ve a Administrar > Flujos de datos > Selecciona tu flujo web
 * 4. Copia el MEASUREMENT_ID (formato: G-XXXXXXXXXX)
 * 5. En la misma página, busca "Measurement Protocol API secrets"
 * 6. Crea un nuevo secreto y copia el valor
 */

return [
    // Tu ID de medición de Google Analytics 4
    'measurement_id' => 'G-SQ96GGJ0ZT',
    
    // Tu secreto de API del Measurement Protocol
    'api_secret' => 'YOUR_API_SECRET_HERE',
    
    // Opcional: Habilitar modo debug (true/false)
    'debug_mode' => false
];
?>
