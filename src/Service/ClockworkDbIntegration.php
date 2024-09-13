<?php
namespace ON\Service;

use ON\Application;
use ON\Event\EventSubscriberInterface;

class ClockworkDbIntegration implements EventSubscriberInterface {
    public function onQuery($event)
    {
        $query = $event->getQuery();

        $filter = \Clockwork\Helpers\StackFilter::make()
            ->isNotVendor([ 'itsgoingd', 'guilhermeaiolfi', 'league' ])
			      ->isNotNamespace([ 'Clockwork', 'League', 'Invoker' ])
            ->isNotFunction([ 'profileCall', 'emitEvent' ]);

        $trace = \Clockwork\Helpers\StackTrace::get()->resolveViewName()->skip($filter);

        clock()->addDatabaseQuery(
            $query->getSql(), 
            $query->getParameters(), 
            floor($query->getDuration() * 1000),
            [
                "trace" => (new \Clockwork\Helpers\Serializer)->trace($trace)
            ]
        );
    }

    public function onManagerCreate($event)
    {
        $container = Application::getContainer();
        $database = $event->getSubject();

        $config = $container->get('config');
        
        if ($database->getName() == $config["db"]["default"]) {
            $database->getConnection()->setEventDispatcher($container->get(\Psr\EventDispatcher\EventDispatcherInterface::class));
        }
    }

    public static function getSubscribedEvents(): array {
        return [
            "pdo.query" => 'onQuery',
            "core.db.manager.create" => 'onManagerCreate'
        ];
    }
}