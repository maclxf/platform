<?php declare(strict_types=1);

namespace Shopware\Storefront\Test\Framework\Captcha;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\ErrorController;
use Shopware\Storefront\Framework\Captcha\AbstractCaptcha;
use Shopware\Storefront\Framework\Captcha\BasicCaptcha;
use Shopware\Storefront\Framework\Captcha\CaptchaRouteListener;
use Shopware\Storefront\Framework\Captcha\Exception\CaptchaInvalidException;
use Shopware\Storefront\Framework\Routing\StorefrontResponse;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal
 */
class CaptchaRouteListenerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;
    use StorefrontControllerTestBehaviour;

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        static::assertSame([
            KernelEvents::CONTROLLER => [
                ['validateCaptcha', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE],
            ],
        ], CaptchaRouteListener::getSubscribedEvents());
    }

    /**
     * @dataProvider controllerEventProvider
     */
    public function testThrowsExceptionWhenValidationFails(ControllerEvent $event): void
    {
        $this->expectException(CaptchaInvalidException::class);

        (new CaptchaRouteListener(
            $this->getCaptchas(true, false),
            $this->getContainer()->get(ErrorController::class),
            $this->getContainer()->get(SystemConfigService::class)
        ))->validateCaptcha($event);
    }

    /**
     * @return array<int, array<int, ControllerEvent>>
     */
    public function controllerEventProvider(): array
    {
        return [
            [
                $this->getControllerEventMock(),
            ],
        ];
    }

    public function testJsonResponseWhenCaptchaValidationFails(): void
    {
        $systemConfig = $this->getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.basicInformation.activeCaptchasV2', [
            BasicCaptcha::CAPTCHA_NAME => [
                'name' => BasicCaptcha::CAPTCHA_NAME,
                'isActive' => true,
            ],
        ]);

        $salutation = $this->getContainer()->get('salutation.repository')->search(
            (new Criteria())->setLimit(1),
            Context::createDefaultContext()
        )->first()->getId();

        $data = [
            'salutationId' => $salutation,
            'email' => 'kyln@shopware.com',
            'firstName' => 'Ky',
            'lastName' => 'Le',
            'subject' => 'Captcha',
            'comment' => 'Basic Captcha',
            'phone' => '+4920 3920173',
            'shopware_basic_captcha_confirm' => 'notkyln',
        ];

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
        $browser->request(
            'POST',
            $_SERVER['APP_URL'] . '/form/contact',
            $this->tokenize('frontend.form.contact.send', $data)
        );

        $response = $browser->getResponse();
        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(200, $response->getStatusCode());

        $responseContent = $response->getContent() ?: '';
        $content = (array) json_decode($responseContent, null, 512, \JSON_THROW_ON_ERROR);

        static::assertCount(1, $content);
        static::assertObjectHasAttribute('type', $content[0]);

        $type = $content[0]->type;

        static::assertSame('danger', $type);
    }

    public function testResponseWhenCaptchaValidationFails(): void
    {
        $systemConfig = $this->getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.basicInformation.activeCaptchasV2', [
            BasicCaptcha::CAPTCHA_NAME => [
                'name' => BasicCaptcha::CAPTCHA_NAME,
                'isActive' => true,
            ],
        ]);

        $data = [
            'shopware_basic_captcha_confirm' => 'kyln',
        ];

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request(
            'POST',
            $_SERVER['APP_URL'] . '/account/register',
            $this->tokenize('frontend.account.register.save', $data)
        );

        /** @var StorefrontResponse $response */
        $response = $browser->getResponse();

        static::assertInstanceOf(Response::class, $response);
        static::assertSame(200, $response->getStatusCode(), $response->getContent() ?: '');
        static::assertSame('frontend.account.home.page', $response->getData()['redirectTo']);
    }

    /**
     * @return array<int, AbstractCaptcha|MockObject>
     */
    private function getCaptchas(bool $supports, bool $isValid): array
    {
        $captcha = $this->getMockBuilder(AbstractCaptcha::class)->getMock();

        $captcha->expects(static::once())
            ->method('supports')
            ->willReturn($supports);

        $captcha->expects($supports ? static::once() : static::never())
            ->method('isValid')
            ->willReturn($isValid);

        $captcha->expects($supports ? static::once() : static::never())
            ->method('shouldBreak')
            ->willReturn(true);

        return [$captcha];
    }

    private function getControllerEventMock(): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            function (): void {
            },
            self::getRequest($this->getRequestAttributes(true)),
            HttpKernelInterface::MAIN_REQUEST
        );
    }

    private static function getRequest(ParameterBag $attributes): Request
    {
        return new Request([], [], $attributes->all(), [], [], []);
    }

    private function getRequestAttributes(bool $isCheckEnabled): ParameterBag
    {
        $param = [
            '_captcha' => $isCheckEnabled ? true : null,
        ];

        return new ParameterBag($isCheckEnabled ? $param : []);
    }
}
