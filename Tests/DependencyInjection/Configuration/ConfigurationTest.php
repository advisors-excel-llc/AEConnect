<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 3:53 PM
 */

namespace AE\ConnectBundle\Tests\DependencyInjection\Configuration;

use AE\ConnectBundle\DependencyInjection\Configuration;
use Matthias\SymfonyConfigTest\PhpUnit\ConfigurationTestCaseTrait;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    use ConfigurationTestCaseTrait;

    protected function getConfiguration()
    {
        return new Configuration();
    }

    public function testEmptyConnection()
    {
        $this->assertProcessedConfigurationEquals(
            [
                'ae_connection' => [],
            ],
            [
                'paths'              => [],
                'default_connection' => 'default',
                'enqueue'            => 'default',
                'db_batch_size'      => 50,
                'connections'        => [],
                'app_name'           => null,
            ]
        );
    }

    public function testSingleConnection()
    {
        $this->assertProcessedConfigurationEquals(
            [
                'ae_connect' => [
                    'paths'       => ['%kernel.project_dir%/src/App/Entity'],
                    'connections' => [
                        'default' => [
                            'login'           => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                                'password' => 'password',
                            ],
                            'topics'          => [
                                'TestTopic' => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                            ],
                            'platform_events' => [
                                'TestEvent__e',
                            ],
                            'change_events'   => [
                                'Account',
                                'CustomObject__c',
                            ],
                            'polling'         => [
                                'User',
                            ],
                            'generic_events'  => [
                                'TestGenericEvent',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'paths'              => ['%kernel.project_dir%/src/App/Entity'],
                'default_connection' => 'default',
                'enqueue'            => 'default',
                'db_batch_size'      => 50,
                'app_name'           => null,
                'connections'        => [
                    'default' => [
                        'version'         => '44.0',
                        'login'           => [
                            'key'      => 'client_key',
                            'secret'   => 'client_secret',
                            'username' => 'username',
                            'password' => 'password',
                            'url'      => 'https://login.salesforce.com',
                        ],
                        'topics'          => [
                            'TestTopic' => [
                                'type'   => 'Account',
                                'filter' => [
                                    'CustomField__c' => 'Seattle',
                                ],
                            ],
                        ],
                        'config'          => [
                            'replay_start_id'         => -2,
                            'cache'                   => [
                                'metadata_provider' => 'ae_connect_metadata',
                                'auth_provider'     => 'ae_connect_auth',
                                'replay_provider'   => 'ae_connect_replay',
                            ],
                            'use_change_data_capture' => true,
                            'bulk_api_min_count'      => 100000,
                            'connection_logger'       => 'logger',
                            'app_filtering'           => [
                                'enabled'           => true,
                                'permitted_objects' => [],
                            ],
                        ],
                        'platform_events' => [
                            'TestEvent__e',
                        ],
                        'objects'         => [],
                        'change_events'   => [
                            'Account',
                            'CustomObject__c',
                        ],
                        'polling'         => [
                            'User',
                        ],
                        'generic_events'  => [
                            'TestGenericEvent',
                        ],
                    ],
                ],
            ]
        );
    }

    public function testDoubleConnection()
    {
        $this->assertProcessedConfigurationEquals(
            [
                'ae_connect' => [
                    'paths'         => ['%kernel.project_dir%/src/App/Entity'],
                    'app_name'      => 'testing_app',
                    'db_batch_size' => 100,
                    'connections'   => [
                        'default'     => [
                            'login'  => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                                'password' => 'password',
                            ],
                            'topics' => [
                                'TestTopic' => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                            ],
                        ],
                        'non_default' => [
                            'version' => '46.0',
                            'login'   => [
                                'entity' => 'App\\Entity\\Connection',
                            ],
                            'topics'  => [
                                'TestTopic'  => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                                'OtherTopic' => [
                                    'type'   => 'Contact',
                                    'filter' => [
                                        'CustomField__c' => 'Manhattan',
                                    ],
                                ],
                            ],
                            'config'  => [
                                'replay_start_id'    => -1,
                                'cache'              => [
                                    'metadata_provider' => 'test_metadata',
                                    'auth_provider'     => 'test_auth',
                                    'replay_provider'   => 'test_auth',
                                ],
                                'bulk_api_min_count' => PHP_INT_MAX,
                                'connection_logger'  => 'test_logger',
                                'app_filtering'      => [
                                    'permitted_objects' => [
                                        'Case',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'paths'              => ['%kernel.project_dir%/src/App/Entity'],
                'default_connection' => 'default',
                'enqueue'            => 'default',
                'db_batch_size'      => 100,
                'app_name'           => 'testing_app',
                'connections'        => [
                    'default'     => [
                        'version'         => '44.0',
                        'login'           => [
                            'key'      => 'client_key',
                            'secret'   => 'client_secret',
                            'username' => 'username',
                            'password' => 'password',
                            'url'      => 'https://login.salesforce.com',
                        ],
                        'topics'          => [
                            'TestTopic' => [
                                'type'   => 'Account',
                                'filter' => [
                                    'CustomField__c' => 'Seattle',
                                ],
                            ],
                        ],
                        'config'          => [
                            'replay_start_id'         => -2,
                            'cache'                   => [
                                'metadata_provider' => 'ae_connect_metadata',
                                'auth_provider'     => 'ae_connect_auth',
                                'replay_provider'   => 'ae_connect_replay',
                            ],
                            'use_change_data_capture' => true,
                            'bulk_api_min_count'      => 100000,
                            'connection_logger'       => 'logger',
                            'app_filtering'           => [
                                'enabled'           => true,
                                'permitted_objects' => [],
                            ],
                        ],
                        'platform_events' => [],
                        'objects'         => [],
                        'generic_events'  => [],
                        'change_events'   => [],
                        'polling'         => [],
                    ],
                    'non_default' => [
                        'version'         => '46.0',
                        'login'           => [
                            'entity' => 'App\\Entity\\Connection',
                            'url'    => 'https://login.salesforce.com',
                        ],
                        'topics'          => [
                            'TestTopic'  => [
                                'type'   => 'Account',
                                'filter' => [
                                    'CustomField__c' => 'Seattle',
                                ],
                            ],
                            'OtherTopic' => [
                                'type'   => 'Contact',
                                'filter' => [
                                    'CustomField__c' => 'Manhattan',
                                ],
                            ],
                        ],
                        'config'          => [
                            'replay_start_id'         => -1,
                            'cache'                   => [
                                'metadata_provider' => 'test_metadata',
                                'auth_provider'     => 'test_auth',
                                'replay_provider'   => 'test_auth',
                            ],
                            'use_change_data_capture' => true,
                            'bulk_api_min_count'      => PHP_INT_MAX,
                            'connection_logger'       => 'test_logger',
                            'app_filtering'           => [
                                'enabled'           => true,
                                'permitted_objects' => [
                                    'Case',
                                ],
                            ],
                        ],
                        'platform_events' => [],
                        'objects'         => [],
                        'change_events'   => [],
                        'polling'         => [],
                        'generic_events'  => [],
                    ],
                ],
            ]
        );
    }

    public function testInvalidDoubleDefaults()
    {
        $this->assertConfigurationIsInvalid(
            [
                'ae_connect' => [
                    'paths'             => ['%kernel.project_dir%/src/App/Entity'],
                    'default_connecion' => 'bob',
                    'connections'       => [
                        'default'     => [
                            'login'  => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                                'password' => 'password',
                            ],
                            'topics' => [
                                'TestTopic' => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                            ],
                        ],
                        'non_default' => [
                            'login'  => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                                'password' => 'password',
                                'url'      => 'http://test.salesforce.com',
                            ],
                            'topics' => [
                                'TestTopic'  => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                                'OtherTopic' => [
                                    'type'   => 'Contact',
                                    'filter' => [
                                        'CustomField__c' => 'Manhattan',
                                    ],
                                ],
                            ],
                            'config' => [
                                'replay_start_id' => -1,
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    public function testInvalidReplayStart()
    {
        $this->assertConfigurationIsInvalid(
            [
                'ae_connect' => [
                    'paths'              => ['%kernel.project_dir%/src/App/Entity'],
                    'default_connection' => 'non_default',
                    'connections'        => [
                        'default'     => [
                            'login'  => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                                'password' => 'password',
                            ],
                            'topics' => [
                                'TestTopic' => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                            ],
                        ],
                        'non_default' => [
                            'login'  => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                                'password' => 'password',
                                'url'      => 'http://test.salesforce.com',
                            ],
                            'topics' => [
                                'TestTopic'  => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                                'OtherTopic' => [
                                    'type'   => 'Contact',
                                    'filter' => [
                                        'CustomField__c' => 'Manhattan',
                                    ],
                                ],
                            ],
                            'config' => [
                                'replay_start_id' => -4,
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    public function testInvalidLogin()
    {
        $this->assertConfigurationIsInvalid(
            [
                'ae_connect' => [
                    'paths'              => ['%kernel.project_dir%/src/App/Entity'],
                    'default_connection' => 'non_default',
                    'connections'        => [
                        'default' => [
                            'login'  => [
                                'key'      => 'client_key',
                                'secret'   => 'client_secret',
                                'username' => 'username',
                            ],
                            'topics' => [
                                'TestTopic' => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertConfigurationIsInvalid(
            [
                'ae_connect' => [
                    'paths'              => ['%kernel.project_dir%/src/App/Entity'],
                    'default_connection' => 'non_default',
                    'connections'        => [
                        'default' => [
                            'login'  => [
                                'key'    => 'client_key',
                                'secret' => 'client_secret',
                            ],
                            'topics' => [
                                'TestTopic' => [
                                    'type'   => 'Account',
                                    'filter' => [
                                        'CustomField__c' => 'Seattle',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
