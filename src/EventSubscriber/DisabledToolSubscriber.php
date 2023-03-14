<?php

declare(strict_types = 1);

namespace App\EventSubscriber;

use App\Controller\XtoolsController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A DisabledToolSubscriber checks to see if the current tool is disabled
 * and will throw an exception accordingly.
 */
class DisabledToolSubscriber implements EventSubscriberInterface
{

    protected ContainerInterface $container;

    /**
     * Save the container for later use.
     * @param ContainerInterface $container The DI container.
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Register our interest in the kernel.controller event.
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    /**
     * Check to see if the current tool is enabled.
     * @param ControllerEvent $event The event.
     * @throws NotFoundHttpException If the tool is not enabled.
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if ($controller instanceof XtoolsController && method_exists($controller, 'getIndexRoute')) {
            $tool = $controller[0]->getIndexRoute();
            if (!in_array($tool, ['homepage', 'meta', 'Quote']) && !$this->container->getParameter("enable.$tool")) {
                throw new NotFoundHttpException('This tool is disabled');
            }
        }
    }
}
