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

## Configuration Options

AE Connect tries to be smart about API limits. For that purpose, bulk operations don't actually use the Bulk API unless
there are a LOT of objects to be imported. Defaulty, if there are less than 100,000 objects, the composite API is used
instead.
 
The Bulk API can make as few as three API requests just to start a job and then has to make additional requests to
determine if a job is ready to be downloaded. And then additional requests to download the data. So for smaller batches
of objects, calling the Bulk API can result in very very many API requests, each of which count against your limits.

Using the Composite API for smaller batches allows as many as 5.000 objects to be queried and downloaded at one time.
For 100,000 objects, this results in 20 requests. Depending on how busy your Salesforce Org is with other jobs, this
could result in far more, or far less, requests.

The Composite API is also faster, while the Bulk API is meant to happen asynchronously and in the background.

In the end, 100,000, might not be a good number for your own needs. You may never want to use the Composite API or you may
always want to use it. Because of this, this value is now configurable per connection.

```yaml
# app/config.yml (or config/ae_connect.yaml if you're using flex)

ae_connect:
    connections:
        default: 
            #...
            config:
                bulk_api_min_count: 0 # 0 will disable the Composite API and always use Bulk APi
                # OR
                bulk_api_min_count: !php/const PHP_INT_MAX # use something high to always use Composite Api
```
