# Database & ORM

Overnight supports multiple database backends through Cycle ORM.

## Configuration

```php
use ON\Db\DatabaseConfig;
use ON\DatabaseExtension;

$config = new DatabaseConfig();

// MySQL
$config->addDatabase('default', 'mysql:host=localhost;dbname=myapp', 'root', 'password');

// PostgreSQL
$config->addDatabase('default', 'pgsql:host=localhost;dbname=myapp', 'user', 'pass');

// SQLite
$config->addDatabase('default', 'sqlite:' . __DIR__ . '/database.db');

// Multiple databases
$config->addDatabase('users', 'mysql:host=localhost;dbname=users', 'root', '');
$config->addDatabase('orders', 'mysql:host=localhost;dbname=orders', 'root', '');

$config->setDefault('default');

$app->install(new DatabaseExtension($config));
```

## Cycle ORM

### Entity Definition

```php
use Cycle\Annotated\Annotation as ORM;

#[ORM\Entity]
class User
{
    #[ORM\Column(type: 'int')]
    #[ORM\PrimaryKey]
    #[ORM\AutoIncrement]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name;

    #[ORM\Column(type: 'string', unique: true)]
    public string $email;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $password = null;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $createdAt;

    #[ORM\Relation\HasMany(target: Post::class)]
    public array $posts = [];
}
```

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

```php
#[ORM\Entity]
class Post
{
    #[ORM\Column(type: 'int')]
    #[ORM\PrimaryKey]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title;

    #[ORM\Column(type: 'text')]
    public string $content;

    #[ORM\Relation\BelongsTo(target: User::class)]
    public ?User $user = null;
}
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
php bin/console orm:migrate:generate create_users_table
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
# Run all pending migrations
php bin/console orm:migrate

# Run specific migration
php bin/console orm:migrate:up create_users_table

# Rollback last migration
php bin/console orm:migrate:down

# Show migration status
php bin/console orm:migrate:status
```

## Schema Synchronization

```bash
# Sync schema to database (development)
php bin/console orm:schema:sync

# Show pending changes
php bin/console orm:schema:diff

# Clear cache
php bin/console orm:schema:cache:clear
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
$manager = $app->ext('db');

// Get default database
$db = $manager->getDatabase();

// Get named database
$usersDb = $manager->getDatabase('users');

// Get PDO connection
$pdo = $manager->getDatabaseConnection();
```

## Best Practices

1. **Use repositories** - Encapsulate data access logic
2. **Define entities clearly** - Use annotations for schema
3. **Run migrations** - Never modify schema directly in production
4. **Use transactions** - Wrap multi-table operations
5. **Index wisely** - Add indexes for frequent queries
6. **Avoid N+1** - Use eager loading for relations
