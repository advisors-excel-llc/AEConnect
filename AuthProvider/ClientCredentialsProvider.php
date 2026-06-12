<?php

namespace AE\ConnectBundle\AuthProvider;

use AE\SalesforceRestSdk\AuthProvider\AuthProviderInterface;
use AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException;
use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;

class ClientCredentialsProvider implements AuthProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @var DoctrineAdapter
     */
    private $cache;

    /**
     * @var bool
     */
    private $isAuthorized = false;

    /**
     * @var string|null
     */
    private $token;

    /**
     * @var string
     */
    private $tokenType = 'Bearer';

    /**
     * @var string|null
     */
    private $instanceUrl;

    /**
     * @var string|null
     */
    private $identityUrl;

    /**
     * @var int|null
     */
    private $issuedAt;

    private const TOKEN_MAX_AGE = 5400;

    public function __construct(
        CacheProvider $cache,
        string $clientId,
        string $clientSecret,
        string $url = 'https://login.salesforce.com'
    ) {
        $this->cache        = new DoctrineAdapter($cache);
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->httpClient   = new Client(['base_uri' => $url]);
        $this->logger       = new NullLogger();
    }

    /**
     * @param bool $reauth
     *
     * @return string
     * @throws SessionExpiredOrInvalidException
     */
    public function authorize($reauth = false): string
    {
        // Try restoring from cache
        if (!$reauth && null === $this->token) {
            try {
                $cacheKey = 'cc_'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $this->clientId);
                if ($this->cache->hasItem($cacheKey)) {
                    $values             = $this->cache->getItem($cacheKey)->get();
                    $this->token        = $values['token'];
                    $this->tokenType    = $values['tokenType'];
                    $this->instanceUrl  = $values['instanceUrl'];
                    $this->identityUrl  = $values['identityUrl'];
                    $this->isAuthorized = $values['isAuthorized'];
                    $this->issuedAt     = $values['issuedAt'] ?? null;
                }
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }

        if (!$reauth && $this->isAuthorized && null !== $this->token && !$this->isTokenExpired()) {
            return "{$this->tokenType} {$this->token}";
        }

        try {
            $response = $this->httpClient->post(
                '/services/oauth2/token',
                [
                    'form_params' => [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $this->clientId,
                        'client_secret' => $this->clientSecret,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Accept'       => 'application/json',
                    ],
                    'http_errors' => false,
                ]
            );

            $body  = (string) $response->getBody();
            $parts = json_decode($body, true);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $sfError = $parts['error'] ?? ($parts['errorCode'] ?? 'UNKNOWN_ERROR');
                $sfMessage = $parts['error_description'] ?? ($parts['message'] ?? $body);
                $this->logger->critical("Client Credentials auth failed [{$statusCode}]: {$sfError} - {$sfMessage}");
                $this->revoke();

                throw new SessionExpiredOrInvalidException($sfMessage, $sfError);
            }

            $this->token        = $parts['access_token'];
            $this->tokenType    = $parts['token_type'] ?? 'Bearer';
            $this->instanceUrl  = $parts['instance_url'];
            $this->identityUrl  = $parts['id'] ?? null;
            $this->issuedAt     = time();
            $this->isAuthorized = true;

            // Persist to cache
            try {
                $cacheKey = 'cc_'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $this->clientId);
                $item     = $this->cache->getItem($cacheKey);
                $item->set([
                    'token'        => $this->token,
                    'tokenType'    => $this->tokenType,
                    'instanceUrl'  => $this->instanceUrl,
                    'identityUrl'  => $this->identityUrl,
                    'isAuthorized' => $this->isAuthorized,
                    'issuedAt'     => $this->issuedAt,
                ]);
                $this->cache->save($item);
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }

            return "{$this->tokenType} {$this->token}";
        } catch (RequestException $e) {
            $this->logger->critical('Client Credentials auth failed: '.$e->getMessage());
            $this->revoke();

            throw new SessionExpiredOrInvalidException(
                'Failed to authenticate with Salesforce using Client Credentials.',
                'INVALID_CREDENTIALS'
            );
        }
    }

    public function reauthorize(): string
    {
        return $this->authorize(true);
    }

    public function refreshToken(): string
    {
        if (null === $this->token || $this->isTokenExpired()) {
            $this->logger->info('Token expired or missing, obtaining new token via client_credentials grant.');
            return $this->authorize(true);
        }

        try {
            $response = $this->httpClient->post(
                '/services/oauth2/introspect',
                [
                    'form_params' => [
                        'token'           => $this->token,
                        'client_id'       => $this->clientId,
                        'client_secret'   => $this->clientSecret,
                        'token_type_hint' => 'access_token',
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Accept'       => 'application/json',
                    ],
                    'http_errors' => false,
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() >= 400 || empty($body['active'])) {
                $this->logger->info('Token is no longer active, obtaining new token via client_credentials grant.');
                return $this->authorize(true);
            }

            $this->logger->info('Token is still active, no refresh needed.');
            return "{$this->tokenType} {$this->token}";
        } catch (\Exception $e) {
            $this->logger->warning('Token introspection failed: '.$e->getMessage().'. Falling back to reauth.');
            return $this->authorize(true);
        }
    }

    public function revoke(): void
    {
        try {
            $cacheKey = 'cc_'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $this->clientId);
            if ($this->cache->hasItem($cacheKey)) {
                $this->cache->deleteItem($cacheKey);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        $this->token        = null;
        $this->isAuthorized = false;
        $this->identityUrl  = null;
        $this->issuedAt     = null;
    }

    private function isTokenExpired(): bool
    {
        if (null === $this->issuedAt) {
            return true;
        }

        return (time() - $this->issuedAt) >= self::TOKEN_MAX_AGE;
    }

    public function getIdentity(): array
    {
        if (null === $this->identityUrl) {
            return [];
        }

        try {
            $response = $this->httpClient->get(
                $this->identityUrl,
                [
                    'headers' => ['Authorization' => "{$this->tokenType} {$this->token}"],
                ]
            );

            if (200 === $response->getStatusCode()) {
                return json_decode((string) $response->getBody(), true);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch identity: '.$e->getMessage());
        }

        return [];
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getTokenType(): ?string
    {
        return $this->tokenType;
    }

    public function isAuthorized(): bool
    {
        return $this->isAuthorized;
    }

    public function getInstanceUrl(): ?string
    {
        return $this->instanceUrl;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }
}
