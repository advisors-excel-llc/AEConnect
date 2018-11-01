# Bulk Synchronization

There are times, such as when switching or refreshing sandboxes, where you want to pull existing data from Salesforce
and getting your local data into it.

AE Connect lets you run a simple command to do just that! You can also specify whether you want your local records updated
or not and if you want the records in Salesforce updated or not.

Defaultly, only the Ids are sync'd on download from Salesforce unless you specify that you want your local data updated
with the values for the mapped fields in Salesforce.

Also, only new records are created in Salesforce by default. Only when you specify that you want existing data in Salesforce
to be updated using your local records, will that data be updated.

So out of the box, only new data will be synced down or up. It's only when you opt update local data or data in Salesforce
will that data be updated.

Ok, now that that's all out of the way, let's sync!

> It's best to stop all `ae_connect:listen` and `ae_connect:consume` processes before running the bulk sync

> ALWAYS! ALWAYS! ALWAYS! Backup data before performing operations that alter data

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