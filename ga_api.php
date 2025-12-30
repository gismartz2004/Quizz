<?php
/**
 * Google Analytics 4 - Measurement Protocol Helper
 * Envía eventos server-side a Google Analytics
 */

class GoogleAnalyticsAPI {
    private $measurement_id;
    private $api_secret;
    private $endpoint = 'https://www.google-analytics.com/mp/collect';

    public function __construct($measurement_id = null, $api_secret = null) {
        // Cargar configuración desde archivo
        $config = file_exists('ga_config.php') ? include('ga_config.php') : [];
        
        $this->measurement_id = $measurement_id ?: ($config['measurement_id'] ?? 'G-XXXXXXXXXX');
        $this->api_secret = $api_secret ?: ($config['api_secret'] ?? 'YOUR_API_SECRET_HERE');
    }

    /**
     * Envía un evento a Google Analytics
     * @param string $client_id - Identificador único del usuario (puede ser email hasheado)
     * @param string $event_name - Nombre del evento (ej: 'exam_completed')
     * @param array $params - Parámetros del evento
     * @return bool - true si se envió correctamente
     */
    public function enviarEvento($client_id, $event_name, $params = []) {
        $url = $this->endpoint . '?measurement_id=' . $this->measurement_id . '&api_secret=' . $this->api_secret;

        $payload = [
            'client_id' => $client_id,
            'events' => [
                [
                    'name' => $event_name,
                    'params' => $params
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // GA4 Measurement Protocol devuelve 204 si es exitoso
        return $http_code === 204;
    }

    /**
     * Envía un evento de examen completado
     */
    public function enviarExamenCompletado($usuario_id, $usuario_nombre, $puntos, $paralelo, $quiz_titulo) {
        // Crear un client_id único (hash del usuario_id para privacidad)
        $client_id = md5($usuario_id);

        $params = [
            'student_name' => $usuario_nombre,
            'score' => (int)$puntos,
            'max_score' => 250,
            'percentage' => round(($puntos / 250) * 100, 2),
            'parallel' => $paralelo,
            'exam_name' => $quiz_titulo,
            'grade' => 'Décimo',
            'location' => 'Guayaquil'
        ];

        return $this->enviarEvento($client_id, 'exam_completed', $params);
    }
}
?>
