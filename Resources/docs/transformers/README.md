# Data Transformers

Mapping is not enough to get the data to and from Salesforce. Data can live one way in your application and another
way in Salesforce. The data must be transformed to and from in order to successfully be synced.

The most notorious of the transformers is the `AssociationTransformer`. This transformer is what makes it possible
to map associated entities to and from Salesforce. It, like all the other transformers, does this using some very simplistic
methods.

It's probably a good time to mention that transformers operate at a field level. So they only ever agree to handle a value
given for a field/property and set a value in the payload for that given field/property of an SObject/Entity.

## Custom Transformers

There may come a day when the default transformers defined in this bundle are not enough and you have to do something all
on your own. Well kudos to you! You're a rockstar, so go get 'em!

The first thing you should know is that ALL transformers implement the `TransformerPluginInterface` which simply enforces
that you have `supports()` and `transform()` methods.

But it gets simpler, because, afterall, there's a lot of work to be done. You have to determine if the data is inbound or
outbound and how to make the necessary determinations for either case.

There's help for that called the `AbstractTransformerPlugin`. This guy breaks things down a little more and makes it so
you can focus on what's necessary.

The `AbstractTransformerPlugin` breaks things up a bit and has 4 methods you will want to overwrite:
* `supportsInbound()`
* `supportsOutbound()`
* `transformInbound()`
* `transformOutbound()`

Each of these methods takes a `TransformerPayload` object. The `supports*()` methods simply need to return a boolean.
This tells the Transformer services, "Hey! Yea! I can work with that payload." or "Nah! Pass! NEXT!"

> Just because a transformer returns true in its supports() method, doesn't mean it has to actually do
> something to the payload data. Think of the supports() method as a window to say "Hey! I'll take a closer look at that"
> The quicker the transformer can say no, the faster the data for the entire entity is transformed.

### Transforming Inbound

Once your custom transformer says yes to the dress (i.e. returns true in your `supports*` method), then it's time to get
to work. If the data is inbound, `supportsInbound()` is called with the a `TransformerPayload` object as its only argument
and your transformer has said YES! Now `tranformInbound()` is called with that same `TransformerPayload` object.

When data is inbound, the `TransformPayload` looks a little like this:

* getValue(): gets the current value from the SObject that's being transformed
* setValue(): sets the value to be used as the new property value on the Entity for the given SObject field
* getFieldName(): gets the current field name from the SObject the value is being mapped from
* getPropertyName(): gets the current property name on the Entity that the value is being mapped to
* getMetadata(): gets the `Metadata` that's currently being used to map the SObject to the Entity class
* getFieldMetadata(): gets the `FieldMetadata` object created for the field which can be used to get Salesforce's metadata for the field or the current value of the field
* getEntity(): for inbound, this is actually the SObject instance from Salesforce
* getClassMetadata(): this is the ClassMetadata for the Entity as compiled by Doctrine
* getDirection(): though this isn't really super helpful here, it is to the `AbstractTransformerPlugin`, the value will be `TransformerPayload::INBOUND`

### Transforming Outbound

Like inbound, the `supportsOutbound()` is called with a `TransformerPayload` object, if the method returns true, `transformOutbound()`
is called with the same payload.

When data is outbound, the `TransformPayload` looks a little like this:

* getValue(): gets the current value from the Entity that's being transformed
* setValue(): sets the value to be used as the new field value on the SObject
* getFieldName(): gets the current field name from the SObject the value is being mapped from
* getPropertyName(): gets the current property name on the Entity that the value is being mapped to
* getMetadata(): gets the `Metadata` that's currently being used to map the Entity
* getFieldMetadata(): gets the `FieldMetadata` object created for the field which can be used to get Salesforce's metadata for the field or the current value of the field
* getEntity(): for outbound, this is Entity instance itself
* getClassMetadata(): this is the ClassMetadata for the Entity as compiled by Doctrine
* getDirection(): though this isn't really super helpful here, it is to the `AbstractTransformerPlugin`, the value will be `TransformerPayload::OUTBOUND`

## Example

This isn't going to be rocket science, but it'll give ya a feel for it.

```php
<?php

namespace App\Salesforce\Transformer\Plugin;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\AbstractTransformerPlugin;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;

class MyCoolTransformer extends AbstractTransformerPlugin {
    
    protected function supportsInbound(TransformerPayload $payload): bool
    {
        // Do something to figure out if you even want to transform the value
        return true;
    }
    
    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        // Do something to figure out if you even want to transform the value
        return true;
    }

    
    protected function transformInbound(TransformerPayload $payload)
    {
        $value = $payload->getValue();
        
        // Do something with the value
        
        // Setting the value will change it for the Entity
        $payload->setValue($value);
    }
    
    protected function transformOutbound(TransformerPayload $payload)
    {
        $value = $payload->getValue();
        
        // Do something with the value
        
        // Setting the value will change it for the SObject
        $payload->setValue($value);
    }
}

```