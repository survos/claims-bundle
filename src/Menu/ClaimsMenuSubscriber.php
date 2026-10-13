<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Menu;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Entity\ClaimRun;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class ClaimsMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string
    {
        return 'Claims';
    }

    protected function getResourceClasses(): array
    {
        return [
            'Claims' => Claim::class,
            'Claim Runs' => ClaimRun::class,
        ];
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}
