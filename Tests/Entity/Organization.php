<?php

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Connection\Dbal\RefreshTokenCredentialsInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use JMS\Serializer\Annotation as Serializer;

/**
 * Class Organization
 *
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table(name="organization")
 */
class Organization implements RefreshTokenCredentialsInterface
{
    /**
     * @var null|int
     * @ORM\Id()
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @Assert\NotBlank(groups={"Default", "edit"})
     * @Serializer\Groups({"default"})
     */
    private $label;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @Assert\Regex(pattern="/^[a-zA-z0-9][a-zA-Z0-9_-]*$/", groups={"Default", "edit"})
     * @Assert\NotBlank(groups={"Default", "edit"})
     * @Serializer\Groups({"default"})
     */
    private $name;

    /**
     * @var null|string
     * @ORM\Column(length=80, nullable=true)
     * @Assert\NotBlank(groups={"edit"})
     * @Assert\Email(groups={"edit"})
     * @Serializer\Groups({"default"})
     */
    private $username;

    /**
     * @var null|string
     * @ORM\Column(length=192, nullable=false)
     * @Assert\NotBlank(groups={"Default", "edit"})
     * @Serializer\Groups({"default"})
     */
    private $clientKey;

    /**
     * @var null|string
     * @ORM\Column(length=100, nullable=false)
     * @Assert\NotBlank(groups={"Default", "edit"})
     * @Serializer\Groups({"default"})
     */
    private $clientSecret;

    /**
     * @var string
     * @ORM\Column(length=60, nullable=false)
     * @Assert\NotBlank(groups={"Default", "edit"})
     * @Serializer\Groups({"default"})
     */
    private $loginUrl;

    /**
     * @var string
     * @ORM\Column(length=192, nullable=false)
     * @Assert\NotBlank(groups={"Default", "edit"})
     * @Serializer\Groups({"default"})
     */
    private $redirectUri;

    /**
     * @var null|string
     * @ORM\Column(length=192, nullable=true)
     * @Assert\NotBlank(groups={"edit"})
     * @Serializer\Groups({"default"})
     */
    private $token;

    /**
     * @var null|string
     * @ORM\Column(length=192, nullable=true)
     * @Assert\NotBlank(groups={"edit"})
     * @Serializer\Groups({"default"})
     */
    private $refreshToken;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     * @Serializer\Groups({"default"})
     */
    private $active = false;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @param int|null $id
     *
     * @return Organization
     */
    public function setId(?int $id): Organization
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * @param string $label
     *
     * @return Organization
     */
    public function setLabel(?string $label): Organization
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name ?: '';
    }

    /**
     * @param string $name
     *
     * @return Organization
     */
    public function setName(?string $name): Organization
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getClientKey(): ?string
    {
        return $this->clientKey;
    }

    /**
     * @param null|string $clientKey
     *
     * @return Organization
     */
    public function setClientKey(?string $clientKey): Organization
    {
        $this->clientKey = $clientKey;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    /**
     * @param null|string $clientSecret
     *
     * @return Organization
     */
    public function setClientSecret(?string $clientSecret): Organization
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    /**
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->loginUrl ?: '';
    }

    /**
     * @param string $loginUrl
     *
     * @return Organization
     */
    public function setLoginUrl(?string $loginUrl): Organization
    {
        $this->loginUrl = $loginUrl;

        return $this;
    }

    /**
     * @return string
     */
    public function getRedirectUri(): string
    {
        return $this->redirectUri ?: '';
    }

    /**
     * @param string $redirectUri
     *
     * @return Organization
     */
    public function setRedirectUri(?string $redirectUri): Organization
    {
        $this->redirectUri = $redirectUri;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * @param null|string $token
     *
     * @return Organization
     */
    public function setToken(?string $token): Organization
    {
        $this->token = $token;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * @param null|string $refreshToken
     *
     * @return Organization
     */
    public function setRefreshToken(?string $refreshToken): Organization
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active
     *
     * @return Organization
     */
    public function setActive(?bool $active): Organization
    {
        $this->active = $active;

        return $this;
    }

    public function getType(): string
    {
        return self::OAUTH;
    }

    /**
     * @return null|string
     */
    public function getUsername(): string
    {
        return $this->username ?: '';
    }

    /**
     * @param null|string $username
     *
     * @return Organization
     */
    public function setUsername(?string $username): Organization
    {
        $this->username = $username;

        return $this;
    }

    public function getPassword(): ?string
    {
        return null;
    }
}
