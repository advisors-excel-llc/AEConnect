# Entity Mapping

Using annotations, data is mapped to Salesforce from a local database Entity and from Salesforce to an Entity. First,
the Entity class must have an `SObjectType` annotation. This annotation tells AE Connect that the Entity also exists
in Salesforce.

Next, any property that has data that needs to go to Salesforce or be update from Salesforce, needs to be
mapped using the `Field` annotation, either on the property directly, or it's associated getter or setter. We'll talk
more about this in a moment.

A special annotation, `SalesforceId` is used in place of the `Field` annotation for the field that will be used for the
actual Id of the record in Salesforce. When outbound data is persisted to Salesforce, this field is immediately updated
with the Id of the created record; regardless of whether or not AE Connect is configured to listen to changes on the object.

Some objects are further broken up into RecordTypes. In order to discriminate which Entities are associated to which
RecordType, the `RecordType` annotation is used.

## SObjectType

The `SObjectType` annotation is required for any Entity that has data that needs to be written to or read from Salesforce.
The annotation is only used on the `class`.

If the Entity maps to more than one connection, then each connection name should be specified in the `connections={}`
attribute of the annotation. If the `connections` attribute is omitted, the default connection is used.

> *NEW!* You can now use a * wildcard to target ALL connections, i.e. `connections={"*"}`. This is useful for
> standard fields.

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account", connections={"default", "other_connection"})
 */
class Account {

}

```

## Field

The `Field` annotation can be used with any property, getter, or setter where the Entity's field data needs to map to or
from a Salesforce's SObject data.

When the annotation is on a **property**, data will be mapped **bi-directionally**, inbound and outbound. If applied
to a **getter**, meaning if the property name is `name` the method name is `getName()`, the data returned from the mapped
getter is used for **outbound** data to Salesforce. Likewise, when used on a **setter**, the setter is used to set the 
data **inbound** from Salesforce to the Entity.

If a **setter** is mapped and not the property or getter, data **inbound** from Salesforce will be applied to the Entity,
but no data for the setter's related property will be used for outbound data.

If a **getter** is mapped and not a property or setter, only **outbound** data to Salesforce will be mapped. No inbound
data will effect the Entity.

This is a useful way of making data **read** or **write** only.

Like `SObjectType`, the `Field` annotation also supports the `connections={}` attribute, using the default connection
if not specified. This is extremely helpful when an Entity maps to multiple connections where field names in each
Salesforce org are different.

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Annotations\Field;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account", connections={"default", "other_connection"})
 */
class Account {

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @Field("Name", connections={"default", "other_connection"})
     */
    private $name;
    
    /**
     * @var int 
     * @ORM\Column(type="integer")
     * @Field("Penalties__c")
     */
    private $penalties = 0;
    
    // ...
    
    /**
     * @Field("Penalty_Count__c", connections={"other_connection"})
     * @return int
     */
    public function getPenalties()
    {
        return $this->penalties;
    }
    
    /**
     * @param int $penalties
     *                      
     * @return $this
     */
    public function setPenalties(int $penalties)
    {
        $this->penalties = $penalties;
        
        return $this;
    }
}

```

In the example above, the Entity's data will be read from and write to the default Salesforce connection. The
*other_connection* will read from and write to the `$name` property but will only be able to **read** the `$penalties`
value via the `getPenalties()` method. Inbound data from *other_connection* for the `Penalty_Count__c` field will never
be set on `$penalties`.

### ExternalId

Another annotation, not previously mentioned, `ExternalId`, works in conjunction with the `Field` annotation. When
`ExternalId` is annotated on the same property as `Field`, that property and the field name used in the `Field` annotation
are used as a way to identify values uniquely in Salesforce. It's always a good idea to have an `ExternalId` field
on any object that sends data to Salesforce in order to prevent duplications from occurring.

External ID fields must be unique. Unlike `Field` and `SObjectType`, the **do not** have a `connections` attribute.
Instead, the `connections` attribute from the related `Field` annotation for the connection is used.

The `ExternalId` annotation can only be used with properties and not on a getter or setter. This applies
to the associated `Field` annotation as well.

> An easy way to use External Ids is to create a 36-character, unique, case sensitive text field in Salesforce
> (Also check the External checkbox).
> Then, using the [Uuid Doctrine](https://github.com/ramsey/uuid-doctrine) extension, use the column type `uuid`
> for your `@Column` mapping with the unique flag set to true. AE Connect will handle the rest!

> If your object is a system level object and you cannot add a custom field to it, use what Salesforce uses
> to uniquely identify the record. Usually this is something like `DeveloperName`.

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\ExternalId;
use Ramsey\Uuid\Uuid;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account", connections={"default", "other_connection"})
 */
class Account {

    // ...
    
    /**
     * @var string 
     * @ORM\Column(type="guid", unique=true, nullable=false)
     * @Field("ae_ext__c")
     * @Field("external__c", connections={"other_connection"})
     * @ExternalId()
     */
    private $extId;
    
    // ...
    
    /**
     * @ORM\PrePersist()
     * @throws \Exception
     */
    public function prePersist()
    {
        if (null === $this->extId) {
            $this->extId = Uuid::uuid4()->toString();
        }
    }
}

```

### Associations

Due to the way Salesforce Lookup fields work in Salesforce, associated entities can only be used on `ManyToOne`
mappings.

For instance, a *Contact* object in Salesforce has an `AccountId` field which associates the *Contact* to the *Account*.
You want to create the same association in your local database. In order to do so, you will need to create a `ManyToOne`
mapping on the Entity class mapped to the *Contact* object, targeting the Entity class mapped to the *Account* object.

It's ok to have a reciprocating `OneToMany` on the *Account* Entity, but don't use the `Field` annotation on the mapping.
Where would you map that field anyway?

> Since the `ManyToOne` mapping can only be applied to the property, this forces the `Field` annotation to only
> work on the property for associations, too. However, if the target entity of the association isn't mapped to the 
> same connection the data is being processed for, then the associated entity is ignored.

Here's an example:

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account", connections={"default", "other_connection"})
 */
class Account {

    // ...
    
    /**
     * @var string 
     * @ORM\OneToMany(targetEntity="App\Entity\Contact", mappedBy="account", transformer="assocation")
     */
    private $contact;
    
    // ...
}

```


```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Annotations\Field;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Contact", connections={"default", "other_connection"})
 */
class Contact {

    // ...
    
    /**
     * @var string 
     * @ORM\ManyToOne(targetEntity="App\Entity\Account", inversedBy="contact")
     * @Field("AccountId", connections={"default", "other_connection", transformer="assocation"})
     */
    private $account;
    
    // ...
}

```

## SalesforceId

For any Entity that will sync with Salesforce, it's imperative that the Salesforce record's Id be persisted with the
Entity's data.

The `SalesforceId` annotation is used in place of `Field` and uses the `connection` (singular) attribute. When
`connection` is omitted, the default connection is used. There should be one `SalesforceId` mapped property for each
connection the Entity is mapped to.

Like `ExternalId`, `SalesforceId` can only be used on properties and not on a getter or setter.

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Annotations\SalesforceId;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account", connections={"default", "other_connection"})
 */
class Account {

    // ...
    
    /**
     * @var int 
     * @ORM\Column(length=18, unique=true, nullable=true)
     * @SalesforceId()
     */
    private $sfid;
    
    /**
     * @var string
     * @ORM\Column(length=18, unique=true, nullable=true)
     * @SalesforceId(connection="other_connection")
     */
    private $oc_sfid;
    
    // ...
}

```

## RecordType

The `RecordType` association is like if the `SObjectType`, `Field` and `SalesforceId` annotation had a baby, at least when it comes to
how to use it.

Like `SalesforceId`, it takes the place of the `Field` annotation because it will always map the data to and from the *RecordTypeId*
field on the Salesforce object.

It can be used on the **class** like `SObjectType`. Just specify the *DeveloperName* of the RecordType as the attribute
and the RecordType is statically mapped.

When not used on the **class**, it acts more like `Field`. You can put it on a **property** or **getter** or **setter**
and the same rules apply to it as did `Field`, though it's expected that the value of the property, getter, or setter
be the *DeveloperName* of the RecordType to be referenced.

> It is recommended that if you are only using `RecordType` on a setter, that you have a good reason or some weird work
> around. It would be very odd to accept an inbound value from Salesforce while not sending it one in the first place.
> But who am I to judge? I won't stop you from doing some kookiness.

Also like `Field` and `SObjectType`, `RecordType` accepts a `connections={}` attribute, allowing you to get a little crazy.
In the example below, we'll statically map a record type of "Client" to the default connection while allowing for a dynamic
value to "other_connection".

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Annotations\RecordType;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account", connections={"default", "other_connection"})
 * @RecordType("Client")
 */
class Account {

    // ...
    
    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @RecordType(connections={"other_connection"})
     */
    private $recordType = "Master";
    
    // ...
}

```
