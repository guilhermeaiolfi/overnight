# Events

Overnight uses League\Event for a powerful event dispatcher system.

## Configuration

```php
use ON\Events\EventsExtension;

$app->install(new EventsExtension());
```

## Dispatching Events

```php
$dispatcher = $app->ext('events');

// Dispatch an event
$dispatcher->dispatch(new UserRegistered($user));

// With data
$dispatcher->dispatch('user.registered', [
    'user' => $user,
    'ip' => $request->getClientIp(),
]);
```

## Creating Events

### Event Class

```php
use League\Event\AbstractEvent;

class UserRegistered extends AbstractEvent
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function name(): string
    {
        return 'user.registered';
    }
}
```

### Simple Event

```php
use ON\Event\NamedEvent;

class OrderPlaced extends NamedEvent
{
    public function __construct(
        public Order $order,
        public Customer $customer
    ) {}

    public function eventName(): string
    {
        return 'order.placed';
    }
}
```

## Event Listeners

### Closure Listener

```php
$dispatcher->addListener('user.registered', function($event) {
    // Handle the event
    $user = $event->getUser();
    // Send welcome email, etc.
});
```

### Callable Listener

```php
class UserEventListener
{
    public function onUserRegistered(UserRegistered $event): void
    {
        $user = $event->getUser();
        $this->emailService->sendWelcome($user);
    }

    public function onUserDeleted(UserDeleted $event): void
    {
        $this->log->info('User deleted', ['id' => $event->getUserId()]);
    }
}

// Register
$listener = new UserEventListener($container);
$dispatcher->addListener('user.registered', [$listener, 'onUserRegistered']);
```

### Priority

Higher priority listeners execute first:

```php
$dispatcher->addListener('user.registered', $listener1, 100);  // First
$dispatcher->addListener('user.registered', $listener2, 50);  // Second
$dispatcher->addListener('user.registered', $listener3, 10);  // Third
```

### One-time Listeners

Listener only fires once:

```php
$dispatcher->addListener('app.boot', $callback, 0, true);
```

## Event Subscribers

### Subscriber Interface

```php
use League\Event\EventSubscriberInterface;

class ApplicationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private MailerInterface $mailer
    ) {}

    public static function subscribe($dispatcher): array
    {
        return [
            'user.registered' => ['onUserRegistered', 0],
            'user.deleted' => ['onUserDeleted', 0],
            'order.placed' => 'onOrderPlaced',
        ];
    }

    public function onUserRegistered(UserRegistered $event): void
    {
        $this->mailer->sendWelcome($event->getUser());
    }

    public function onUserDeleted(UserDeleted $event): void
    {
        $this->logger->info('User deleted', ['id' => $event->getUserId()]);
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->logger->info('Order placed', ['order' => $event->order->id]);
    }
}
```

### Register Subscriber

```php
$dispatcher->subscribeWith(new ApplicationSubscriber($container));
```

## Event Attributes (PHP 8)

### EventHandler Attribute

```php
use ON\Event\Attribute\EventHandler;

class UserService
{
    #[EventHandler(eventName: 'user.registered', priority: 50)]
    public function onUserRegistered(UserRegistered $event): void
    {
        // Handle event
    }

    #[EventHandler(eventName: 'order.placed', once: true)]
    public function onOrderPlacedOnce(OrderPlaced $event): void
    {
        // Fires only once
    }
}
```

### Register Attribute Listener

```php
$app->ext('events')->loadEventSubscriber(UserService::class);
```

## Generator Listeners

Generators allow emitting multiple events from one listener:

```php
use League\Event\Generator\EmitterTrait;

class OrderProcessor
{
    use EmitterTrait;

    public function process(array $data): Order
    {
        $order = $this->createOrder($data);

        // Emit events
        $this->emit(new OrderCreated($order));

        if ($order->isPaid()) {
            $this->emit(new OrderPaid($order));
        }

        foreach ($order->items as $item) {
            $this->emit(new OrderItemAdded($order, $item));
        }

        return $order;
    }
}
```

## Removing Listeners

```php
// Remove all listeners for an event
$dispatcher->removeListeners('user.registered');

// Remove specific listener
$dispatcher->removeListener('user.registered', $callback);

// Remove subscriber
$dispatcher->removeSubscriber($subscriber);
```

## Built-in Events

### App Events

```php
// Available in EventsExtension
'app.ready'      // App is ready to handle requests
'app.boot'       // App is booting
'app.shutdown'    // App is shutting down
```

### Router Events

```php
'router.match'   // After route is matched
'router.not_found' // No route matched
```

### Request Events

```php
'request.received'   // Request received
'response.sent'       // Response sent
```

## Event Propagation

Stop event propagation to prevent other listeners:

```php
use League\Event\Event;

class MyListener
{
    public function handle(Event $event): void
    {
        if ($this->shouldStop()) {
            $event->stopPropagation();
            return;
        }

        // Continue processing
    }
}
```

## Testing Events

```php
use PHPUnit\Framework\TestCase;

class UserServiceTest extends TestCase
{
    public function testSendsWelcomeEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('sendWelcome')
            ->with($this->callback(function($user) {
                return $user->email === 'test@example.com';
            }));

        $listener = new UserRegisteredListener($mailer);
        $event = new UserRegistered(new User('test@example.com'));

        $listener->handle($event);
    }
}
```

## Best Practices

1. **Use meaningful names** - `user.registered` not `user_event`
2. **Keep listeners small** - One listener, one action
3. **Use event classes** - Type-safe with meaningful data
4. **Document events** - List all events in your docs
5. **Be careful with order** - Use priority for execution order
