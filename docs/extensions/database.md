# Database & ORM

Overnight supports multiple database backends through Cycle ORM.

## Configuration

Register `ON\DB\DatabaseExtension` in `config/extensions.php` (or via `$app->install(DatabaseExtension::class, [])`) and configure connections on `ON\DB\DatabaseConfig`:

```php
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseExtension;

// Register the extension (typically in config/extensions.php)
$app->install(DatabaseExtension::class, []);

// Configure databases via DatabaseConfig (signature: $name, $dsn, $attributes = [], $username = '', $password = '', ...)
$config = new DatabaseConfig();

// MySQL
$config->addDatabase('default', 'mysql:host=localhost;dbname=myapp', [], 'root', 'password');

// PostgreSQL
$config->addDatabase('default', 'pgsql:host=localhost;dbname=myapp', [], 'user', 'pass');

// SQLite
$config->addDatabase('default', 'sqlite:' . __DIR__ . '/database.db');

// Multiple databases (named arguments also work)
$config->addDatabase(
    name: 'users',
    dsn: 'mysql:host=localhost;dbname=users',
    username: 'root',
    password: '',
);
$config->addDatabase(
    name: 'orders',
    dsn: 'mysql:host=localhost;dbname=orders',
    username: 'root',
    password: '',
);

$config->setDefault('default');
```

## Cycle ORM

### Entity Definition

Overnight defines entities through the sole `ON\Data\Definition\Registry` (registered via `DataDefinitionConfigureEvent`) rather than `Cycle\Annotated` attributes. `ON\DB\Cycle\Schema\CycleRegistryGenerator` compiles the registry into a Cycle ORM schema automatically.

```php
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

final class AppDefinitionsExtension extends AbstractExtension
{
    public function register(Init $init): void
    {
        $init->on(DataDefinitionConfigureEvent::class, function (DataDefinitionConfigureEvent $event): void {
            $event->registry
                ->collection('user')
                ->table('users')
                ->primaryKey('id')
                ->field('id', 'int')->autoIncrement(true)->end()
                ->field('name', 'string')->validation('required|max:255')->end()
                ->field('email', 'string')->unique(true)->validation('required|email')->end()
                ->field('password', 'string')->nullable(true)->hidden(true)->end()
                ->field('created_at', 'datetime')->end()
                ->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
                ->end();
        });
    }
}
```

See [ORM Entity Definition](../orm-entity-definition.md) and [ON\Data Definition Architecture](../ondata-cycle-schema-migration.md) for the full fluent API, `CycleCollectionPlugin`, and caching details.

### Repository Pattern

```php
class UserRepository
{
    public function __construct(
        private Database $db
    ) {}

    public function find(int $id): ?User
    {
        return $this->db->users->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->db->users->select()->where('email', $email)->fetchOne();
    }

    public function all(): array
    {
        return $this->db->users->select()->fetchAll();
    }

    public function create(array $data): User
    {
        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->createdAt = new \DateTime();

        $this->db->users->insert($user);
        return $user;
    }

    public function update(int $id, array $data): bool
    {
        return $this->db->users->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->db->users->delete($id);
    }
}
```

### Relations

Define relations on collections using the ON\Data fluent builder (`innerKey` on the source collection, `outerKey` on the target collection):

```php
$event->registry
    ->collection('post')
    ->table('posts')
    ->primaryKey('id')
    ->field('id', 'int')->autoIncrement(true)->end()
    ->field('title', 'string')->end()
    ->field('content', 'text')->end()
    ->field('user_id', 'int')->end()
    ->belongsTo('user', 'user')->innerKey('user_id')->outerKey('id')->end()
    ->end();
```

## Queries

### Select Queries

```php
// Basic select
$users = $db->users->select()->fetchAll();

// Where conditions
$users = $db->users
    ->select()
    ->where('active', true)
    ->where('role', 'admin')
    ->fetchAll();

// Find one
$user = $db->users->select()->where('id', 1)->fetchOne();

// Ordering
$posts = $db->posts
    ->select()
    ->orderBy('createdAt', 'DESC')
    ->fetchAll();

// Limit & Offset
$page = $db->users->select()->limit(10)->offset(20)->fetchAll();

// Aggregation
$count = $db->users->count();
$count = $db->users->count()->where('active', true)->fetchOne();
```

### Insert

```php
$db->users->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'createdAt' => new \DateTime(),
]);
```

### Update

```php
$db->users->update(1, [
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
]);

// Or with where
$db->users
    ->update(['active' => true])
    ->where('id', '>', 10)
    ->run();
```

### Delete

```php
$db->users->delete(1);

// Or with where
$db->users->delete()->where('active', false)->run();
```

## Transactions

```php
$db = $app->ext('db');

// Auto transaction
$db->transaction(function($db) {
    $user = $db->users->insert(['name' => 'John']);
    $db->profiles->insert(['userId' => $user->id, 'bio' => 'Test']);
});

// Manual transaction
$db->begin();
try {
    $db->users->insert(['name' => 'John']);
    $db->profiles->insert(['userId' => 1, 'bio' => 'Test']);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}
```

## Migrations

### Generate Migration

```bash
php bin/console db:migrate:generate create_users_table
```

### Migration File

```php
use Cycle\Schema\Definition;
use Cycle\Migrations\AtomicMigration;

return new class extends AtomicMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('id', 'int', ['primary' => true, 'autoIncrement' => true])
            ->addColumn('name', 'string')
            ->addColumn('email', 'string', ['unique' => true])
            ->addColumn('created_at', 'datetime')
            ->create();
    }

    public function down(): void
    {
        $this->dropTable('users');
    }
};
```

### Run Migrations

```bash
# Run next pending migration
php bin/console db:migrate:up

# Run all pending migrations
php bin/console db:migrate:all

# Rollback last migration
php bin/console db:migrate:down

# Show migration status
php bin/console db:migrate:status
```

The `db:migrate:all` command runs all pending migrations in sequence, auto-initializing the migration table if needed.

## Schema Synchronization

```bash
# Sync schema to database (development)
php bin/console orm:schema:sync

# Show pending changes
php bin/console orm:schema:diff

# Clear cache
php bin/console orm:schema:cache:clear

# Clear all caches
php bin/console cache:clear
```

## Raw SQL

```php
// Query builder
$users = $db->query(
    'SELECT * FROM users WHERE active = ? AND role = ?',
    [true, 'admin']
)->fetchAll();

// Execute
$db->execute('UPDATE users SET active = 0 WHERE id > ?', [100]);
```

## Database Manager

```php
use ON\DB\DatabaseManager;

$manager = $app->container->get(DatabaseManager::class);

// Get default database
$db = $manager->getDatabase();

// Get named database
$usersDb = $manager->getDatabase('users');

// Get underlying connection (Cycle DatabaseManager or PDO)
$connection = $manager->getDatabaseConnection();
```

## Best Practices

1. **Use repositories** - Encapsulate data access logic
2. **Define entities clearly** - Register collections on `ON\Data\Definition\Registry` via `DataDefinitionConfigureEvent`
3. **Run migrations** - Never modify schema directly in production
4. **Use transactions** - Wrap multi-table operations
5. **Index wisely** - Add indexes for frequent queries
6. **Avoid N+1** - Use eager loading for relations
