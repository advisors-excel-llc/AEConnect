# Advanced Connection Strategies

In some cases, your application may need to sync data to and from many Salesforce organizations. The same entity in your
local database could exist on any number of Salesforce orgs at once and it's your application's job to keep that data
in sync.

In the following scenarios, your application must use a database entity to store connection data and manage
the multiple connections as specified in *[Connections Created from Stored Credentials](./runtime_connections.md)*.
Let's assume your `default` connection is using a database entity for configuration.

Using AE Connect's annotations in the right way, along with the correct Doctrine configuration, can unlock a multitude
of possibilities, allowing for just about any configuration with any number of concurrent connections to Salesforce.

## All Entities for All Connections

The first scenario we will look at is probably the most simple of the advanced strategies we will look at. In this
scenario, all mapped entities will be synced to all connections configured for AE Connect; given that assertion validation
permits the entity to be synced. *See [Inbound and Outbound Data Validation](../validation/README.md)*.

First, we need to create an object that implements the [SalesforceIdEntityInterface](../../../Connection/Dbal/SalesforceIdEntityInterface.php)
which is used to map the Salesforce Id of each org to the connection the SObject the entity represents has been persisted to.
Since we have only one entity in our local representing an SObject in *x* number of connections, we need to store the Salesforce Id
for the entity in the database with relation to the connection which the Salesforce Id is associated with.

### Salesforce Id Entity

Take a look at the sample entity below. Let's walk through this together.

Note the `@Connection` and `@SalesforceId` annotations. Though these are not necessary for AE Connect to use the
entity in place of a string Salesforce Id, it does add a performance enhancement. As long as the `SalesforceIdEntityInterface`
is used, AE Connect will be able to get and set the Salesforce ID with respect to the Connection processing the Entity.
Without these annotations, there is simply a higher cost of performance to do so.

> Though the `@Connection` and `@SalesforceId` are being used here, it's worth noting that their `connection(s)` attributes
> are not used. So it's unnecessary to specify any attributes when these annotations are used on a `SalesforceIdEntityInterface` entity

Likewise, the field names do not have to be explicitly `connection` and `salesforceId`, but it does make the entity PSR-2
compliant. If the aforementioned annotations are not used, these property names are then checked in an attempt to prevent
further performance cost. If these properties are not found and neither are the annotations, all entities using the mapped class
are queried and processed one by one using the decalared getter methods in the interface until an Salesforce Id for a given
connection is found, if one exists. You see how you might want to avoid this.

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations as AEConnect;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class SalesforceId
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="salesforce_id")
 */
class SalesforceId implements SalesforceIdEntityInterface
{
    /**
     * @var int|null
     * @ORM\Id()
     * @ORM\Column(type="integer", nullable=false, unique=true, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string|null
     * @ORM\Column(length=80, nullable=false)
     * @AEConnect\Connection()
     */
    private $connection;

    /**
     * @var string|null
     * @ORM\Column(length=18, unique=true, nullable=true)
     * @AEConnect\SalesforceId()
     */
    private $salesforceId;

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
     * @return SalesforceId
     */
    public function setId(?int $id): SalesforceId
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * @param string $connection
     *
     * @return SalesforceId
     */
    public function setConnection($connection): SalesforceId
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSalesforceId(): ?string
    {
        return $this->salesforceId;
    }

    /**
     * @param null|string $salesforceId
     *
     * @return SalesforceId
     */
    public function setSalesforceId(?string $salesforceId): SalesforceId
    {
        $this->salesforceId = $salesforceId;

        return $this;
    }
}

```

### Associate an Entity to the Salesforce Id Entity

Now that we have an entity that represents the mapping of a Salesforce Id to a connection, we need to associate this mapping
to the entity which the Salesforce Id is meant for. See below for an example of how an Account can be associated to a Salesforce Id.

*See [Entity Mappings](./entity_mapping.md) for more information.*

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations as AEConnect;
use App\Entity\SalesforceId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @AEConnect\SObjectType("Account")
 */
class Account {

    // ...
    
    /**
     * @var SalesforceId[]|Collection 
     * @ORM\ManyToMany(targetEntity="App\Entity\SalesforceId", cascade={"persist", "merge", "remove"}, orphanRemoval=true)
     * @AEConnect\SalesforceId()
     */
    private $sfids;
    
    public function __construct() {
        $this->sfids = new ArrayCollection();
    }
}

```

That's it! AE Connect will take care of the rest.

Make a note of the `ManyToMany` configuration. We map this field to our `SalesforceId` entity as declared above and then
we specified the `cascade` options. You could easily get away with `cascade={"all"}` but the example above shows what is
required for the `cascade` option in order for AE Connect to work properly. The option, `orphanRemoval=true` is just
for good housekeeping. No need to have unassociated Salesforce Id's in the database.
 
Without this configuration, the `SalesforceId` would never be associated with the `Account` entity even though the 
SalesforceId is being set on the Account entity when the entity is persisted inbound from a Salesforce Org. 
Meaning, if an entity is created in a Salesforce Org and notification is sent to your application, which AE Connect
handles and compiles the SObject into an entity, the entity wouldn't have a Salesforce Id associated with it unless 
these cascade options were specified.

### Alternative Connection Configuration

In the previous example, the `SalesforceId` used a string value for its `connection` property. While that works well,
there's also the option of mapping `connection` as a `ManyToOne` to any entity that implements the 
[ConnectionEntityInterface](../../../Connection/Dbal/ConnectionEntityInterface.php).

For instance, let's reflect back on the *[Connections Created from Stored Credentials](./runtime_connections.md)* section
and see why this might be useful.

> This will be very useful in a following scenario

When creating a connection using a stored credential, we created our own `App\Entity\OrgConnection` entity which implements
the [AuthCredentialsInterface](../../../Connection/Dbal/AuthCredentialsInterface.php). This interface extends the
`ConnectionEntityInterface`, which means, you can use your stored configuration entity as your connection value.

Let's make a small change to our `SalesforceId` entity:

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations as AEConnect;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use App\Entity\OrgConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class SalesforceId
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="salesforce_id")
 */
class SalesforceId implements SalesforceIdEntityInterface
{
    //...

    /**
     * @var string|null
     * @ORM\ManyToOne(targetEntity="App\Entity\OrgConnection", cascade={"persist"})
     * @AEConnect\Connection()
     */
    private $connection;

    /**
     * @var string|null
     * @ORM\Column(length=18, unique=true, nullable=true)
     * @AEConnect\SalesforceId()
     */
    private $salesforceId;

    //...

    /**
     * @return OrgConnection
     */
    public function getConnection(): OrgConnection
    {
        return $this->connection;
    }

    /**
     * @param OrgConnection $connection
     *
     * @return SalesforceId
     */
    public function setConnection($connection): SalesforceId
    {
        $this->connection = $connection;

        return $this;
    }

    //...
}

```

## Selective Org Entity Sync

Perhaps you don't want all of your entities syncing to all of your Salesforce Orgs. You may want to control which 
entities are associated to which Org(s).

### One Entity to One Connection

First of all, this scenario is very basic and is what AE Connect was originally designed to handle. One entity is associated
with one connection. There is really no need to have any associations to a `SalesforceIdEntityInterface` or `ConnectEntityInterface`
but to show the flexibility of AE Connect, I thought we'd do this simply because we can!

What this scenario does allow for is a dynamic ability to migrate entities easily from one org to another without having
to do a lot of rewiring. So that is a possible reason for doing this. You may thing of others. Get creative.

Using the `SalesforceId` and `OrgConnection` entities defined above, let's associate our Account with only one Entity.

```php
<?php

namespace App\Entity;

use App\Entity\SalesforceId;
use AE\ConnectBundle\Annotations as AEConnect;
use Doctrine\ORM\Mapping as ORM;

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @AEConnect\SObjectType("Account")
 */
class Account {

    // ...
    
    /**
     * @var \App\Entity\OrgConnection|null
     * @ORM\ManyToOne(targetEntity="App\Entity\OrgConnection", cascade={"persist"})
     * @AEConnect\Connection()
     */
    private $connection;
    
    /**
     * @var SalesforceId|null 
     * @ORM\OneToOne(targetEntity="App\Entity\SalesforceId", cascade={"persist", "merge", "remove"}, orphanRemoval=true)
     * @AEConnect\SalesforceId()
     */
    private $sfid;
    
    //...
}

```

Notice that the `sfid` property is mapped using `OneToOne` but the `connection` property is mapped using `ManyToOne`.
This is very important because you don't want to use the same Salesforce Id for multiple entities, but you do want
to use the same connection for multiple entities.

> Note: upon persistence, it is up to the application to set which connection the entity should persist to. If, null,
> the entity will be distributed to all entities. This could result in errors later, since the Salesforce Id is mapped
> `OneToOne`. Whichever Salesforce Org saved to the entity first will have its Salesforce Id associated with the entity,
> and every subsequent sync to a Salesforce Org will attempt an update call using the given Salesforce Id, which
> will result in an error.

### One Entity, Many Specific Connections

The last scenario is a bit of a mix between the previous. Let's say we want our Account entity to only be synced to specific
connections (yes, more than one!).

We continue to use our `SalesforceId` and `OrgConnection` entities as stated above and we wireup our Account like this:

```php
<?php

namespace App\Entity;

use App\Entity\SalesforceId;
use AE\ConnectBundle\Annotations as AEConnect;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @AEConnect\SObjectType("Account")
 */
class Account {

    // ...
    
    /**
     * @var App\Entity\OrgConnection[]|Collection
     * @ORM\ManyToMany(targetEntity="App\Entity\OrgConnection", cascade={"persist"})
     * @AEConnect\Connection()
     */
    private $connections;
    
    /**
     * @var SalesforceId[]|Collection 
     * @ORM\ManyToMany(targetEntity="App\Entity\SalesforceId", cascade={"persist", "merge", "remove"}, orphanRemoval=true)
     * @AEConnect\SalesforceId()
     */
    private $sfids;
    
    public function __construct()
    {
        $this->connections = new ArrayCollection();
        $this->sfids       = new ArrayCollection();
    }
    
    //...
}

```

Again, when specifying a `@Connection()` annotation, only the connections returned will be synced to and from. The main
difference here is that `connections` will never be null, which prevents any attempts to sync to every connection.