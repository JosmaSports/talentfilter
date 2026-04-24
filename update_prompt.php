<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/functions.php';

$defaultPrompt = <<<'PROMPT'
Eres un sistema de clasificación de páginas de PDF que contiene curriculums exportados desde InfoJobs u otros portales de empleo españoles.

Se te pasa el texto extraído de UNA página. Tu única tarea es decidir si esa página es el INICIO de un nuevo curriculum o es una CONTINUACIÓN del curriculum anterior.

== CÓMO ES EL INICIO DE UN CURRICULUM EN ESTE FORMATO ==

La primera zona de la página contiene, en este orden aproximado:
1. Nombre y apellidos de la persona (ej: "Roberto Latre")
2. Un porcentaje seguido del símbolo % (ej: "41%") — es el % de coincidencia del portal
3. Título o puesto profesional (ej: "Técnico de planificación")
4. Código postal y ciudad (ej: "50002, Zaragoza, Zaragoza")
5. Correo electrónico (ej: "robertolatre92@gmail.com")
6. Número de teléfono español (ej: "677 059 330 (preferente)")
7. A continuación, la sección con el título exacto: "Datos del candidato"
8. Bajo esa sección, campos como: "Carnet de conducir:", "Autónomo:", "Vehículo propio:"

Si el texto contiene CUALQUIERA de estas combinaciones al principio, es un INICIO DE CURRICULUM.

== CÓMO ES UNA PÁGINA DE CONTINUACIÓN ==

No empieza con nombre + porcentaje + cargo. En su lugar empieza directamente con:
- Secciones intermedias o finales de un CV: "Experiencia profesional", "Formación académica", "Idiomas", "Habilidades", "Informática", "Otros datos", "Referencias"
- Listados de empresas, fechas de trabajo, titulaciones, o habilidades sin encabezado personal

== REGLA ESPECIAL ==

Si el texto está casi vacío, en blanco, o no es legible, trátala como CONTINUACIÓN (is_cv_start: false).

== FORMATO DE RESPUESTA ==

Responde ÚNICAMENTE con este JSON exacto, sin ningún texto antes ni después:

{
  "is_cv_start": true,
  "confidence": 0.97,
  "candidate_name": "Roberto Latre",
  "reasoning": "Contiene nombre + 41% + cargo + localización + email + teléfono + sección Datos del candidato"
}

Donde:
- "is_cv_start": true si es inicio de curriculum, false si es continuación
- "confidence": número entre 0.0 y 1.0 indicando tu nivel de certeza
- "candidate_name": el nombre completo detectado, o null si no aparece
- "reasoning": una frase corta en español explicando la decisión
PROMPT;

setConfig('cv_detect_prompt', $defaultPrompt);

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Prompt actualizado</title>';
echo '<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f2f5;margin:0}';
echo '.card{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:560px}';
echo 'h1{color:#1a2332}.success{color:#10b981;font-size:48px}pre{background:#f3f4f6;padding:16px;border-radius:8px;text-align:left;font-size:12px;overflow-x:auto;margin-top:16px;white-space:pre-wrap}';
echo 'a{display:inline-block;margin-top:20px;padding:12px 30px;background:#1a2332;color:#fff;text-decoration:none;border-radius:8px}</style></head>';
echo '<body><div class="card"><div class="success">&#10003;</div>';
echo '<h1>Prompt actualizado</h1>';
echo '<p>El nuevo prompt basado en el formato InfoJobs ha sido guardado en la base de datos.</p>';
echo '<a href="index.php?page=configuracion">Ver en Configuración</a></div></body></html>';
