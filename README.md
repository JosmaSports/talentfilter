# Talent Filter

Sistema de separación y clasificación automática de CVs mediante IA (OpenAI).

## Requisitos

- XAMPP (PHP 8.0+ con extensiones: curl, mbstring, zip, fileinfo)
- MySQL 5.7+
- Composer

## Instalación

### 1. Instalar dependencias PHP

```bash
php composer.phar install
```

### 2. Crear la base de datos

Accede a `http://localhost/talent-filter/install.php` en tu navegador. Esto creará la base de datos `talent_filter` con todas las tablas necesarias.

### 3. Configurar PHP (importante para PDFs grandes)

Edita `C:\xampp\php\php.ini` y ajusta estos valores:

```ini
upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 300
memory_limit = 512M
max_input_time = 300
```

Reinicia Apache después de los cambios.

### 4. Configurar API de OpenAI

1. Accede a `http://localhost/talent-filter/?page=configuracion`
2. Introduce tu API Key de OpenAI
3. Selecciona el modelo (recomendado: GPT-4o Mini)
4. Prueba la conexión

## Uso

### Extractor
1. Introduce el nombre de la selección de CVs
2. Sube un PDF con múltiples curriculums
3. El sistema separará cada página en un PDF independiente

### CVs Pendientes
1. Selecciona la selección o archivo a procesar
2. Pulsa "Unificar CVs"
3. El sistema analizará página por página con IA para detectar inicios de CV
4. Las páginas se agruparán y unificarán automáticamente

### CVs Listos
- Descarga individual o masiva (ZIP) de los CVs procesados
- Filtros por selección, nombre de candidato y número de páginas
- Eliminación con confirmación

## Estructura

```
talent-filter/
├── index.php              # Layout principal
├── install.php            # Instalador de BD
├── config/database.php    # Configuración BD
├── includes/              # Funciones y Logger
├── pages/                 # Vistas (extractor, pendientes, listos, config, logs)
├── api/                   # Endpoints AJAX
├── assets/css/            # Estilos
├── assets/js/             # JavaScript
└── uploads/               # PDFs (originals, pages, unified)
```

## Notas técnicas

- La separación de PDFs se hace por lotes (50 páginas/petición) para evitar timeouts
- El análisis con IA es secuencial (1 página por petición AJAX) para control granular
- La unificación agrupa páginas entre inicios de CV detectados por la IA
- Todo el procesamiento es asíncrono vía AJAX
