# Console / CLI

Overnight integrates with Symfony Console for CLI commands.

## Configuration

```php
use ON\Console\ConsoleExtension;

$app->install(new ConsoleExtension());

// Run CLI
$app->run();
```

## Built-in Commands

### Serve Command

```bash
# Start development server
php bin/console serve

# Custom host and port
php bin/console serve --host=0.0.0.0 --port=8080

# With router
php bin/console serve --docroot=public
```

### Routes Command

```bash
# List all routes
php bin/console routes

# Show detailed route info
php bin/console routes --verbose

# Filter routes
php bin/console routes --path=/api
```

### Clear Cache

```bash
# Clear all caches
php bin/console cache:clear

# Clear specific cache
php bin/console cache:clear --route
php bin/console cache:clear --discovery
php bin/console cache:clear --templates
```

### Database Commands

```bash
# Run next pending migration
php bin/console db:migrate:up

# Run all pending migrations at once
php bin/console db:migrate:all

# Rollback last migration
php bin/console db:migrate:down

# Initialize migration tracking
php bin/console db:migrate:init

# Show migration status
php bin/console db:migrate:status
```

The `db:migrate:all` command runs all pending migrations in a loop until the database is up to date. It auto-initializes the migration table if not yet configured.

## Creating Commands

### Basic Command

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:greet',
    description: 'Greet a user',
)]
class GreetCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Hello, World!');

        return Command::SUCCESS;
    }
}
```

### With Arguments and Options

```php
#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user',
)]
class CreateUserCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('name', InputArgument::OPTIONAL, 'User name', 'Anonymous')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Create as admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        $name = $input->getArgument('name');
        $isAdmin = $input->getOption('admin');

        $output->writeln("Creating user: $name <$email>");
        
        if ($isAdmin) {
            $output->writeln('Setting as admin...');
        }

        // Create user logic here...

        $output->writeln('<info>User created successfully!</info>');

        return Command::SUCCESS;
    }
}
```

### Interactive Input

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $dialog = $this->getHelper('dialog');

    // Ask for confirmation
    if (!$dialog->askConfirmation(
        $output,
        'Continue with this action?',
        false
    )) {
        $output->writeln('Cancelled.');
        return Command::SUCCESS;
    }

    // Ask for choice
    $colors = ['red', 'blue', 'green'];
    $color = $dialog->select($output, 'Choose a color', $colors);

    // Ask for hidden input (password)
    $password = $dialog->askHiddenResponse(
        $output,
        'Enter password'
    );

    return Command::SUCCESS;
}
```

## Registering Commands

### Via Extension

```php
use ON\Console\ConsoleExtension;

class AppExtension extends AbstractExtension
{
    public function setup(): void
    {
        $console = $this->app->ext('console');

        $console->addCommand(GreetCommand::class);
        $console->addCommand(CreateUserCommand::class);
    }
}
```

### Auto-Discovery

Commands with `#[AsCommand]` attribute are auto-discovered:

```php
// In discovery config
$discovery->addProcessor(CommandAttributeProcessor::class, [
    'namespace' => 'App\\Commands',
]);
```

## Console Output

### Styling

```php
// Colors and styles
$output->writeln('<info>Success message</info>');
$output->writeln('<error>Error message</error>');
$output->writeln('<comment>Warning message</comment>');
$output->writeln('<question>Question</question>');

// Bold
$output->writeln('<fg=yellow;options=bold>Important!</>');

// Background
$output->writeln('<bg=red;fg=white>Alert!</>');
```

### Tables

```php
use Symfony\Component\Console\Helper\Table;

$table = new Table($output);
$table
    ->setHeaders(['ID', 'Name', 'Email'])
    ->setRows([
        [1, 'John', 'john@example.com'],
        [2, 'Jane', 'jane@example.com'],
    ]);
$table->render();
```

### Progress Bar

```php
$progressBar = new ProgressBar($output, 100);
$progressBar->start();

for ($i = 0; $i < 100; $i++) {
    // Do work
    $progressBar->advance();
}

$progressBar->finish();
```

## Input/Output Helpers

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Arguments
    $name = $input->getArgument('name');
    
    // Options
    $force = $input->getOption('force');
    
    // Check if option is set
    if ($input->hasOption('verbose')) {
        $verbose = $input->getOption('verbose');
    }
    
    // Check argument presence
    if ($input->getArgumentExist('email')) {
        // ...
    }

    return Command::SUCCESS;
}
```

## Error Handling

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    try {
        // Command logic
        $this->service->run();
    } catch (\Exception $e) {
        $output->writeln('<error>' . $e->getMessage() . '</error>');
        return Command::FAILURE;
    }

    return Command::SUCCESS;
}
```

## Exit Codes

```php
return Command::SUCCESS;        // 0
return Command::FAILURE;         // 1
return Command::INVALID;         // 2
```

## Running Commands from Code

```php
use Symfony\Component\Console\Application;

$console = $app->ext('console');

// Run a command
$console->runCommand('app:greet');

// Run with arguments
$console->runCommand('app:create-user john@example.com John --admin');

// Find command
$command = $console->find('app:greet');
$command->run($input, $output);
```

## Writing Output

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Single line
    $output->writeln('Hello');

    // Multiple lines
    $output->writeln([
        'Line 1',
        'Line 2',
        'Line 3',
    ]);

    // Without newline
    $output->write('Loading...');
    
    // Verbose mode
    if ($output->isVerbose()) {
        $output->writeln('Debug info...');
    }

    return Command::SUCCESS;
}
```

## Best Practices

1. **Return codes** - Always return appropriate exit codes
2. **Be descriptive** - Clear command names and descriptions
3. **Handle errors** - Catch exceptions and show meaningful messages
4. **Use styling** - Use colors to indicate success/error/warning
5. **Show progress** - Use progress bars for long operations
6. **Validate input** - Check arguments before processing
