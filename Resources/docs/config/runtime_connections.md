# Connections Created from Stored Credentials

AE Connect now supports adding and managing connections from credentials stored in the database. The configuration
is similar but there are some major differences. Let's go through them.

## Connection Entity

First, you will need to create an Entity that implements either the `AuthCredentialsInterface`, or if you're using
an authorization code and not explicit username and password credentials, the `RefreshTokenCredentialsInterface`
should be used.

For this example, we're going to use an `App\Entity\OrgConnection` as our credentials entity which implements the 
`RefreshTokenCredentialsInterface`. A typical `AuthCredentialsInterface` workflow is basic, so let's choose the more
complicated version.
 
With a SOAP or OAuth with username and password, the username will be the username (surprise!)
but the password will be the user's password AND the user's personal token. Save that to the database and BOOM you're done.
The refresh token, or authentication code, method is very much more involved and intricate.

## Configuration

After you create your entity to hold the connection credentials, we have to declare it in the configuration:

```yaml
ae_connect:
    connections:
        my_dbal_connection: # <- this will be what you use in your metadata annotations for connections={}
            login:
                entity: 'App\Entity\OrgConnection'
            objects:
                - #...
```

Simple, right? Easy peasy.

So far, yea... that's the idea!

Even though this connection declared in the configuration has a name, it is not actually the name of each connection
that will be created using the `App\Entity\OrgConnection` entity. The name is just used for metadata mapping. The
metadata that is generated using the connection name, `my_dbal_connection`, will cloned and rebuilt for each connection
loaded from the database, each of which have their own unique name.

## Creating Connections

Here, if we were just doing a typical username and password authorization, we could just have a form and ask for this
info and save a new `App\Entity\OrgConnection` entity.

However, we're going to go for the harder route.

Before you create an credentials, you'll want to make sure your **Connected App** has the following:

 * OAuth must be enabled
 * The `refresh_token` grant, aka the Offline Access grant, must be allowed
 * A redirect uri set to return to your app. In the controller for this route, we will actually create the credential entity
 
### Getting Your Authorization Code

In your app where you want to someone, perhaps an admin, to create a new connection, you'll create a link to Salesforce
that looks something like this (really, you just want to get them to this URL somehow, link, redirect, etc...):

> https://login.salesforce.com/services/oauth2/authorize?response_type=code&client_id=CLIENT_ID_OF_YOUR_CONNECTED_APP&redirect_uri=THE_ROUTE_TO_YOUR_REDIRECT_URI_IN_YOUR_SYMFONY_APPLICATION

Of course, if you're using a sandbox, swap out *login.salesforce.com* for *test.salesforce.com*.

You can also, and SHOULD, add some optional query parameters to this URL:

1. `&state=YOUR_STATE_VALUE`<br>
    State is used to prevent a Man-in-the-Middle attack. Essentially, you create a random and unique value and hold on
    to the value, say in your database or in the user's session, then when the user arrives back at your REDIRECT_URI,
    you check the state to ensure the value is the same. If it's not, someone is trying to mess with you. Throw an
    invalid error.
2. `&prompt=login%20consent`<br>
    Adding this parameter with the explicit value of `login%20consent`, tells Salesforce that the user should be
    forced to login. Not only does this ensure that the user is who they say they are, but also that the connection
    will be made to the correct organization.
    
### Creating the Connection at the Redirect URI

Let's say that when you configured your Connected App in Salesforce, you specified your redirect uri to be
https://my.awesome.app/salesforce/auth.

You'll need to create a controller to handle the code that Salesforce is going to send to you and create the actual
OrgConnection entity.

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use AE\ConnectBundle\Sdk\AuthProvider\MutableOAuthProvider;
use Symfony\Bridge\Doctrine\RegistryInterface;
use AE\ConnectBundle\Connection\Dbal\RefreshTokenCredentialsInterface;
use Symfony\Component\HttpFoundation\Response;
use AE\ConnectBundle\Driver\DbalConnectionDriver;
use AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException;
use App\Entity\OrgConnection;

/**
 * Class SalesforceAuthConnection
 * @package App\Controller
 * @Route("/salesforce")
 */
class SalesforceConnection extends Controller {
    
    /**
     * @var RegistryInterface
     */
    private $doctrine;
    
    /**
     * @var DbalConnectionDriver
     */
    private $dbalDriver;
    
    public function __construct(RegistryInterface $doctrine, DbalConnectionDriver $driver)
     {
         $this->doctrine   = $doctrine;
         $this->dbalDriver = $driver;
     }
    
    /**
     * @param Request $request
     * @Route("/auth")
     * @throws AuthenticationException
     */
    public function authAction(Request $request)
    {
        // If Salesforce sends the error parameter, it's not good
        if ($request->query->has('error')) {
            throw new AuthenticationException($request->query->get('error'));
        }
        
        $session = $request->getSession();
        // Check the state to see if the request is valid
        if ($session->has('state')) {
            if (!$request->query->has('state') || $session->get('state') !== $request->query->get('state')) {
                throw new AuthenticationException('Invalid state provided.');
            }
        }
        
        $code = $request->query->get('code');
        
        // Next, we need to authorize the code and get the session and refresh keys
        $authProvider = new MutableOAuthProvider(
            'MY_CLIENT_ID',
            'MY_CLIENT_SECRET',
            'https://login.salesforce.com', // Should be the same as the domain in your code request
            null, // Don't need the username
            null, // Don't need the password
            MutableOAuthProvider::GRANT_CODE, // Need this in order to say, hey! this is a code grant
            'https://my.awesome.app/salesforce/auth', // This is the redirect uri
            $code // Gotta have the code!
        );
        
        try {
            $authProvider->authorize();
            
            // Now we can pull some stuff off the auth provider to store if for AE Connect to use later
            $manager = $this->doctrine->getManagerForClass(OrgConnection::class);
            
            /** @var RefreshTokenCredentialsInterface $connection */
            $connection = new OrgConnection();
            $connection->setName('some_made_up_name_somehow');
            $connection->setLoginUrl('https://login.salesforce.com'); // Helps if you're using a sandbox
            $connection->setUsername($authProvider->getUsername()); // Once authorized, the $authProvider gets some user info
            $connection->setToken($authProvider->getToken());
            $connection->setRefreshToken($authProvider->getRefreshToken());
            $connection->setType(RefreshTokenCredentialsInterface::OAUTH); // Need to tell it that it's an OAuth connection
            $connection->setClientId('MY_CLIENT_ID');
            $connection->setClientSecret('MY_CLIENT_SECRET');
            $connection->setActive(true);
            
            $manager->persist($connection);
            $manager->flush();
            
            // This right here will load the connection into AE Connect and cache the metadata for the connection
            $this->dbalDriver->loadConnections();
            
            return new Response('Connection succesfully created');
        } catch (SessionExpiredOrInvalidException $e) {
            throw new AuthenticationException("The authorization code provided is invalid.");
        }
    }
}
```

In reality, you probably would create the connection before shipping the user off to Salesforce. This way you could
get a unique name, maybe allow them to set the ClientId and ClientSecret and choose if it's a Sandbox or Production
organization.

Then, when they reach the controller above, you could use something stored in the session, perhaps the
same value as was used for `state`, to query the database for the pre-existing connection, fill in the username,
token, and refresh token, set to active, and save. For the sake of brevity, I skipped all of that. Just know that there
is a better way than this.

## Success!

So that's it! The heavy lifting is really in getting the authentication code from Salesforce. Everything else
is easy peasy!

## Data Containment
There are some things you be aware of when handling multiple connections in this way. There's a high potential for
data bleed. Even if you've specified the connection name for your database-driven connection factory, there's nothing
to tell AE Connect which data goes where!

Or is there?

I would like to introduce you to my friend, the `@Connection` annotation. Simply annotate a property (or getter and setter)
on your entities with `@Connection` and this value will be used to determine which connection, explicity, this exact
instance of your entity should be sent to.

Likewise, anything inbound from Salesforce will ensure the connection name matches before updating any found entities
and will set the connection name when creating any new ones.

The connection name acts as a segmentation key, ensuring that even though an entity may be mapped the same way for
many different connections, only the records that should update are updated.

### IMPORTANT!

For records created locally and sent outbound, it is up to the application to set the value of the property associated
with the `@Connection` annotation. Most commonly, this value would pertain to the state of the data, like which user
owns the record.

Users are the easiest entity to leverage because if you have a local entity mapped to the User object
in Salesforce, you can have a connection property that is set when the user is added (or if they're loaded from Salesforce,
AE Connect will set this property for you). Any entities associated with the user object could then leverage its
connection property.

The `@Connection` annotation does support the `connections={}` attribute, allowing you to specify only the entity-driven
connections and ignore any standard, config-defined connections.

> There's more to `@Connection` and the `AuthCredentialsInterface` than meets the eye!
> Check out *[Advanced Connection Strategies](./advanced_connections.md)* to learn more.