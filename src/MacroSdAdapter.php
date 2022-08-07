<?php

declare(strict_types=1);

namespace Ddrv\Flysystem\MacroSd;

use Ddrv\Flysystem\MacroSd\Exception\UnknownFilesystemException;
use League\Flysystem\Config;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidStreamProvided;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMountFilesystem;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToResolveFilesystemMount;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnreadableFileEncountered;
use League\Flysystem\Visibility;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class MacroSdAdapter implements FilesystemAdapter
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private string $host;
    private string $user;
    private string $password;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $host,
        string $user,
        string $password
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
    }

    public function fileExists(string $path): bool
    {
        $request = $this->createRequest('fileExists', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToCheckFileExistence('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $result = $this->parseResponse($response);
        return (bool)$result['fileExists'];
    }

    public function directoryExists(string $path): bool
    {
        $request = $this->createRequest('directoryExists', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToCheckDirectoryExistence('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $result = $this->parseResponse($response);
        return (bool)$result['directoryExists'];
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = $this->streamFactory->createStream($contents);
        $this->writePsrStream($path, $stream, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $stream = $this->streamFactory->createStreamFromResource($contents);
        $this->writePsrStream($path, $stream, $config);
    }

    public function read(string $path): string
    {
        $stream = $this->readPsrStream($path);
        return (string)$stream;
    }

    public function readStream(string $path)
    {
        $stream = $this->readPsrStream($path);
        return $stream->detach();
    }

    public function delete(string $path): void
    {
        $request = $this->createRequest('delete', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToDeleteFile('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    public function deleteDirectory(string $path): void
    {
        $request = $this->createRequest('deleteDirectory', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToDeleteDirectory('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $visibility = $config->get(
            Config::OPTION_DIRECTORY_VISIBILITY,
            $config->get(Config::OPTION_VISIBILITY, Visibility::PRIVATE)
        );
        $request = $this->createRequest('createDirectory', ['location' => $path, 'visibility' => $visibility]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToCreateDirectory('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $request = $this->createRequest('setVisibility', ['location' => $path, 'visibility' => $visibility]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToSetVisibility('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    public function visibility(string $path): FileAttributes
    {
        $request = $this->createRequest('visibility', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToRetrieveMetadata('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $result = $this->parseResponse($response);
        $result['path'] = $path;
        return FileAttributes::fromArray($result);
    }

    public function mimeType(string $path): FileAttributes
    {
        $request = $this->createRequest('mimeType', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToRetrieveMetadata('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $result = $this->parseResponse($response);
        return FileAttributes::fromArray([
            'path' => $path,
            'mime_type' => $result['mimeType'],
        ]);
    }

    public function lastModified(string $path): FileAttributes
    {
        $request = $this->createRequest('lastModified', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToRetrieveMetadata('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $result = $this->parseResponse($response);
        return FileAttributes::fromArray([
            'path' => $path,
            'last_modified' => $result['lastModified'],
        ]);
    }

    public function fileSize(string $path): FileAttributes
    {
        $request = $this->createRequest('fileSize', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToRetrieveMetadata('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $result = $this->parseResponse($response);
        return FileAttributes::fromArray([
            'path' => $path,
            'file_size' => $result['fileSize'],
        ]);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $request = $this->createRequest('listContents', ['location' => $path, 'deep' => $deep ? 'true' : 'false']);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToReadFile('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
        $body = $response->getBody();
        $stream = $body->detach();
        while (!feof($stream)) {
            $row = fgetcsv($stream);
            if (!is_array($row)) {
                continue;
            }
            $array = [
                'type' => $row[0],
                'path' => $row[1],
                'visibility' => $row[2],
                'last_modified' => (int)$row[3],
                'file_size' => null,
                'mime_type' => null,
                'extra_metadata' => null,
            ];
            if (!empty($row[4])) {
                $array['file_size'] = (int)$row[4];
            }
            if (!empty($row[5])) {
                $array['mime_type'] = $row[5];
            }
            if (!empty($row[6])) {
                $array['file_size'] = json_decode($row[6], true);
            }
            switch ($array['type']) {
                case 'file':
                    yield FileAttributes::fromArray($array);
                    break;
                case 'dir':
                    yield DirectoryAttributes::fromArray($array);
                    break;
            }
        }
        fclose($stream);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $arguments = $this->getCopyArguments($source, $destination, $config);
        $request = $this->createRequest('move', $arguments);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToMoveFile('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $arguments = $this->getCopyArguments($source, $destination, $config);
        $request = $this->createRequest('copy', $arguments);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToCopyFile('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    private function getCopyArguments(string $source, string $destination, Config $config): array
    {
        $arguments = [
            'source' => $source,
            'destination' => $destination,
        ];
        $visibility = $config->get(Config::OPTION_VISIBILITY);
        if (in_array($visibility, [Visibility::PUBLIC, Visibility::PRIVATE])) {
            $arguments['visibility'] = $visibility;
        }
        $directoryVisibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY);
        if (in_array($directoryVisibility, [Visibility::PUBLIC, Visibility::PRIVATE])) {
            $arguments['directory_visibility'] = $directoryVisibility;
        }
        return $arguments;
    }

    private function createRequest(string $endpoint, array $arguments): RequestInterface
    {
        $arguments['method'] = $endpoint;
        $uri = $this->host . '/?' . http_build_query($arguments);
        return $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Authorization', ['basic ' . base64_encode($this->user . ':' . $this->password)])
            ;
    }

    /**
     * @throws FilesystemException
     */
    private function checkResponse(ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 500) {
            $json = $response->getBody()->__toString();
            $data = json_decode($json, true);
            $error = $data['error'] ?? null;
            $message = $data['message'] ?? '';
            $code = $data['code'] ?? 0;
            if (is_string($error) && is_string($message) && is_int($code)) {
                throw match ($error) {
                    'PathTraversalDetected' => new PathTraversalDetected($message, $code),
                    'SymbolicLinkEncountered' => new SymbolicLinkEncountered($message, $code),
                    'InvalidVisibilityProvided' => new InvalidVisibilityProvided($message, $code),
                    'CorruptedPathDetected' => new CorruptedPathDetected($message, $code),
                    'UnableToResolveFilesystemMount' => new UnableToResolveFilesystemMount($message, $code),
                    'InvalidStreamProvided' => new InvalidStreamProvided($message, $code),
                    'UnableToSetVisibility' => new UnableToSetVisibility($message, $code),
                    'UnableToCheckExistence' => new UnableToCheckExistence($message, $code),
                    'UnableToCheckDirectoryExistence' => new UnableToCheckDirectoryExistence($message, $code),
                    'UnableToCheckFileExistence' => new UnableToCheckFileExistence($message, $code),
                    'UnableToCreateDirectory' => new UnableToCreateDirectory($message, $code),
                    'UnableToWriteFile' => new UnableToWriteFile($message, $code),
                    'UnableToRetrieveMetadata' => new UnableToRetrieveMetadata($message, $code),
                    'UnableToCopyFile' => new UnableToCopyFile($message, $code),
                    'UnableToReadFile' => new UnableToReadFile($message, $code),
                    'UnableToDeleteFile' => new UnableToDeleteFile($message, $code),
                    'UnableToMoveFile' => new UnableToMoveFile($message, $code),
                    'UnableToDeleteDirectory' => new UnableToDeleteDirectory($message, $code),
                    'UnableToMountFilesystem' => new UnableToMountFilesystem($message, $code),
                    'UnreadableFileEncountered' => new UnreadableFileEncountered($message, $code),
                    default => new UnknownFilesystemException($message, $code),
                };
            }
        }
    }

    /**
     * @throws FilesystemException
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $this->checkResponse($response);
        $json = $response->getBody()->__toString();
        return json_decode($json, true);
    }

    /**
     * @throws FilesystemException
     */
    private function writePsrStream(string $path, StreamInterface $stream, Config $config): void
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY, Visibility::PRIVATE);
        $request = $this->createRequest('write', ['location' => $path, 'visibility' => $visibility])
            ->withBody($stream)
        ;
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToWriteFile('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
    }

    /**
     * @throws FilesystemException
     */
    private function readPsrStream(string $path): StreamInterface
    {
        $request = $this->createRequest('read', ['location' => $path]);
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new UnableToReadFile('http error: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkResponse($response);
        return $response->getBody();
    }
}
