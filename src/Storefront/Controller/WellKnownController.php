<?php declare(strict_types=1);

namespace Shopware\Storefront\Controller;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @internal
 */
#[Route(path: '.well-known/', defaults: ['_routeScope' => ['storefront']])]
#[Package('storefront')]
class WellKnownController extends StorefrontController
{
    #[Route(path: 'change-password', name: 'frontend.well-known.change-password', methods: ['GET'])]
    public function changePassword(): Response
    {
        return $this->redirectToRoute(
            'frontend.account.profile.page',
            ['_fragment' => '#profile-password-form'],
            Response::HTTP_FOUND
        );
    }
}
