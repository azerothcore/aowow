![logo](static/images/logos/home.png)

[🇬🇧 Ingles](README.md) | [🇪🇸 Español](README_ES.md)

## Introducción

- AoWoW es una herramienta de base de datos para World of Warcraft v3.3.5 (compilación 12340)
- Se basa en la otra famosa herramienta de base de datos de WoW, featuring the red smiling rocket.
- Si bien los primeros lanzamientos se pueden encontrar ya en 2008, hoy es imposible decir quién creó este proyecto.
- Esta es una reescritura completa del código PHP del lado del servidor y una actualización de los javascripts del lado del cliente desde 2008 a algo del 2013.
- Yo mismo no me atribuyo ningún crédito por las secuencias de comandos, el diseño y la disposición del lado del cliente que estos scripts php atienden.
- Además, ¡este proyecto no está destinado a ser utilizado con fines comerciales de ningún tipo!

## Requisitos

+ Servidor web que ejecuta PHP ≥ 8.0, incluidas las extensiones:
  + SimpleXML
  + GD
  + Mysqli
  + mbString
+ MySQL ≥ 5.6
+ Las herramientas requieren cmake: consulte los repositorios individuales para obtener información detallada
  + [MPQExtractor](https://github.com/Sarjuuk/MPQExtractor) / [FFmpeg](https://ffmpeg.org/download.html) / [BLPConverter](https://github.com/Sarjuuk/BLPConverter) (opcional)
  + A los usuarios de Windows les puede resultar más fácil utilizar estas alternativas
     + [MPQEditor](http://www.zezula.net/en/mpq/download.html) / [FFmpeg](http://ffmpeg.zeranoe.com/builds/) / [BLPConverter](https://github.com/PatrickCyr/BLPConverter) (opcional)

El procesamiento de audio puede requerir [lame](https://sourceforge.net/projects/lame/files/lame/3.99/) o [vorbis-tools](https://www.xiph.org/downloads/) (que puede requerir libvorbis (que puede requerir libogg))

En Linux (basado en Debian) puede instalar los requisitos con el siguiente comando:

```
sudo apt install php-gd php-xml php-mbstring -y
```

```
apt install php-gd php-xml php-mbstring -y
```

#### Altamente recomendada

+ Establecer los siguientes valores de configuración en su servidor AzerothCore aumentará en gran medida la precisión de los puntos de generación
  > Calculate.Creature.Zone.Area.Data = 1
  > Calculate.Gameoject.Zone.Area.Data = 1

## Instalación

#### 1. Adquirir los repositorios requeridos

- `git clone https://github.com/azerothcore/aowow.git aowow`
- `git clone https://github.com/Sarjuuk/MPQExtractor.git MPQExtractor`

#### 2. Preparar la base de datos

Asegúrese de que la cuenta que va a utilizar tenga acceso **completo** a la base de datos que AoWoW va a ocupar e idealmente solo acceso de **lectura** a la base de datos mundial a la que va a hacer referencia. Importe `setup/db_structure.sql` a la base de datos de AoWoW `mysql -p {your-db-here} < setup/db_structure.sql`

Importe a su base de datos AzerothCore la tabla `spell_learn_spell`, impórtela desde `www-aowow/setup/spell_learn_spell.sql`.

#### 3. Archivos creados en el servidor

Asegúrese de que el servidor web pueda escribir los siguientes directorios y sus hijos. Si faltan, la configuración los creará con los permisos adecuados.

 * `cache/`
 * `config/`
 * `static/download/`
 * `static/widgets/`
 * `static/js/`
 * `static/uploads/`
 * `static/images/wow/`
 * `datasets/`
 
#### 4. Extraiga los archivos del cliente (MPQ)

Extraiga los siguientes directorios de los archivos del cliente a `setup/mpqdata/`, mientras mantiene el orden de los parches (base mpq -> patch-mpq: 1 -> 9 -> A -> Z). Las rutas requeridas se encuentran dispersas por los archivos. Sobrescriba los archivos más antiguos si se le solicita.

   .. Para cada configuración regional que vayas a utilizar:
   > \<localeCode>/DBFilesClient/
   > \<localeCode>/Interface/WorldMap/
   > \<localeCode>/Interface/FrameXML/GlobalStrings.lua

   .. Una vez es suficiente (aunque aún aplica el código local):
   > \<localeCode>/Interface/TalentFrame/
   > \<localeCode>/Interface/Glues/Credits/
   > \<localeCode>/Interface/Icons/
   > \<localeCode>/Interface/Spellbook/
   > \<localeCode>/Interface/PaperDoll/
   > \<localeCode>/Interface/GLUES/CHARACTERCREATE/
   > \<localeCode>/Interface/Pictures
   > \<localeCode>/Interface/PvPRankBadges
   > \<localeCode>/Interface/FlavorImages
   > \<localeCode>/Interface/Calendar/Holidays/
   > \<localeCode>/Sound/

   .. Opcionalmente (no usado en AoWoW):
   > \<localeCode>/Interface/GLUES/LOADINGSCREENS/

**PRESTE ATENCIÓN:** debe crear un directorio en `setup/mpqdata/` como `enus` (MINÚSCULAS) que contenga los datos enumerados anteriormente.

Por ejemplo, puede simplemente copiar el directorio `Interface` y `DBFilesClient` en `setup/mpqdata/enus/` y mover `Sound` a `setup/mpqdata/enus`.

Puede usar MPQ Extractor para extraer los datos; una vez que lo haya instalado correctamente, puede usar este script bash para extraer los datos en el orden correcto.

- [extract.sh](https://gist.github.com/Helias/d9bd7708e28e9e8dcd5274bd2f3b68bc)

#### 5. Vuelva a codificar los archivos de audio.

Los archivos WAV deben volver a codificarse como `ogg/vorbis` y algunos MP3 pueden identificarse como `application/octet-stream` en lugar de `audio/mpeg`.

 * [Ejemplo para Windows](https://gist.github.com/Sarjuuk/d77b203f7b71d191509afddabad5fc9f)
 * [Ejemplo para \*nix](https://gist.github.com/Sarjuuk/1f05ef2affe49a7e7ca0fad7b01c081d)

Nota: llevará mucho tiempo.

#### 6. Ejecute la configuración inicial desde la CLI

`php aowow --setup`

Esto debería guiarlo con una participación mínima requerida por su parte, pero llevará algo de tiempo, especialmente compilar las imágenes de zona. Úselo para familiarizarse con las otras funciones que tiene esta configuración. Sí, lo digo en serio: *¡Ve a leer el código!* Te ayudará a comprender cómo configurar AoWoW y mantenerlo sincronizado con tu base de datos mundial.

Cuando haya creado su cuenta de administrador, habrá terminado.

## Solución de problemas

**P: La página aparece blanca, sin ningún estilo.**

- R: El contenido estático no se muestra. O estás utilizando SSL y AoWoW no puede detectarlo o STATIC_HOST no está definido correctamente. De cualquier manera, esto se puede solucionar mediante la configuración `php aowow --siteconfig`
- Probablemente necesites modificar [13] y [18].
- Por ejemplo, si tu proyecto está en `htdocs/aowow/` (o `/var/www/html/aowow`), de ahí que lo visitas con `http://localhost/aowow/`, debes poner:

- [10] localhost/aowow
- [15] localhost/aowow/static

**P: Error grave: No se puede heredar la función abstracta \<nombreFunción> (abstracta previamente declarada en \<nombreClase>) en \<ruta>**

- R: Estás utilizando módulos de optimización de caché para PHP, que están en conflicto entre sí. (Zend OPcache, Cache, ..) Deshabilite todos menos uno.

**P: Algunas imágenes generadas aparecen distorsionadas o tienen problemas con el canal alfa.**

- R: La compresión de imágenes está más allá de mi comprensión, por lo que no puedo solucionar estos problemas dentro de blpReader.
- PERO puede convertir el archivo blp afectado en un archivo png en el mismo directorio, utilizando el BLPConverter proporcionado.
- AoWoW dará prioridad a los archivos png sobre los archivos blp.

**P: ¿Cómo puedo hacer que funcione el visor de modelos?**

- R: Ya no puedes. Wowhead cambió de Flash a WebGL (como debería) y movió o eliminó los archivos antiguos en el proceso.

**P: ¡Recibo errores aleatorios de JavaScript!**

- R: Algunas configuraciones de servidor o servicios externos (como Cloudflare) vienen con módulos que minimizan automáticamente los archivos js y css. A veces se rompen en el proceso. Deshabilite el módulo en este caso.

**P: Algunos resultados de búsqueda dentro del generador de perfiles actúan de forma bastante extraña. ¿Como funciona?**

- R: Cada vez que intentas ver un personaje nuevo, AoWoW debe buscarlo primero. Dado que los datos están estructurados para las necesidades de TrinityCore y no para una fácil visualización, AoWoW necesita guardarlos y reestructurarlos localmente. Con este fin, cada solicitud de carácter se coloca en una cola. Si bien la cola no está vacía, se ejecuta una única instancia de `prQueue` en segundo plano para no abrumar la base de datos de caracteres con solicitudes. Esto también significa que algunas consultas de búsqueda más exóticas no se pueden ejecutar en la base de datos de personajes y tienen que usar los perfiles almacenados en caché incompletos/obsoletos de AoWoW.

**P: La carga de la captura de pantalla falla porque el tamaño del archivo es demasiado grande y/o los subdirectorios son visibles desde la web.**

- R: Ese es un problema de configuración del servidor web. Si está utilizando Apache, es posible que necesite [habilitar el uso de .htaccess] (http://httpd.apache.org/docs/2.4/de/mod/core.html#allowoverride). Otros servidores requieren una configuración individual.

**P: Un artículo, misión o NPC que agregué o edité no se puede buscar. ¿Por qué?**

- R: Sólo se realiza una búsqueda en la configuración regional utilizada actualmente. Es posible que solo haya editado el campo de nombre en la tabla base en lugar de agregar varias cadenas en las tablas \*_locale apropiadas. En este caso, las búsquedas en una configuración regional distinta del inglés se ejecutan en un campo de nombre vacío._

**P: No se pudo conectar a la base de datos.**

- R: verifique la configuración de su archivo en `aowow/config/config.php`, si todo está correcto, verifique si su contraseña tiene el carácter **"#"** contenido en la contraseña y reemplácelo con el carácter *codificado (URL)* corresponsal **"%23"**, haga lo mismo con los caracteres especiales si aún recibe este error.
- Si no lo resuelve, intente no utilizar **"#"** en su contraseña.

**P: No puedo ver Datos breves. Error de consola "Markup.js" no encontrado**

R: Desafortunadamente, a veces la configuración puede fallar al copiar `tools/filegen/templates/Markup.js.in` en `static/js/Markup.js` por motivos de permisos, ya que esto también puede faltar en otros archivos js, verifique el problema de permisos o copiarlos manualmente.

## Gracias

- @mix: por proporcionar el script php para analizar .blp y .dbc en imágenes y tablas utilizables.
- @LordJZ: la clase contenedora para DBSimple; la idea básica para la clase de usuario.
- @kliver: Implementación básica de carga de capturas de pantalla.
- @Sarjuuk: mantener del proyecto.

## Gracias especiales

Said website with the red smiling rocket, for providing this beautifull website!

Por favor, no considere este proyecto como una estafa descarada, sino como "Nos gustó mucho su presentación, pero a medida que avanza el tiempo y el contenido, lamentablemente ya no nos proporciona los datos que necesitamos".

![Usa insignias](http://forthebadge.com/images/badges/uses-badges.svg)
