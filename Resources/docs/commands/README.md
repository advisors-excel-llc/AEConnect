# Commands and Debugging
This section is provided for quick reference to the various Command Line Interface (CLI) programs found in AE Connect.
Some of these commands are necessary for the processing of data to and from Salesforce. Some are to debug configuration,
connections, and observing streaming data.

## Operations Commands
Operations Commands are process runners and background task runners that are required for AE Connect to handle data
transactions to and from Salesforce. Much of the operations of AE Connect happens in the background to allow client-side
requests to be as fast as possible and to prevent the app from crashing if the connection to Salesforce is broken for any reason.

Since these are background processes, it's best to run them using something like `supervisord` or their own Docker container.

### ae_connect:consume

This command is a specialized configuration for the Enqueue Consume command that collects messages in batch and sends
changes to Salesforce in bulk after the idle window, `-w`, defaultly 10 seconds, completes or when count reaches 1000 messages.
The idle window is reset every time a new message is received. If 1000 messages are received before the idle window can complete,
then 1000 messages are sent to Salesforce in a single composite request.

```bash
$ bin/console ae_connect:consume # -w 10 or --wait 10 appended would wait 10 seconds from the last message
```

[Read More](../outbound/README.md)

### ae_connect:listen

While `ae_connect:consume` is meant for the background processing of outbound messages sending to Salesforce,
`ae_connect:listen` is meant to receive messages from Salesforce using the Streaming API.

```bash
$ bin/console ae_connect:listen  # this will start the default connection
$ bin/console ae_connect:listen other_connection # this will start the listener for a connection named "other_connection"
```

[Read More](../inbound/README.md)

### ae_connect:poll

Some objects cannot be handled via the Streaming API. In those cases, changes must be polled at a given interval.
AE Connect will handle any objects that are not able to be supported by Streaming API via Polling. For more information
on polling and configuration surrounding polling, see [Object Polling](../config/README.md#Object Polling)

```bash
# crontab
# Run every 5 mins
*/5 * * * * php bin/console ae_connect:poll [specify connection or else it uses default] &>/var/log/ae_connect_poll_log &
```

[Read More](../config/README.md#Object Polling)

## On-Demand Data Sync

In most situations, one system or another is a "source-of-truth" for data. Whether it be your local application or
Salesforce, data has to get from one application to another; often times in the case of connecting a new app to Salesforce
or after changing data structures through a migration. Point being, data evolves and things change. When that happens
it's important to sync up every integrated system involved.

Unlike Operations Commands, On-Demand Commands are meant to be run as needed.

On-Demand commands are bulk commands but that doesn't necessarily mean they use the Bulk API. Each connection can be
configured on how many records should exist before using the Bulk API, otherwise, requests are made for data from
Salesforce using the Composite API. See [Bulk Configuration Options](../bulk/README.md#Configuration Options) for more
information.

### ae_connect:bulk

```bash
# This command will sync all new entities for all object types for all connections
$ bin/console ae_connect:bulk

# This command will sync all new entities for all object types for only the default connection
$ bin/console ae_connect:bulk default

# This command will sync all entities down but only new entities up for all connections
$ bin/console ae_connect:bulk -i

# This command will sync all entities up but only new entities down for all connections
$ bin/console ae_connect:bulk -o

# This command will sync only new entities associated with accounts for all connections
$ bin/console ae_connect:bulk -t Account

# Use -c to clear all existing Salesforce IDs from the database. They will be re-synced to existing entities using
# the external id. This is handy for sandbox refreshes.
$ bin/console ae_connect:bulk -c

# Let's put it all together!
# This command will sync all entities associated the Account and Contact types both up and down for the default connection
# clearing all pre-existing Salesforce IDs
$ bin/console ae_connect:bulk default -t Account -t Contact -i -o -c

```

[Read More](../bulk/README.md)

### ae_connect:bulk:import:query

In situations where you need to download a subset of data from Salesforce to your application, `ae_connect:bulk:import:query`
is perfect.

Just remember that it's up to you to specify which fields from Salesforce you need in your data. If the data
coming from Salesforce does not exist in the local application's database, a new entity will be created. If there are any
fields that null values are not permitted and those fields' mapped SObject field names are not in the SOQL query, it can
cause errors when syncing.

If you're just trying to update existing data in your local, then only the fields specified will be updated. Any other field
data on the entity will remain the same.

```bash
$ bin/console ae_connect:bulk:import:query "SELECT Name, AccountNumber FROM Account WHERE CreatedDate >= TODAY" -c [connection name or else it uses default]
```

Wildcards are supported in the Select statement of the SOQL query, even though SOQL does not support them. AE Connect replaces
the wildcard with all fields defined in the metadata for the connection.

```bash
$ bin/console ae_connect:bulk:import:query "SELECT * FROM Account WHERE CreatedDate >= TODAY" -c default
```

[Read More](../bulk/README.md#Query Data from Salesforce)

## Debug Commands

Errors occur and when they do debugging commands are a huge help when troubleshooting. Use any of the following commands
to check connection configurations, metadata mapping, or watch data come streaming in from Salesforce.

### debug:ae_connect:connections

Using this `debug:ae_connect:connections` command, you can get a birds-eye view of all the connection in your app, or
dig in to a specific one to get more information, such as the current OAuth Token.

```bash
$ bin/console debug:ae_connect:connections # See all connections, their instance URL, if they're active, etc
$ bin/console debug:ae_connect:connections default # Get specifics on a connection, Auth token, is authorized, client key and secret, etc
```

### debug:ae_connect:metadata

In those instances where you need to know if your annotation mapping on an entity is correct, the `debug:ae_connect:metadata`
command is a huge help.

The command takes one argument, which can be the entity's namespaced classname, i.e. `App\Entity\Account`, or the SObject type
in Salesforce, i.e. `Product2`.

Defaultly, the command will spit out all the metadata for the given entity/object for every connection defined in the
configuration. If you're only interested in one, just add the option `-c` or `--connection`.


```bash
$ bin/console debug:ae_connect:metadata Account # gets the metadata config for EVERY entity that is mapped to the SObject type Account for EVERY connection
$ bin/console debug:ae_connect:metadata App\\Entity\\Account # gets the metadata config for the Account entity for EVERY connection
$ bin/console debug:ae_connect:metadata App\\Entity\\Account -c default # gets the metadata config for the Account entity for the "default" connection
```

### debug:ae_connect:streaming

This command will subscribe to all push topics, generic events, platform events and change data events, as defined in the
configuration, and output received messages in JSON format to the console or file. This can be helpful to troubleshooting
any custom consumers you've written or to ensure that the data is actually being sent from Salesforce.

```bash
$ bin/console debug:ae_connect:streaming -c [connection name or else it uses "default"]
$ bin/console debug:ae_connect:streaming > ./my_streaming_file.txt
```

The command will continue to run until killed by pressing `ctrl+c`.