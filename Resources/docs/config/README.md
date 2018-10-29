# Configuration

* [Entity Mapping](entity_mapping.md)

AE Connect can be configured for one or more Salesforce connections. A connection is an authorized session with a
Salesforce organization. Along with login information, the connection configuration will also determine which channels
AE Connect should subscribe to via the Streaming API.
 
## Basic Config
Not much is required to get connected to a Salesforce org. Most of the heavy lifting occurs in the
 [Entity Mapping](entity_mapping.md).
 
One important thing to note is the `paths` key. These are the paths in which AE Connect will look
for [mapped entities](entity_mapping.md). If no paths are supplied, no entities can be mapped to and from the database.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    paths: ['%kernel.project_dir%/src/Entity/']
    connections:
        default:
            login:
                username: someuser@mysalesforceorg.com
                password: MYPASSWORD_my_user_token
            objects:
                - Account
                - Contact
                - SomeCustomObject__c
```

Given this configuration, AE Connect will login to a production environment for which *someuser@mysalesforceorg.com*
is a user. AE Connect will also listen to changes on *Account*, *Contact*, and *SomeCustomObject__c* objects via the
Streaming API on the Change Events channel. See the [Inbound Data](../inbound/README.md) section for more information
about Change Events.

### Login Config

By default, AE Connect will attempt to login to a production environment using https://login.salesforce.com. But if
you need to login to a Sandbox environment, then you would want to change the `url` setting to https://test.salesforce.com.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default:
            login:
                username: someuser@mysalesforceorg.com.sandbox
                password: MYPASSWORD_my_user_token
                url: https://test.salesforce.com
            # ...
```

Perhaps you would like a little more control over the access AE Connect has to your organization. For instance, you
may want the ability to disable the application that uses AE Connect from the Salesforce side. If you create a
*Connected App* in Salesforce, you can provide the `key` and `secret` settings in AE Connect's config. Then, if ever
something were to happen in the application using AE Connect, access could be forcibly disabled in Salesforce via the
Connected App.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default:
            login:
                key: MY_CONNETED_APP_KEY
                secret: MY_CONNECTED_APP_SECRET
                username: someuser@mysalesforceorg.com.sandbox
                password: MYPASSWORD_my_user_token
                url: https://test.salesforce.com
            # ...
```

### Subscribing to Events in Salesforce

AE Connect allows your application to subscribe to data changes, push topics, platform events, and generic events via
the Streaming API. AE Connect even handles data changes to [mapped entities](entity_mapping.md) for you.

> In order to start listening to the subscribed events configured below, start this command in the background:<br><br>
> `bin/console ae_connect:listen [connection_name]`<br><br>
> *[connection_name] is optional, we'll talk about connection names below*

Let's take a look at how you can connect to Salesforce's Streaming API without any heavy lifting:

#### Push Topics

A Push Topic must be first created on the Salesforce org before it can be subscribed to. Once the
Push Topic is created, simply use the topic's name to subscribe to it.

You can also filter out results of a Push Topic using the fields from the topic's query and some specified values. This
is a convenient way of reusing existing topics without having to create new ones. Salesforce limits the number of Push Topics
used in an organization, so it's best to use an existing Push Topic, adding any missing fields that are needed, and filtering
on those fields when subscribing to the topic.

The config example below will only tell AE Connect to subscribe to the topic channel. AE Connect will use the `type` value
to tell its SObjectConsumer what type of object the topic subscribes to. From there, AE Connect will attempt to update
the local database with the relative change.

You can choose to disable the SObjectConsumer and use your own, or create a side-effect from the event. 
See [Inbound Data from Salesforce](../inbound/README.md) for how to set that up.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default:
            login:
                # ...
            topics:
                MyCustomTopic:
                    type: 'MyCustomObject__c'
                MyFilteredTopic:
                    type: 'MyOtherCustomObject__c'
                    filters:
                        CustomField__c: 'Seattle'
                        OtherCustom__c: 'Pomegranate'
```

#### Platform Events

Platform Events are an amazing feature of Salesforce. They can be triggered via Process Builder as well as consumed
by Process Builder. They have a defined schema and are possibly the best way to communicate real time changes to and from
Salesforce as well as within Salesforce. Platform Events can trigger UI updates within Salesforce even if triggered from
an outside application. So it seemed pretty important to include them here.

Like Push Topics, the Platform Event must be created in Salesforce before AE Connect can subscribe to it. All Platform
Event API names end in `__e`.

Telling AE Connect to listen for Platform Events is simple. Though, like Push Topics, you will need a custom consumer to
actually handle the data. See [Inbound Data from Salesforce](../inbound/README.md) for how to set that up.

> Though it is possible to trigger Platform Events via the Rest API, at this time the Salesforce Rest SDK
> does not support it easily. It is possible to do with the Rest SDK, but a more simple feature
> is slated for a later release.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default:
            login:
                # ...
            topics:
                # ...
            platform_events:
                - MyPlatformEvent__e
```

#### Generic Events

Generic Events are similar to Platform Events and Push Topics, but they are extremely limited. Salesforce also
restricts the number of Generic Events created in an Organization. Before AE Connect can subscribe to a Generic Event,
a StreamingEvent record must be created in Salesforce.

Unlike Platform Events and Push Topics, the payload of a Generic Event can be anything and is usually interpreted as
a basic string. So the payload must be serialized as JSON or XML if it's intended to contain a more complex payload body.

Generic Events aren't as usable as Platform Events or Push Topics. In Salesforce, they can really only be fired from 
custom Apex and the Process Builder or Triggers can't interact with them, let alone the UI.

Generic Events are really only kept around for posterity. AE Connect can subscribe to them, but a custom consumer is
required to do anything with the data. See [Inbound Data from Salesforce](../inbound/README.md) for how to set that up.

> Though it is possible to trigger Generic Events via the Rest API, at this time the Salesforce Rest SDK
> does not support it easily. It is possible to do with the Rest SDK, but a more simple feature
> is slated for a later release.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default:
            login:
                # ...
            topics:
                # ...
            platform_events:
                # ...
            generic_events:
                - MyGenericEventName
```

#### Object Change Events

Saving the best for last! With Salesforce Winter '19 (aka Version 44.0), something amazing was announced: Change Events.
Change Events are special Platform Events that are native to Salesforce. When an object is created, updated, or deleted,
a Change Event is dispatched to tell the world the good news.

But before a Change Event can be dispatched, Salesforce must first be told to enable Change Events for an object.

To enable Change Events for an object:
* Click **Setup** in the Settings menu in the top, right-hand corner
* Type **Change Data Capture** in the search field above the left-hand navigatoin
* Click **Change Data Capture** under the **Integrations** menu
* Select all the objects you want to enable Change Events for and move them from the left column to the right column
* Click Save and you're done!

Well... done with the Salesforce Setup part. Now you need to tell AE Connect to listen for changes.

But wait! I've got some good news. Unlike all the other event configurations, this one requires no custom consumer!!
That's right! If the object declared in the config settings is also a [mapped entity](entity_mapping.md), then
AE Connect will handle all the heavy lifting and sync the data to your database for you based on your entity mapping.

Of course, you can create your own custom consumer if you'd like! See [Inbound Data from Salesforce](../inbound/README.md)
for how to set that up.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default:
            login:
                # ...
            topics:
                # ...
            platform_events:
                # ...
            generic_events:
                # ...
            objects:
                - Account
                - User
                - UserRole
```

##### Object Polling

*Hey! Wait a minute. UserRole wasn't listed in the **Change Data Capture** field in Salesforce. How can I listen to change
events on it?*

It's true. Not all objects are supported for use with the Streaming API, and UserRole is one of them. However, no need
to worry about it. Any objects declared in the `objects` configuration that aren't supported by the Streaming API
will be polled for changes intermittently... given that they are supported by the Rest API.

Objects that require polling require a command to be run at certain intervals, most likely via Cron:

```bash
bin/console ae_connect:poll [connection_name]
```
*Again, the [connection_name] is optional and defaults to `default`. Let's get to that now.*

## Multiple Connections / Multiple Orgs

Sometimes one org just isn't enough. Maybe you have too many licenses to have one org. Whatever the reason is,
AE Connect has to covered.

First, let's setup our config with two different connections:

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    paths: ['%kernel.project_dir%/src/Entity/']
    default_connection: my_connection
    connections:
        my_connection:
            login:
                username: someuser@mysalesforceorg.com
                password: MYPASSWORD_my_user_token
            objects:
                - Account
                - Contact
                - SomeCustomObject__c
        other_connection:
            login:
                username: someotheruser@myothersforg.com
                password: PASSWORD_user_token
            objects:
                - Account
                - Contact
                - AnotherCustomObject__c
                - UserRole
                - User
```

The key under `connections` is the connection name. If a connection is named `default` it is always the default connection.
If a connection is not named `default`, such as `my_connection` in the example above, `default_connection` must be set to
the name of the connection that is meant to be default.

The default connection is what is used when no connection name is given. Typically, this is applicable for console commands:

```bash
$ bin/console ae_connect:listen  # listens to the default connection
$ bin/console ae_connect:listen other_connection  # listens to the `other_connection` connection
```

Connection names are also used in [Entity Mapping](entity_mapping.md) and [Inbound and Outbound Validations](../validation/README.md);