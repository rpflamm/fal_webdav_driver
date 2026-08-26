<?php

declare(strict_types=1);

namespace RPflamm\FalWebDavDriver\Client;

use Sabre\DAV\Client;
use Sabre\HTTP\RequestInterface;

final class WebDavClientFactory
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function create(array $configuration): Client
    {
        $baseUri = rtrim((string) ($configuration['baseUri'] ?? ''), '/') . '/';
        $authType = (string) ($configuration['authType'] ?? 'basic');

        $settings = ['baseUri' => $baseUri];

        if ($authType === 'basic' && isset($configuration['username']) && $configuration['username'] !== '') {
            $settings['userName'] = (string) $configuration['username'];
            $settings['password'] = (string) ($configuration['password'] ?? '');
            $settings['authType'] = Client::AUTH_BASIC;
        }

        $client = new Client($settings);

        if ($authType === 'bearer' && isset($configuration['bearerToken']) && $configuration['bearerToken'] !== '') {
            $bearerToken = (string) $configuration['bearerToken'];
            $client->on('beforeRequest', static function (RequestInterface $request) use ($bearerToken): void {
                $request->setHeader('Authorization', 'Bearer ' . $bearerToken);
            });
        }

        if (isset($configuration['verifySsl']) && !(bool) $configuration['verifySsl']) {
            $client->addCurlSetting(CURLOPT_SSL_VERIFYPEER, false);
            $client->addCurlSetting(CURLOPT_SSL_VERIFYHOST, 0);
        }

        return $client;
    }
}
