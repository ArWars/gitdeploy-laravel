# Despliegue de proyectos Laravel usando webhooks Git

gitdeploy-laravel permite la implementación automatizada mediante solicitudes de webhook del servidor de su repositorio y extrae automáticamente el código del proyecto mediante el binario Git local.

Esto debería funcionar de inmediato con Laravel 9.x usando webhooks de servidores GitHub y GitLab.

Esta es una herramienta interna para ayudar con nuestro patrón de flujo de trabajo común, pero no dude en tomarla prestada, cambiarla y mejorarla.

## Instalación


### Paso 1

Agregue lo siguiente a su archivo `composer.json` y luego actualice su compositor como de costumbre:

    {
        "require" : {
            "ArWars/gitdeploy-laravel" : "dev-master"
        }
    }

O usar:

    composer require ArWars/gitdeploy-laravel

### Paso 2

Add the _/git-deploy_ route to CSRF exceptions so your repo's host can send messages to your project.


In file in `app/Http/Middleware/VerifyCsrfToken.php` add:

    protected $except = [
        'git-deploy',
    ];

### Paso 3 Opcional
En caso de que necesite una acción adicional después de una confirmación exitosa, puede agregar su propio Event Listener.
Por ejemplo, puede escribir su propio script de actualización para ejecutar migraciones, etc.

**1)**  Cree un Listener para realizar una acción cuando se realiza una implementación de git.
Abra el `App/Listeners` directorio (o créelo si no existe). Ahora crea un nuevo archivo y llámalo `GitDeployedListener.php`. Pegue este código:

```php
<?php

namespace App\Listeners;

use \ArWars\GitDeploy\Events\GitDeployed;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;

class GitDeployedListener implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        // TODO:
    }

    /**
     * Handle the event.
     *
     * @param  ReactionAdded  $event
     * @return void
     */
    public function handle(GitDeployed $gitDeployed)
    {
        // Haz algo de magia con los datos de eventos $gitDeployed contiene las confirmaciones
    }
}

```
Como puede ver, es un detector de eventos normal. Puede notar que el oyente `implements ShouldQueue`; es útil porque nuestra aplicación debe responder rápido. Si desea algunas cosas de larga ejecución en su detector de eventos, debe configurar una cola.

**2)** Ahora añadimos este oyente a `/App/Providers/EventServiceProvider.php` como cualquier otro detector de eventos:
```php
// ...

protected $listen = [

        // ...

       \ArWars\GitDeploy\Events\GitDeployed::class => [
            \App\Listeners\GitDeployedListener::class
        ]
    ];

// ...
```


## Usar

Agregue un webhook para http://your.website.url/git-deploy a su proyecto en GitHub/GitLab y este paquete se encargará del resto. El webhook debe activarse en eventos push.
Su sitio web recibirá automáticamente mensajes POST del administrador de repositorios y realizará una extracción de Git.

## Configuración

En la mayoría de los casos, el paquete encontrará el repositorio de Git y el ejecutable de Git correctos, pero recomendamos publicar nuestra configuración de todos modos porque le permitirá habilitar opciones de seguridad adicionales y notificaciones por correo electrónico.

Para agregar una ejecución de configuración personalizada:

    php artisan vendor:publish --provider="ArWars\GitDeploy\GitDeployServiceProvider"

Luego edite `/config/gitdeploy.php` para satisfacer sus necesidades.

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email recipients
    |--------------------------------------------------------------------------
    |
    | The email address and name that notification emails will be sent to.
    | Leave the array empty to disable emails.
    |
    | [
    |     ['name' => 'Joe Bloggs', 'address' => 'email@example1.com'],
    |     ['name' => 'Jane Doe', 'address' => 'email@example2.com'],
    |     ...
    | ]
    |
    */

    'email_recipients' => [],

    /*
    |--------------------------------------------------------------------------
    | Email sender
    |--------------------------------------------------------------------------
    |
    | The email address and name that notification emails will be sent from.
    | This will default to the sender in config(mail.from) if left null.
    |
    */

    'email_sender' => ['address' => null, 'name' => null],

    /*
    |--------------------------------------------------------------------------
    | Repository path
    |--------------------------------------------------------------------------
    |
    | This the root path of the Git repository that will be pulled. If this
    | is left empty the script will try to determine the directory itself
    | but looking for the project's .env file it's nearby .git directory.
    |
    | No trailing slash
    |
    */

    'repo_path' => '',

    /*
    |--------------------------------------------------------------------------
    | Allowed sources
    |--------------------------------------------------------------------------
    |
    | A request will be ignored unless it comes from an IP listed in this
    | array. Leave the array empty to allow all sources.
    |
    | This is useful for a little extra security if you run your own Git
    | repo server.
    |
    | Relies on the REMOTE_ADDR of the connecting client matching a value
    | in the array below. So if using IPv6 on both the server and the
    | notifing git server, then make sure to add it to the array. If your git
    | server listens on IPv4 and IPv6 it would be safest to add both.
    |
    | e.g.
    | 
    | 'allowed_sources' => ['192.160.0.1', '::1'], 
    |
    */

    'allowed_sources' => [],

    /*
    |--------------------------------------------------------------------------
    | Remote name
    |--------------------------------------------------------------------------
    |
    | The name of the remote repository to pull the changes from
    |
    */
    
    'remote' => 'origin',

    /*
    |--------------------------------------------------------------------------
    | Git binary path
    |--------------------------------------------------------------------------
    |
    | The full path to the system git binary. e.g. /usr/bin/git
    |
    | Leave blank to let the system detect using the current PATH variable
    |
    */
    
    'git_path' => '',

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode
    |--------------------------------------------------------------------------
    |
    | Allow the git hook to put the site into maintenance mode before doing
    | the pull from the remote server.
    |
    | After a successful pull the site will be switched back to normal
    | operations. This does leave a possibility of the site remaining in
    | maintenance mode should an error occur during the pull.
    |
    */

    'maintenance_mode' => true,

    /*
    |--------------------------------------------------------------------------
    | Fire Event
    |--------------------------------------------------------------------------
    |
    | Allow the git hook to fire a event "GitDeployed" so that everybody can listen to that event.
    | See readme how to create a nice listener on that.
    |
    */
    'fire_event' => true,

    /*
    |--------------------------------------------------------------------------
    | Secret signature
    |--------------------------------------------------------------------------
    |
    | Allow webhook requests to be signed with a secret signature.
    |
    | If 'secret' is set to true, Gitdeploy will deny requests where the
    | signature does not match. If set to false it will ignore any signature
    | headers it recieves.
    | 
    | For Gitlab servers, you probably want the settings below:
    | 
    |     'secret_type' => 'plain',
    |     'secret_header' => 'X-Gitlab-Token',
    |
    | For Github, use something like the below (untested):
    |
    |    'secret_type' => 'hmac',
    |    'secret_header' => 'X-Hub-Signature',
    */
   
    'secret' => false,

    /**
     * plain|hmac
     */
    'secret_type' => 'plain',

    /**
     * X-Gitlab-Token|X-Hub-Signature
     */
    'secret_header' => 'X-Gitlab-Token',

    /**
     * The key you specified in the pushing client
     */
    'secret_key' => '',

];

```

## Planes Futuros
* Informe por correo electrónico sobre conflictos de código que impiden una extracción
* Soporte para realizar `composer install` después de la implementación
* Soporte para reiniciar las colas de laravel después de la implementación con `artisan queue:restart`
* Soporte para ejecutar comandos artesanales personalizados después de extracciones exitosas