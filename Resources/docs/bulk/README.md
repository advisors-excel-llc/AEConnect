# Bulk Synchronization

There are times, such as when switching or refreshing sandboxes, where you want to pull existing data from Salesforce
and getting your local data into it.

AE Connect lets you run a simple command to do just that! You can also specify whether you want your local records updated
or not and if you want the records in Salesforce updated or not.

When you perform a bulk sync, all of your Salesforce Ids associated with your records are cleared. This helps determine
which records are in the Salesforce Org and which are not. If, after the download sync from Salesforce, a Salesforce Id
is missing, then the record does not exist in Salesforce and will be created when the upload sync occurs.

Defaultly, only the Ids are sync'd on download from Salesforce unless you specify that you want your local data updated
with the values for the mapped fields in Salesforce.

Also, only new records are created in Salesforce by default. Only when you specify that you want existing data in Salesforce
to be updated with your local, will that data be updated.

So out of the box, only new data will be synced down or up. It's only when you opt update local data or data in Salesforce
will that data be updated.

Ok, now that that's all out of the way, let's sync!

> It's best to stop all `ae_connect:listen` and `ae_connect:consume` processes before running the bulk sync

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

# This will limit the number of entities queried at one time from your local database,
# which helps in conserving local resources
$ bin/console ae_connect:bulk -l 1000 # limits to querying 1000 records at a time

# Let's put it all together!
# This command will sync all entities associated the Account type both up and down for the default connection
# limiting local queries to 1000 records
$ bin/console ae_connect:bulk default -t Account -i -o -l 1000

```