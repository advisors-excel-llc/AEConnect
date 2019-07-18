# Inbound and Outbound Data Validation

AE Connect doesn't really validate your data in the context that Salesforce will validate your data. Just because
your outbound entity passes validation doesn't mean Salesforce won't throw a validation error when the data is synced.

Instead, validation is more of a gatekeeper, in this sense. An entity is validated before it is compiled to an SObject
for outbound syncing, and after an Entity object has been created from the inbound SObject but before it's persisted to
the database. In this regard, you're always validating the Entity object itself.

AE Connect works with the [Symfony Validator Component](https://symfony.com/doc/current/components/validator.html) to
determine if an Entity should be sent to Salesforce or synced from Salesforce.

## Inbound Validation

When syncing inbound data, AE Connect uses the `ae_connect.inbound` group, as well as a group with the connection
name appended to it; `ae_connect.inbound.[some_connection_name]` or `ae_connect.inbound.default`. The group without the
connection name is used for all connections. So if you only want a constraint used in for with a particular
connection, make sure you append the connection name as above.

Data inbound to your application is converted from an SObject to an Entity based on the [entity mapping](../config/entity_mapping.md)
annotations. Right before the Entity is persisted to the database, it is validated using the fore mentioned groups. If
the validation fails, it means the Entity shouldn't be saved to the local database, and is detached from the entity manager
and ignored.

## Outbound Validation

Similar to inbound, outbound data to Salesforce is validated using the group `ae_connect.outbound`, as well as a group with the
connection name appended to it: `ae_connect.outbound.default`. The group without the connection name is used for all
connections. So if you only want a constraint used in for with a particular connection, make sure you append the
connection name as above.

When an entity fails validation on outbound, it's still persisted to the local database. Actually, the outbound processing
of an Entity happens on the PostPersist/PostUpdate/PostDelete lifecycles. So the data is already in the database by the time
it's being prepared to be sent to Salesforce. Failing validation simply says to AE Connect, "Hey! Not me. Don't send me!"
and the Entity is simply ignored.

## Example

Here's a basic example of an Entity that uses validation to determine if it should be synced. Any validation constraint,
custom or standard to the Validator Component in Symfony, is able to be used.

```php
<?php

namespace App\Entity;

use AE\ConnectBundle\Annotations\SObjectType;
use AE\ConnectBundle\Annotations\RecordType;
use AE\ConnectBundle\Annotations\Field;
// ...

/**
* Class Account
 * @package App\Entity
 * @ORM\Entity()
 * @ORM\Table("account")
 * @ORM\HasLifecycleCallbacks()
 *          
 * @SObjectType("Account")
 * @RecordType("Client")
 */
class Account {

    // ...
    
    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @Field()
     */
    private $name;
    
    /**
     * @var bool 
     * @ORM\Column(type="bool")
     * @Assert\IsTrue(groups={"ae_connect.inbound", "ae_connect.outbound"})
     */
    private $shouldSync = false;
    
    // ...
}

```

If `$shouldSync` is false, the entity won't be sent to Salesforce or updated in the local database from Salesforce.