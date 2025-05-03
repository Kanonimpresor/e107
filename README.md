# [![Sistema de Gestión de Contenidos e107](https://e107.org/e107_images/logoHD.png)](https://e107.org)

[![Versiones PHP compatibles](https://img.shields.io/badge/PHP-8.0,%208.1,%208.2,%208.3,,%208.4-blue)](https://github.com/e107inc/e107/)
[![Última versión](https://img.shields.io/github/v/release/e107inc/e107?logo=data\:image/png;base64,...)](https://github.com/e107inc/e107/releases)
[![Estado de las pruebas unitarias](https://img.shields.io/github/actions/workflow/status/e107inc/e107/test-unit.yml?logo=data\:image/png;base64,...)](https://github.com/e107inc/e107/actions)
[![Cobertura del código](https://img.shields.io/codeclimate/coverage/e107inc/e107?logo=code-climate)](https://codeclimate.com/github/e107inc/e107/test_coverage)
[![Chat en Gitter](https://img.shields.io/gitter/room/e107inc/e107?logo=gitter\&color=ED1965)](https://gitter.im/e107inc/e107?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=badge)
[![Descargar e107](https://img.shields.io/sourceforge/dt/e107.svg)](https://sourceforge.net/projects/e107/files/latest/download)

**\[e107]\[1]** es un sistema de gestión de contenidos (CMS) libre y de código abierto que te permite gestionar y publicar contenido online de forma sencilla. Los desarrolladores pueden ahorrar tiempo creando sitios web y aplicaciones web potentes. ¡Y los usuarios pueden evitar programar por completo! Blogs, webs, intranets... e107 lo hace todo.

## Tabla de contenidos

* [e107 Sistema de Gestión de Contenidos](README.md)

  * [Tabla de contenidos](#tabla-de-contenidos)
  * [Requisitos](#requisitos)

    * [Mínimos](#minimos)
    * [Recomendados](#recomendados)
    * [Guía de versiones](#guia-de-versiones)
  * [Instalación](#instalacion)

    * [Instalación estándar](#instalacion-estandar)
    * [Instalación con Git (versión para desarrolladores)](#instalacion-con-git-version-para-desarrolladores)
  * [Informe de errores](#informe-de-errores)
  * [Contribuir al desarrollo](#contribuir-al-desarrollo)
  * [Donaciones](#donaciones)
  * [Soporte](#soporte)
  * [Licencia](#licencia)

## Requisitos

## Mínimos

* Un servidor web (Apache o Microsoft IIS) con PHP 7.4 o superior
* MySQL 4.x o superior, o MariaDB
* Acceso FTP al servidor y un cliente FTP (como FileZilla)
* Nombre de usuario y contraseña de tu base de datos MySQL

## Recomendados

* Apache 2.4 o superior en Linux
* PHP 7.4 o superior
* MySQL 5.6 o superior, o MariaDB 10.3 o superior
* Un nombre de dominio registrado
* Acceso a un panel de control del servidor (como cPanel)

## Guía de versiones

| Versión | Estado |                               Última versión                              |  Versión PHP  |
| :-----: | :----: | :-----------------------------------------------------------------------: | :-----------: |
|  1.0.x  |   EOL  | [v1.0.4](https://sourceforge.net/projects/e107/files/e107/e107%20v1.0.4/) |     >= 5.6    |
|  1.0.4  |   EOL  | [v1.0.4](https://sourceforge.net/projects/e107/files/e107/e107%20v1.0.4/) |     >= 5.6    |
|  2.0.x  |   EOL  |             [v2.3.3](https://github.com/e107inc/e107/releases)            |     >= 7.4    |
|  2.1.x  |   EOL  |             [v2.3.3](https://github.com/e107inc/e107/releases)            |     >= 7.4    |
|  2.2.x  |   EOL  |             [v2.3.3](https://github.com/e107inc/e107/releases)            |   5.6 - 7.4   |
|  2.3.2  |   OK   |             [v2.3.3](https://github.com/e107inc/e107/releases)            |  5.6 - 8.1.\* |
|  2.3.3  | Última |             [v2.3.3](https://github.com/e107inc/e107/releases)            | 7.4 - ^8.2.12 |

## Instalación

## Instalación estándar

1. [Descarga e107](https://e107.org/download).
2. Descomprime el archivo en la carpeta deseada de tu servidor web (por ejemplo, `public_html`).
3. Abre tu navegador y accede al archivo `install.php` (por ejemplo, `https://tudominio.com/carpeta/install.php`).
4. Sigue el asistente de instalación en tu navegador.

## Instalación con Git (versión para desarrolladores)

1. Ejecuta los siguientes comandos, reemplazando `~` con tu directorio de documentos (padre de `public_html`) y `xxx:xxx` por el propietario de los archivos:

   ```
   cd ~
   git clone https://github.com/e107inc/e107.git public_html
   chown -R xxx:xxx public_html
   ```
2. Abre tu navegador y accede al archivo `install.php` (por ejemplo, `https://tudominio.com/carpeta/install.php`).
3. Sigue el asistente de instalación.

## Informe de errores

Asegúrate de estar usando la versión más reciente de e107 antes de informar un problema.
Puedes informar errores o solicitar nuevas funciones en la [página de issues de GitHub](https://github.com/e107inc/e107/issues).

## Contribuir al desarrollo

* Envía 1 pull request por cada issue de GitHub que trabajes.
* Asegúrate de que solo las líneas que has modificado aparezcan en el diff.
  Algunos editores cambian todas las líneas; esto debe evitarse.
* Se recomienda configurar `git pull` para hacer rebase con la rama master y evitar commits de merge innecesarios. Puedes hacerlo en el archivo `.git/config` de tu repo:

  ```
  [branch "master"]
    rebase = true
  ```
* Consulta el documento [CONTRIBUTING](.github/CONTRIBUTING.md) para empezar.

## Donaciones

Si te gusta e107 y quieres ayudar a que mejore, considera hacer una pequeña donación.

* PayPal: donate (arroba) e107.org

## Soporte

¿Tienes problemas para instalar e107? ¿Algo no funciona como debería? Aunque no tenemos tiempo para mantener una comunidad de soporte completa, hay varias formas de obtener ayuda:

* Si crees que encontraste un bug, consulta la sección de Informe de errores.
* Si necesitas ayuda para usar e107 o tienes dudas sobre desarrollo (como crear un tema o plugin), visita nuestra [documentación](https://e107.org/developer-manual).
* También puedes pedir ayuda en [Discusiones de GitHub](https://github.com/e107inc/e107/discussions).
* Para soporte técnico en tiempo real, visita nuestro [canal en Gitter](https://gitter.im/e107inc/e107).
* Para otros comentarios, síguenos en nuestras redes sociales: Facebook, Twitter, Google+ y Reddit.

## Licencia

e107 se distribuye bajo los términos y condiciones de la licencia GNU/GPL.
