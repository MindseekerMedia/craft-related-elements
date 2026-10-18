<?php

// Run with: php tests/cte-quoting.php (no database server required).
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
require_once dirname(__DIR__) . '/src/services/Relations.php';

use craft\elements\Entry;
use mindseekermedia\craftrelatedelements\services\Relations;
use yii\db\Connection;

$app = new class(['id' => 'cte-quoting-test', 'basePath' => __DIR__]) extends yii\console\Application {
    public function getDb(): Connection
    {
        return $this->get('db');
    }
};

// Only query construction is under test; skip element initialization services.
$element = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
$element->id = 5;
$element->siteId = 1;
$service = new Relations();
$method = new ReflectionMethod($service, 'referencedByQuery');

foreach (['pgsql' => '"', 'mysql' => '`'] as $driver => $quote) {
    $db = new Connection(['dsn' => "$driver:host=localhost;dbname=unused"]);
    // MySQL's builder reads this cache before opening a server connection.
    $db->schemaCache = new yii\caching\ArrayCache();
    $db->enableSchemaCache = true;
    $db->schemaCache->set([yii\db\mysql\QueryBuilder::class . '::supportsFractionalSeconds', $db->dsn], '8.0.0');
    $app->set('db', $db);
    $query = $method->invoke($service, $element, ['entries']);
    [$sql] = $db->getQueryBuilder()->build($query);
    $alias = $quote . 'relatedOwners' . $quote;
    if (!str_starts_with($sql, "WITH RECURSIVE $alias AS (") || substr_count($sql, $alias) !== 3) {
        throw new RuntimeException("$driver: CTE declaration and references must use the same quoted name.\n$sql");
    }
    echo "PASS: $driver recursive CTE declaration and references match\n";
}
