<?php
/**
 * generar_sql_estudiantes.php
 * Genera un script SQL para insertar múltiples estudiantes con códigos secuenciales.
 */

$cantidad = 100; // Puedes cambiar este número
$prefijo = "EST"; // Ejemplo: EST1001, EST1002...
$inicio = 1001;

$sql = "-- SCRIPT GENERADO PARA $cantidad ESTUDIANTES\n";
$sql .= "INSERT INTO usuarios (nombre, password, rol) VALUES \n";

$values = [];
for ($i = 0; $i < $cantidad; $i++) {
    $codigo = $prefijo . ($inicio + $i);
    $nombre = "Estudiante " . str_pad($i + 1, 3, "0", STR_PAD_LEFT);
    $values[] = "('$nombre', '$codigo', 'estudiante')";
}

$sql .= implode(",\n", $values) . ";\n";

// Guardar en un archivo SQL
file_put_contents('carga_masiva_estudiantes.sql', $sql);

echo "✅ Se ha generado el archivo 'carga_masiva_estudiantes.sql' con $cantidad estudiantes.\n";
echo "Puedes abrirlo y copiar su contenido para ejecutarlo en el SQL Editor de Neon.\n";
?>
