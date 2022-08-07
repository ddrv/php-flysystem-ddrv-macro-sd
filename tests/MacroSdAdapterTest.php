<?php

declare(strict_types=1);

namespace Tests\Ddrv\Flysystem\MacroSd;

use App\App;
use App\Service\Database\Database;
use App\Service\StorageManager\StorageManager;
use App\Web\Handler\Api;
use App\Web\Handler\Get;
use App\Web\Middleware\AttachStorageMiddleware;
use App\Web\Middleware\AuthMiddleware;
use App\Web\Middleware\AuthRequiredMiddleware;
use App\Web\Middleware\RewindResponseBodyMiddleware;
use Ddrv\Container\Container;
use Ddrv\Flysystem\MacroSd\MacroSdAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Webclient\Fake\FakeHttpClient;

class MacroSdAdapterTest extends FilesystemAdapterTestCase
{
    private static ?ContainerInterface $container = null;

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $container = self::bootstrap();

        /** @var App $macroSd */
        $macroSd = $container->get(App::class);

        $handler = new class ($macroSd) implements RequestHandlerInterface
        {
            private App $app;

            public function __construct(App $app)
            {
                $this->app = $app;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->app->web($request);
            }
        };

        $client = new FakeHttpClient($handler);

        return new MacroSdAdapter(
            $client,
            $container->get(RequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            'http://localhost',
            'user',
            'pass'
        );
    }

    private static function bootstrap(): ContainerInterface
    {
        if (is_null(self::$container)) {
            $config = [
                'localhost' => [
                    'users' => [
                        'user' => '$2y$10$v/lO6ne/jW2/2sZwHPkiXuCGQelDSJY3vlOA4FX.t9nfzYTuzNkOO', // user:pass
                    ],
                    'storage' => 'memory://test',
                ],
            ];
            $httpFactory = new Psr17Factory();
            $container = new Container();
            $container->value(Psr17Factory::class, $httpFactory);
            $container->value('root', sys_get_temp_dir());
            $container->value('config', $config);
            $container->value('debug', false);
            $container->bind(RequestFactoryInterface::class, Psr17Factory::class);
            $container->bind(ResponseFactoryInterface::class, Psr17Factory::class);
            $container->bind(ServerRequestFactoryInterface::class, Psr17Factory::class);
            $container->bind(StreamFactoryInterface::class, Psr17Factory::class);
            $container->bind(UploadedFileFactoryInterface::class, Psr17Factory::class);
            $container->bind(UriFactoryInterface::class, Psr17Factory::class);

            $container->service(RewindResponseBodyMiddleware::class, function () {
                return new RewindResponseBodyMiddleware();
            });

            $container->service(AuthMiddleware::class, function (ContainerInterface $container) {
                return new AuthMiddleware($container->get('config'));
            });
            $container->service(AuthRequiredMiddleware::class, function () {
                return new AuthRequiredMiddleware();
            });

            $container->service(AttachStorageMiddleware::class, function (ContainerInterface $container) {
                return new AttachStorageMiddleware(
                    $container->get(StorageManager::class)
                );
            });

            $container->service(Database::class, function (ContainerInterface $container) {
                $dsn = 'sqlite:' . $container->get('root')
                    . DIRECTORY_SEPARATOR . 'var'
                    . DIRECTORY_SEPARATOR . 'visibility.sqlite';
                return new Database($dsn);
            });

            $container->service(StorageManager::class, function (ContainerInterface $container) {
                return new StorageManager(
                    $container->get(Database::class),
                    $container->get('config'),
                    $container->get('root')
                );
            });

            $container->service(App::class, function (ContainerInterface $container) {
                return new App($container);
            });

            $container->service(Get::class, function (ContainerInterface $container) {
                return new Get(
                    $container->get(ResponseFactoryInterface::class),
                    $container->get(StreamFactoryInterface::class),
                );
            });

            $container->service(Api::class, function (ContainerInterface $container) {
                return new Api(
                    $container->get(ResponseFactoryInterface::class),
                    $container->get(StreamFactoryInterface::class)
                );
            });
            self::$container = $container;
        }

        return self::$container;
    }
}
