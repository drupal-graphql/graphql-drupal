<?php

namespace Drupal\Tests\graphql\Traits;

use Drupal\Component\Plugin\Discovery\DerivativeDiscoveryDecorator;
use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\ContainerDerivativeDiscoveryDecorator;
use Drupal\graphql\Annotation\GraphQLEnum;
use Drupal\graphql\Annotation\GraphQLField;
use Drupal\graphql\Annotation\GraphQLInputType;
use Drupal\graphql\Annotation\GraphQLInterface;
use Drupal\graphql\Annotation\GraphQLMutation;
use Drupal\graphql\Annotation\GraphQLType;
use Drupal\graphql\Annotation\GraphQLUnionType;
use Drupal\graphql\Plugin\GraphQL\Enums\EnumPluginBase;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use Drupal\graphql\Plugin\GraphQL\InputTypes\InputTypePluginBase;
use Drupal\graphql\Plugin\GraphQL\Interfaces\InterfacePluginBase;
use Drupal\graphql\Plugin\GraphQL\Mutations\MutationPluginBase;
use Drupal\graphql\Plugin\GraphQL\Scalars\ScalarPluginBase;
use Drupal\graphql\Plugin\GraphQL\Schemas\SchemaPluginBase;
use Drupal\graphql\Plugin\GraphQL\Subscriptions\SubscriptionPluginBase;
use Drupal\graphql\Plugin\GraphQL\Types\TypePluginBase;
use Drupal\graphql\Plugin\GraphQL\Unions\UnionTypePluginBase;

/**
 * Trait for mocking GraphQL type system plugins.
 */
trait MockGraphQLPluginTrait {

  /**
   * The list of mocked type system plugins.
   *
   * @var array
   */
  protected $graphQLPlugins = [];

  protected $graphQLPluginManagers = [];

  protected $graphqlPluginDecorators = [];

  /**
   * Reset static caches in plugin managers.
   */
  protected function resetStaticCaches() {
    $definitionsProperty = new \ReflectionProperty(DefaultPluginManager::class, 'definitions');
    $definitionsProperty->setAccessible(TRUE);

    foreach ($this->graphQLPluginManagers as $manager) {
      $definitionsProperty->setValue($manager, NULL);
    }

    $deriversProperty = new \ReflectionProperty(DerivativeDiscoveryDecorator::class, 'derivers');
    $deriversProperty->setAccessible(TRUE);

    foreach ($this->graphqlPluginDecorators as $decorator) {
      $deriversProperty->setValue($decorator, NULL);
    }
  }

  /**
   * Maps type system manager id's to required plugin interfaces.
   *
   * @var string[]
   */
  protected $graphQLPluginClassMap = [
    'plugin.manager.graphql.schema' => SchemaPluginBase::class,
    'plugin.manager.graphql.field' => FieldPluginBase::class,
    'plugin.manager.graphql.mutation' => MutationPluginBase::class,
    'plugin.manager.graphql.subscription' => SubscriptionPluginBase::class,
    'plugin.manager.graphql.union' => UnionTypePluginBase::class,
    'plugin.manager.graphql.interface' => InterfacePluginBase::class,
    'plugin.manager.graphql.type' => TypePluginBase::class,
    'plugin.manager.graphql.input' => InputTypePluginBase::class,
    'plugin.manager.graphql.scalar' => ScalarPluginBase::class,
    'plugin.manager.graphql.enum' => EnumPluginBase::class,
  ];

  /**
   * Register the mocked plugin managers during container build.
   *
   * Injects the mocked schema managers into the drupal container. Has to be
   * invoked during the KernelTest's register callback.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container instance.
   *
   * @throws \Exception
   * @throws \ReflectionException
   */
  protected function injectTypeSystemPluginManagers(ContainerBuilder $container) {
    foreach ($this->graphQLPluginClassMap as $id => $class) {
      $this->graphQLPlugins[$class] = [];
      /** @var \Drupal\Core\Plugin\DefaultPluginManager $manager */
      $manager = $container->get($id);

      $this->graphQLPluginManagers[$id] = $manager;

      // Really?
      $factoryMethod = new \ReflectionMethod($manager, 'getFactory');
      $factoryMethod->setAccessible(TRUE);
      $factoryProp = new \ReflectionProperty($manager, 'factory');
      $factoryProp->setAccessible(TRUE);

      $discoveryMethod = new \ReflectionMethod($manager, 'getDiscovery');
      $discoveryMethod->setAccessible(TRUE);
      $discoveryProp = new \ReflectionProperty($manager, 'discovery');
      $discoveryProp->setAccessible(TRUE);

      /** @var FactoryInterface $factory */
      $factory = $factoryMethod->invoke($manager);
      /** @var DiscoveryInterface $discovery */
      $discovery = $discoveryMethod->invoke($manager);

      $decoratedProp = new \ReflectionProperty(DerivativeDiscoveryDecorator::class, 'decorated');
      $decoratedProp->setAccessible(TRUE);
      $unwrappedDiscovery = $decoratedProp->getValue($discovery);

      $this->graphQLPlugins[$class] = [];

      $mockFactory = $this
        ->getMockBuilder(FactoryInterface::class)
        ->setMethods([
          'createInstance',
        ])
        ->getMock();

      $mockDiscovery = $this
        ->getMockBuilder(DiscoveryInterface::class)
        ->setMethods([
          'hasDefinition',
          'getDefinitions',
          'getDefinition',
        ])
        ->getMock();

      $decoratedDiscovery = new ContainerDerivativeDiscoveryDecorator($mockDiscovery);

      $this->graphqlPluginDecorators[$id] = $decoratedDiscovery;

      $mockDiscovery
        ->expects(static::any())
        ->method('getDefinitions')
        ->willReturnCallback(function () use ($class, $unwrappedDiscovery) {
          $mockDefinitions = array_map(function ($plugin) {
            return $plugin['definition'];
          }, $this->graphQLPlugins[$class]);
          $realDefinitions = $unwrappedDiscovery->getDefinitions();
          return array_merge($mockDefinitions, $realDefinitions);
        });

      $mockDiscovery
        ->expects(static::any())
        ->method('hasDefinition')
        ->with(static::anything())
        ->willReturnCallback(function ($pluginId) use ($class, $discovery) {
          $basePluginId = $this->getBasePluginId($pluginId);
          return isset($this->graphQLPlugins[$class][$basePluginId]) || $discovery->hasDefinition($pluginId);
        });

      $mockDiscovery
        ->expects(static::any())
        ->method('getDefinition')
        ->with(static::anything(), static::anything())
        ->willReturnCallback(function ($pluginId, $except) use ($class, $discovery) {
          $basePluginId = $this->getBasePluginId($pluginId);
          if (array_key_exists($basePluginId, $this->graphQLPlugins[$class])) {
            return $this->graphQLPlugins[$class][$basePluginId]['definition'];
          }
          return $discovery->getDefinition($pluginId, $except);
        });

      $discoveryProp->setValue($manager, $decoratedDiscovery);

      $mockFactory->expects(static::any())
        ->method('createInstance')
        ->with(static::anything(), static::anything())
        ->willReturnCallback(function ($pluginId, $configuration) use ($class, $factory, $decoratedDiscovery) {
          $basePluginId = $this->getBasePluginId($pluginId);
          if (array_key_exists($basePluginId, $this->graphQLPlugins[$class])) {
            $definition = $decoratedDiscovery->getDefinition($pluginId);
            $args = $this->graphQLPlugins[$class][$basePluginId];
            $args['definition'] = $definition;
            return call_user_func_array([$this, $definition['mock_factory']], $args);
          }
          return $factory->createInstance($pluginId, $configuration);
        });

      $factoryProp->setValue($manager, $mockFactory);
    }
  }

  /**
   * @param $pluginId
   *
   * @return mixed
   */
  private function getBasePluginId($pluginId) {
    return strpos($pluginId, ':') ? explode(':', $pluginId)[0] : $pluginId;
  }

  /**
   * Get a plugin definition.
   *
   * Merges plugin definition with the default values for a specified
   * annotation class.
   *
   * @param string $annotationClass
   *   The plugin annotation class name.
   * @param array $definition
   *   The definition values.
   *
   * @return array
   *   The complete plugin definition.
   *
   * @internal
   */
  protected function getTypeSystemPluginDefinition($annotationClass, array $definition) {
    return (new $annotationClass($definition))->get();
  }

  /**
   * Add a new plugin to the GraphQL type system.
   *
   * @param \Drupal\Component\Plugin\PluginInspectionInterface $plugin
   *   The plugin to add.
   *
   * @internal
   */
  protected function addTypeSystemPlugin(PluginInspectionInterface $plugin) {
    foreach ($this->graphQLPluginClassMap as $id => $class) {
      if ($plugin instanceof $class) {
        $this->graphQLPlugins[$id][$plugin->getPluginId()] = $plugin;
      }
    }
  }

  /**
   * Turn a value into a result promise.
   *
   * @param mixed $value
   *   The return value. Can also be a value callback.
   *
   * @return \PHPUnit_Framework_MockObject_Stub_ReturnCallback
   *   The return callback promise.
   */
  protected function toPromise($value) {
    return $this->returnCallback(is_callable($value) ? $value : function () use ($value) {
      yield $value;
    });
  }

  /**
   * Turn a value into a bound result promise.
   *
   * @param mixed $value
   *   The return value. Can also be a value callback.
   * @param mixed $scope
   *   The resolver's bound object and class scope.
   *
   * @return \PHPUnit_Framework_MockObject_Stub_ReturnCallback
   *   The return callback promise.
   */
  protected function toBoundPromise($value, $scope) {
    return $this->toPromise(is_callable($value) ? \Closure::bind($value, $scope, $scope) : $value);
  }

  /**
   * Mock a schema instance.
   *
   * @param string $id
   *   The schema id.
   * @param callable|null $builder
   *   A builder callback to modify the mock instance.
   */
  protected function mockSchema($id, $builder = NULL) {
    $this->graphQLPlugins[SchemaPluginBase::class][$id] = [
      'definition' => $this->getSchemaDefinitions()[$id] + [
        'mock_factory' => 'mockSchemaFactory',
      ],
      'builder' => $builder,
    ];
  }

  protected function mockSchemaFactory($definition, $builder) {
    $schema = $this->getMockForAbstractClass(SchemaPluginBase::class, [
      [],
      $definition['id'],
      $definition,
      $this->container->get('plugin.manager.graphql.field'),
      $this->container->get('plugin.manager.graphql.mutation'),
      $this->container->get('plugin.manager.graphql.subscription'),
      $this->container->get('graphql.type_manager_aggregator'),
      $this->container->get('graphql.query_provider'),
      $this->container->get('current_user'),
      $this->container->get('logger.channel.graphql'),
      $this->container->get('language_manager'),
      $this->container->getParameter('graphql.config')
    ]);

    if (is_callable($builder)) {
      $builder($schema);
    }

    return $schema;
  }

  /**
   * Mock a GraphQL field.
   *
   * @param string $id
   *   The field id.
   * @param array $definition
   *   The plugin definition. Will be merged with the field defaults.
   * @param mixed|null $result
   *   A result for this field. Can be a value or a callback. If omitted, no
   *   resolve method mock will be attached.
   * @param callable|null $builder
   *   A builder callback to modify the mock instance.
   */
  protected function mockField($id, $definition, $result = NULL, $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLField::class,
      $definition + [
        'secure' => TRUE,
        'id' => $id,
        'class' => FieldPluginBase::class,
        'mock_factory' => 'mockFieldFactory',
      ]
    );

    $this->graphQLPlugins[FieldPluginBase::class][$id] = [
      'definition' => $definition,
      'result' => $result,
      'builder' => $builder,
    ];
  }

  protected function mockFieldFactory($definition, $result = NULL, $builder = NULL) {
    $field = $this->getMockBuilder(FieldPluginBase::class)
      ->setConstructorArgs([[], $definition['id'], $definition])
      ->setMethods([
        'resolveValues',
      ])->getMock();

    if (isset($result)) {
      $field
        ->expects(static::any())
        ->method('resolveValues')
        ->with(static::anything(), static::anything(), static::anything(), static::anything())
        ->will($this->toBoundPromise($result, $field));
    }

    if (is_callable($builder)) {
      $builder($field);
    }

    return $field;
  }

  /**
   * Mock a GraphQL type.
   *
   * @param string $id
   *   The type id.
   * @param array $definition
   *   The plugin definition. Will be merged with the type defaults.
   * @param mixed|null $applies
   *   A result for the types "applies" method. Defaults to `TRUE`.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The type mock object.
   */
  protected function mockType($id, array $definition, $applies = TRUE, $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLType::class,
      $definition + [
        'id' => $id,
        'class' => TypePluginBase::class,
        'mock_factory' => 'mockTypeFactory',
      ]
    );

    $this->graphQLPlugins[TypePluginBase::class][$id] = [
      'definition' => $definition,
      'applies' => $applies,
      'builder' => $builder,
    ];

  }

  protected function mockTypeFactory($definition, $applies = TRUE, $builder = NULL) {
    $type = $this->getMockBuilder(TypePluginBase::class)
      ->setConstructorArgs([[], $definition['id'], $definition])
      ->setMethods([
        'applies',
      ])->getMock();

    $type
      ->expects(static::any())
      ->method('applies')
      ->with($this->anything(), $this->anything())
      ->will($this->toBoundPromise($applies, $type));

    if (is_callable($builder)) {
      $builder($type);
    }

    return $type;
  }

  /**
   * Mock a GraphQL input type.
   *
   * @param string $id
   *   The input type id.
   * @param array $definition
   *   The plugin definition. Will be merged with the input type defaults.
   */
  protected function mockInputType($id, array $definition, $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLInputType::class,
      $definition + [
        'id' => $id,
        'class' => InputTypePluginBase::class,
        'mock_factory' => 'mockInputTypeFactory',
      ]
    );
    $this->graphQLPlugins[InputTypePluginBase::class][$id] = [
      'definition' => $definition,
      'builder' => $builder,
    ];
  }

  protected function mockInputTypeFactory($definition, $builder) {
    $input = $this->getMockForAbstractClass(
      InputTypePluginBase::class, [
        [],
        $definition['id'],
        $definition,
      ]
    );

    if (is_callable($builder)) {
      $builder($input);
    }

    return $input;
  }

  /**
   * Mock a GraphQL mutation.
   *
   * @param string $id
   *   The mutation id.
   * @param array $definition
   *   The plugin definition. Will be merged with the mutation defaults.
   * @param mixed|null $result
   *   A result for this mutation. Can be a value or a callback. If omitted, no
   *   resolve method mock will be attached.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The mutation mock object.
   */
  protected function mockMutation($id, array $definition, $result = NULL, $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLMutation::class,
      $definition + [
        'id' => $id,
        'class' => MutationPluginBase::class,
        'mock_factory' => 'mockMutationFactory',
      ]
    );

    $this->graphQLPlugins[MutationPluginBase::class][$id] = [
      'definition' => $definition,
      'result' => $result,
      'builder' => $builder,
    ];
  }

  protected function mockMutationFactory($definition, $result = NULL, $builder = NULL) {
    $mutation = $this->getMockBuilder(MutationPluginBase::class)
      ->setConstructorArgs([[], $definition['id'], $definition])
      ->setMethods([
        'resolve',
      ])->getMock();

    if (isset($result)) {
      $mutation
        ->expects(static::any())
        ->method('resolve')
        ->with(static::anything(), static::anything(), static::anything(), static::anything())
        ->will($this->toBoundPromise($result, $mutation));
    }

    if (is_callable($builder)) {
      $builder($mutation);
    }

    return $mutation;
  }

  /**
   * Mock a GraphQL interface.
   *
   * @param string $id
   *   The interface id.
   * @param array $definition
   *   The plugin definition. Will be merged with the interface defaults.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The interface mock object.
   */
  protected function mockInterface($id, array $definition, $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLInterface::class,
      $definition + [
        'id' => $id,
        'class' => InterfacePluginBase::class,
        'mock_factory' => 'mockInterfaceFactory',
      ]
    );

    $this->graphQLPlugins[InputTypePluginBase::class][$id] = [
      'definition' => $definition,
      'builder' => $builder,
    ];
  }

  protected function mockInterfaceFactory($definition, $builder = NULL) {
    $interface = $this->getMockForAbstractClass(InterfacePluginBase::class, [
      [],
      $definition['id'],
      $definition,
    ]);

    if (is_callable($builder)) {
      $builder($interface);
    }

    return $interface;
  }


  /**
   * Mock a GraphQL union.
   *
   * @param string $id
   *   The union id.
   * @param array $definition
   *   The plugin definition. Will be merged with the union defaults.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The union mock object.
   */
  protected function mockUnion($id, array $definition, $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLUnionType::class,
      $definition + [
        'id' => $id,
        'class' => UnionTypePluginBase::class,
        'mock_factory' => 'mockUnionFactory',
      ]
    );

    $this->graphQLPlugins[UnionTypePluginBase::class][$id] = [
      'definition' => $definition,
      'builder' => $builder,
    ];
  }

  protected function mockUnionFactory($definition, $builder) {
    $union = $this->getMockForAbstractClass(UnionTypePluginBase::class, [
      [],
      $definition['id'],
      $definition,
    ]);

    if (is_callable($union)) {
      $builder($union);
    }

    return $union;
  }

  /**
   * Mock a GraphQL enum.
   *
   * @param string $id
   *   The enum id.
   * @param array $definition
   *   The plugin definition. Will be merged with the enum defaults.
   * @param mixed $values
   *   The array enum values. Can also be a value callback.
   */
  protected function mockEnum($id, array $definition, $values = [], $builder = NULL) {
    $definition = $this->getTypeSystemPluginDefinition(
      GraphQLEnum::class,
      $definition + [
        'id' => $id,
        'class' => EnumPluginBase::class,
        'mock_factory' => 'mockEnumFactory',
      ]
    );

    $this->graphQLPlugins[EnumPluginBase::class][$id] = [
      'definition' => $definition,
      'values' => $values,
      'builder' => $builder,
    ];
  }

  protected function mockEnumFactory($definition, $values = [], $builder = NULL) {
    $enum = $this->getMockBuilder(EnumPluginBase::class)
      ->setConstructorArgs([[], $definition['id'], $definition])
      ->setMethods([
        'buildEnumValues',
      ])->getMock();

    $enum
      ->expects(static::any())
      ->method('buildEnumValues')
      ->with($this->anything())
      ->will($this->toBoundPromise($values, $enum));

    if (is_callable($builder)) {
      $builder($enum);
    }

    return $enum;
  }

}
